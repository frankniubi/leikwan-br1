using System.Collections.Concurrent;
using System.Security.Cryptography;
using System.Text.Json;
using LeikwanBridge.Core.Helpers;
using LeikwanBridge.Core.Models;
using LeikwanBridge.Core.Services;
using LeikwanBridge.Core.Services.Platform;
using LeikwanBridge.Core.Services.Ultra;
using Serilog;

namespace LeikwanBridge.Mac.Services;

public sealed class MacUltraRuntimeHost : IUltraRuntimeHost
{
    private sealed record RuntimeState(string Directory, string SocketDirectory, string SocketPath, string StatusPath);

    /// <summary>
    /// Budget for delivering the lease batch over the control socket.
    /// <para>
    /// The runtime answers the delivery only after its selection window closes:
    /// probe duration + datagram association budget + response grace, which is
    /// roughly 45-50 seconds with the shipped contract. The Windows host waits
    /// without an explicit deadline (it relies on
    /// <see cref="UnixUltraLeaseClient.BatchTimeout"/>); a hard 20 second cap
    /// here cancelled every multi-candidate delivery on macOS and made all
    /// forwards report "forward start failed". Keep this strictly above the
    /// lease client's own budget so the inner timeout always decides.
    /// </para>
    /// </summary>
    private static readonly TimeSpan LeaseDeliveryTimeout = TimeSpan.FromSeconds(90);

    private readonly AgentConfig _config;
    private readonly IProcessSupervisor _supervisor;
    private readonly StateManager _stateManager;
    private readonly Func<string, bool> _binaryExists;
    private readonly Func<string, bool> _socketExists;
    private readonly Func<int, bool> _portListeningProbe;
    private readonly Func<string, int, UltraLease, CancellationToken, Task<bool>> _leaseSender;
    private readonly Func<string, int, IReadOnlyList<UltraLease>, CancellationToken, Task<bool>> _leaseBatchSender;
    private readonly Func<int, PortPreflightResult>? _portPreflight;
    private readonly string _socketRoot;
    private readonly ConcurrentDictionary<int, RuntimeState> _runtimes = new();

    public MacUltraRuntimeHost(
        AgentConfig config,
        IProcessSupervisor supervisor,
        StateManager stateManager,
        Func<string, bool>? binaryExists = null,
        Func<string, bool>? socketExists = null,
        Func<int, bool>? portListeningProbe = null,
        Func<string, int, UltraLease, CancellationToken, Task<bool>>? leaseSender = null,
        Func<int, PortPreflightResult>? portPreflight = null,
        string? socketRoot = null,
        Func<string, int, IReadOnlyList<UltraLease>, CancellationToken, Task<bool>>? leaseBatchSender = null)
    {
        _config = config;
        _supervisor = supervisor;
        _stateManager = stateManager;
        _binaryExists = binaryExists ?? File.Exists;
        _socketExists = socketExists ?? File.Exists;
        _portListeningProbe = portListeningProbe ?? NetworkProbe.IsPortListening;
        var client = new UnixUltraLeaseClient();
        _leaseSender = leaseSender ?? client.SendAsync;
        _leaseBatchSender = leaseBatchSender ?? client.SendAsync;
        _portPreflight = portPreflight;
        _socketRoot = socketRoot ?? (OperatingSystem.IsMacOS() ? "/tmp" : Path.GetTempPath());
    }

    public void Start(ForwardConfig forward, UltraLease lease, string contractSignature, string applyReason)
        => Start(forward, new[] { lease }, contractSignature, applyReason);

