using LeikwanBridge.Core.Helpers;
using LeikwanBridge.Core.Models;
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
    private ClientMeta? _latestClient;
    private NativeOutboundCapability? _latestNativeOutbound;
    private DateTimeOffset _lastRecoveryTriggerAtUtc = DateTimeOffset.MinValue;
    private static readonly TimeSpan RecoveryTriggerThrottle = TimeSpan.FromSeconds(10);

    public event Action<List<ForwardConfig>>? ForwardsUpdated;
    public event Action<PullState>? PullStateChanged;
    public event Action<Exception>? SyncError;
    public event Action<int, string>? ForwardRejected;
    public event Action<ClientMeta>? ClientUpdated;
    public event Action<NativeOutboundCapability?>? NativeOutboundUpdated;
    public event Action<int, int>? PreparationProgress;

    public List<ForwardConfig> LatestForwards => _latestForwards;
    public ClientMeta? LatestClient => _latestClient;
    public NativeOutboundCapability? LatestNativeOutbound => _latestNativeOutbound;

    public ConfigPollingService(
        MasterApiClient api,
        ForwardRuntimeManager runtimeManager,
        StateManager stateManager,
        IForwardAcceptancePolicy? forwardPolicy = null)
    {
        _api = api;
        _runtimeManager = runtimeManager;
        _stateManager = stateManager;
        _forwardPolicy = forwardPolicy ?? WindowsForwardAcceptancePolicy.Instance;
        _runtimeManager.UltraRecoveryRequested += RequestUltraRecovery;
    }

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
                try { await Task.Delay(TimeSpan.FromMilliseconds(delayMs), ct); }
                catch (OperationCanceledException) { break; }
            }
        }, ct);
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
        var pull = await _api.PullConfigsAsync(sinceRevision, LongPollWaitSeconds, ct);
        if (!pull.Ok)
        {
            Log.Warning("pull response ok=false");
            return;
        }

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
        }
        _runtimeManager.ApplyAuthoritativeAgentConfig(pull.Client?.AgentConfigJson);

        _latestNativeOutbound = pull.NativeOutbound;

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
                && isRunning)
            {
                var currentSignature = _runtimeManager.ComputeContractSignature(effectiveForward);
                if (string.Equals(appliedState!.ContractSignature, currentSignature, StringComparison.Ordinal)
                    && !RecoveryNeedsRestart(effectiveForward))
                    return;
            }

            IReadOnlyList<UltraLease> leases;
            try
            {
                leases = await _api.ResolveUltraLeasesAutoAsync(effectiveForward, ct);
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
                // A rotated grant/ticket (409 lease_scope_invalid and friends) is repaired by
                // the next config pull, never by tearing the forward down. Stopping it here
                // produced an endless start/stop loop - the panel re-mints the lease ticket
                // whenever the relay material rotates, and the forward was usually not yet
                // running at refresh time, so nothing could be "preserved" and the agent
                // restarted it over and over (the user-visible "Starting routes" flashing).
                if (IsGrantRefreshError(ex) || IsTransientControlPlaneError(ex))
                {
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
            if (string.Equals(effectiveForward.BackendMode, "ultra", StringComparison.OrdinalIgnoreCase)
                && !_runtimeManager.RefreshUltraLease(effectiveForward)
                && !TryPreserveRunningUltra(forward.ForwardId, hasAppliedState, isRunning, "lease delivery failed"))
                _runtimeManager.StopForwardForPolicy(forward.ForwardId);
            return;
        }

        try
        {
            _runtimeManager.ApplyForward(effectiveForward, contractSignature, "config_sync");
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
            && policy.RenewAfter > DateTimeOffset.UtcNow)
            return;

        IReadOnlyList<UltraLease> leases;
        try
        {
            leases = await _api.ResolveUltraLeasesAutoAsync(effectiveForward, ct);
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
            if (IsGrantRefreshError(ex) || IsTransientControlPlaneError(ex))
            {
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
            if (!_runtimeManager.RefreshUltraLease(effectiveForward)
                && !TryPreserveRunningUltra(forward.ForwardId, true, isRunning, "lease delivery failed"))
                _runtimeManager.StopForwardForPolicy(forward.ForwardId);
            return;
        }

        try
        {
            _runtimeManager.ApplyForward(effectiveForward, contractSignature, "config_sync");
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

    private static bool IsTransientControlPlaneError(Exception exception)
        => exception is HttpRequestException
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
