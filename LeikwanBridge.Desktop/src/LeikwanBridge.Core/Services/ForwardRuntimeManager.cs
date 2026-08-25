using System.Diagnostics;
using System.Text.Json;
using LeikwanBridge.Core.Helpers;
using LeikwanBridge.Core.Models;
using Serilog;

namespace LeikwanBridge.Core.Services;

/// <summary>
/// Manages forward process lifecycles: apply (start), stop, restart.
/// </summary>
public class ForwardRuntimeManager : IDisposable
{
    private readonly AgentConfig _config;
    private readonly ProcessSupervisor _supervisor;
    private readonly BondRuntimeBuilder _bondBuilder;
    private readonly BondEndpointHealthService _bondEndpointHealth;
    private readonly MptcpRuntimeBuilder _mptcpBuilder;
    private readonly StateManager _stateManager;
    private readonly Func<string, bool> _binaryExists;
    private readonly Func<int, bool> _portListeningProbe;
    private readonly Func<int, UltraLease, CancellationToken, Task<bool>> _ultraLeaseSender;

    public ForwardRuntimeManager(
        AgentConfig config,
        ProcessSupervisor supervisor,
        StateManager stateManager,
        Func<string, bool>? binaryExists = null,
        Func<int, bool>? portListeningProbe = null,
        Func<int, UltraLease, CancellationToken, Task<bool>>? ultraLeaseSender = null)
    {
        _config = config;
        _supervisor = supervisor;
        _bondBuilder = new BondRuntimeBuilder(config);
        _mptcpBuilder = new MptcpRuntimeBuilder(config);
        _bondEndpointHealth = new BondEndpointHealthService(stateManager);
        _stateManager = stateManager;
        _binaryExists = binaryExists ?? File.Exists;
        _portListeningProbe = portListeningProbe ?? NetworkProbe.IsPortListening;
        _ultraLeaseSender = ultraLeaseSender ?? UltraLeaseClient.SendAsync;
    }

    public virtual void ApplyForward(ForwardConfig forward)
    {
        ApplyForward(forward, contractSignature: null, applyReason: "manual_apply");
    }

    public virtual void ApplyForward(ForwardConfig forward, string? contractSignature, string applyReason)
    {
        var applied = _stateManager.LoadApplied();
        applied.TryGetValue(forward.ForwardId.ToString(), out var previousState);
        ApplyForwardCore(forward, previousState, contractSignature, applyReason);
    }

    public virtual void ApplyAuthoritativeAgentConfig(JsonElement? agentConfigJson)
    {
        _config.ApplyAuthoritativeConfig(agentConfigJson);
    }

    public virtual string ComputeContractSignature(ForwardConfig forward)
    {
        var sidepath = _config.UdpUotSidepath;
        if (sidepath == null || !sidepath.EnabledForwardIds.Contains(forward.ForwardId))
            return ForwardContractSignature.Compute(forward);

        var uotIdentity = UotRuntimeCapabilityProbe.BuildIdentity(_config.GetEffectiveUotBondBin());
        return ForwardContractSignature.Compute(forward, sidepath, uotIdentity);
    }

