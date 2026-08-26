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
    private const int ReArmDelayMs = 500;

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
    private volatile bool _pendingResync;
    private ClientMeta? _latestClient;

    public event Action<List<ForwardConfig>>? ForwardsUpdated;
    public event Action<PullState>? PullStateChanged;
    public event Action<Exception>? SyncError;
    public event Action<int, string>? ForwardRejected;
    public event Action<ClientMeta>? ClientUpdated;

    public List<ForwardConfig> LatestForwards => _latestForwards;
    public ClientMeta? LatestClient => _latestClient;

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
                catch (OperationCanceledException) { break; }
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
        _ = SyncOnceAsync();
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
                _lastConfigRevision = "";
            }
            else
            {
                await RefreshUltraForwardsAsync(ct);
            }
            SavePullState("ok", _latestForwards.Count);
            // Re-publish the current snapshot so the GUI reflects liveness changes
            // discovered during lease refresh/reconciliation.
            ForwardsUpdated?.Invoke(_latestForwards);
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

        var forwards = new List<ForwardConfig>();
        foreach (var forward in pull.Forwards)
        {
            var acceptance = _forwardPolicy.Evaluate(forward);
            if (!acceptance.Accepted)
            {
                ForwardRejected?.Invoke(forward.ForwardId, acceptance.Notice);
                continue;
            }
            if (forward.ServiceStatus == "active")
                forwards.Add(forward);
        }
        _latestForwards = forwards;

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

        // Apply new/changed forwards
        foreach (var forward in forwards)
        {
            var key = forward.ForwardId.ToString();
            var hasAppliedState = applied.TryGetValue(key, out var appliedState);
            if (hasAppliedState && appliedState!.UserStopped)
                continue;

            var effectiveForward = EffectiveForwardConfigBuilder.Build(forward);
            if (string.Equals(effectiveForward.BackendMode, "ultra", StringComparison.OrdinalIgnoreCase))
            {
                UltraLease? lease;
                try
                {
                    lease = await _api.ResolveUltraLeaseAsync(effectiveForward, ct);
                }
                catch (OperationCanceledException) when (ct.IsCancellationRequested)
                {
                    throw;
                }
                catch (Exception ex)
                {
                    Log.Warning("Ultra lease refresh failed for forward {ForwardId}; stopping by Panel policy (error={ErrorType})", forward.ForwardId, ex.GetType().Name);
                    _runtimeManager.StopForwardForPolicy(forward.ForwardId);
                    continue;
                }
                if (lease is null)
                {
                    _runtimeManager.StopForwardForPolicy(forward.ForwardId);
                    continue;
                }
                effectiveForward.RuntimeLease = lease;
            }
            var contractSignature = _runtimeManager.ComputeContractSignature(effectiveForward);
            var isRunning = _runtimeManager.IsForwardRunning(forward.ForwardId);
            var signatureMatches = hasAppliedState
                && string.Equals(appliedState!.ContractSignature, contractSignature, StringComparison.Ordinal);

            if (hasAppliedState && isRunning && signatureMatches)
            {
                if (string.Equals(effectiveForward.BackendMode, "ultra", StringComparison.OrdinalIgnoreCase))
                {
                    if (!_runtimeManager.RefreshUltraLease(effectiveForward))
                        _runtimeManager.StopForwardForPolicy(forward.ForwardId);
                }
                continue;
            }

            try
            {
                _runtimeManager.ApplyForward(effectiveForward, contractSignature, "config_sync");
            }
            catch (Exception ex)
            {
                Log.Error(ex, "failed applying forward {ForwardId}", forward.ForwardId);
            }
        }

        if (_latestClient is not null)
            ClientUpdated?.Invoke(_latestClient);
        ForwardsUpdated?.Invoke(forwards);
    }

    private async Task RefreshUltraForwardsAsync(CancellationToken ct)
    {
        var applied = _stateManager.LoadApplied();
        foreach (var forward in _latestForwards)
        {
            if (forward.ServiceStatus != "active"
                || !string.Equals(forward.BackendMode, "ultra", StringComparison.OrdinalIgnoreCase))
                continue;

            var key = forward.ForwardId.ToString();
            if (applied.TryGetValue(key, out var appliedState) && appliedState.UserStopped)
                continue;

            var effectiveForward = EffectiveForwardConfigBuilder.Build(forward);
            UltraLease? lease;
            try
            {
                lease = await _api.ResolveUltraLeaseAsync(effectiveForward, ct);
            }
            catch (OperationCanceledException) when (ct.IsCancellationRequested)
            {
                throw;
            }
            catch (Exception ex)
            {
                // Keep a currently-running runtime alive through transient API failures;
                // retry on the next long-poll cycle rather than revoking it immediately.
                Log.Warning("Ultra lease refresh failed for forward {ForwardId}; preserving current runtime (error={ErrorType})", forward.ForwardId, ex.GetType().Name);
                continue;
            }

            if (lease is null)
            {
                _runtimeManager.StopForwardForPolicy(forward.ForwardId);
                continue;
            }

            effectiveForward.RuntimeLease = lease;
            var contractSignature = _runtimeManager.ComputeContractSignature(effectiveForward);
            var isRunning = _runtimeManager.IsForwardRunning(forward.ForwardId);
            var signatureMatches = appliedState != null
                && string.Equals(appliedState.ContractSignature, contractSignature, StringComparison.Ordinal);

            if (appliedState != null && isRunning && signatureMatches)
            {
                if (!_runtimeManager.RefreshUltraLease(effectiveForward))
                    _runtimeManager.StopForwardForPolicy(forward.ForwardId);
                continue;
            }

            try
            {
                _runtimeManager.ApplyForward(effectiveForward, contractSignature, "config_sync");
            }
            catch (Exception ex)
            {
                Log.Error(ex, "failed applying forward {ForwardId} after lease refresh", forward.ForwardId);
            }
        }
    }

    private bool NeedsUltraPolicyRefresh()
    {
        var refreshBefore = DateTimeOffset.UtcNow.AddMinutes(2);
        return _latestForwards.Any(forward =>
            string.Equals(forward.BackendMode, "ultra", StringComparison.OrdinalIgnoreCase)
            && (forward.UltraAccess is null || forward.UltraAccess.ExpiresAt <= refreshBefore));
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
