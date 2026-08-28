using LeikwanBridge.Core.Models;

namespace LeikwanBridge.Core.Services;

public interface IDesktopRuntimeSession : IAsyncDisposable
{
    event Action<IReadOnlyList<ForwardConfig>>? ForwardsChanged;
    event Action<int, string>? ForwardRejected;
    event Action<string>? LogRaised;

    IReadOnlyList<ForwardConfig> Forwards { get; }
    bool IsForwardRunning(int forwardId);
    Task StartAsync(CancellationToken ct);
    Task RefreshAsync(CancellationToken ct);
    Task StopAsync();
    Task StartForwardAsync(int forwardId, CancellationToken ct);
    Task StopForwardAsync(int forwardId, CancellationToken ct);
    Task StartAllAsync(CancellationToken ct);
    Task StopAllAsync(CancellationToken ct);
}

public sealed class DesktopRuntimeSession : IDesktopRuntimeSession
{
    private readonly MasterApiClient _api;
    private readonly ConfigPollingService _polling;
    private readonly HeartbeatService _heartbeat;
    private readonly HealthCheckService _health;
    private readonly ForwardRuntimeManager _runtime;
    private readonly ClientDiagnosticsService? _diagnostics;
    private readonly int _pollIntervalSeconds;
    private readonly int _healthIntervalSeconds;
    private readonly TimeSpan _heartbeatInterval;
    private readonly SemaphoreSlim _heartbeatGate = new(1, 1);
    private readonly object _taskLock = new();
    private CancellationTokenSource? _cts;
    private Task? _heartbeatLoop;
    private Task? _lastHeartbeat;
    private Task? _lastDiagnostics;
    private bool _healthStarted;
    private bool _stopped;

    public DesktopRuntimeSession(
        MasterApiClient api,
        ConfigPollingService polling,
        HeartbeatService heartbeat,
        HealthCheckService health,
        ForwardRuntimeManager runtime,
        ClientDiagnosticsService? diagnostics = null,
        int pollIntervalSeconds = 30,
        int healthIntervalSeconds = 20,
        TimeSpan? heartbeatInterval = null)
    {
        _api = api;
        _polling = polling;
        _heartbeat = heartbeat;
        _health = health;
        _runtime = runtime;
        _diagnostics = diagnostics;
        _pollIntervalSeconds = Math.Max(1, pollIntervalSeconds);
        _healthIntervalSeconds = Math.Max(1, healthIntervalSeconds);
        _heartbeatInterval = heartbeatInterval ?? TimeSpan.FromSeconds(30);
        _polling.ForwardsUpdated += OnForwardsUpdated;
        _polling.ForwardRejected += (forwardId, notice) => ForwardRejected?.Invoke(forwardId, notice);
        _polling.PullStateChanged += state =>
        {
            if (string.Equals(state.Status, "ok", StringComparison.OrdinalIgnoreCase))
                QueueHeartbeat();
        };
        _polling.SyncError += exception => LogRaised?.Invoke($"config_sync_failed:{exception.GetType().Name}");
        _polling.ClientUpdated += client => QueueDiagnostics(client);
        _health.HealthIssue += (forwardId, issue) => LogRaised?.Invoke($"health_issue:{forwardId}:{BoundCode(issue)}");
        _heartbeat.UltraActionsReceived += ApplyUltraPolicyActions;
    }

    public event Action<IReadOnlyList<ForwardConfig>>? ForwardsChanged;
    public event Action<int, string>? ForwardRejected;
    public event Action<string>? LogRaised;

    public IReadOnlyList<ForwardConfig> Forwards => _polling.LatestForwards;

    public bool IsForwardRunning(int forwardId) => _runtime.IsForwardRunning(forwardId);

    public async Task StartAsync(CancellationToken ct)
    {
        if (_cts is not null)
            return;
        _cts = CancellationTokenSource.CreateLinkedTokenSource(ct);
        await _polling.SyncOnceAsync(_cts.Token);
        _polling.Start(_pollIntervalSeconds);
        _heartbeatLoop = RunHeartbeatLoopAsync(_cts.Token);
    }

    public async Task RefreshAsync(CancellationToken ct)
    {
        if (_cts is null || _stopped)
            throw new InvalidOperationException("client_not_connected");

        // Config pulls use a server long-poll. Bound an explicit user refresh
        // without cancelling the session's background polling token.
        using var refreshCts = CancellationTokenSource.CreateLinkedTokenSource(ct, _cts.Token);
        refreshCts.CancelAfter(TimeSpan.FromSeconds(15));
        await _polling.SyncOnceAsync(refreshCts.Token);
    }

    public async Task StopAsync()
    {
        if (_stopped)
            return;
        _stopped = true;
        _cts?.Cancel();
        await _polling.StopAsync();
        await _health.StopAsync();
        if (_heartbeatLoop is not null)
            await IgnoreCancellationAsync(_heartbeatLoop);
        Task? heartbeat;
        Task? diagnostics;
        lock (_taskLock)
        {
            heartbeat = _lastHeartbeat;
            diagnostics = _lastDiagnostics;
        }
        if (heartbeat is not null)
            await IgnoreCancellationAsync(heartbeat);
        if (diagnostics is not null)
            await IgnoreCancellationAsync(diagnostics);
        _runtime.StopAll();
        _runtime.Dispose();
        _api.Dispose();
        _cts?.Dispose();
    }

