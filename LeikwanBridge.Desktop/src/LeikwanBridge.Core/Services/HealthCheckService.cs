using LeikwanBridge.Core.Helpers;
using LeikwanBridge.Core.Models;
using LeikwanBridge.Core.Services.Platform;
using Serilog;
using System.Diagnostics;
using System.Net;
using System.Net.Http;
using System.Net.Sockets;

namespace LeikwanBridge.Core.Services;

/// <summary>
/// Periodic health checks for running forwards.
/// Port of client_agent.py:ensure_runtime_health().
/// </summary>
public sealed class HealthCheckService : IDisposable
{
    private readonly IProcessSupervisor _supervisor;
    private readonly ForwardRuntimeManager _runtimeManager;
    private readonly StateManager _stateManager;
    private readonly AgentConfig _config;
    private readonly Func<int, bool> _portListeningProbe;
    private readonly Func<ForwardConfig, EndpointConfig, int, (bool ok, double? latencyMs, string? error)> _bondLegProbe;
    private readonly Func<string, int, bool> _relayHealthProbe;
    private readonly BondEndpointHealthService _bondEndpointHealth;
    private readonly Dictionary<int, DateTime> _restartCooldown = [];
    private readonly Dictionary<int, ForwardConfig> _forwards = [];
    private readonly HashSet<int> _restartInProgress = [];
    private readonly HashSet<int> _nativeOutboundNoticed = [];

    private CancellationTokenSource? _cts;
    private Task? _checkTask;
    private int _stopRequested;
    private bool _disposed;

    public event Action<int, string>? HealthIssue;

    public HealthCheckService(
        IProcessSupervisor supervisor,
        ForwardRuntimeManager runtimeManager,
        StateManager stateManager,
        Func<int, bool>? portListeningProbe = null,
        Func<ForwardConfig, EndpointConfig, int, (bool ok, double? latencyMs, string? error)>? bondLegProbe = null,
        AgentConfig? config = null,
        Func<string, int, bool>? relayHealthProbe = null)
    {
        _supervisor = supervisor;
        _runtimeManager = runtimeManager;
        _stateManager = stateManager;
        _config = config ?? new AgentConfig();
        _portListeningProbe = portListeningProbe ?? NetworkProbe.IsPortListening;
        _bondLegProbe = bondLegProbe ?? RunDefaultBondOneLegProbe;
        _relayHealthProbe = relayHealthProbe ?? ProbeRelayHealth;
        _bondEndpointHealth = new BondEndpointHealthService(stateManager);
        _supervisor.ProcessExited += HandleProcessExited;
    }

    public void Start(List<ForwardConfig> forwards, int intervalSeconds = 20)
    {
        if (_disposed)
            throw new ObjectDisposedException(nameof(HealthCheckService));

        UpdateForwards(forwards);
        Volatile.Write(ref _stopRequested, 0);
        _cts?.Cancel();
        _cts = new CancellationTokenSource();
        var ct = _cts.Token;

        _checkTask = Task.Run(async () =>
        {
            while (!ct.IsCancellationRequested)
            {
                try { await Task.Delay(TimeSpan.FromSeconds(intervalSeconds), ct); }
                catch (OperationCanceledException) { break; }

                try { await CheckAllAsync(_forwards.Values.ToList(), ct); }
                catch (OperationCanceledException) { break; }
                catch (Exception ex) { Log.Error(ex, "health check cycle failed"); }
            }
        }, ct);
    }

    public void UpdateForwards(List<ForwardConfig> forwards)
    {
        _forwards.Clear();
        foreach (var forward in forwards)
            _forwards[forward.ForwardId] = forward;
    }

    public void Stop()
    {
        Volatile.Write(ref _stopRequested, 1);
        _cts?.Cancel();
    }

    public async Task StopAsync()
    {
        Stop();
        var checkTask = _checkTask;
        if (checkTask is null)
            return;
        try
        {
            await checkTask;
        }
        catch (OperationCanceledException)
        {
        }
    }

    public void Dispose()
    {
        if (_disposed)
            return;

        _disposed = true;
        Stop();
        _supervisor.ProcessExited -= HandleProcessExited;
    }

    public Task RunCheckOnceAsync(List<ForwardConfig> forwards, CancellationToken ct = default)
    {
        UpdateForwards(forwards);
        return CheckAllAsync(forwards, ct);
    }

