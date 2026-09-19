using LeikwanBridge.Core.Models;

namespace LeikwanBridge.Core.Services;

public interface IDesktopRuntimeSession : IAsyncDisposable
{
    event Action<IReadOnlyList<ForwardConfig>>? ForwardsChanged;
    event Action<NativeOutboundCapability?>? NativeOutboundChanged;
    event Action<int, string>? ForwardRejected;
    event Action<string>? LogRaised;

    IReadOnlyList<ForwardConfig> Forwards { get; }
    NativeOutboundCapability? NativeOutbound { get; }
    bool IsForwardRunning(int forwardId);
    Task StartAsync(CancellationToken ct);
    Task RefreshAsync(CancellationToken ct);
    Task StopAsync();
    Task StartForwardAsync(int forwardId, CancellationToken ct);
    Task StopForwardAsync(int forwardId, CancellationToken ct);
    Task<ProxyForwardLease> AcquireProxyForwardAsync(int forwardId, CancellationToken ct = default)
        => ProxyForwardLease.AcquireAsync(
            Forwards.FirstOrDefault(item => item.ForwardId == forwardId)
                ?? throw new InvalidOperationException("proxy_forward_unavailable"),
            () => IsForwardRunning(forwardId),
            token => StartForwardAsync(forwardId, token),
            token => StopForwardAsync(forwardId, token), ct);
    Task StartAllAsync(CancellationToken ct);
    Task StopAllAsync(CancellationToken ct);
    Task<NativeOutboundLease?> ResolveNativeOutboundLeaseAsync(
        int forwardId,
        string routeScope,
        CancellationToken ct);
    Task<NativeOutboundLease?> ResolveNativeOutboundLeaseAsync(
        int forwardId,
        string routeScope,
        string routePolicy,
        CancellationToken ct)
        => ResolveNativeOutboundLeaseAsync(forwardId, routeScope, ct);
}

