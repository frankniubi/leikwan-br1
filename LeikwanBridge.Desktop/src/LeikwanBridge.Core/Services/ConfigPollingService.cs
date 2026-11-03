using LeikwanBridge.Core.Helpers;
using LeikwanBridge.Core.Models;
using LeikwanBridge.Core.Services.Ultra;
using Serilog;

namespace LeikwanBridge.Core.Services;

/// <summary>
/// Polls master for forward configs. Revision-aware: sends the last known
/// revision, short-circuits on 304 Not Modified, and reconciles only changed
/// forwards. Port of client_agent.py sync_once() + run() loop.
/// </summary>
public sealed class ConfigPollingService
{
    private const int LongPollWaitSeconds = 10;
    private const int ReArmDelayMs = 5000;
    private const int MaxConcurrentForwardOperations = 4;

    private readonly MasterApiClient _api;
    private readonly ForwardRuntimeManager _runtimeManager;
    private readonly StateManager _stateManager;
    private readonly IForwardAcceptancePolicy _forwardPolicy;
    private readonly TimeProvider _timeProvider;
    private readonly System.Collections.Concurrent.ConcurrentDictionary<int, DateTimeOffset> _deliveredLeaseRenewals = new();

    private List<ForwardConfig> _latestForwards = [];
    private CancellationTokenSource? _cts;
    private Task? _pollingTask;
    private string _lastConfigRevision = "";
    private int _nextPollAfterMs;
    private int _syncInProgress;
    private int _forceFullPull;
    private volatile bool _pendingResync;
    private readonly System.Collections.Concurrent.ConcurrentDictionary<int, byte> _ultraRecoveries = new();
    private readonly System.Collections.Concurrent.ConcurrentDictionary<int, byte> _targetedUltraRefreshes = new();
    private readonly object _recoveryGate = new();

    /// <summary>
    /// Policy generations the panel has answered with policy_generation_invalid.
    /// A refused generation must never be handed back to the runtime by the
    /// last-known-policy fallback, or the refusal repeats for ever.
    /// </summary>
    private readonly System.Collections.Concurrent.ConcurrentDictionary<int, string> _rejectedPolicyGenerations = new();
    private ClientMeta? _latestClient;
    private NativeOutboundCapability? _latestNativeOutbound;
    private DateTimeOffset _lastRecoveryTriggerAtUtc = DateTimeOffset.MinValue;
    private static readonly TimeSpan RecoveryTriggerThrottle = TimeSpan.FromSeconds(10);

    /// <summary>
    /// Steady retry cadence while the panel refuses this device because the
    /// client's online device limit is full. A slot frees when another device
    /// has been silent for 180 s, is kicked, or the limit is raised, so a
    /// fixed ~1 min poll rejoins promptly without ramping like an outage.
    /// </summary>
    public static readonly TimeSpan DeviceLimitRetryInterval = TimeSpan.FromSeconds(60);
    public static readonly TimeSpan DeviceLimitRetryJitter = TimeSpan.FromSeconds(10);

    /// <summary>
    /// Config pull failures (panel/CDN down, timeouts, 5xx). While this is
    /// backing off, the polling loop waits the jittered delay instead of the
    /// 5 s re-arm, and immediate re-syncs requested by health checks, sync
    /// errors or lease failures only queue a full pull for the next attempt.
    /// </summary>
    private readonly ControlPlaneBackoff _pullBackoff;

    /// <summary>
    /// Transient Ultra lease resolve/renew failures. Kept apart from the pull
    /// backoff: a panel whose long poll answers while its lease endpoint fails
    /// must not reset the lease retry sequence on every 304.
    /// </summary>
    private readonly ControlPlaneBackoff _leaseBackoff;

    /// <summary>
    /// Paces lease renewals triggered by runtime failures that suggest a dead
    /// provider credential (see <see cref="RequestFailureDrivenUltraRenewal"/>).
    /// </summary>
    private readonly UltraFailureRenewalLimiter _failureRenewals;
    private int _failureDrivenRenewals;

    private readonly SemaphoreSlim _loopWake = new(0, 1);
    private int _loopSleepingInBackoff;

    public event Action<List<ForwardConfig>>? ForwardsUpdated;
    public event Action<PullState>? PullStateChanged;
    public event Action<Exception>? SyncError;
    public event Action<int, string>? ForwardRejected;
    public event Action<ClientMeta>? ClientUpdated;

    /// <summary>
    /// Raised once per successful full pull (never for 304 / failed pulls)
    /// with the pull's <c>client_update</c> policy, or null when the panel
    /// sent none. Raised before forward reconciliation so a slow apply does
    /// not delay the update gate.
    /// </summary>
    public event Action<ClientUpdatePolicy?>? ClientUpdatePolicyReceived;
    public event Action<NativeOutboundCapability?>? NativeOutboundUpdated;
    public event Action<int, int>? PreparationProgress;

    public List<ForwardConfig> LatestForwards => _latestForwards;
    public ClientMeta? LatestClient => _latestClient;

    /// <summary>Where each full pull's console update request token is
    /// published for the windowless client's update loop.</summary>
    public HeadlessUpdateRequestSignal UpdateRequests { get; init; } = HeadlessUpdateRequestSignal.Shared;
    public NativeOutboundCapability? LatestNativeOutbound => _latestNativeOutbound;

    public ConfigPollingService(
        MasterApiClient api,
        ForwardRuntimeManager runtimeManager,
        StateManager stateManager,
        IForwardAcceptancePolicy? forwardPolicy = null)
        : this(api, runtimeManager, stateManager, forwardPolicy, TimeProvider.System)
    {
    }

    public ConfigPollingService(
        MasterApiClient api,
        ForwardRuntimeManager runtimeManager,
        StateManager stateManager,
        IForwardAcceptancePolicy? forwardPolicy,
        TimeProvider timeProvider)
        : this(api, runtimeManager, stateManager, forwardPolicy, timeProvider, pullBackoff: null, leaseBackoff: null)
    {
    }

