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

    public ForwardRuntimeManager(
        AgentConfig config,
        ProcessSupervisor supervisor,
        StateManager stateManager,
        Func<string, bool>? binaryExists = null,
        Func<int, bool>? portListeningProbe = null)
    {
        _config = config;
        _supervisor = supervisor;
        _bondBuilder = new BondRuntimeBuilder(config);
        _mptcpBuilder = new MptcpRuntimeBuilder(config);
        _bondEndpointHealth = new BondEndpointHealthService(stateManager);
        _stateManager = stateManager;
        _binaryExists = binaryExists ?? File.Exists;
        _portListeningProbe = portListeningProbe ?? NetworkProbe.IsPortListening;
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

    public virtual void StopForward(int forwardId)
    {
        Log.Information("stopping forward {ForwardId}", forwardId);
        _supervisor.StopAll(forwardId);

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

    public bool IsForwardRunning(int forwardId) => _supervisor.IsRunning(forwardId);

    public void StopAll()
    {
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
