using Serilog;

namespace LeikwanBridge.Core.Services;

/// <summary>Hands a verified artifact to the detached apply step.</summary>
public interface IHeadlessUpdateLauncher
{
    Task<bool> LaunchAsync(string stagedFile, string version, CancellationToken ct);
}

public sealed record HeadlessUpdateCycleResult(
    HeadlessUpdateDecisionKind Decision,
    string? TargetVersion,
    bool Launched,
    string Detail);

/// <summary>
/// One cycle of the service-mode update loop: reconcile the state file, check
/// that an update was requested from the console, read the published manifest,
/// decide, download + verify, record the attempt, then launch the detached
/// apply step. Publishing a release never updates a client on its own: without
/// a pending, not yet handled console request the cycle stops before the
/// manifest is read. Every exit path leaves the state file describing what
/// happened, and a failed attempt always leaves a backoff.
/// </summary>
public sealed class HeadlessAutoUpdateCoordinator
{
    private readonly string _installedVersion;
    private readonly string _statePath;
    private readonly string _lockPath;
    private readonly Func<bool> _autoUpdatesEnabled;
    private readonly Func<string?> _requestToken;
    private readonly IClientUpdateSource _source;
    private readonly IHeadlessUpdateLauncher _launcher;
    private readonly Func<DateTimeOffset> _clock;

    public HeadlessAutoUpdateCoordinator(
        string installedVersion,
        string statePath,
        string lockPath,
        Func<bool> autoUpdatesEnabled,
        Func<string?> requestToken,
        IClientUpdateSource source,
        IHeadlessUpdateLauncher launcher,
        Func<DateTimeOffset>? clock = null)
    {
        _installedVersion = installedVersion;
        _statePath = statePath;
        _lockPath = lockPath;
        _autoUpdatesEnabled = autoUpdatesEnabled;
        _requestToken = requestToken;
        _source = source;
        _launcher = launcher;
        _clock = clock ?? (() => DateTimeOffset.UtcNow);
    }

    public string StatePath => _statePath;

    public HeadlessUpdateState LoadState() => HeadlessUpdateStateStore.Load(_statePath);