    private void ApplyForwardCore(ForwardConfig forward, AppliedForwardState? previousState, string? contractSignature, string applyReason)
    {
        if (string.Equals(forward.BackendMode, "ultra", StringComparison.OrdinalIgnoreCase))
        {
            ApplyUltraForward(forward, previousState, contractSignature, applyReason);
            return;
        }
        var selectedForward = forward;
        var forwardId = selectedForward.ForwardId;
        var mode = ModeNormalizer.Normalize(selectedForward.Mode);
        if (mode == "mptcp" && !HasUsableMptcpRelay(selectedForward))
        {
            selectedForward = MptcpFallbackService.ConvertToBond(selectedForward);
            mode = ModeNormalizer.Normalize(selectedForward.Mode);
        }
        var frontendMode = WindowsForwardPolicy.GetSidecarFrontendMode(selectedForward);


        Log.Information("applying forward {ForwardId} mode={Mode} transport={Transport} endpoints={Count}",
            forwardId, mode, selectedForward.Transport, selectedForward.Endpoints.Count);

        BondRuntimeBuilder.RuntimeSpec spec;
        try
        {
            spec = frontendMode != ""
                ? _bondBuilder.BuildFrontend(WithSelectedBondRuntimeEndpoints(selectedForward))
                : mode switch
                {
                    "bond" => _bondBuilder.Build(WithSelectedBondRuntimeEndpoints(selectedForward)),
                    "race" => _bondBuilder.BuildRace(selectedForward),
                    "mptcp" => _mptcpBuilder.Build(selectedForward),
                    _ => _bondBuilder.BuildSingle(selectedForward),
                };
            if (RequiresFeedbackScheduler(selectedForward))
                UotRuntimeCapabilityProbe.RequireFeedbackCapability(_config.GetEffectiveUotBondBin());
        }
        catch (Exception ex)
        {
            Log.Error(ex, "failed to validate runtime spec for forward {ForwardId}; existing runtime preserved", forwardId);
            return;
        }

        // Validation above is intentionally complete before replacing the running runtime.
        _supervisor.StopAll(forwardId);
        WaitForPortsReleased(PortsForReleaseWait(previousState, selectedForward.ListenPort), TimeSpan.FromSeconds(3));

        foreach (var file in spec.GeneratedFiles ?? [])
        {
            var directory = Path.GetDirectoryName(file.FilePath);
            if (!string.IsNullOrWhiteSpace(directory))
                Directory.CreateDirectory(directory);
            File.WriteAllText(file.FilePath, file.Content);
        }

        foreach (var process in spec.Processes.Where(p => p.RequiredForReadiness))
        {
            if (!_binaryExists(process.ExePath))
            {
                Log.Error("binary not found: {ExePath} for forward {ForwardId} [{Label}]", process.ExePath, forwardId, process.Label);
                continue;
            }
            _supervisor.StartProcess(forwardId, process.Role, process.Label, process.ExePath, process.Arguments);
        }

        if (spec.LegPorts.Count > 0 && !WaitForRequiredPorts(spec.LegPorts, TimeSpan.FromSeconds(15)))
        {
            Log.Error("forward {ForwardId}: required leg ports did not become ready", forwardId);
            _supervisor.StopAll(forwardId);
            return;
        }

        foreach (var process in spec.Processes.Where(p => !p.RequiredForReadiness))
        {
            if (!_binaryExists(process.ExePath))
            {
                Log.Error("binary not found: {ExePath} for forward {ForwardId} [{Label}]", process.ExePath, forwardId, process.Label);
                continue;
            }
            _supervisor.StartProcess(forwardId, process.Role, process.Label, process.ExePath, process.Arguments);
        }

        // Save applied state
        var applied = _stateManager.LoadApplied();
        applied[forwardId.ToString()] = new AppliedForwardState
        {
            ForwardId = forwardId,
            Mode = mode,
            ListenPort = selectedForward.ListenPort,
            IngressMode = spec.IngressMode,
            EndpointCount = selectedForward.Endpoints.Count,
            LegPorts = [.. spec.LegPorts],
            ProcessRoles = [.. spec.Processes.Select(process => process.Role)],
            ProcessLabels = [.. spec.Processes.Select(process => process.Label)],
            ProcessIds = [.. _supervisor.GetProcesses(forwardId)
                .Select(process => TryGetProcessId(process.Process))
                .Where(pid => pid > 0)],
            ContractSignature = contractSignature ?? previousState?.ContractSignature ?? string.Empty,
            LastApplyReason = string.IsNullOrWhiteSpace(applyReason)
                ? previousState?.LastApplyReason ?? "config_sync"
                : applyReason,
            UserStopped = false,
            UpdatedAt = DateTimeOffset.UtcNow.ToUnixTimeSeconds(),
        };
        _stateManager.SaveApplied(applied);
    }

