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
    private readonly Func<int, PortPreflightResult>? _portPreflight;

    public WindowsUltraRuntimeHost(
        AgentConfig config,
        IProcessSupervisor supervisor,
        StateManager stateManager,
        Func<string, bool>? binaryExists = null,
        Func<int, bool>? portListeningProbe = null,
        Func<int, UltraLease, CancellationToken, Task<bool>>? leaseSender = null,
        Func<int, PortPreflightResult>? portPreflight = null)
    {
        _config = config;
        _supervisor = supervisor;
        _stateManager = stateManager;
        _binaryExists = binaryExists ?? File.Exists;
        _portListeningProbe = portListeningProbe ?? NetworkProbe.IsPortListening;
        _leaseSender = leaseSender ?? UltraLeaseClient.SendAsync;
        _portPreflight = portPreflight;
    }

    public void Start(ForwardConfig forward, UltraLease lease, string contractSignature, string applyReason)
    {
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
                transport = lease.Transport,
                policy_generation = lease.PolicyGeneration,
                target_digest = lease.TargetDigest,
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
            if (!_leaseSender(forward.ForwardId, lease, CancellationToken.None).GetAwaiter().GetResult())
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
            lease.Dispose();
        }
    }

    public bool Refresh(ForwardConfig forward, UltraLease lease)
    {
        try
        {
            return _leaseSender(forward.ForwardId, lease, CancellationToken.None).GetAwaiter().GetResult();
        }
        finally
        {
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