    private Task CheckAllAsync(List<ForwardConfig> forwards, CancellationToken ct)
    {
        foreach (var forward in forwards)
        {
            if (forward.ServiceStatus != "active") continue;
            var fid = forward.ForwardId;

            // A native outbound rule is panel-declared configuration only: its data
            // plane belongs to a user-initiated native outbound relay connection.
            // There is no agent-managed process to probe, restart, or record, so
            // skip before touching supervisor or applied state. This keeps the
            // periodic cycle both silent and side-effect free.
            if (ForwardRuntimeManager.IsNativeOutboundForward(forward))
            {
                NoteNativeOutboundRule(fid);
                continue;
            }

            if (_runtimeManager.IsForwardStarting(fid)) continue;
            var applied = _stateManager.LoadApplied();
            if (applied.TryGetValue(fid.ToString(), out var appliedState) && appliedState.UserStopped)
                continue;

            if (!_supervisor.IsRunning(fid))
            {
                if (ShouldRestart(fid))
                {
                    Log.Warning("forward {ForwardId}: processes not running, restarting", fid);
                    RequestRestart(forward, "process_dead", force: true);
                }
                continue;
            }

            // Local listener probe
            int port = forward.ListenPort;
            if (port > 0 && !_portListeningProbe(port))
            {
                Log.Warning("forward {ForwardId}: local port {Port} not listening", fid, port);
                if (ShouldRestart(fid))
                {
                    RequestRestart(forward, "port_not_listening", force: true);
                }

                continue;
            }

            if (RequiresBondTopologyRecovery(fid))
            {
                if (ShouldRestart(fid))
                {
                    Log.Warning("forward {ForwardId}: bond runtime topology is degraded, rebuilding", fid);
                    RequestRestart(forward, "bond_topology_degraded", force: true);
                }
            }

            if (BondEndpointHealthService.IsPlainTcpBond(forward))
            {
                CheckBondEndpointHealth(forward);
            }

            if (IsMptcpPreconnectRelayUnhealthy(forward))
            {
                Log.Warning("forward {ForwardId}: preconnect relay health check failed", fid);
                if (ShouldRestart(fid))
                    RequestRestart(forward, "preconnect_relay_unhealthy", force: true);
            }
        }

        return Task.CompletedTask;
    }

    private void CheckBondEndpointHealth(ForwardConfig forward)
    {
        var forwardId = forward.ForwardId;
        var changed = false;
        var anyReady = false;
        for (var index = 0; index < forward.Endpoints.Count; index++)
        {
            var endpoint = forward.Endpoints[index];
            if (endpoint.EndpointId <= 0)
                continue;

            var previousRole = CurrentEndpointRole(forwardId, endpoint.EndpointId);
            var (ok, latencyMs, error) = _bondLegProbe(forward, endpoint, index);
            BondEndpointHealthEntry updated;
            if (ok)
            {
                anyReady = true;
                updated = _bondEndpointHealth.RecordProbeResult(
                    forwardId,
                    endpoint.EndpointId,
                    ok: true,
                    latencyMs,
                    error: null);
            }
            else
            {
                updated = _bondEndpointHealth.ForceQuarantine(
                    forwardId,
                    endpoint.EndpointId,
                    error ?? "bond_leg_probe_failed",
                    latencyMs);
            }

            if (!string.Equals(previousRole, updated.Role, StringComparison.OrdinalIgnoreCase))
                changed = true;
        }

        if (!anyReady)
        {
            if (ShouldRestart(forwardId))
                RequestRestart(forward, "all_bond_legs_failed_probe", force: true);
            return;
        }

        if (changed && ShouldRestart(forwardId))
        {
            RequestRestart(forward, "bond_endpoint_health_changed", force: true);
        }
    }

    private string CurrentEndpointRole(int forwardId, int endpointId)
    {
        var health = _stateManager.LoadBondEndpointHealth();
        if (health.TryGetValue(forwardId.ToString(), out var scoped)
            && scoped.TryGetValue(endpointId.ToString(), out var entry)
            && !string.IsNullOrWhiteSpace(entry.Role))
        {
            return entry.Role;
        }

        return "healthy";
    }