public sealed class DesktopRuntimeSession : IDesktopRuntimeSession
{
    // A control-plane outage during startup should not surface as a hard
    // disconnect when the endpoint supervisor can recover shortly afterwards.
    // Keep this retry local to the initial pull; the API payload and runtime
    // reconciliation remain unchanged.
    private static readonly TimeSpan[] StartupSyncRetryDelays =
    [
        TimeSpan.FromSeconds(1),
        TimeSpan.FromSeconds(3),
        TimeSpan.FromSeconds(7),
    ];

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
    private readonly object _heartbeatScheduleGate = new();
    private readonly object _heartbeatNoticeGate = new();
    private DateTimeOffset _lastHeartbeatScheduledAtUtc = DateTimeOffset.MinValue;
    private DateTimeOffset _lastHeartbeatNoticeAtUtc = DateTimeOffset.MinValue;
    private int _consecutiveHeartbeatFailures;
    private static readonly TimeSpan HeartbeatFailureNoticeInterval = TimeSpan.FromMinutes(5);
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
        _heartbeatInterval = heartbeatInterval is { } interval && interval > TimeSpan.Zero
            ? interval
            : TimeSpan.FromSeconds(30);
        _polling.ForwardsUpdated += OnForwardsUpdated;
        _polling.NativeOutboundUpdated += capability => NativeOutboundChanged?.Invoke(capability);
        _polling.ForwardRejected += (forwardId, notice) => ForwardRejected?.Invoke(forwardId, notice);
        _polling.PreparationProgress += (done, total) => LogRaised?.Invoke($"preparing_routes:{done}/{total}");
        _polling.PullStateChanged += state =>
        {
            if (string.Equals(state.Status, "ok", StringComparison.OrdinalIgnoreCase))
                QueueHeartbeat();
        };
        _polling.SyncError += exception =>
        {
            LogRaised?.Invoke($"config_sync_failed:{exception.GetType().Name}");
            _polling.RequestControlPlaneRecovery();
        };
        _polling.ClientUpdated += client => QueueDiagnostics(client);
        _health.HealthIssue += (forwardId, issue) => LogRaised?.Invoke($"health_issue:{forwardId}:{BoundCode(issue)}");
        _heartbeat.UltraActionsReceived += ApplyUltraPolicyActions;
    }

    public event Action<IReadOnlyList<ForwardConfig>>? ForwardsChanged;
    public event Action<NativeOutboundCapability?>? NativeOutboundChanged;
    public event Action<int, string>? ForwardRejected;
    public event Action<string>? LogRaised;

    public IReadOnlyList<ForwardConfig> Forwards => _polling.LatestForwards;

    public NativeOutboundCapability? NativeOutbound => _polling.LatestNativeOutbound;

    public bool IsForwardRunning(int forwardId) => _runtime.IsForwardRunning(forwardId);

    public async Task<NativeOutboundLease?> ResolveNativeOutboundLeaseAsync(
        int forwardId,
        string routeScope,
        CancellationToken ct)
        => await ResolveNativeOutboundLeaseAsync(forwardId, routeScope, "restricted", ct).ConfigureAwait(false);

    public async Task<NativeOutboundLease?> ResolveNativeOutboundLeaseAsync(
        int forwardId,
        string routeScope,
        string routePolicy,
        CancellationToken ct)
    {
        // Native reconnects need fresh rotating tickets, not a revision-cached
        // forward. Pull only the policy; do not restart ordinary forwards.
        for (var attempt = 0; attempt < 2; attempt++)
        {
            var pull = await _api.PullConfigsAsync(ct: ct).ConfigureAwait(false);
            var forward = pull.Forwards.FirstOrDefault(item => item.ForwardId == forwardId);
            if (!pull.Ok || pull.NotModified || forward is null || pull.NativeOutbound is null)
                return null;
            try
            {
                return await _api.ResolveNativeOutboundLeaseAsync(
                    forward, pull.NativeOutbound, routeScope.Trim().ToLowerInvariant(), routePolicy, ct).ConfigureAwait(false);
            }
            catch (MasterApiException error) when (attempt == 0 && error.StatusCode == 409 && error.Message == "lease_scope_invalid")
            {
                LogRaised?.Invoke("native_lease_policy_refresh");
            }
        }
        return null;
    }

    public async Task StartAsync(CancellationToken ct)
    {
        if (_cts is not null)
            return;
        _cts = CancellationTokenSource.CreateLinkedTokenSource(ct);
        LogRaised?.Invoke("preparing_config");
        Exception? lastSyncError = null;
        for (var attempt = 0; ; attempt++)
        {
            try
            {
                await _polling.SyncOnceAsync(_cts.Token).ConfigureAwait(false);
                lastSyncError = null;
                break;
            }
            catch (OperationCanceledException) when (_cts.Token.IsCancellationRequested)
            {
                throw;
            }
            catch (Exception exception) when (attempt < StartupSyncRetryDelays.Length)
            {
                lastSyncError = exception;
                LogRaised?.Invoke($"startup_sync_retry:{attempt + 1}:{exception.GetType().Name}");
                await Task.Delay(StartupSyncRetryDelays[attempt], _cts.Token).ConfigureAwait(false);
            }
            catch (Exception exception)
            {
                lastSyncError = exception;
                break;
            }
        }
        if (lastSyncError is not null)
            throw lastSyncError;
        _cts.Token.ThrowIfCancellationRequested();
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
        return Task.Run(() =>
        {
            _runtime.RestartForward(forward, force: true, reason: "manual_apply");
            PublishCurrentForwards();
        }, ct);
    }

    public async Task<ProxyForwardLease> AcquireProxyForwardAsync(int forwardId, CancellationToken ct = default)
    {
        var forward = Forwards.FirstOrDefault(item => item.ForwardId == forwardId)
            ?? throw new InvalidOperationException("proxy_forward_unavailable");
        ProxyForwardLease? lease = null;
        try
        {
            lease = await _runtime.AcquireProxyForwardAsync(forward, ct).ConfigureAwait(false);
            PublishCurrentForwards();
            return lease.OwnsForward
                ? new ProxyForwardLease(lease.Forward, true, async _ =>
                {
                    try { await lease.DisposeAsync().ConfigureAwait(false); }
                    finally { PublishCurrentForwards(); }
                })
                : lease;
        }
        catch
        {
            if (lease is not null)
                await lease.DisposeAsync().ConfigureAwait(false);
            PublishCurrentForwards();
            throw;
        }
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
        await RunBoundedAsync(
            Forwards,
            4,
            forward =>
            {
                ct.ThrowIfCancellationRequested();
                _runtime.RestartForward(forward, force: true, reason: "manual_apply");
                return Task.CompletedTask;
            },
            ct);
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
                QueueHeartbeat();
        }
        catch (OperationCanceledException) when (ct.IsCancellationRequested)
        {
        }
    }

    private void QueueHeartbeat()
    {
        lock (_heartbeatScheduleGate)
        {
            var now = DateTimeOffset.UtcNow;
            if (now - _lastHeartbeatScheduledAtUtc < _heartbeatInterval)
                return;
            _lastHeartbeatScheduledAtUtc = now;
        }

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
            lock (_heartbeatNoticeGate)
            {
                _consecutiveHeartbeatFailures = 0;
                _lastHeartbeatNoticeAtUtc = DateTimeOffset.MinValue;
            }
        }
        catch (OperationCanceledException) when (ct.IsCancellationRequested)
        {
        }
        catch (Exception exception)
        {
            var shouldNotify = false;
            lock (_heartbeatNoticeGate)
            {
                _consecutiveHeartbeatFailures++;
                var now = DateTimeOffset.UtcNow;
                if (_consecutiveHeartbeatFailures == 1
                    || now - _lastHeartbeatNoticeAtUtc >= HeartbeatFailureNoticeInterval)
                {
                    _lastHeartbeatNoticeAtUtc = now;
                    shouldNotify = true;
                }
            }
            if (shouldNotify)
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
            _diagnostics.QueueDebugLogUpload(client, _api, ct);
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

    private static async Task RunBoundedAsync<T>(
        IEnumerable<T> items,
        int maxConcurrency,
        Func<T, Task> action,
        CancellationToken ct)
    {
        using var gate = new SemaphoreSlim(Math.Max(1, maxConcurrency));
        var tasks = items.Select(async item =>
        {
            await gate.WaitAsync(ct);
            try
            {
                await Task.Run(() => action(item), ct).ConfigureAwait(false);
            }
            finally
            {
                gate.Release();
            }
        });
        await Task.WhenAll(tasks);
    }
}