    public async Task<HeadlessUpdateCycleResult> RunCycleAsync(CancellationToken ct)
    {
        var now = _clock();
        var state = HeadlessUpdateStateStore.Load(_statePath);
        if (HeadlessUpdatePolicy.Reconcile(state, _installedVersion, now))
            TrySave(state);

        bool enabled;
        try
        {
            enabled = _autoUpdatesEnabled();
        }
        catch (Exception exception)
        {
            // Unreadable config: fail safe toward "do nothing this cycle".
            Log.Warning("headless_update_config_unreadable type={ErrorType}", exception.GetType().Name);
            return new(HeadlessUpdateDecisionKind.Disabled, null, false, "config unreadable");
        }

        if (!enabled)
        {
            Log.Information("headless_update_skipped reason=disabled installed={Installed}", _installedVersion);
            return new(HeadlessUpdateDecisionKind.Disabled, null, false, "disable_auto_updates is set");
        }

        string requestToken;
        try
        {
            requestToken = (_requestToken() ?? string.Empty).Trim();
        }
        catch (Exception exception)
        {
            Log.Warning("headless_update_request_unreadable type={ErrorType}", exception.GetType().Name);
            requestToken = string.Empty;
        }

        if (!HeadlessUpdatePolicy.IsUpdateRequested(requestToken, state))
        {
            Log.Information("headless_update_skipped reason=not_requested installed={Installed}", _installedVersion);
            return new(HeadlessUpdateDecisionKind.NotRequested, null, false, "no update requested from the console");
        }

        Log.Information("headless_update_requested installed={Installed}", _installedVersion);
        PublishedClientUpdate? published;
        try
        {
            published = await _source.ReadPublishedAsync(ct).ConfigureAwait(false);
        }
        catch (OperationCanceledException) when (ct.IsCancellationRequested)
        {
            throw;
        }
        catch (Exception exception)
        {
            // Not an attempt: the panel being unreachable says nothing about
            // the artifact, and the next regular cycle retries.
            Log.Warning("headless_update_manifest_unavailable installed={Installed} type={ErrorType}", _installedVersion, exception.GetType().Name);
            state.LastCheckAt = now;
            TrySave(state);
            return new(HeadlessUpdateDecisionKind.ManifestUnavailable, null, false, exception.GetType().Name);
        }

        state.LastCheckAt = now;
        var decision = HeadlessUpdatePolicy.Decide(true, _installedVersion, published, state, now);
        if (decision.Kind != HeadlessUpdateDecisionKind.Apply)
        {
            // A launched apply is not marked handled: the restarted build sees
            // itself up to date on its next cycle and completes the request
            // there, so an apply step that fails still retries under the backoff.
            if (HeadlessUpdatePolicy.CompletesRequest(decision.Kind))
                state.HandledRequestToken = requestToken;
            TrySave(state);
            // Expected steady states log at Information; anything that means
            // "the published build will not be installed" logs at Warning.
            var level = decision.Kind is HeadlessUpdateDecisionKind.UpToDate
                    or HeadlessUpdateDecisionKind.BackingOff
                    or HeadlessUpdateDecisionKind.ApplyInProgress
                ? Serilog.Events.LogEventLevel.Information
                : Serilog.Events.LogEventLevel.Warning;
            Log.Write(
                level,
                "headless_update_skipped reason={Decision} installed={Installed} published={Published} detail={Detail}",
                decision.Kind,
                _installedVersion,
                published?.Version ?? "none",
                decision.Reason);
            return new(decision.Kind, decision.TargetVersion, false, decision.Reason);
        }

        var target = published!;
        using (var applyLock = UpdateApplyLock.TryAcquire(_lockPath))
        {
            if (applyLock is null)
            {
                Log.Information("headless_update_skipped reason=apply_locked installed={Installed} published={Published}", _installedVersion, target.Version);
                TrySave(state);
                return new(HeadlessUpdateDecisionKind.ApplyInProgress, target.Version, false, "another update holds the apply lock");
            }

            Log.Information(
                "headless_update_available installed={Installed} published={Published} size={Size}",
                _installedVersion,
                target.Version,
                target.SizeBytes);

            string stagedFile;
            try
            {
                stagedFile = await _source.DownloadVerifiedAsync(target, ct).ConfigureAwait(false);
            }
            catch (OperationCanceledException) when (ct.IsCancellationRequested)
            {
                throw;
            }
            catch (ClientUpdateVerificationException exception)
            {
                HeadlessUpdatePolicy.RecordFailure(state, target.Version, HeadlessUpdateResults.VerifyFailed, exception.Message, _clock());
                TrySave(state);
                Log.Warning(
                    "headless_update_verify_failed published={Published} attempts={Attempts} next_retry_at={NextRetry} detail={Detail}",
                    target.Version,
                    state.Attempts,
                    state.NextRetryAt,
                    state.LastError);
                return new(HeadlessUpdateDecisionKind.Apply, target.Version, false, HeadlessUpdateResults.VerifyFailed);
            }
            catch (Exception exception)
            {
                HeadlessUpdatePolicy.RecordFailure(state, target.Version, HeadlessUpdateResults.DownloadFailed, exception.GetType().Name, _clock());
                TrySave(state);
                Log.Warning(
                    "headless_update_download_failed published={Published} attempts={Attempts} next_retry_at={NextRetry} type={ErrorType}",
                    target.Version,
                    state.Attempts,
                    state.NextRetryAt,
                    exception.GetType().Name);
                return new(HeadlessUpdateDecisionKind.Apply, target.Version, false, HeadlessUpdateResults.DownloadFailed);
            }

            Log.Information("headless_update_verified published={Published} sha256_prefix={Sha}", target.Version, Prefix(target.Sha256));

            // Count the attempt and schedule its retry before the service is
            // touched: if the apply step or the new build dies in any way, the
            // next cycle backs off instead of looping.
            HeadlessUpdatePolicy.RecordApplyStarted(state, target.Version, _clock());
            if (!TrySave(state))
            {
                Log.Warning("headless_update_aborted reason=state_not_persisted published={Published}", target.Version);
                return new(HeadlessUpdateDecisionKind.Apply, target.Version, false, "update state could not be persisted");
            }

            // The detached step takes the lock itself; release ours first.
            applyLock.Dispose();

            bool launched;
            try
            {
                launched = await _launcher.LaunchAsync(stagedFile, target.Version, ct).ConfigureAwait(false);
            }
            catch (Exception exception) when (exception is not OperationCanceledException || !ct.IsCancellationRequested)
            {
                Log.Warning("headless_update_launch_error published={Published} type={ErrorType}", target.Version, exception.GetType().Name);
                launched = false;
            }

            if (!launched)
            {
                HeadlessUpdatePolicy.RecordApplyFailure(state, target.Version, HeadlessUpdateResults.LaunchFailed, "detached apply step did not start", _clock());
                TrySave(state);
                Log.Warning(
                    "headless_update_launch_failed published={Published} attempts={Attempts} next_retry_at={NextRetry}",
                    target.Version,
                    state.Attempts,
                    state.NextRetryAt);
                return new(HeadlessUpdateDecisionKind.Apply, target.Version, false, HeadlessUpdateResults.LaunchFailed);
            }

            Log.Information(
                "headless_update_apply_launched installed={Installed} published={Published} attempt={Attempts}",
                _installedVersion,
                target.Version,
                state.Attempts);
            return new(HeadlessUpdateDecisionKind.Apply, target.Version, true, HeadlessUpdateResults.ApplyStarted);
        }
    }

