using System.Text.Json;
using LeikwanBridge.Core.Helpers;
using LeikwanBridge.Core.Models;
using LeikwanBridge.Core.Services.Platform;
using Serilog;

namespace LeikwanBridge.Core.Services.Ultra;

public sealed class WindowsUltraRuntimeHost : IUltraRuntimeHost
{
    private readonly AgentConfig _config;
    private readonly IProcessSupervisor _supervisor;
    private readonly StateManager _stateManager;
    private readonly Func<string, bool> _binaryExists;
    private readonly Func<int, bool> _portListeningProbe;
    private readonly Func<int, UltraLease, CancellationToken, Task<bool>> _leaseSender;
    private readonly Func<int, IReadOnlyList<UltraLease>, CancellationToken, Task<bool>> _leaseBatchSender;
    private readonly TimeSpan _leaseDeliveryRetryWindow;
    private readonly TimeSpan _leaseDeliveryRetryInterval;
    private readonly Func<int, PortPreflightResult>? _portPreflight;

    /// <summary>
    /// The runtime opens its private control endpoint a moment after the process
    /// is spawned, so the first delivery attempt can legitimately be refused.
    /// Treating that as a failed start killed the runtime and respawned it on the
    /// next sync; with several forwards preparing at once the retries overlapped
    /// into a start/stop storm that also churned healthy forwards on the same
    /// agent. Retry inside a bounded window instead.
    /// </summary>
    private static readonly TimeSpan DefaultLeaseDeliveryRetryWindow = TimeSpan.FromSeconds(20);

    private static readonly TimeSpan DefaultLeaseDeliveryRetryInterval = TimeSpan.FromMilliseconds(250);

    public WindowsUltraRuntimeHost(
        AgentConfig config,
        IProcessSupervisor supervisor,
        StateManager stateManager,
        Func<string, bool>? binaryExists = null,
        Func<int, bool>? portListeningProbe = null,
        Func<int, UltraLease, CancellationToken, Task<bool>>? leaseSender = null,
        Func<int, PortPreflightResult>? portPreflight = null,
        Func<int, IReadOnlyList<UltraLease>, CancellationToken, Task<bool>>? leaseBatchSender = null,
        TimeSpan? leaseDeliveryRetryWindow = null,
        TimeSpan? leaseDeliveryRetryInterval = null)
    {
        _config = config;
        _supervisor = supervisor;
        _stateManager = stateManager;
        _binaryExists = binaryExists ?? File.Exists;
        _portListeningProbe = portListeningProbe ?? NetworkProbe.IsPortListening;
        _leaseSender = leaseSender ?? UltraLeaseClient.SendAsync;
        _leaseBatchSender = leaseBatchSender ?? UltraLeaseClient.SendAsync;
        _leaseDeliveryRetryWindow = leaseDeliveryRetryWindow ?? DefaultLeaseDeliveryRetryWindow;
        _leaseDeliveryRetryInterval = leaseDeliveryRetryInterval ?? DefaultLeaseDeliveryRetryInterval;
        _portPreflight = portPreflight;
    }

    public void Start(ForwardConfig forward, UltraLease lease, string contractSignature, string applyReason)
        => Start(forward, new[] { lease }, contractSignature, applyReason);