    private (bool ok, double? latencyMs, string? error) RunDefaultBondOneLegProbe(
        ForwardConfig forward,
        EndpointConfig endpoint,
        int endpointIndex)
    {
        var forwardId = forward.ForwardId;
        var token = forward.Bonding?.SessionToken ?? "";
        if (forwardId <= 0 || endpoint.EndpointId <= 0 || string.IsNullOrWhiteSpace(token))
            return (false, null, "bond_probe_missing_forward_fields");

        var bondBin = _config.GetEffectiveBondBin();
        if (!File.Exists(bondBin))
            return (false, null, $"bond_binary_missing:{bondBin}");

        var probePort = AllocateLoopbackPort();
        var backPort = BondRuntimeBuilderBackPort(forwardId, endpointIndex);
        var wsPriority = forward.Bonding?.WsPriority > 0 ? forward.Bonding.WsPriority : 10;
        var legSpec =
            $"tcp://127.0.0.1:{backPort}?priority={wsPriority}&endpoint_id={endpoint.EndpointId}&display_host={Uri.EscapeDataString(endpoint.Host)}";
        var bonding = forward.Bonding ?? new BondingConfig();
        var args =
            $"client --protocol tcp --listen 127.0.0.1:{probePort} " +
            $"--legs \"{legSpec}\" --session-token \"{token}\" " +
            $"--chunk-size {bonding.ChunkSize} " +
            $"--max-window-bytes {bonding.MaxWindowBytes} " +
            $"--retransmit-ms {bonding.RetransmitMs} " +
            $"--connect-timeout-ms {bonding.ConnectTimeoutMs} " +
            $"--join-timeout-ms {bonding.JoinTimeoutMs} " +
            $"--interactive-chunk-bytes {bonding.InteractiveChunkBytes} " +
            $"--latency-guard-bytes {bonding.LatencyGuardBytes} " +
            $"--interactive-rtt-slack-ms {bonding.InteractiveRttSlackMs} " +
            $"--warmup-trigger-bytes {bonding.WarmupTriggerBytes} " +
            $"--warmup-slice-bytes {bonding.WarmupSliceBytes} " +
            $"--warmup-leg-delay-ms {bonding.WarmupLegDelayMs} " +
            $"--bulk-promote-bytes {bonding.BulkPromoteBytes} " +
            $"--leg-cooldown-ms {bonding.LegCooldownMs}";
        if (string.Equals(bonding.PolicyMode, "latency_first", StringComparison.OrdinalIgnoreCase))
            args += " --latency-first";

        using var process = new Process
        {
            StartInfo = new ProcessStartInfo
            {
                FileName = bondBin,
                Arguments = args,
                UseShellExecute = false,
                CreateNoWindow = true,
                RedirectStandardError = true,
                RedirectStandardOutput = true,
                WorkingDirectory = Path.GetDirectoryName(bondBin) ?? "",
            },
        };

        var startedAt = DateTime.UtcNow;
        try
        {
            process.Start();
            var deadline = DateTime.UtcNow + TimeSpan.FromSeconds(3);
            var lastError = "bond_leg_probe_timeout";
            while (DateTime.UtcNow < deadline)
            {
                if (process.HasExited)
                    return (false, null, ReadProcessError(process));

                try
                {
                    using var client = new TcpClient();
                    var task = client.ConnectAsync(IPAddress.Loopback, probePort);
                    if (task.Wait(TimeSpan.FromMilliseconds(300)) && client.Connected)
                    {
                        return (true, (DateTime.UtcNow - startedAt).TotalMilliseconds, null);
                    }
                }
                catch (Exception ex)
                {
                    lastError = ex.Message;
                }

                Thread.Sleep(50);
            }

            return (false, null, lastError);
        }
        catch (Exception ex)
        {
            return (false, null, ex.Message);
        }
        finally
        {
            try
            {
                if (!process.HasExited)
                {
                    process.Kill(entireProcessTree: true);
                    process.WaitForExit(1000);
                }
            }
            catch
            {
                // best-effort probe cleanup
            }
        }
    }

    private static int AllocateLoopbackPort()
    {
        var listener = new TcpListener(IPAddress.Loopback, 0);
        listener.Start();
        var port = ((IPEndPoint)listener.LocalEndpoint).Port;
        listener.Stop();
        return port;
    }

    private static int BondRuntimeBuilderBackPort(int forwardId, int endpointIndex)
    {
        return 20000 + ((forwardId * 37) % 15000) + Math.Max(0, endpointIndex);
    }

    private static string ReadProcessError(Process process)
    {
        try
        {
            var stderr = process.StandardError.ReadToEnd();
            var lines = stderr.Split(["\r\n", "\n"], StringSplitOptions.RemoveEmptyEntries);
            return lines.Length > 0 ? lines[^1].Trim() : $"bond_probe_exit_{process.ExitCode}";
        }
        catch
        {
            return "bond_probe_exited";
        }
    }

    /// <summary>
    /// Records the native outbound rule once per process so the skip is auditable
    /// without repeating on every health cycle.
    /// </summary>
    private void NoteNativeOutboundRule(int forwardId)
    {
        lock (_nativeOutboundNoticed)
        {
            if (!_nativeOutboundNoticed.Add(forwardId))
                return;
        }

        Log.Information(
            "native outbound rule forward {ForwardId}: agent-managed runtime not applicable",
            forwardId);
    }