    public Task StartForwardAsync(int forwardId, CancellationToken ct)
    {
        ct.ThrowIfCancellationRequested();
        var forward = Forwards.FirstOrDefault(item => item.ForwardId == forwardId)
            ?? throw new KeyNotFoundException("forward_not_found");
        _runtime.RestartForward(forward, force: true, reason: "manual_apply");
        PublishCurrentForwards();
        return Task.CompletedTask;
    }

    public Task StopForwardAsync(int forwardId, CancellationToken ct)
    {
        ct.ThrowIfCancellationRequested();
        _runtime.StopForward(forwardId);
        PublishCurrentForwards();
        return Task.CompletedTask;
    }

    public async Task StartAllAsync(CancellationToken ct)
    {
        foreach (var forward in Forwards)
        {
            ct.ThrowIfCancellationRequested();
            _runtime.RestartForward(forward, force: true, reason: "manual_apply");
            await Task.Yield();
        }
        PublishCurrentForwards();
    }

    public Task StopAllAsync(CancellationToken ct)
    {
        ct.ThrowIfCancellationRequested();
        _runtime.StopAll();
        PublishCurrentForwards();
        return Task.CompletedTask;
    }

    public async ValueTask DisposeAsync()
    {
        await StopAsync();
        _heartbeatGate.Dispose();
    }

    private void OnForwardsUpdated(List<ForwardConfig> forwards)
    {
        if (forwards.Count > 0)
        {
            _health.UpdateForwards(forwards);
            if (!_healthStarted)
            {
                _healthStarted = true;
                _health.Start(forwards, _healthIntervalSeconds);
            }
        }
        else if (_healthStarted)
        {
            _health.UpdateForwards([]);
        }
        ForwardsChanged?.Invoke(forwards);
    }

    private async Task RunHeartbeatLoopAsync(CancellationToken ct)
    {
        using var timer = new PeriodicTimer(_heartbeatInterval);
        try
        {
            while (await timer.WaitForNextTickAsync(ct))
                await SendHeartbeatSafeAsync(ct);
        }
        catch (OperationCanceledException) when (ct.IsCancellationRequested)
        {
        }
    }

    private void QueueHeartbeat()
    {
        var ct = _cts?.Token ?? CancellationToken.None;
        var task = SendHeartbeatSafeAsync(ct);
        lock (_taskLock)
            _lastHeartbeat = task;
    }

    private async Task SendHeartbeatSafeAsync(CancellationToken ct)
    {
        try
        {
            await _heartbeatGate.WaitAsync(ct);
            try
            {
                await _heartbeat.SendHeartbeatAsync(ct);
            }
            finally
            {
                _heartbeatGate.Release();
            }
        }
        catch (OperationCanceledException) when (ct.IsCancellationRequested)
        {
        }
        catch (Exception exception)
        {
            LogRaised?.Invoke($"heartbeat_failed:{exception.GetType().Name}");
        }
    }

    private void QueueDiagnostics(ClientMeta client)
    {
        if (_diagnostics is null)
            return;
        var ct = _cts?.Token ?? CancellationToken.None;
        var task = SubmitDiagnosticsSafeAsync(client, ct);
        lock (_taskLock)
            _lastDiagnostics = task;
    }

    private async Task SubmitDiagnosticsSafeAsync(ClientMeta client, CancellationToken ct)
    {
        try
        {
            await _diagnostics!.SubmitIfRequestedAsync(client, Forwards, _api, ct);
        }
        catch (OperationCanceledException) when (ct.IsCancellationRequested)
        {
        }
        catch (Exception exception)
        {
            LogRaised?.Invoke($"diagnostics_failed:{exception.GetType().Name}");
        }
    }

    private void ApplyUltraPolicyActions(IReadOnlyList<UltraPolicyAction> actions)
    {
        foreach (var action in actions.Take(32))
        {
            if (!string.Equals(action.Action, "stop", StringComparison.OrdinalIgnoreCase)
                || action.ForwardId <= 0)
                continue;
            var forward = Forwards.FirstOrDefault(item => item.ForwardId == action.ForwardId);
            if (forward?.UltraAccess is null
                || !string.Equals(forward.BackendMode, "ultra", StringComparison.OrdinalIgnoreCase)
                || !string.Equals(forward.UltraAccess.PolicyGeneration, action.PolicyGeneration, StringComparison.Ordinal))
                continue;
            _runtime.StopForwardForPolicy(action.ForwardId);
            LogRaised?.Invoke($"ultra_stopped:{action.ForwardId}:{BoundCode(action.Reason)}");
        }
        PublishCurrentForwards();
    }

    private void PublishCurrentForwards()
    {
        ForwardsChanged?.Invoke(Forwards);
    }

    private static string BoundCode(string value)
    {
        var normalized = new string((value ?? string.Empty).ToLowerInvariant()
            .Where(character => character is >= 'a' and <= 'z' or >= '0' and <= '9' or '_')
            .Take(64)
            .ToArray());
        return normalized.Length == 0 ? "unknown" : normalized;
    }

    private static async Task IgnoreCancellationAsync(Task task)
    {
        try
        {
            await task;
        }
        catch (OperationCanceledException)
        {
        }
    }
}
