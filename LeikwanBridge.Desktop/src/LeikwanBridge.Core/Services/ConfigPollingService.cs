using LeikwanBridge.Core.Helpers;
using LeikwanBridge.Core.Models;
using Serilog;

namespace LeikwanBridge.Core.Services;

/// <summary>
/// Polls master for forward configs every N seconds.
/// Port of client_agent.py sync_once() + run() loop.
/// </summary>
public sealed class ConfigPollingService
{
    private readonly MasterApiClient _api;
    private readonly ForwardRuntimeManager _runtimeManager;
    private readonly StateManager _stateManager;

    private List<ForwardConfig> _latestForwards = [];
    private CancellationTokenSource? _cts;
    private Task? _pollingTask;

    public event Action<List<ForwardConfig>>? ForwardsUpdated;
    public event Action<PullState>? PullStateChanged;
    public event Action<Exception>? SyncError;

    public List<ForwardConfig> LatestForwards => _latestForwards;

    public ConfigPollingService(MasterApiClient api, ForwardRuntimeManager runtimeManager, StateManager stateManager)
    {
        _api = api;
        _runtimeManager = runtimeManager;
        _stateManager = stateManager;
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
                try { await Task.Delay(TimeSpan.FromSeconds(intervalSeconds), ct); }
                catch (OperationCanceledException) { break; }
            }
        }, ct);
    }

    public void Stop()
    {
        _cts?.Cancel();
    }

    public async Task SyncOnceAsync(CancellationToken ct = default)
    {
        var pull = await _api.PullConfigsAsync(ct);
        if (!pull.Ok)
        {
            Log.Warning("pull response ok=false");
            return;
        }

        _runtimeManager.ApplyAuthoritativeAgentConfig(pull.Client?.AgentConfigJson);

        var forwards = WindowsForwardPolicy.FilterSupportedForwards(pull.Forwards)
            .Where(f => f.ServiceStatus == "active")
            .ToList();
        _latestForwards = forwards;

        var pullState = new PullState
        {
            Status = "ok",
            LastAttemptAt = DateTimeOffset.UtcNow.ToUnixTimeMilliseconds() / 1000.0,
            LastSuccessAt = DateTimeOffset.UtcNow.ToUnixTimeMilliseconds() / 1000.0,
            AgentVersion = MasterApiClient.AgentVersion,
            ForwardsTotal = forwards.Count,
            AppliedForwardIds = forwards.Select(f => f.ForwardId).ToList(),
        };
        _stateManager.SavePullState(pullState);
        PullStateChanged?.Invoke(pullState);

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
                    _runtimeManager.StopForward(fid);
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
            if (applied.TryGetValue(key, out var appliedState) && appliedState.UserStopped)
                continue;

            if (!applied.ContainsKey(key) || !_runtimeManager.IsForwardRunning(forward.ForwardId))
            {
                try
                {
                    _runtimeManager.ApplyForward(forward);
                }
                catch (Exception ex)
                {
                    Log.Error(ex, "failed applying forward {ForwardId}", forward.ForwardId);
                }
            }
        }

        ForwardsUpdated?.Invoke(forwards);
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