    public void Start(ForwardConfig forward, IReadOnlyList<UltraLease> leases, string contractSignature, string applyReason)
    {
        if (leases is null || leases.Count is < 1 or > 4)
            throw new ArgumentException("Ultra lease candidates are required", nameof(leases));
        byte[] bootstrap = [];
        var phase = "validate";
        try
        {
            Log.Information("ultra runtime start begin for forward {ForwardId}", forward.ForwardId);
            if (forward.ListenPort is < 1 or > 65535)
                throw new InvalidOperationException("ultra_listen_port_invalid");
            var ultraBinary = _config.GetEffectiveUltraBin();
            if (!_binaryExists(ultraBinary))
                throw new InvalidOperationException("ultra_runtime_unavailable");
            if (_portPreflight is not null)
            {
                phase = "port_preflight";
                Log.Information("ultra port preflight begin for forward {ForwardId}", forward.ForwardId);
                var preflight = _portPreflight.Invoke(forward.ListenPort);
                Log.Information("ultra port preflight complete blocked={Blocked} for forward {ForwardId}",
                    preflight.Blocked, forward.ForwardId);
                if (preflight.Blocked)
                    throw new InvalidOperationException("ultra_port_conflict");
            }

            phase = "stop_previous_runtime";
            Log.Information("ultra previous runtime stop begin for forward {ForwardId}", forward.ForwardId);
            Stop(forward.ForwardId);
            Log.Information("ultra previous runtime stop complete for forward {ForwardId}", forward.ForwardId);
            var runtimeRoot = Path.Combine(_config.GetEffectiveStateDir(), "runtime");
            Directory.CreateDirectory(runtimeRoot);
            SetPrivateDirectoryMode(runtimeRoot);
            var runtimeDirectory = Path.Combine(runtimeRoot, $"ultra-{forward.ForwardId}-{Guid.NewGuid():N}");
            Directory.CreateDirectory(runtimeDirectory);
            SetPrivateDirectoryMode(runtimeDirectory);
            Directory.CreateDirectory(_socketRoot);
            var socketDirectory = Path.Combine(_socketRoot, $"lk-u-{Guid.NewGuid():N}");
            Directory.CreateDirectory(socketDirectory);
            SetPrivateDirectoryMode(socketDirectory);
            var socketPath = Path.Combine(socketDirectory, "c.sock");
            var statusPath = Path.Combine(runtimeDirectory, "status.json");
            var runtimeState = new RuntimeState(runtimeDirectory, socketDirectory, socketPath, statusPath);
            _runtimes[forward.ForwardId] = runtimeState;
            Log.Information("ultra runtime paths ready for forward {ForwardId}", forward.ForwardId);

            var autoSelect = leases.Count > 1;
            var udpCapable = string.Equals(leases[0].Transport, "udp", StringComparison.OrdinalIgnoreCase)
                || string.Equals(leases[0].Transport, "tcp+udp", StringComparison.OrdinalIgnoreCase);
            var bootstrapConfig = new Dictionary<string, object?>
            {
                ["forward_id"] = forward.ForwardId,
                ["listen_host"] = "127.0.0.1",
                ["listen_port"] = forward.ListenPort,
                ["target"] = forward.Target,
                ["control_socket"] = socketPath,
                ["transport"] = leases[0].Transport,
                ["policy_generation"] = leases[0].PolicyGeneration,
                ["target_digest"] = leases[0].TargetDigest,
                ["auto_select"] = autoSelect,
                ["max_tcp_connections"] = 512,
                ["udp_queue_packets"] = 256,
                ["connect_timeout_seconds"] = 5,
                ["status_path"] = statusPath,
                ["location_cache_path"] = Path.Combine(runtimeDirectory, "location.json"),
                ["location_lock_path"] = Path.Combine(runtimeDirectory, "location.lock"),
            };
            EgressInterfaceConfig.Apply(bootstrapConfig, _config.PreferredEgressInterface);
            if (udpCapable)
            {
                bootstrapConfig["udp_prewarm"] = true;
                bootstrapConfig["udp_keepalive_interval_seconds"] = 5;
            }
            if (autoSelect)
            {
                bootstrapConfig["probe_target"] = UltraProbeContract.Target;
                bootstrapConfig["probe_duration_seconds"] = UltraProbeContract.DurationSeconds;
                bootstrapConfig["probe_timeout_ms"] = UltraProbeContract.TimeoutMilliseconds;
                bootstrapConfig["probe_samples"] = UltraProbeContract.Samples;
                bootstrapConfig["probe_interval_ms"] = UltraProbeContract.IntervalMilliseconds;
                bootstrapConfig["probe_workers"] = UltraProbeContract.Workers;
            }
            // Bootstrap-only contract: the runtime reads external_uot from its
            // startup config, so a rotated token needs a runtime restart (the
            // lease-socket delivery cannot update it). The payload only exists
            // in memory and is zeroed in the finally block below.
            var externalUot = ExternalUotGrant.Select(leases);
            if (externalUot is not null)
                bootstrapConfig["external_uot"] = externalUot.ToBootstrapPayload();
            var firstFlight = forward.UltraAccess?.TCPFirstFlight?.ToBootstrapPayload(forward, leases);
            if (firstFlight is not null)
                bootstrapConfig["tcp_first_flight"] = firstFlight;
            phase = "spawn_runtime";
            bootstrap = JsonSerializer.SerializeToUtf8Bytes(bootstrapConfig);
            _supervisor.StartProcess(
                forward.ForwardId,
                "ultra-runtime",
                "Ultra direct runtime",
                ultraBinary,
                "--config-stdin",
                bootstrap,
                logArguments: false,
                workingDirectory: runtimeDirectory);
            Log.Information("ultra runtime process started for forward {ForwardId}", forward.ForwardId);
            phase = "wait_control_socket";
            var socketReady = WaitForSocket(socketPath, TimeSpan.FromSeconds(2));
            Log.Information("ultra control socket ready={SocketReady} for forward {ForwardId}", socketReady, forward.ForwardId);
            if (!socketReady)
                throw new InvalidOperationException("ultra_control_socket_not_ready");
            phase = "deliver_lease";
            Log.Information("ultra lease delivery begin for forward {ForwardId}", forward.ForwardId);
            using var deliveryTimeout = new CancellationTokenSource(LeaseDeliveryTimeout);
            var leaseDelivered = (leases.Count == 1
                ? _leaseSender(socketPath, forward.ForwardId, leases[0], deliveryTimeout.Token)
                : _leaseBatchSender(socketPath, forward.ForwardId, leases, deliveryTimeout.Token))
                .WaitAsync(deliveryTimeout.Token)
                .GetAwaiter()
                .GetResult();
            Log.Information("ultra lease delivery result={LeaseDelivered} for forward {ForwardId}", leaseDelivered, forward.ForwardId);
            if (!leaseDelivered)
                throw new InvalidOperationException("ultra_lease_delivery_failed");
            phase = "wait_listener";
            var portReady = WaitForPort(forward.ListenPort, _portListeningProbe, TimeSpan.FromSeconds(6));
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
            CryptographicOperations.ZeroMemory(bootstrap);
            DisposeLeases(leases);
        }
    }