    public void Start(ForwardConfig forward, IReadOnlyList<UltraLease> leases, string contractSignature, string applyReason)
    {
        if (leases is null || leases.Count is < 1 or > 4)
            throw new ArgumentException("Ultra lease candidates are required", nameof(leases));
        var phase = "validate";
        try
        {
            Log.Information("ultra runtime start begin for forward {ForwardId}", forward.ForwardId);
            if (forward.ListenPort is < 1 or > 65535)
                throw new InvalidOperationException("ultra_listen_port_invalid");
            var ultraBin = _config.GetEffectiveUltraBin();
            if (!_binaryExists(ultraBin))
                throw new InvalidOperationException("ultra_runtime_unavailable");
            if (_portPreflight is not null)
            {
                phase = "port_preflight";
                Log.Information("ultra port preflight begin for forward {ForwardId}", forward.ForwardId);
                var preflight = _portPreflight(forward.ListenPort);
                if (preflight.Blocked)
                {
                    Log.Warning("Ultra forward {ForwardId}: local port preflight blocked port {Port} code={Code} foreignPid={ForeignPid}",
                        forward.ForwardId, forward.ListenPort, preflight.ConflictCode, preflight.ForeignPid);
                    throw new InvalidOperationException("ultra_port_conflict");
                }
                Log.Information("ultra port preflight complete blocked={Blocked} for forward {ForwardId}",
                    preflight.Blocked, forward.ForwardId);
                if (preflight.QuiescedPids.Count > 0)
                {
                    Log.Information("Ultra forward {ForwardId}: quiesced managed-old owners {Pids}",
                        forward.ForwardId, preflight.QuiescedPids);
                }
            }

            phase = "stop_previous_runtime";
            Log.Information("ultra previous runtime stop begin for forward {ForwardId}", forward.ForwardId);
            Stop(forward.ForwardId);
            Log.Information("ultra previous runtime stop complete for forward {ForwardId}", forward.ForwardId);
            var runtimeDirectory = Path.Combine(_config.GetEffectiveStateDir(), "runtime");
            var configPath = Path.Combine(runtimeDirectory, $"ultra-forward-{forward.ForwardId}.json");
            var statusPath = Path.Combine(runtimeDirectory, $"ultra-forward-{forward.ForwardId}.status.json");
            var autoSelect = leases.Count > 1;
            var udpCapable = string.Equals(leases[0].Transport, "udp", StringComparison.OrdinalIgnoreCase)
                || string.Equals(leases[0].Transport, "tcp+udp", StringComparison.OrdinalIgnoreCase);
            var staticConfig = new Dictionary<string, object?>
            {
                ["forward_id"] = forward.ForwardId,
                ["listen_host"] = "127.0.0.1",
                ["listen_port"] = forward.ListenPort,
                ["target"] = forward.Target,
                ["control_socket"] = $"\\\\.\\pipe\\{UltraLeaseClient.PipeName(forward.ForwardId)}",
                ["transport"] = leases[0].Transport,
                ["policy_generation"] = leases[0].PolicyGeneration,
                ["target_digest"] = leases[0].TargetDigest,
                ["auto_select"] = autoSelect,
                ["max_tcp_connections"] = 512,
                ["udp_queue_packets"] = 256,
                ["connect_timeout_seconds"] = 5,
                ["status_path"] = statusPath,
                // Without these the runtime falls back to the Linux paths,
                // cannot take the lookup lock, and waits ~3.5 s on every start.
                ["location_cache_path"] = Path.Combine(runtimeDirectory, "location.json"),
                ["location_lock_path"] = Path.Combine(runtimeDirectory, "location.lock"),
            };
            EgressInterfaceConfig.Apply(staticConfig, _config.PreferredEgressInterface);
            if (udpCapable)
            {
                staticConfig["udp_prewarm"] = true;
                staticConfig["udp_keepalive_interval_seconds"] = 5;
            }
            if (autoSelect)
            {
                staticConfig["probe_target"] = UltraProbeContract.Target;
                staticConfig["probe_duration_seconds"] = UltraProbeContract.DurationSeconds;
                staticConfig["probe_timeout_ms"] = UltraProbeContract.TimeoutMilliseconds;
                staticConfig["probe_samples"] = UltraProbeContract.Samples;
                staticConfig["probe_interval_ms"] = UltraProbeContract.IntervalMilliseconds;
                staticConfig["probe_workers"] = UltraProbeContract.Workers;
            }
            // Bootstrap-only contract: the runtime reads external_uot from its
            // startup config, so a rotated token needs a runtime restart (the
            // lease-pipe delivery cannot update it).
            var externalUot = ExternalUotGrant.Select(leases);
            if (externalUot is not null)
                staticConfig["external_uot"] = externalUot.ToBootstrapPayload();
            var firstFlight = forward.UltraAccess?.TCPFirstFlight?.ToBootstrapPayload(forward, leases);
            if (firstFlight is not null)
                staticConfig["tcp_first_flight"] = firstFlight;
            Directory.CreateDirectory(runtimeDirectory);
            File.WriteAllText(configPath, JsonSerializer.Serialize(staticConfig, new JsonSerializerOptions
            {
                WriteIndented = true,
                DefaultIgnoreCondition = System.Text.Json.Serialization.JsonIgnoreCondition.WhenWritingNull,
            }));
            phase = "spawn_runtime";
            _supervisor.StartProcess(
                forward.ForwardId,
                "ultra-runtime",
                "Ultra direct runtime",
                ultraBin,
                $"--config \"{configPath}\"");
            Log.Information("ultra runtime process started for forward {ForwardId}", forward.ForwardId);
            phase = "deliver_lease";
            Log.Information("ultra lease delivery begin for forward {ForwardId}", forward.ForwardId);
            var delivered = DeliverLeaseWithRetry(forward.ForwardId, leases);
            Log.Information("ultra lease delivery result={LeaseDelivered} for forward {ForwardId}", delivered, forward.ForwardId);
            if (!delivered)
                throw new InvalidOperationException("ultra_lease_delivery_failed");
            phase = "wait_listener";
            var portReady = WaitForPort(forward.ListenPort, _portListeningProbe, ListenerReadyTimeout,
                () => _supervisor.IsRunning(forward.ForwardId));
            Log.Information("ultra listener ready={PortReady} for forward {ForwardId}", portReady, forward.ForwardId);
            if (!portReady)
                throw new InvalidOperationException("ultra_listener_not_ready");

            phase = "persist_applied_state";
            var applied = _stateManager.LoadApplied();
            applied[forward.ForwardId.ToString()] = new AppliedForwardState
            {
                ForwardId = forward.ForwardId,
                Mode = "ultra",
                ListenPort = forward.ListenPort,
                IngressMode = "direct-provider",
                EndpointCount = 0,
                LegPorts = [],
                ProcessRoles = ["ultra-runtime"],
                ProcessLabels = ["Ultra direct runtime"],
                ProcessIds = [.. _supervisor.GetProcessIds(forward.ForwardId)],
                ContractSignature = contractSignature,
                LastApplyReason = string.IsNullOrWhiteSpace(applyReason) ? "config_sync" : applyReason,
                UserStopped = false,
                UpdatedAt = DateTimeOffset.UtcNow.ToUnixTimeSeconds(),
            };
            _stateManager.SaveApplied(applied);
        }
        catch (Exception exception)
        {
            Log.Warning(exception, "ultra runtime start failed for forward {ForwardId} phase={Phase} ({ErrorType})",
                forward.ForwardId,
                phase,
                exception.GetType().Name);
            Stop(forward.ForwardId);
            throw new InvalidOperationException("ultra_runtime_start_failed", exception);
        }
        finally
        {
            DisposeLeases(leases);
        }
    }