    public ConfigPollingService(
        MasterApiClient api,
        ForwardRuntimeManager runtimeManager,
        StateManager stateManager,
        IForwardAcceptancePolicy? forwardPolicy,
        TimeProvider timeProvider,
        ControlPlaneBackoff? pullBackoff,
        ControlPlaneBackoff? leaseBackoff)
        : this(api, runtimeManager, stateManager, forwardPolicy, timeProvider, pullBackoff, leaseBackoff, failureRenewals: null)
    {
    }

    public ConfigPollingService(
        MasterApiClient api,
        ForwardRuntimeManager runtimeManager,
        StateManager stateManager,
        IForwardAcceptancePolicy? forwardPolicy,
        TimeProvider timeProvider,
        ControlPlaneBackoff? pullBackoff,
        ControlPlaneBackoff? leaseBackoff,
        UltraFailureRenewalLimiter? failureRenewals)
    {
        _api = api;
        _runtimeManager = runtimeManager;
        _stateManager = stateManager;
        _forwardPolicy = forwardPolicy ?? WindowsForwardAcceptancePolicy.Instance;
        _timeProvider = timeProvider;
        _pullBackoff = pullBackoff ?? new ControlPlaneBackoff(timeProvider);
        _leaseBackoff = leaseBackoff ?? new ControlPlaneBackoff(timeProvider);
        _failureRenewals = failureRenewals ?? new UltraFailureRenewalLimiter();
        _runtimeManager.UltraRecoveryRequested += RequestUltraRecovery;
        _runtimeManager.UltraLeaseRefreshRequested += RequestUltraLeaseRefresh;
        _runtimeManager.UltraCredentialSuspected += RequestFailureDrivenUltraRenewal;
    }

    /// <summary>Failure-driven lease renewals admitted so far (diagnostics and tests).</summary>
    public int FailureDrivenRenewalCount => Volatile.Read(ref _failureDrivenRenewals);

    /// <summary>True while either control-plane retry sequence is waiting.</summary>
    public bool IsControlPlaneBackingOff => _pullBackoff.IsBackingOff || _leaseBackoff.IsBackingOff;

    public ControlPlaneBackoff PullBackoff => _pullBackoff;

    public ControlPlaneBackoff LeaseBackoff => _leaseBackoff;

    public void Start(int intervalSeconds = 30)
    {
        _cts?.Cancel();
        _cts = new CancellationTokenSource();
        var ct = _cts.Token;
        _pollingTask = Task.Run(async () =>
        {
            while (!ct.IsCancellationRequested)
            {
                try
                {
                    await SyncOnceAsync(ct);
                }
                catch (OperationCanceledException) when (ct.IsCancellationRequested) { break; }
                catch (Exception ex)
                {
                    Log.Error(ex, "config sync failed");
                    SyncError?.Invoke(ex);
                    SavePullError(ex);
                }

                // Steady-state uses a short re-arm because the server long-poll
                // already waits for a change; before the first revision we fall
                // back to the Panel-provided or configured interval.
                var delayMs = _lastConfigRevision == ""
                    ? (_nextPollAfterMs > 0 ? _nextPollAfterMs : intervalSeconds * 1000)
                    : ReArmDelayMs;
                var delay = NextLoopDelay(TimeSpan.FromMilliseconds(delayMs), out var backingOff);
                try { await WaitForNextPollAsync(delay, backingOff, ct); }
                catch (OperationCanceledException) { break; }
            }
        }, ct);
    }

    /// <summary>
    /// A failed pull replaces the fixed re-arm with its jittered backoff (so a
    /// dead panel is not retried every 5 s); a lease-only outage stretches the
    /// re-arm to the lease backoff, because each forced full pull would
    /// otherwise immediately retry the failing lease request.
    /// </summary>
    public TimeSpan NextLoopDelay(TimeSpan normal, out bool backingOff)
    {
        var pullRemaining = _pullBackoff.RemainingDelay;
        var leaseRemaining = _leaseBackoff.RemainingDelay;
        backingOff = pullRemaining > TimeSpan.Zero || leaseRemaining > TimeSpan.Zero;
        if (pullRemaining > TimeSpan.Zero)
            return pullRemaining > leaseRemaining ? pullRemaining : leaseRemaining;
        return leaseRemaining > normal ? leaseRemaining : normal;
    }

    private async Task WaitForNextPollAsync(TimeSpan delay, bool backingOff, CancellationToken ct)
    {
        if (!backingOff)
        {
            await Task.Delay(delay, ct);
            return;
        }
        // An out-of-band pull (user refresh) that reaches the panel ends the
        // backoff early, so the long poll resumes at once instead of after up
        // to a minute.
        while (_loopWake.CurrentCount > 0)
            _loopWake.Wait(0);
        Interlocked.Exchange(ref _loopSleepingInBackoff, 1);
        try
        {
            await _loopWake.WaitAsync(delay, ct);
        }
        finally
        {
            Interlocked.Exchange(ref _loopSleepingInBackoff, 0);
        }
    }

    private void NotePullFailure(string reason)
    {
        var delay = _pullBackoff.RecordFailure();
        Log.Information(
            "control-plane pull failed ({Reason}); backing off {DelaySeconds:0.0}s (consecutive={Failures})",
            reason,
            delay.TotalSeconds,
            _pullBackoff.ConsecutiveFailures);
    }

    private void NotePullDeviceLimit(DeviceLimitReachedException limit)
    {
        // Not an outage: the endpoint answered, so no mirror is penalized and
        // the delay does not ramp. The saved profile and anything already
        // running are left exactly as on any other failed pull.
        var delay = _pullBackoff.RecordSteadyFailure(DeviceLimitRetryInterval, DeviceLimitRetryJitter);
        Log.Warning(
            "config pull refused: online device limit reached ({Active}/{Limit}); retrying in {DelaySeconds:0}s",
            limit.ActiveOnlineDevices,
            limit.OnlineDeviceLimit,
            delay.TotalSeconds);
    }