    /// <summary>
    /// The service-mode loop: first cycle after 1..10 minutes, then every
    /// 6 h ± 30 min, or sooner when a backed-off retry falls due. A cycle
    /// without a pending console request does nothing. Never throws
    /// except on cancellation; the tunnel it runs next to must not be affected.
    /// </summary>
    public async Task RunLoopAsync(Random random, Func<TimeSpan, CancellationToken, Task>? delay = null, CancellationToken ct = default)
    {
        var wait = delay ?? ((span, token) => Task.Delay(span, token));
        var first = HeadlessUpdatePolicy.InitialDelay(random);
        Log.Information("headless_update_loop_started installed={Installed} first_check_in={Minutes:F1}min", _installedVersion, first.TotalMinutes);
        try
        {
            await wait(first, ct).ConfigureAwait(false);
            while (!ct.IsCancellationRequested)
            {
                try
                {
                    // After a launch the apply step stops this service; if it
                    // never does, the next cycle sees apply_in_progress and
                    // then the recorded backoff, so continuing is safe.
                    await RunCycleAsync(ct).ConfigureAwait(false);
                }
                catch (OperationCanceledException) when (ct.IsCancellationRequested)
                {
                    return;
                }
                catch (Exception exception)
                {
                    Log.Warning("headless_update_cycle_failed type={ErrorType}", exception.GetType().Name);
                }

                var next = HeadlessUpdatePolicy.NextDelay(LoadState(), _clock(), random);
                Log.Information("headless_update_next_check in={Minutes:F1}min", next.TotalMinutes);
                await wait(next, ct).ConfigureAwait(false);
            }
        }
        catch (OperationCanceledException) when (ct.IsCancellationRequested)
        {
        }
    }

    private bool TrySave(HeadlessUpdateState state)
    {
        try
        {
            HeadlessUpdateStateStore.Save(_statePath, state);
            return true;
        }
        catch (Exception exception) when (exception is IOException or UnauthorizedAccessException)
        {
            Log.Warning("headless_update_state_write_failed type={ErrorType}", exception.GetType().Name);
            return false;
        }
    }

    private static string Prefix(string value) => value.Length > 16 ? value[..16] : value;
}