    public bool Refresh(ForwardConfig forward, UltraLease lease)
        => Refresh(forward, new[] { lease });

    public bool Refresh(ForwardConfig forward, IReadOnlyList<UltraLease> leases)
    {
        if (leases is null || leases.Count is < 1 or > 4)
            return false;
        try
        {
            return leases.Count == 1
                ? _leaseSender(forward.ForwardId, leases[0], CancellationToken.None).GetAwaiter().GetResult()
                : _leaseBatchSender(forward.ForwardId, leases, CancellationToken.None).GetAwaiter().GetResult();
        }
        finally
        {
            DisposeLeases(leases);
        }
    }

    private bool DeliverLeaseWithRetry(int forwardId, IReadOnlyList<UltraLease> leases)
    {
        var deadline = DateTimeOffset.UtcNow + _leaseDeliveryRetryWindow;
        while (true)
        {
            var delivered = leases.Count == 1
                ? _leaseSender(forwardId, leases[0], CancellationToken.None).GetAwaiter().GetResult()
                : _leaseBatchSender(forwardId, leases, CancellationToken.None).GetAwaiter().GetResult();
            if (delivered)
                return true;
            if (DateTimeOffset.UtcNow >= deadline)
                return false;
            Thread.Sleep(_leaseDeliveryRetryInterval);
        }
    }

    private static void DisposeLeases(IEnumerable<UltraLease> leases)
    {
        var seen = new HashSet<UltraLease>();
        foreach (var lease in leases)
        {
            if (seen.Add(lease))
                lease.Dispose();
        }
    }

    /// <summary>
    /// A cold start (several forwards starting together, candidate probes,
    /// antivirus scanning a fresh binary) can take well over the 6 s this used
    /// to allow; giving up early killed a healthy runtime and restarted it.
    /// The wait ends as soon as the runtime process exits.
    /// </summary>
    public static readonly TimeSpan ListenerReadyTimeout = TimeSpan.FromSeconds(20);

    public static bool WaitForPort(int port, Func<int, bool> probe, TimeSpan timeout, Func<bool> processAlive)
    {
        var deadline = DateTime.UtcNow + timeout;
        while (DateTime.UtcNow < deadline)
        {
            if (probe(port))
                return true;
            if (!processAlive())
                return probe(port);
            Thread.Sleep(25);
        }
        return probe(port);
    }

    public void Stop(int forwardId)
    {
        _supervisor.StopAll(forwardId);
        CleanupRuntimeFiles(forwardId);
    }

    public string? GetStatusPath(int forwardId)
        => forwardId <= 0
            ? null
            : Path.Combine(_config.GetEffectiveStateDir(), "runtime", $"ultra-forward-{forwardId}.status.json");

    private void CleanupRuntimeFiles(int forwardId)
    {
        if (forwardId <= 0)
            return;
        var runtimeDirectory = Path.Combine(_config.GetEffectiveStateDir(), "runtime");
        foreach (var path in new[]
        {
            Path.Combine(runtimeDirectory, $"ultra-forward-{forwardId}.json"),
            Path.Combine(runtimeDirectory, $"ultra-forward-{forwardId}.status.json"),
        })
        {
            try
            {
                if (File.Exists(path))
                    File.Delete(path);
            }
            catch (IOException)
            {
            }
        }
    }
}
