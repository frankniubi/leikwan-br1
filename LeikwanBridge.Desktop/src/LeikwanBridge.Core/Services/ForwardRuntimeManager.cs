using System.Text.Json;
using LeikwanBridge.Core.Helpers;
using LeikwanBridge.Core.Models;
using LeikwanBridge.Core.Services.Platform;
using LeikwanBridge.Core.Services.Ultra;
using Serilog;

namespace LeikwanBridge.Core.Services;

/// <summary>
/// Manages forward process lifecycles: apply (start), stop, restart.
/// </summary>
public class ForwardRuntimeManager : IDisposable
{
    private readonly AgentConfig _config;
    private readonly IProcessSupervisor _supervisor;
    private readonly BondRuntimeBuilder _bondBuilder;
    private readonly BondEndpointHealthService _bondEndpointHealth;
    private readonly MptcpRuntimeBuilder _mptcpBuilder;
    private readonly StateManager _stateManager;
    private readonly Func<string, bool> _binaryExists;
    private readonly Func<int, bool> _portListeningProbe;
    private readonly IUltraRuntimeHost _ultraRuntimeHost;

    public ForwardRuntimeManager(
        AgentConfig config,
        IProcessSupervisor supervisor,
        StateManager stateManager,
        Func<string, bool>? binaryExists = null,
        Func<int, bool>? portListeningProbe = null,
        Func<int, UltraLease, CancellationToken, Task<bool>>? ultraLeaseSender = null,
        Func<int, PortPreflightResult>? portPreflight = null,
        IUltraRuntimeHost? ultraRuntimeHost = null)
    {
        _config = config;
        _supervisor = supervisor;
        _bondBuilder = new BondRuntimeBuilder(config);
        _mptcpBuilder = new MptcpRuntimeBuilder(config);
        _bondEndpointHealth = new BondEndpointHealthService(stateManager);
        _stateManager = stateManager;
        _binaryExists = binaryExists ?? File.Exists;
        _portListeningProbe = portListeningProbe ?? NetworkProbe.IsPortListening;
        _ultraRuntimeHost = ultraRuntimeHost ?? new WindowsUltraRuntimeHost(
            config,
            supervisor,
            stateManager,
            _binaryExists,
            _portListeningProbe,
            ultraLeaseSender,
            portPreflight);
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
            ProcessIds = [.. _supervisor.GetProcessIds(forwardId)],
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
        var policyFailure = policy?.GetValidationFailure(forward, now);
        var leaseUsable = policy is not null && lease is not null && lease.IsUsable(policy, forward, now);
        if (policy is null || lease is null || policyFailure is not null || !leaseUsable)
        {
            Log.Warning(
                "Ultra policy or lease rejected for forward {ForwardId}; stopping without legacy fallback (policy_reason={PolicyReason}, lease_present={LeasePresent}, lease_usable={LeaseUsable})",
                forward.ForwardId,
                policy is null ? "missing" : policyFailure ?? "ok",
                lease is not null,
                leaseUsable);
            lease?.Dispose();
            forward.RuntimeLease = null;
            StopForwardForPolicy(forward.ForwardId);
            return;
        }
        try
        {
            _ultraRuntimeHost.Start(
                forward,
                lease,
                contractSignature ?? previousState?.ContractSignature ?? string.Empty,
                string.IsNullOrWhiteSpace(applyReason) ? "config_sync" : applyReason);
        }
        catch (Exception ex)
        {
            Log.Warning("Ultra runtime start failed for forward {ForwardId} ({ErrorType})",
                forward.ForwardId, ex.GetType().Name);
            StopForwardForPolicy(forward.ForwardId);
        }
        finally
        {
            lease.Dispose();
            forward.RuntimeLease = null;
        }
    }

    public virtual void StopForward(int forwardId)
    {
        Log.Information("stopping forward {ForwardId}", forwardId);
        _ultraRuntimeHost.Stop(forwardId);

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
            return _ultraRuntimeHost.Refresh(forward, lease);
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
        foreach (var forwardId in _stateManager.LoadApplied().Keys.Select(key => int.TryParse(key, out var id) ? id : 0).Where(id => id > 0))
            _ultraRuntimeHost.Stop(forwardId);
        _supervisor.StopAll();
        _stateManager.SaveApplied([]);
    }

    public void Dispose() => _supervisor.Dispose();

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

}
