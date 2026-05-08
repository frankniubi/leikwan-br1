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
        _bondEndpointHealth = new BondEndpointHealthService(stateManager);
        _stateManager = stateManager;
        _binaryExists = binaryExists ?? File.Exists;
        _portListeningProbe = portListeningProbe ?? NetworkProbe.IsPortListening;
    }

    public virtual void ApplyForward(ForwardConfig forward)
    {
        var applied = _stateManager.LoadApplied();
        applied.TryGetValue(forward.ForwardId.ToString(), out var previousState);
        ApplyForwardCore(forward, previousState);
    }

    public virtual void ApplyAuthoritativeAgentConfig(JsonElement? agentConfigJson)
    {
        _config.ApplyAuthoritativeConfig(agentConfigJson);
    }

    private void ApplyForwardCore(ForwardConfig forward, AppliedForwardState? previousState)
    {
        var forwardId = forward.ForwardId;
        var mode = ModeNormalizer.Normalize(forward.Mode);

        // MPTCP on Windows => Bond fallback
        if (mode == "mptcp")
        {
            Log.Warning("forward {ForwardId}: mptcp mode on Windows, falling back to bond", forwardId);
            forward = MptcpFallbackService.ConvertToBond(forward);
            mode = "bond";
        }

        Log.Information("applying forward {ForwardId} mode={Mode} transport={Transport} endpoints={Count}",
            forwardId, mode, forward.Transport, forward.Endpoints.Count);

        // Stop existing processes
        _supervisor.StopAll(forwardId);
        WaitForPortsReleased(PortsForReleaseWait(previousState, forward.ListenPort), TimeSpan.FromSeconds(3));

        BondRuntimeBuilder.RuntimeSpec spec;
        try
        {
            spec = mode switch
            {
                "bond" => _bondBuilder.Build(WithSelectedBondRuntimeEndpoints(forward)),
                "race" => _bondBuilder.BuildRace(forward),
                _ => _bondBuilder.BuildSingle(forward),
            };
        }
        catch (Exception ex)
        {
            Log.Error(ex, "failed to build runtime spec for forward {ForwardId}", forwardId);
            return;
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

        foreach (var file in spec.GeneratedFiles ?? [])
        {
            var directory = Path.GetDirectoryName(file.FilePath);
            if (!string.IsNullOrWhiteSpace(directory))
                Directory.CreateDirectory(directory);
            File.WriteAllText(file.FilePath, file.Content);
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
            ListenPort = forward.ListenPort,
            IngressMode = spec.IngressMode,
            EndpointCount = forward.Endpoints.Count,
            LegPorts = [.. spec.LegPorts],
            ProcessRoles = [.. spec.Processes.Select(process => process.Role)],
            ProcessLabels = [.. spec.Processes.Select(process => process.Label)],
            ProcessIds = [.. _supervisor.GetProcesses(forwardId)
                .Select(process => TryGetProcessId(process.Process))
                .Where(pid => pid > 0)],
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
        var applied = _stateManager.LoadApplied();
        applied.TryGetValue(forward.ForwardId.ToString(), out var previousState);
        ApplyForwardCore(forward, previousState);
    }

    public bool IsForwardRunning(int forwardId) => _supervisor.IsRunning(forwardId);

    public void StopAll()
    {
        _supervisor.StopAll();
        _stateManager.SaveApplied([]);
    }

    public void Dispose() => _supervisor.Dispose();

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

    private ForwardConfig WithSelectedBondRuntimeEndpoints(ForwardConfig forward)
    {
        if (ModeNormalizer.Normalize(forward.Mode) != "bond"
            || !string.Equals(forward.Bonding?.PolicyMode, "latency_first", StringComparison.OrdinalIgnoreCase))
            return forward;

        var selectedEndpoints = _bondEndpointHealth.SelectRuntimeEndpoints(forward);
        return new ForwardConfig
        {
            ForwardId = forward.ForwardId,
            ServiceStatus = forward.ServiceStatus,
            Mode = forward.Mode,
            BackendMode = forward.BackendMode,
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