    /// <summary>
    /// Payload ceiling the running runtime last published for a forward, or 0 while the
    /// association is unusable or has not been measured yet. The value lives in the status
    /// file handed to the helper as "status_path"; the Ultra forwards have no TUN, so this
    /// is the observation point rather than an MTU input.
    /// </summary>
    public int GetPayloadCeiling(int forwardId)
        => _runtimes.TryGetValue(forwardId, out var runtime)
            ? MacUltraRuntimeStatusReader.ReadPayloadCeiling(runtime.StatusPath)
            : 0;

    public bool Refresh(ForwardConfig forward, UltraLease lease)
        => Refresh(forward, new[] { lease });

    public bool Refresh(ForwardConfig forward, IReadOnlyList<UltraLease> leases)
    {
        if (leases is null || leases.Count is < 1 or > 4)
            return false;
        try
        {
            return _runtimes.TryGetValue(forward.ForwardId, out var runtime)
                && _socketExists(runtime.SocketPath)
                && (leases.Count == 1
                    ? _leaseSender(runtime.SocketPath, forward.ForwardId, leases[0], CancellationToken.None)
                    : _leaseBatchSender(runtime.SocketPath, forward.ForwardId, leases, CancellationToken.None)).GetAwaiter().GetResult();
        }
        catch
        {
            return false;
        }
        finally
        {
            DisposeLeases(leases);
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

    public void Stop(int forwardId)
    {
        _supervisor.StopAll(forwardId);
        if (_runtimes.TryRemove(forwardId, out var runtime))
        {
            DeletePrivateDirectory(runtime.Directory, Path.Combine(_config.GetEffectiveStateDir(), "runtime"), "ultra-");
            DeletePrivateDirectory(runtime.SocketDirectory, _socketRoot, "lk-u-");
        }
        CleanupRuntimeDirectories(forwardId);
    }

    private void CleanupRuntimeDirectories(int forwardId)
    {
        if (forwardId <= 0)
            return;

        var runtimeRoot = Path.GetFullPath(Path.Combine(_config.GetEffectiveStateDir(), "runtime"));
        if (!Directory.Exists(runtimeRoot))
            return;

        var prefix = $"ultra-{forwardId}-";
        try
        {
            foreach (var directory in Directory.EnumerateDirectories(runtimeRoot, prefix + "*", SearchOption.TopDirectoryOnly))
                DeletePrivateDirectory(directory, runtimeRoot, prefix);
        }
        catch (DirectoryNotFoundException)
        {
        }
        catch (IOException)
        {
        }
        catch (UnauthorizedAccessException)
        {
        }
    }

    private bool WaitForSocket(string socketPath, TimeSpan timeout)
    {
        var deadline = DateTime.UtcNow + timeout;
        while (DateTime.UtcNow < deadline)
        {
            if (_socketExists(socketPath))
                return true;
            Thread.Sleep(25);
        }
        return _socketExists(socketPath);
    }

    private bool WaitForPort(int port, Func<int, bool> probe, TimeSpan timeout)
    {
        var deadline = DateTime.UtcNow + timeout;
        while (DateTime.UtcNow < deadline)
        {
            if (probe(port))
                return true;
            Thread.Sleep(25);
        }
        return probe(port);
    }

    private static void DeletePrivateDirectory(string directory, string allowedRoot, string requiredPrefix)
    {
        var root = Path.GetFullPath(allowedRoot);
        var resolved = Path.GetFullPath(directory);
        var rootPrefix = root.TrimEnd(Path.DirectorySeparatorChar, Path.AltDirectorySeparatorChar)
            + Path.DirectorySeparatorChar;
        if (!resolved.StartsWith(rootPrefix, StringComparison.Ordinal)
            || !Path.GetFileName(resolved).StartsWith(requiredPrefix, StringComparison.Ordinal))
            return;
        try
        {
            if (Directory.Exists(resolved))
                Directory.Delete(resolved, recursive: true);
        }
        catch (IOException)
        {
        }
        catch (UnauthorizedAccessException)
        {
        }
    }

    private static void SetPrivateDirectoryMode(string path)
    {
        if (!OperatingSystem.IsWindows())
            File.SetUnixFileMode(path, UnixFileMode.UserRead | UnixFileMode.UserWrite | UnixFileMode.UserExecute);
    }
}