    private bool ShouldRestart(int forwardId)
    {
        if (!_restartCooldown.TryGetValue(forwardId, out var lastRestart))
            return true;
        return (DateTime.UtcNow - lastRestart).TotalSeconds >= 30;
    }

    private bool IsMptcpPreconnectRelayUnhealthy(ForwardConfig forward)
    {
        if (!string.Equals(ModeNormalizer.Normalize(forward.Mode), "mptcp", StringComparison.OrdinalIgnoreCase))
            return false;

        var mptcp = forward.Mptcp;
        if (mptcp == null)
            return false;

        var host = (mptcp.SlaveRelayHost ?? string.Empty).Trim();
        if (string.IsNullOrWhiteSpace(host))
            return false;

        var underlays = new List<UnderlayConfig>();
        if (mptcp.Underlay != null)
            underlays.Add(mptcp.Underlay);
        underlays.AddRange(mptcp.Underlays);

        var preconnectLegs = underlays
            .SelectMany(underlay => underlay.Legs)
            .Where(leg => leg.SlavePreconnect && leg.PreconnectRelayPort > 0)
            .ToList();

        var selectedLegRelayPort = preconnectLegs
            .FirstOrDefault(leg => string.Equals((leg.HealthStatus ?? string.Empty).Trim(), "active", StringComparison.OrdinalIgnoreCase))
            ?.PreconnectRelayPort
            ?? preconnectLegs.FirstOrDefault()?.PreconnectRelayPort;

        var relayPort = selectedLegRelayPort.GetValueOrDefault() > 0
            ? selectedLegRelayPort!.Value
            : mptcp.SlaveRelayPort;

        if (relayPort <= 0)
            return false;

        return !_relayHealthProbe(host, relayPort);
    }

    private static bool ProbeRelayHealth(string host, int relayPort)
    {
        try
        {
            // Data-plane relay probe: keep OS default routing (so it still
            // works through a TUN) but never route through an HTTP proxy.
            using var http = LeikwanHttpClientFactory.CreatePlain();
            http.Timeout = TimeSpan.FromSeconds(2);
            using var response = http.GetAsync($"http://{host}:{relayPort + 1}/health").GetAwaiter().GetResult();
            return response.IsSuccessStatusCode;
        }
        catch
        {
            return false;
        }
    }

    private bool RequiresBondTopologyRecovery(int forwardId)
    {
        var applied = _stateManager.LoadApplied();
        if (!applied.TryGetValue(forwardId.ToString(), out var state))
            return false;

        if (state.UserStopped)
            return false;

        if (!string.Equals(state.Mode, "bond", StringComparison.OrdinalIgnoreCase))
            return false;

        if (state.LegPorts.Any(port => !_portListeningProbe(port)))
            return true;

        var runningRoles = _supervisor.GetRunningRoles(forwardId);
        return state.ProcessRoles.Any(role => !runningRoles.Contains(role, StringComparer.Ordinal));
    }

    private void HandleProcessExited(int forwardId, string role)
    {
        if (Volatile.Read(ref _stopRequested) != 0 || _disposed)
            return;

        if (!_forwards.TryGetValue(forwardId, out var forward))
            return;

        // Native outbound rules never own agent-managed processes.
        if (ForwardRuntimeManager.IsNativeOutboundForward(forward))
            return;

        var applied = _stateManager.LoadApplied();
        if (!applied.TryGetValue(forwardId.ToString(), out var state))
            return;

        if (state.UserStopped)
            return;

        if (!state.ProcessRoles.Contains(role, StringComparer.Ordinal))
            return;

        if (string.Equals(forward.BackendMode, "ultra", StringComparison.OrdinalIgnoreCase))
        {
            // Ultra restarts require a freshly issued one-shot lease. Config polling
            // owns that exchange and will reconcile the stopped runtime safely.
            Log.Warning("forward {ForwardId}: Ultra runtime role exited [{Role}], waiting for lease-aware config sync", forwardId, role);
            return;
        }

        if (!ShouldRestart(forwardId))
            return;

        Log.Warning("forward {ForwardId}: critical runtime role exited [{Role}], rebuilding", forwardId, role);
        RequestRestart(forward, $"critical_process_exit:{role}", force: true);
    }

    private void RequestRestart(ForwardConfig forward, string issue, bool force = false)
    {
        var forwardId = forward.ForwardId;
        if (_restartInProgress.Contains(forwardId))
            return;

        _restartInProgress.Add(forwardId);
        _restartCooldown[forwardId] = DateTime.UtcNow;

        try
        {
            HealthIssue?.Invoke(forwardId, issue);
            _runtimeManager.RestartForward(forward, force, issue);
        }
        finally
        {
            _restartInProgress.Remove(forwardId);
        }
    }
}
