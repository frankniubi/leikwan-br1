using System.IO;
using LeikwanBridge.Core.Models;
using LeikwanBridge.Core.Services;

namespace LeikwanBridge.Headless;

// The windowless client builds the exact same Core service graph the windowed
// client builds in MainViewModel.ConnectAsync. Only the lifecycle differs: no
// window, no tray, no proxy/game UI. Keeping the graph identical is what makes
// the lease handling, heartbeat attestation and failover behaviour match the
// GUI client byte for byte.
public interface IHeadlessClientStack : IAsyncDisposable
{
    IDesktopRuntimeSession Session { get; }

    AgentConfig Config { get; }
}

internal sealed class WindowsClientStack : IHeadlessClientStack
{
    private readonly MasterApiClient _api;
    private readonly ForwardRuntimeManager _runtime;
    private readonly DesktopRuntimeSession _session;

    private WindowsClientStack(
        AgentConfig config,
        MasterApiClient api,
        ForwardRuntimeManager runtime,
        WireGuardManager wireGuard,
        DesktopRuntimeSession session)
    {
        Config = config;
        _api = api;
        _runtime = runtime;
        _session = session;
    }

    public IDesktopRuntimeSession Session => _session;

    public AgentConfig Config { get; }

    public static async Task<WindowsClientStack> ConnectAsync(
        AgentConfig config,
        Action<string> log,
        CancellationToken ct)
    {
        var clientId = config.ActiveClientId > 0 ? config.ActiveClientId : config.ClientId;
        var api = new MasterApiClient(
            config.MasterUrl,
            config.AgentToken,
            MasterApiClient.BuildWindowsDeviceId(clientId, Environment.MachineName),
            $"Leikwan Bridge on {Environment.MachineName}",
            "windows-desktop");

        // The agent token is the whole identity: verify it against the panel
        // before any runtime is started, so a stale token fails fast and
        // visibly instead of half-starting forwards.
        var pull = await api.PullConfigsAsync(ct: ct).ConfigureAwait(false);
        if (pull.Client is { Id: > 0 } returned && returned.Id != clientId)
        {
            api.Dispose();
            throw new InvalidOperationException("agent_token_client_mismatch");
        }

        var stateManager = new StateManager(config.GetEffectiveStateDir());
        var supervisor = new ProcessSupervisor((forwardId, label, line) => log($"[fwd-{forwardId}/{label}] {line}"));
        var runtime = new ForwardRuntimeManager(
            config,
            supervisor,
            stateManager,
            portPreflight: PortConflictPreflight.Bind(supervisor, ClientDefaults.ResolveInstallRoot()));
        var polling = new ConfigPollingService(api, runtime, stateManager);
        var diagnostics = new ClientDiagnosticsService(
            supervisor,
            config,
            logDirectory: Path.Combine(
                Environment.GetFolderPath(Environment.SpecialFolder.CommonApplicationData),
                "Leikwan", "ClientAgent", "logs"));
        var health = new HealthCheckService(supervisor, runtime, stateManager, config: config);
        var wireGuard = new WireGuardManager(config.GetEffectiveWgBin(), config.GetEffectiveStateDir());
        wireGuard.EnsureIdentity();
        var heartbeat = new HeartbeatService(
            api,
            stateManager,
            wireGuard.PublicKey,
            config.GetEffectiveWireguardListenPort(),
            config.GetEffectiveWireguardFallbackTcpPort(),
            actions => ApplyUltraPolicyActions(polling, runtime, actions, log),
            additionalRuntimeDirectories: [Path.Combine(config.GetEffectiveStateDir(), "proxy-runtime")]);

        var session = new DesktopRuntimeSession(
            api,
            polling,
            heartbeat,
            health,
            runtime,
            diagnostics,
            config.PollIntervalSeconds,
            config.HealthCheckIntervalSeconds);
        session.LogRaised += message => log(message);
        var stack = new WindowsClientStack(config, api, runtime, wireGuard, session);
        await session.StartAsync(ct).ConfigureAwait(false);
        return stack;
    }

    // Mirrors MainViewModel.ApplyUltraPolicyActions: the panel can revoke an
    // Ultra policy generation, and the client must stop the affected forward
    // instead of keeping a route the panel no longer authorizes.
    private static void ApplyUltraPolicyActions(
        ConfigPollingService polling,
        ForwardRuntimeManager runtime,
        IReadOnlyList<UltraPolicyAction> actions,
        Action<string> log)
    {
        foreach (var action in actions.Take(32))
        {
            if (!string.Equals(action.Action, "stop", StringComparison.OrdinalIgnoreCase)
                || action.ForwardId <= 0
                || !System.Text.RegularExpressions.Regex.IsMatch(action.PolicyGeneration ?? "", "^[a-f0-9]{64}$"))
                continue;
            var forward = polling.LatestForwards.FirstOrDefault(item => item.ForwardId == action.ForwardId);
            if (forward?.UltraAccess is null
                || !string.Equals(forward.BackendMode, "ultra", StringComparison.OrdinalIgnoreCase)
                || !string.Equals(forward.UltraAccess.PolicyGeneration, action.PolicyGeneration, StringComparison.Ordinal))
                continue;
            runtime.StopForwardForPolicy(action.ForwardId);
            log($"panel stopped Ultra forward {action.ForwardId}: {action.Reason}");
        }
    }

    public async ValueTask DisposeAsync()
    {
        await _session.DisposeAsync().ConfigureAwait(false);
        _runtime.Dispose();
        _api.Dispose();
    }
}