    private void NotePullSuccess()
    {
        if (!_pullBackoff.RecordSuccess())
            return;
        Log.Information("control-plane pull succeeded again; backoff reset");
        if (Volatile.Read(ref _loopSleepingInBackoff) != 0)
        {
            try { _loopWake.Release(); }
            catch (SemaphoreFullException) { }
        }
    }

    private void NoteLeaseFailure(int forwardId, Exception exception)
    {
        var delay = _leaseBackoff.RecordFailure();
        Log.Information(
            "Ultra lease request for forward {ForwardId} hit a control-plane failure ({ErrorType}); lease retries back off {DelaySeconds:0.0}s (consecutive={Failures})",
            forwardId,
            exception.GetType().Name,
            delay.TotalSeconds,
            _leaseBackoff.ConsecutiveFailures);
    }

    private void NoteLeaseAnswered()
    {
        if (_leaseBackoff.RecordSuccess())
            Log.Information("Ultra lease endpoint answered again; lease backoff reset");
    }

    public void Stop()
    {
        _cts?.Cancel();
    }

    public async Task StopAsync()
    {
        Stop();
        var pollingTask = _pollingTask;
        if (pollingTask is null)
            return;
        try
        {
            await pollingTask;
        }
        catch (OperationCanceledException)
        {
        }
    }

    /// <summary>Immediate revision-aware pull for reconnect / window-foreground / user Start.</summary>
    public void TriggerImmediateSync()
    {
        var ct = _cts?.Token ?? CancellationToken.None;
        if (ct.IsCancellationRequested) return;
        // While the control plane is failing, an immediate re-sync (health
        // check, sync error, lease failure) would hammer it every few seconds.
        // The caller has already queued a full pull; the polling loop runs it
        // when the backoff elapses.
        if (IsControlPlaneBackingOff)
        {
            Log.Debug("immediate config sync deferred by control-plane backoff");
            return;
        }
        _ = Task.Run(async () =>
        {
            try { await SyncOnceAsync(ct); }
            catch (OperationCanceledException) when (ct.IsCancellationRequested) { }
            catch (Exception ex)
            {
                Log.Error(ex, "immediate config sync failed");
                SyncError?.Invoke(ex);
                SavePullError(ex);
            }
        }, ct);
    }

    /// <summary>
    /// Forces the next pull to carry the full policy payload. Ultra session
    /// tickets rotate independently of the forward revision, so a native
    /// lease request must never be made from an expired cached ticket.
    /// </summary>
    public void RequestPolicyRefresh()
    {
        QueueConfigRefreshSoon();
        TriggerImmediateSync();
    }

    /// <summary>
    /// Requests a full policy pull after a transient control-plane failure,
    /// optionally prioritising the affected Ultra forward.
    /// Keep the request throttled so a dual-CDN outage cannot create a tight
    /// retry loop; the normal polling loop remains responsible for subsequent
    /// retries. No API path or payload is changed by this operation.
    /// </summary>
    public void RequestControlPlaneRecovery(int? targetedForwardId = null)
    {
        QueueConfigRefreshSoon(targetedForwardId);
        if (_cts is null || _cts.IsCancellationRequested)
            return;

        lock (_recoveryGate)
        {
            var now = DateTimeOffset.UtcNow;
            if (now - _lastRecoveryTriggerAtUtc < RecoveryTriggerThrottle)
                return;
            _lastRecoveryTriggerAtUtc = now;
        }

        TriggerImmediateSync();
    }

    private void RequestUltraRecovery(int forwardId, string reason)
    {
        if (_cts?.IsCancellationRequested == true) return;
        _ultraRecoveries[forwardId] = 0;
        Log.Warning("Ultra recovery queued for fresh policy and lease (forward={ForwardId}, reason={Reason})", forwardId, reason);
        QueueConfigRefreshSoon(forwardId);
        TriggerImmediateSync();
    }

    /// <summary>
    /// An idle (awaiting/lapsed) runtime needs a lease on its control channel,
    /// not a restart: make its renewal due and ask for a targeted full pull,
    /// which delivers through RefreshUltraLease when the contract is unchanged.
    /// The request goes through the throttled, backoff-gated recovery path, so
    /// a panel outage does not turn it into a retry storm.
    /// </summary>
    private void RequestUltraLeaseRefresh(int forwardId, string reason)
    {
        if (_cts?.IsCancellationRequested == true) return;
        _deliveredLeaseRenewals.TryRemove(forwardId, out _);
        Log.Information("Ultra lease delivery requested for idle forward {ForwardId} (reason={Reason})", forwardId, reason);
        RequestControlPlaneRecovery(forwardId);
    }

    /// <summary>
    /// The runtime reported a failure that suggests the relay no longer accepts
    /// this forward's provider credential. The source host invalidates a
    /// line's credential when it re-acquires the line (about every 20 min) and
    /// uploads the new one within seconds, so waiting for renew_after left the
    /// runtime on a dead credential for minutes (Windows forward 243,
    /// 2026-09-24). Fetch a fresh policy and lease for this forward now and
    /// deliver it on the control channel: a targeted full pull, reconciled
    /// through RefreshUltraLease when the contract is unchanged (no restart).
    /// <para>
    /// Paced per forward by <see cref="UltraFailureRenewalLimiter"/> so a burst
    /// of stream failures is one renewal. The immediate sync is skipped while
    /// the control plane is backing off (TriggerImmediateSync defers it); the
    /// queued targeted pull then runs when the backoff allows.
    /// </para>
    /// </summary>
    private void RequestFailureDrivenUltraRenewal(int forwardId, string kind)
    {
        var cts = _cts;
        if (cts?.IsCancellationRequested == true) return;
        if (!_failureRenewals.TryAdmit(forwardId, _timeProvider.GetUtcNow(), out var nextIn))
            return;
        Interlocked.Increment(ref _failureDrivenRenewals);
        _deliveredLeaseRenewals.TryRemove(forwardId, out _);
        QueueConfigRefreshSoon(forwardId);
        var backingOff = IsControlPlaneBackingOff;
        Log.Information(
            "Ultra runtime reported {Kind} for forward {ForwardId}; renewing its lease now (backing_off={BackingOff}, next failure-driven renewal in >= {NextSeconds:0}s)",
            kind,
            forwardId,
            backingOff,
            nextIn.TotalSeconds);
        // Not started (tests, shutdown): the queued pull is picked up by the
        // next SyncOnceAsync. Concurrent requests coalesce in SyncOnceAsync
        // (one running sync plus at most one pending re-run).
        if (cts is null) return;
        TriggerImmediateSync();
    }