    private void ApplyUltraForward(ForwardConfig forward, AppliedForwardState? previousState, string? contractSignature, string applyReason)
    {
        var policy = forward.UltraAccess;
        var lease = forward.RuntimeLease;
        var now = DateTimeOffset.UtcNow;
        if (policy is null || lease is null || !policy.IsUsable(forward, now) || !lease.IsUsable(policy, forward, now))
        {
            Log.Warning("Ultra policy or lease rejected for forward {ForwardId}; stopping without legacy fallback", forward.ForwardId);
            lease?.Dispose();
            forward.RuntimeLease = null;
            StopForwardForPolicy(forward.ForwardId);
            return;
        }
        if (forward.ListenPort is < 1 or > 65535)
            throw new InvalidOperationException("Ultra listen port invalid");
        var configPath = Path.Combine(_config.GetEffectiveStateDir(), "runtime", $"ultra-forward-{forward.ForwardId}.json");
        var statusPath = Path.Combine(_config.GetEffectiveStateDir(), "runtime", $"ultra-forward-{forward.ForwardId}.status.json");
        var controlPipe = UltraLeaseClient.PipeName(forward.ForwardId);
        var staticConfig = new
        {
            forward_id = forward.ForwardId,
            listen_host = "127.0.0.1",
            listen_port = forward.ListenPort,
            target = forward.Target,
            control_socket = $"\\\\.\\pipe\\{controlPipe}",
            transport = policy.Transport,
            policy_generation = policy.PolicyGeneration,
            target_digest = policy.TargetDigest,
            max_tcp_connections = 512,
            udp_queue_packets = 64,
            connect_timeout_seconds = 5,
            status_path = statusPath,
        };
        Directory.CreateDirectory(Path.GetDirectoryName(configPath)!);
        File.WriteAllText(configPath, JsonSerializer.Serialize(staticConfig, new JsonSerializerOptions { WriteIndented = true }));
        _supervisor.StopAll(forward.ForwardId);
        var ultraBin = _config.GetEffectiveUltraBin();
        if (!_binaryExists(ultraBin))
        {
            Log.Error("Ultra helper not found for forward {ForwardId}: {Path}", forward.ForwardId, ultraBin);
            lease.Dispose();
            forward.RuntimeLease = null;
            CleanupUltraRuntimeFiles(forward.ForwardId);
            return;
        }
        _supervisor.StartProcess(forward.ForwardId, "ultra-runtime", "Ultra direct runtime", ultraBin, $"--config \"{configPath}\"");
        if (!_ultraLeaseSender(forward.ForwardId, lease, CancellationToken.None).GetAwaiter().GetResult())
        {
            _supervisor.StopAll(forward.ForwardId);
            CleanupUltraRuntimeFiles(forward.ForwardId);
            lease.Dispose();
            forward.RuntimeLease = null;
            throw new InvalidOperationException("Ultra lease delivery failed");
        }
        if (!_portListeningProbe(forward.ListenPort))
        {
            _supervisor.StopAll(forward.ForwardId);
            CleanupUltraRuntimeFiles(forward.ForwardId);
            lease.Dispose();
            forward.RuntimeLease = null;
            throw new InvalidOperationException("Ultra runtime listener not ready");
        }
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
            ProcessIds = [.. _supervisor.GetProcesses(forward.ForwardId).Select(p => TryGetProcessId(p.Process)).Where(pid => pid > 0)],
            ContractSignature = contractSignature ?? previousState?.ContractSignature ?? string.Empty,
            LastApplyReason = string.IsNullOrWhiteSpace(applyReason) ? "config_sync" : applyReason,
            UserStopped = false,
            UpdatedAt = DateTimeOffset.UtcNow.ToUnixTimeSeconds(),
        };
        _stateManager.SaveApplied(applied);
        lease.Dispose();
        forward.RuntimeLease = null;
    }

    public virtual void StopForward(int forwardId)
    {
        Log.Information("stopping forward {ForwardId}", forwardId);
        _supervisor.StopAll(forwardId);
        CleanupUltraRuntimeFiles(forwardId);

        var applied = _stateManager.LoadApplied();
        if (!applied.TryGetValue(forwardId.ToString(), out var state))
        {
            state = new AppliedForwardState
            {
                ForwardId = forwardId,
            };
        }

        state.ProcessIds = [];
        state.UserStopped = true;
        state.UpdatedAt = DateTimeOffset.UtcNow.ToUnixTimeSeconds();
        applied[forwardId.ToString()] = state;
        _stateManager.SaveApplied(applied);
    }

    public virtual void StopForwardForPolicy(int forwardId)
    {
        StopForward(forwardId);
        var applied = _stateManager.LoadApplied();
        if (!applied.TryGetValue(forwardId.ToString(), out var state))
            return;
        state.UserStopped = false;
        state.UpdatedAt = DateTimeOffset.UtcNow.ToUnixTimeSeconds();
        applied[forwardId.ToString()] = state;
        _stateManager.SaveApplied(applied);
    }

    public virtual void RestartForward(ForwardConfig forward)
    {
        RestartForward(forward, force: true, reason: "manual_apply");
    }

    public virtual void RestartForward(ForwardConfig forward, bool force, string reason)
    {
        var applied = _stateManager.LoadApplied();
        applied.TryGetValue(forward.ForwardId.ToString(), out var previousState);

        var effectiveForward = EffectiveForwardConfigBuilder.Build(forward);
        var contractSignature = ComputeContractSignature(effectiveForward);
        var signatureMatches = previousState != null
            && string.Equals(previousState.ContractSignature, contractSignature, StringComparison.Ordinal);
        if (!force && signatureMatches && IsForwardRunning(forward.ForwardId))
            return;

        ApplyForwardCore(effectiveForward, previousState, contractSignature, string.IsNullOrWhiteSpace(reason) ? "manual_apply" : reason);
    }

    public virtual bool RefreshUltraLease(ForwardConfig forward)
    {
        var lease = forward.RuntimeLease;
        var policy = forward.UltraAccess;
        try
        {
            if (!string.Equals(forward.BackendMode, "ultra", StringComparison.OrdinalIgnoreCase)
                || lease is null || policy is null
                || !policy.IsUsable(forward, DateTimeOffset.UtcNow)
                || !lease.IsUsable(policy, forward, DateTimeOffset.UtcNow))
                return false;
            return _ultraLeaseSender(forward.ForwardId, lease, CancellationToken.None).GetAwaiter().GetResult();
        }
        finally
        {
            lease?.Dispose();
            forward.RuntimeLease = null;
        }
    }

    public bool IsForwardRunning(int forwardId) => _supervisor.IsRunning(forwardId);

    public void StopAll()
    {
        _supervisor.StopAll();
        var runtimeDir = Path.Combine(_config.GetEffectiveStateDir(), "runtime");
        if (Directory.Exists(runtimeDir))
        {
            foreach (var path in Directory.EnumerateFiles(runtimeDir, "ultra-forward-*"))
            {
                try { File.Delete(path); } catch (IOException) { }
            }
        }
        _stateManager.SaveApplied([]);
    }

    public void Dispose() => _supervisor.Dispose();

    private void CleanupUltraRuntimeFiles(int forwardId)
    {
        if (forwardId <= 0)
            return;
        var runtimeDir = Path.Combine(_config.GetEffectiveStateDir(), "runtime");
        foreach (var path in new[]
        {
            Path.Combine(runtimeDir, $"ultra-forward-{forwardId}.json"),
            Path.Combine(runtimeDir, $"ultra-forward-{forwardId}.status.json"),
        })
        {
            try { if (File.Exists(path)) File.Delete(path); } catch (IOException) { }
        }
    }

    private bool RequiresFeedbackScheduler(ForwardConfig forward)
    {
        var sidepath = _config.UdpUotSidepath;
        return sidepath != null
            && sidepath.EnabledForwardIds.Contains(forward.ForwardId)
            && string.Equals(sidepath.SchedulerMode, "feedback_drr", StringComparison.OrdinalIgnoreCase);
    }

    private bool WaitForRequiredPorts(IEnumerable<int> ports, TimeSpan timeout)
    {
        if (!ports.Any())
            return true;

        var deadline = DateTime.UtcNow + timeout;
        while (DateTime.UtcNow < deadline)
        {
            if (ports.All(_portListeningProbe))
                return true;

            Thread.Sleep(100);
        }

        return ports.All(_portListeningProbe);
    }

    private void WaitForPortsReleased(IEnumerable<int> ports, TimeSpan timeout)
    {
        var requiredPorts = ports.Where(port => port > 0).Distinct().ToList();
        if (requiredPorts.Count == 0)
            return;

        var deadline = DateTime.UtcNow + timeout;
        while (DateTime.UtcNow < deadline)
        {
            if (requiredPorts.All(port => !_portListeningProbe(port)))
                return;

            Thread.Sleep(100);
        }
    }

    private static IEnumerable<int> PortsForReleaseWait(AppliedForwardState? previousState, int listenPort)
    {
        var ports = new List<int> { listenPort };
        if (previousState != null)
            ports.AddRange(previousState.LegPorts);

        return ports;
    }

    private static bool HasUsableMptcpRelay(ForwardConfig forward)
    {
        var mptcp = forward.Mptcp;
        if (mptcp is null)
            return false;

        if (!string.IsNullOrWhiteSpace(mptcp.SlaveRelayHost) && mptcp.SlaveRelayPort > 0)
            return true;

        if (!string.IsNullOrWhiteSpace(mptcp.RelayHost) && mptcp.RelayPort > 0)
            return true;

        if (!string.IsNullOrWhiteSpace(mptcp.RemoteHost) && mptcp.RemotePort > 0)
            return true;

        if (!string.IsNullOrWhiteSpace(mptcp.SidecarHost) && mptcp.SidecarPort > 0)
            return true;

        return false;
    }

    private ForwardConfig WithSelectedBondRuntimeEndpoints(ForwardConfig forward)
    {
        if (!WindowsForwardPolicy.UsesBondRuntime(forward)
            || !string.Equals(forward.Bonding?.PolicyMode, "latency_first", StringComparison.OrdinalIgnoreCase))
            return forward;

        var selectedEndpoints = _bondEndpointHealth.SelectRuntimeEndpoints(forward);
        return new ForwardConfig
        {
            ForwardId = forward.ForwardId,
            ServiceStatus = forward.ServiceStatus,
            Mode = forward.Mode,
            BackendMode = forward.BackendMode,
            ContractVersion = forward.ContractVersion,
            ClientMode = forward.ClientMode,
            RuntimePreference = forward.RuntimePreference,
            BondIngressMode = forward.BondIngressMode,
            ActiveFamily = forward.ActiveFamily,
            AggregationEnabled = forward.AggregationEnabled,
            TargetContract = forward.TargetContract,
            SidecarRequired = forward.SidecarRequired,
            BondSidecarPointId = forward.BondSidecarPointId,
            ListenPort = forward.ListenPort,
            Target = forward.Target,
            Sni = forward.Sni,
            PathPrefix = forward.PathPrefix,
            Transport = forward.Transport,
            SpeedLimitMbps = forward.SpeedLimitMbps,
            Endpoints = selectedEndpoints,
            Bonding = forward.Bonding,
            Mptcp = forward.Mptcp,
        };
    }

    private static int TryGetProcessId(Process process)
    {
        try
        {
            return process.HasExited ? -1 : process.Id;
        }
        catch
        {
            return -1;
        }
    }
}
