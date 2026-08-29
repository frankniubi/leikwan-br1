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
    private readonly Func<int, PortPreflightResult>? _portPreflight;

    public WindowsUltraRuntimeHost(
        AgentConfig config,
        IProcessSupervisor supervisor,
        StateManager stateManager,
        Func<string, bool>? binaryExists = null,
        Func<int, bool>? portListeningProbe = null,
        Func<int, UltraLease, CancellationToken, Task<bool>>? leaseSender = null,
        Func<int, PortPreflightResult>? portPreflight = null,
        Func<int, IReadOnlyList<UltraLease>, CancellationToken, Task<bool>>? leaseBatchSender = null)
    {
        _config = config;
        _supervisor = supervisor;
        _stateManager = stateManager;
        _binaryExists = binaryExists ?? File.Exists;
        _portListeningProbe = portListeningProbe ?? NetworkProbe.IsPortListening;
        _leaseSender = leaseSender ?? UltraLeaseClient.SendAsync;
        _leaseBatchSender = leaseBatchSender ?? UltraLeaseClient.SendAsync;
        _portPreflight = portPreflight;
    }

    public void Start(ForwardConfig forward, UltraLease lease, string contractSignature, string applyReason)
        => Start(forward, new[] { lease }, contractSignature, applyReason);

    public void Start(ForwardConfig forward, IReadOnlyList<UltraLease> leases, string contractSignature, string applyReason)
    {
        if (leases is null || leases.Count is < 1 or > 4)
            throw new ArgumentException("Ultra lease candidates are required", nameof(leases));
        try
        {
            if (forward.ListenPort is < 1 or > 65535)
                throw new InvalidOperationException("ultra_listen_port_invalid");
            var ultraBin = _config.GetEffectiveUltraBin();
            if (!_binaryExists(ultraBin))
                throw new InvalidOperationException("ultra_runtime_unavailable");
            if (_portPreflight is not null)
            {
                var preflight = _portPreflight(forward.ListenPort);
                if (preflight.Blocked)
                    throw new InvalidOperationException("ultra_port_conflict");
                if (preflight.QuiescedPids.Count > 0)
                {
                    Log.Information("Ultra forward {ForwardId}: quiesced managed-old owners {Pids}",
                        forward.ForwardId, preflight.QuiescedPids);
                }
            }

            Stop(forward.ForwardId);
            var runtimeDirectory = Path.Combine(_config.GetEffectiveStateDir(), "runtime");
            var configPath = Path.Combine(runtimeDirectory, $"ultra-forward-{forward.ForwardId}.json");
            var statusPath = Path.Combine(runtimeDirectory, $"ultra-forward-{forward.ForwardId}.status.json");
            var staticConfig = new
            {
                forward_id = forward.ForwardId,
                listen_host = "127.0.0.1",
                listen_port = forward.ListenPort,
                target = forward.Target,
                control_socket = $"\\\\.\\pipe\\{UltraLeaseClient.PipeName(forward.ForwardId)}",
                transport = leases[0].Transport,
                policy_generation = leases[0].PolicyGeneration,
                target_digest = leases[0].TargetDigest,
                auto_select = leases.Count > 1,
                probe_timeout_ms = 300,
                probe_samples = 20,
                probe_interval_ms = 100,
                max_tcp_connections = 512,
                udp_queue_packets = 64,
                connect_timeout_seconds = 5,
                status_path = statusPath,
            };
            Directory.CreateDirectory(runtimeDirectory);
            File.WriteAllText(configPath, JsonSerializer.Serialize(staticConfig, new JsonSerializerOptions { WriteIndented = true }));
            _supervisor.StartProcess(
                forward.ForwardId,
                "ultra-runtime",
                "Ultra direct runtime",
                ultraBin,
                $"--config \"{configPath}\"");
            var delivered = leases.Count == 1
                ? _leaseSender(forward.ForwardId, leases[0], CancellationToken.None).GetAwaiter().GetResult()
                : _leaseBatchSender(forward.ForwardId, leases, CancellationToken.None).GetAwaiter().GetResult();
            if (!delivered)
                throw new InvalidOperationException("ultra_lease_delivery_failed");
            if (!_portListeningProbe(forward.ListenPort))
                throw new InvalidOperationException("ultra_listener_not_ready");

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
        catch
        {
            Stop(forward.ForwardId);
            throw;
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
        CleanupRuntimeFiles(forwardId);
    }

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