    public async Task SyncOnceAsync(CancellationToken ct = default)
    {
        if (Interlocked.CompareExchange(ref _syncInProgress, 1, 0) != 0)
        {
            _pendingResync = true;
            return;
        }

        try
        {
            do
            {
                _pendingResync = false;
                await SyncOnceCoreAsync(ct);
            }
            while (_pendingResync && !ct.IsCancellationRequested);
        }
        finally
        {
            Interlocked.Exchange(ref _syncInProgress, 0);
        }
    }

    private async Task SyncOnceCoreAsync(CancellationToken ct)
    {
        var forcedFullPull = Interlocked.Exchange(ref _forceFullPull, 0) != 0;
        if (forcedFullPull)
            _lastConfigRevision = "";
        var targetedUltraRefreshes = forcedFullPull
            ? SnapshotTargetedUltraRefreshes()
            : new HashSet<int>();
        var sinceRevision = string.IsNullOrWhiteSpace(_lastConfigRevision) ? null : _lastConfigRevision;
        PullResponse pull;
        try
        {
            pull = await _api.PullConfigsAsync(sinceRevision, LongPollWaitSeconds, ct);
        }
        catch (OperationCanceledException) when (ct.IsCancellationRequested)
        {
            throw;
        }
        catch (Exception exception)
        {
            // Keep the forced-full-pull marker taken above for the retry, so
            // it still carries the full policy payload.
            if (forcedFullPull)
                Interlocked.Exchange(ref _forceFullPull, 1);
            if (DeviceLimitReachedException.TryFind(exception, out var limit))
                NotePullDeviceLimit(limit);
            else
                NotePullFailure(exception.GetType().Name);
            throw;
        }
        if (!pull.Ok)
        {
            Log.Warning("pull response ok=false");
            if (forcedFullPull)
                Interlocked.Exchange(ref _forceFullPull, 1);
            NotePullFailure("ok_false");
            return;
        }
        NotePullSuccess();

        if (pull.NotModified)
        {
            Log.Debug("config unchanged (revision {Revision})", pull.ConfigRevision);
            if (NeedsUltraPolicyRefresh())
            {
                // Ultra grants carry a short-lived session ticket. Refresh the
                // full policy before its renewal window so the next lease request
                // uses the current ticket rather than a stale one.
                QueueConfigRefreshSoon();
            }
            else
            {
                await RefreshUltraForwardsAsync(ct);
            }
            SavePullState("ok", _latestForwards.Count);
            // Re-publish the current snapshot so the GUI reflects liveness changes
            // discovered during lease refresh/reconciliation.
            ForwardsUpdated?.Invoke(_latestForwards);
            NativeOutboundUpdated?.Invoke(_latestNativeOutbound);
            return;
        }

        _lastConfigRevision = pull.ConfigRevision;
        if (pull.NextPollAfterMs > 0)
            _nextPollAfterMs = pull.NextPollAfterMs;

        if (pull.Client is not null)
        {
            _latestClient = pull.Client;
            UpdateRequests.Observe(pull.Client);
        }
        _runtimeManager.ApplyAuthoritativeAgentConfig(pull.Client?.AgentConfigJson);

        _latestNativeOutbound = pull.NativeOutbound;
        PublishClientUpdatePolicy(pull.ClientUpdate);

        var forwards = new List<ForwardConfig>();
        foreach (var forward in pull.Forwards)
        {
            // The capability is a pull-scoped, non-secret entitlement. Keep it
            // available to an explicit native-mode UI action without serializing
            // it as part of the forward or applying it automatically.
            forward.NativeOutbound = _latestNativeOutbound;
            if (forward.NativeOutbound?.FindForward(forward.ForwardId) is { } carrier)
                forward.NativeRouteScope = carrier.DefaultRouteScope;
            var acceptance = _forwardPolicy.Evaluate(forward);
            if (!acceptance.Accepted)
            {
                ForwardRejected?.Invoke(forward.ForwardId, acceptance.Notice);
                continue;
            }
            if (forward.ServiceStatus == "active")
                forwards.Add(forward);
        }
        RestoreLastKnownUltraPolicies(forwards);
        _latestForwards = forwards;

        // The fresh policy payload has now been received. A failed pull keeps
        // the targeted marker for the next retry; once the payload is known,
        // this reconciliation pass owns the marker and may queue it again if
        // the affected forward still needs recovery.
        AcknowledgeTargetedUltraRefreshes(targetedUltraRefreshes);

        SavePullState("ok", forwards.Count);

        // Determine which forwards to apply/remove
        var applied = _stateManager.LoadApplied();
        var activeIds = new HashSet<string>(forwards.Select(f => f.ForwardId.ToString()));
        var appliedIds = new HashSet<string>(applied.Keys);

        // Stop removed forwards
        foreach (var id in appliedIds.Except(activeIds))
        {
            if (int.TryParse(id, out var fid))
            {
                try
                {
                    _runtimeManager.StopForwardForPolicy(fid);
                    _stateManager.RemoveApplied(fid);
                }
                catch (Exception ex)
                {
                    Log.Error(ex, "failed stopping removed forward {ForwardId}", fid);
                }
            }
        }

        // Resolve and apply independent forwards concurrently, but keep a
        // bounded worker count so a large client cannot exhaust local threads
        // or sockets. Each task owns its own lease/error boundary.
        var prepared = 0;
        PreparationProgress?.Invoke(0, forwards.Count);
        await RunBoundedAsync(
            forwards,
            MaxConcurrentForwardOperations,
            async forward =>
            {
                await ReconcileForwardAsync(forward, applied, targetedUltraRefreshes, ct).ConfigureAwait(false);
                PreparationProgress?.Invoke(Interlocked.Increment(ref prepared), forwards.Count);
            },
            ct);

        if (_latestClient is not null)
            ClientUpdated?.Invoke(_latestClient);
        ForwardsUpdated?.Invoke(forwards);
        NativeOutboundUpdated?.Invoke(_latestNativeOutbound);
    }

    private void PublishClientUpdatePolicy(ClientUpdatePolicy? policy)
    {
        try
        {
            ClientUpdatePolicyReceived?.Invoke(policy);
        }
        catch (Exception exception)
        {
            // The gate is UI state; a faulty subscriber must not fail the pull.
            Log.Warning(exception, "client update policy handler failed");
        }
    }

    private async Task ReconcileForwardAsync(
        ForwardConfig forward,
        IReadOnlyDictionary<string, AppliedForwardState> applied,
        IReadOnlySet<int> targetedUltraRefreshes,
        CancellationToken ct)
    {
        var key = forward.ForwardId.ToString();
        var hasAppliedState = applied.TryGetValue(key, out var appliedState);
        var effectiveForward = EffectiveForwardConfigBuilder.Build(forward);
        var isRunning = _runtimeManager.IsForwardRunning(forward.ForwardId);
        if (ForwardRuntimeManager.IsNativeOutboundForward(effectiveForward))
        {
            // Native outbound has its own relay lifecycle. Remove any stale
            // ordinary Ultra state left by an earlier panel revision, but do
            // not resolve a normal Ultra lease or apply a direct listener.
            if (isRunning || hasAppliedState)
                _runtimeManager.StopForwardForPolicy(forward.ForwardId);
            if (hasAppliedState)
                _stateManager.RemoveApplied(forward.ForwardId);
            return;
        }
        if (hasAppliedState && appliedState!.UserStopped)
            return;

        if (string.Equals(effectiveForward.BackendMode, "ultra", StringComparison.OrdinalIgnoreCase))
        {
            // A grant-scope failure only invalidates the affected forward. A
            // forced full pull is still required to obtain a fresh policy
            // ticket, but re-probing every already-running Ultra forward can
            // stall control-plane liveness for up to the batch probe timeout.
            // Keep an unchanged, healthy sibling on its current runtime.
            if (targetedUltraRefreshes.Count > 0
                && !targetedUltraRefreshes.Contains(forward.ForwardId)
                && hasAppliedState
                && isRunning
                && !DeliveredLeaseRenewalDue(forward.ForwardId))
            {
                var currentSignature = _runtimeManager.ComputeContractSignature(effectiveForward);
                if (string.Equals(appliedState!.ContractSignature, currentSignature, StringComparison.Ordinal)
                    && !RecoveryNeedsRestart(effectiveForward))
                    return;
            }

            if (_leaseBackoff.IsBackingOff)
            {
                // The lease endpoint failed moments ago. A running runtime keeps
                // serving on its current (up to 30 min) lease; retry after the
                // jittered delay instead of on every re-armed pull.
                QueueConfigRefreshSoon(forward.ForwardId);
                Log.Debug("Ultra lease request for forward {ForwardId} deferred by lease backoff (running={Running})",
                    forward.ForwardId, isRunning);
                return;
            }

            IReadOnlyList<UltraLease> leases;
            try
            {
                leases = await _api.ResolveUltraLeasesAutoAsync(effectiveForward, ct);
                NoteLeaseAnswered();
            }
            catch (OperationCanceledException) when (ct.IsCancellationRequested)
            {
                throw;
            }
            catch (Exception ex)
            {
                Log.Warning(
                    "Ultra lease refresh failed for forward {ForwardId} (error={ErrorType}, status={StatusCode}, code={ErrorCode})",
                    forward.ForwardId,
                    ex.GetType().Name,
                    ex is MasterApiException apiException ? apiException.StatusCode : 0,
                    BoundErrorCode(ex.Message));
                // Record before asking for recovery, so the recovery request
                // sees the backoff and does not trigger an immediate re-sync.
                if (ShouldBackOffLease(ex))
                    NoteLeaseFailure(forward.ForwardId, ex);
                // A rotated grant/ticket (409 lease_scope_invalid and friends) is repaired by
                // the next config pull, never by tearing the forward down. Stopping it here
                // produced an endless start/stop loop - the panel re-mints the lease ticket
                // whenever the relay material rotates, and the forward was usually not yet
                // running at refresh time, so nothing could be "preserved" and the agent
                // restarted it over and over (the user-visible "Starting routes" flashing).
                if (IsGrantRefreshError(ex) || IsTransientControlPlaneError(ex))
                {
                    RecordRefusedPolicyGeneration(forward, ex);
                    RequestControlPlaneRecovery(forward.ForwardId);
                    Log.Information("queued immediate policy refresh after Ultra control-plane failure for forward {ForwardId}", forward.ForwardId);
                    return;
                }
                if (TryPreserveRunningUltra(
                        forward.ForwardId,
                        hasAppliedState,
                        isRunning,
                        "lease refresh exception"))
                {
                    return;
                }
                Log.Warning("Ultra lease unavailable for forward {ForwardId}; stopping because no running managed runtime can be preserved", forward.ForwardId);
                _runtimeManager.StopForwardForPolicy(forward.ForwardId);
                return;
            }
            if (leases.Count == 0)
            {
                // A cold forward has no applied state, so preservation cannot
                // schedule its retry. Grant readiness is not part of the stable
                // revision: retry the full payload on the next bounded poll,
                // rather than first waiting through a known-useless 304.
                if (effectiveForward.UltraAccess is null)
                    QueueConfigRefreshSoon(forward.ForwardId);
                // Name the forward in the refresh. A targeted refresh is the only
                // thing that bypasses the "config revision unchanged, skip" path, and
                // a forward can be refused a lease while the config revision stays
                // put: the panel re-mints the policy generation behind the same
                // revision. Without it the runtime kept the stale generation it was
                // started with and the forward stayed down until someone restarted
                // the client by hand.
                if (TryPreserveRunningUltra(forward.ForwardId, hasAppliedState, isRunning, "lease response unavailable", forward.ForwardId))
                    return;
                Log.Warning("Ultra lease unavailable for forward {ForwardId}; stopping because no running managed runtime can be preserved", forward.ForwardId);
                _runtimeManager.StopForwardForPolicy(forward.ForwardId);
                return;
            }
            effectiveForward.RuntimeLeases = leases.ToList();
            effectiveForward.RuntimeLease = effectiveForward.RuntimeLeases[0];
        }
        var contractSignature = _runtimeManager.ComputeContractSignature(effectiveForward);
        var signatureMatches = hasAppliedState
            && string.Equals(appliedState!.ContractSignature, contractSignature, StringComparison.Ordinal);

        if (hasAppliedState && isRunning && signatureMatches && !RecoveryNeedsRestart(effectiveForward))
        {
            if (string.Equals(effectiveForward.BackendMode, "ultra", StringComparison.OrdinalIgnoreCase))
            {
                var renewal = LeaseRenewalDeadline(effectiveForward);
                if (_runtimeManager.RefreshUltraLease(effectiveForward))
                    _deliveredLeaseRenewals[forward.ForwardId] = renewal;
                else if (!TryPreserveRunningUltra(forward.ForwardId, hasAppliedState, isRunning, "lease delivery failed"))
                    _runtimeManager.StopForwardForPolicy(forward.ForwardId);
            }
            return;
        }

        try
        {
            var renewal = LeaseRenewalDeadline(effectiveForward);
            _runtimeManager.ApplyForward(effectiveForward, contractSignature, "config_sync");
            _deliveredLeaseRenewals[forward.ForwardId] = renewal;
            _ultraRecoveries.TryRemove(forward.ForwardId, out _);
        }
        catch (OperationCanceledException) when (ct.IsCancellationRequested)
        {
            throw;
        }
        catch (Exception ex)
        {
            Log.Error(ex, "failed applying forward {ForwardId}", forward.ForwardId);
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
                // Runtime hosts are synchronous. Task.Yield retains a UI
                // context and can deadlock lease IPC during the initial pull.
                await Task.Run(() => action(item), ct).ConfigureAwait(false);
            }
            finally
            {
                gate.Release();
            }
        });
        await Task.WhenAll(tasks);
    }

    private async Task RefreshUltraForwardsAsync(CancellationToken ct)
    {
        var applied = _stateManager.LoadApplied();
        var ultraForwards = _latestForwards.Where(forward =>
            forward.ServiceStatus == "active"
            && string.Equals(forward.BackendMode, "ultra", StringComparison.OrdinalIgnoreCase)
            && !ForwardRuntimeManager.IsNativeOutboundForward(forward));
        await RunBoundedAsync(ultraForwards, MaxConcurrentForwardOperations,
            forward => RefreshUltraForwardAsync(forward, applied, ct), ct);
    }

    /// <summary>
    /// A policy generation the panel has already refused must never be reused.
    /// RestoreLastKnownUltraPolicies() recycles the last policy whenever a pull
    /// arrives without one, and it recycled the very generation the panel was
    /// rejecting: the panel derives the generation from the request alone, so it
    /// kept re-minting the dead value, every lease attempt answered 409
    /// policy_generation_invalid, and the forward stayed enabled with no lease
    /// for ever. Both lease paths must remember the refusal - the full pull
    /// reconciles through ReconcileForwardAsync, and a 304 long poll refreshes
    /// through RefreshUltraForwardAsync.
    /// </summary>
    private void RecordRefusedPolicyGeneration(ForwardConfig forward, Exception exception)
    {
        if (exception is MasterApiException refused
            && refused.StatusCode == 409
            && (refused.Message ?? string.Empty).Contains("policy_generation_invalid", StringComparison.OrdinalIgnoreCase)
            && forward.UltraAccess is { } refusedPolicy)
        {
            _rejectedPolicyGenerations[forward.ForwardId] = refusedPolicy.PolicyGeneration;
        }
    }

    private async Task RefreshUltraForwardAsync(
        ForwardConfig forward,
        IReadOnlyDictionary<string, AppliedForwardState> applied,
        CancellationToken ct)
    {
        var key = forward.ForwardId.ToString();
        if (applied.TryGetValue(key, out var appliedState) && appliedState.UserStopped)
            return;

        var effectiveForward = EffectiveForwardConfigBuilder.Build(forward);
        if (ForwardRuntimeManager.IsNativeOutboundForward(effectiveForward))
            return;
        var isRunning = _runtimeManager.IsForwardRunning(forward.ForwardId);
        if (isRunning
            && effectiveForward.UltraAccess is { } policy
            && policy.RenewAfter > DateTimeOffset.UtcNow
            && !DeliveredLeaseRenewalDue(forward.ForwardId))
            return;

        if (_leaseBackoff.IsBackingOff)
        {
            Log.Debug("Ultra lease renewal for forward {ForwardId} deferred by lease backoff", forward.ForwardId);
            return;
        }

        IReadOnlyList<UltraLease> leases;
        try
        {
            leases = await _api.ResolveUltraLeasesAutoAsync(effectiveForward, ct);
            NoteLeaseAnswered();
        }
        catch (OperationCanceledException) when (ct.IsCancellationRequested)
        {
            throw;
        }
        catch (Exception ex)
        {
            Log.Warning(
                "Ultra lease refresh failed for forward {ForwardId}; preserving current runtime (error={ErrorType}, status={StatusCode}, code={ErrorCode})",
                forward.ForwardId,
                ex.GetType().Name,
                ex is MasterApiException apiException ? apiException.StatusCode : 0,
                BoundErrorCode(ex.Message));
            if (ShouldBackOffLease(ex))
                NoteLeaseFailure(forward.ForwardId, ex);
            if (IsGrantRefreshError(ex) || IsTransientControlPlaneError(ex))
            {
                RecordRefusedPolicyGeneration(forward, ex);
                RequestControlPlaneRecovery(forward.ForwardId);
                Log.Information("queued immediate policy refresh after Ultra control-plane failure for forward {ForwardId}", forward.ForwardId);
            }
            return;
        }

        if (leases.Count == 0)
        {
            if (TryPreserveRunningUltra(forward.ForwardId, appliedState != null, isRunning, "lease response unavailable", forward.ForwardId))
                return;
            Log.Warning("Ultra lease unavailable for forward {ForwardId}; stopping because no running managed runtime can be preserved", forward.ForwardId);
            _runtimeManager.StopForwardForPolicy(forward.ForwardId);
            return;
        }

        effectiveForward.RuntimeLeases = leases.ToList();
        effectiveForward.RuntimeLease = effectiveForward.RuntimeLeases[0];
        var contractSignature = _runtimeManager.ComputeContractSignature(effectiveForward);
        var signatureMatches = appliedState != null
            && string.Equals(appliedState.ContractSignature, contractSignature, StringComparison.Ordinal);

        if (appliedState != null && isRunning && signatureMatches && !RecoveryNeedsRestart(effectiveForward))
        {
            var renewal = LeaseRenewalDeadline(effectiveForward);
            if (_runtimeManager.RefreshUltraLease(effectiveForward))
            {
                _deliveredLeaseRenewals[forward.ForwardId] = renewal;
                _rejectedPolicyGenerations.TryRemove(forward.ForwardId, out _);
            }
            else if (!TryPreserveRunningUltra(forward.ForwardId, true, isRunning, "lease delivery failed"))
                _runtimeManager.StopForwardForPolicy(forward.ForwardId);
            return;
        }

        try
        {
            var renewal = LeaseRenewalDeadline(effectiveForward);
            _runtimeManager.ApplyForward(effectiveForward, contractSignature, "config_sync");
            _deliveredLeaseRenewals[forward.ForwardId] = renewal;
            _ultraRecoveries.TryRemove(forward.ForwardId, out _);
        }
        catch (OperationCanceledException) when (ct.IsCancellationRequested)
        {
            throw;
        }
        catch (Exception ex)
        {
            Log.Error(ex, "failed applying forward {ForwardId} after lease refresh", forward.ForwardId);
        }
    }

    // A full pull can extend the *desired* policy while a targeted sibling
    // recovery skips delivery to this runtime. Only an acknowledged delivery
    // may move its renewal deadline; otherwise repeated sibling failures can
    // starve a healthy forward until its actual runtime lease expires.
    private bool DeliveredLeaseRenewalDue(int forwardId) =>
        !_deliveredLeaseRenewals.TryGetValue(forwardId, out var deadline) ||
        deadline <= _timeProvider.GetUtcNow();

    private DateTimeOffset LeaseRenewalDeadline(ForwardConfig forward)
    {
        var expiry = forward.RuntimeLeases.Select(lease => lease.ExpiresAt)
            .Append(forward.UltraAccess?.ExpiresAt ?? _timeProvider.GetUtcNow()).Min();
        // Leave room for one long poll and the bounded forwarding probe.
        var deadline = expiry.AddMinutes(-1);
        if (forward.UltraAccess is { } policy && policy.RenewAfter != default && policy.RenewAfter < deadline)
            deadline = policy.RenewAfter;
        return deadline;
    }

    private bool RecoveryNeedsRestart(ForwardConfig forward)
    {
        if (!_ultraRecoveries.ContainsKey(forward.ForwardId)) return false;
        if (!_runtimeManager.IsForwardReady(forward)) return true;
        _ultraRecoveries.TryRemove(forward.ForwardId, out _);
        return false;
    }

    private bool NeedsUltraPolicyRefresh()
    {
        var now = DateTimeOffset.UtcNow;
        return _latestForwards.Any(forward =>
        {
            if (!string.Equals(forward.BackendMode, "ultra", StringComparison.OrdinalIgnoreCase))
                return false;

            // Native outbound forwards have no UltraAccess policy by design: the
            // entitlement is the NativeOutbound capability and their grants are
            // minted by the native path. Reading their absent policy as "policy
            // expired" forced a full config pull on every single poll (the field
            // log shows one every ~16 s, ~39k pulls per 200k panel requests).
            if (ForwardRuntimeManager.IsNativeOutboundForward(forward))
                return false;

            var policy = forward.UltraAccess;
            if (policy is null
                || !string.Equals(policy.ProtocolValue, UltraPolicy.Protocol, StringComparison.Ordinal)
                || policy.ExpiresAt <= now)
                return true;

            // Current policy-v2 grants carry the broker's exact renewal point.
            // Older payloads omitted renew_after, so retain an expiry fallback.
            return policy.RenewAfter == default
                ? policy.ExpiresAt <= now.AddMinutes(2)
                : policy.RenewAfter <= now;
        });
    }

    private static string BoundErrorCode(string message)
    {
        var code = new string((message ?? string.Empty)
            .ToLowerInvariant()
            .Where(character => character is >= 'a' and <= 'z' or >= '0' and <= '9' or '_' or '-')
            .Take(96)
            .ToArray());
        return code.Length == 0 ? "unknown" : code;
    }

    // A device-limit refusal is not an outage, but lease retries must still be
    // paced: the next config pull hits the same refusal and holds the loop.
    private static bool ShouldBackOffLease(Exception exception)
        => ControlPlaneBackoff.IsControlPlaneFailure(exception)
            || DeviceLimitReachedException.TryFind(exception, out _);

    private static bool IsGrantRefreshError(Exception exception)
    {
        if (exception is not MasterApiException apiException || apiException.StatusCode != 409)
            return false;

        var code = (apiException.Message ?? string.Empty).Trim().ToLowerInvariant();
        // lease_replayed belongs here: the panel refuses a nonce it has already
        // consumed, which happens when two refreshes race or the first request
        // timed out after the panel committed it. The next config pull re-mints
        // the ticket, so the forward recovers - tearing it down instead left the
        // user's route stopped until the client was restarted by hand.
        return code is "lease_scope_invalid"
            or "lease_replayed"
            or "policy_generation_invalid"
            or "policy_scope_invalid"
            or "policy_unavailable"
            or "no_approved_candidate"
            or "quota_exhausted";
    }

    // Transport errors, gateway errors and timeouts (a lease POST that times
    // out on every mirror surfaces as TimeoutException) preserve the forward and
    // ask for a fresh pull. The backoff itself uses the wider
    // ControlPlaneBackoff.IsControlPlaneFailure set (any 5xx, 429).
    private static bool IsTransientControlPlaneError(Exception exception)
        => exception is HttpRequestException or TimeoutException
            || (exception is MasterApiException apiException
                && apiException.StatusCode is 502 or 503 or 504 or 530);

    private void QueueConfigRefreshSoon(int? targetedForwardId = null)
    {
        if (targetedForwardId is > 0)
            _targetedUltraRefreshes[targetedForwardId.Value] = 0;
        Interlocked.Exchange(ref _forceFullPull, 1);
        _lastConfigRevision = "";
        _nextPollAfterMs = _nextPollAfterMs > 0
            ? Math.Min(_nextPollAfterMs, ReArmDelayMs)
            : ReArmDelayMs;
    }

    private bool TryPreserveRunningUltra(
        int forwardId,
        bool hasAppliedState,
        bool isRunning,
        string reason,
        int? targetedForwardId = null)
    {
        if (!hasAppliedState || !isRunning)
            return false;

        QueueConfigRefreshSoon(targetedForwardId);
        Log.Warning(
            "Ultra lease unavailable for forward {ForwardId}; preserving current runtime and retrying config sync (reason={Reason})",
            forwardId,
            reason);
        return true;
    }

    private void RestoreLastKnownUltraPolicies(List<ForwardConfig> forwards)
    {
        if (_latestForwards.Count == 0)
            return;

        var now = DateTimeOffset.UtcNow;
        var previousById = _latestForwards
            .Where(forward => forward.UltraAccess is not null)
            .GroupBy(forward => forward.ForwardId)
            .ToDictionary(group => group.Key, group => group.First());

        foreach (var forward in forwards)
        {
            if (forward.UltraAccess is not null
                || !string.Equals(forward.BackendMode, "ultra", StringComparison.OrdinalIgnoreCase)
                || ForwardRuntimeManager.IsNativeOutboundForward(forward)
                || !_runtimeManager.IsForwardRunning(forward.ForwardId)
                || !previousById.TryGetValue(forward.ForwardId, out var previous)
                || previous.UltraAccess is not { } previousPolicy
                || !string.Equals(previousPolicy.Transport, forward.Transport, StringComparison.OrdinalIgnoreCase)
                || previousPolicy.GetValidationFailure(forward, now) is not null)
                continue;

            // Never resurrect a generation the panel has already refused.
            if (_rejectedPolicyGenerations.TryGetValue(forward.ForwardId, out var refusedGeneration)
                && string.Equals(refusedGeneration, previousPolicy.PolicyGeneration, StringComparison.Ordinal))
            {
                continue;
            }

            forward.UltraAccess = previousPolicy;
            QueueConfigRefreshSoon(forward.ForwardId);
            Log.Warning(
                "Ultra policy missing for running forward {ForwardId}; reusing last valid policy until {ExpiresAt} and forcing full config refresh",
                forward.ForwardId,
                previousPolicy.ExpiresAt);
        }
    }

    private IReadOnlySet<int> SnapshotTargetedUltraRefreshes()
    {
        var result = new HashSet<int>();
        foreach (var forwardId in _targetedUltraRefreshes.Keys)
            result.Add(forwardId);
        return result;
    }

    private void AcknowledgeTargetedUltraRefreshes(IReadOnlySet<int> targetedForwardIds)
    {
        foreach (var forwardId in targetedForwardIds)
            _targetedUltraRefreshes.TryRemove(forwardId, out _);
    }

    private void SavePullState(string status, int forwardsTotal)
    {
        var pullState = new PullState
        {
            Status = status,
            LastAttemptAt = DateTimeOffset.UtcNow.ToUnixTimeMilliseconds() / 1000.0,
            LastSuccessAt = DateTimeOffset.UtcNow.ToUnixTimeMilliseconds() / 1000.0,
            AgentVersion = MasterApiClient.AgentVersion,
            ForwardsTotal = forwardsTotal,
            AppliedForwardIds = _latestForwards.Select(f => f.ForwardId).ToList(),
        };
        _stateManager.SavePullState(pullState);
        PullStateChanged?.Invoke(pullState);
    }

    private void SavePullError(Exception ex)
    {
        var pullState = new PullState
        {
            Status = "error",
            LastAttemptAt = DateTimeOffset.UtcNow.ToUnixTimeMilliseconds() / 1000.0,
            LastError = ex.Message,
            AgentVersion = MasterApiClient.AgentVersion,
        };
        _stateManager.SavePullState(pullState);
        PullStateChanged?.Invoke(pullState);
    }
}
