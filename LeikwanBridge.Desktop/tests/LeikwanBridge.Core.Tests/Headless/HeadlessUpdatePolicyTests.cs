using LeikwanBridge.Core.Models;
using LeikwanBridge.Core.Services;
using Xunit;

namespace LeikwanBridge.Core.Tests.Headless;

public sealed class HeadlessUpdatePolicyTests
{
    private static readonly DateTimeOffset Now = new(2026, 9, 24, 12, 0, 0, TimeSpan.Zero);

    private static PublishedClientUpdate Published(string version)
        => new(version, "https://panel.example/artifacts/windows/x.zip", new string('a', 64), 1234, "x.zip");

    [Fact]
    public void InitialDelayStaysWithinOneToTenMinutes()
    {
        var random = new Random(7);
        for (var index = 0; index < 2000; index++)
        {
            var delay = HeadlessUpdatePolicy.InitialDelay(random);
            Assert.InRange(delay, TimeSpan.FromMinutes(1), TimeSpan.FromMinutes(10));
        }
    }

    [Fact]
    public void RegularDelayIsSixHoursPlusOrMinusJitter()
    {
        var random = new Random(11);
        var seen = new List<TimeSpan>();
        for (var index = 0; index < 2000; index++)
        {
            var delay = HeadlessUpdatePolicy.RegularDelay(random);
            Assert.InRange(delay, TimeSpan.FromHours(6) - TimeSpan.FromMinutes(30), TimeSpan.FromHours(6) + TimeSpan.FromMinutes(30));
            seen.Add(delay);
        }

        // Actually jittered, not a constant.
        Assert.True(seen.Max() - seen.Min() > TimeSpan.FromMinutes(30));
    }

    [Fact]
    public void BackoffDoublesFromFifteenMinutesAndCapsAtOneDay()
    {
        Assert.Equal(TimeSpan.Zero, HeadlessUpdatePolicy.BackoffFor(0));
        Assert.Equal(TimeSpan.FromMinutes(15), HeadlessUpdatePolicy.BackoffFor(1));
        Assert.Equal(TimeSpan.FromMinutes(30), HeadlessUpdatePolicy.BackoffFor(2));
        Assert.Equal(TimeSpan.FromHours(1), HeadlessUpdatePolicy.BackoffFor(3));
        Assert.Equal(TimeSpan.FromHours(2), HeadlessUpdatePolicy.BackoffFor(4));
        Assert.Equal(TimeSpan.FromHours(16), HeadlessUpdatePolicy.BackoffFor(7));
        Assert.Equal(TimeSpan.FromHours(24), HeadlessUpdatePolicy.BackoffFor(8));
        Assert.Equal(TimeSpan.FromHours(24), HeadlessUpdatePolicy.BackoffFor(50));
        Assert.Equal(TimeSpan.FromHours(24), HeadlessUpdatePolicy.BackoffFor(int.MaxValue));
    }

    [Fact]
    public void RepeatedFailuresPushTheRetryOutExponentially()
    {
        var state = new HeadlessUpdateState();
        var previous = TimeSpan.Zero;
        for (var attempt = 1; attempt <= 10; attempt++)
        {
            HeadlessUpdatePolicy.RecordFailure(state, "0.1.20.54", HeadlessUpdateResults.DownloadFailed, "boom", Now);
            var wait = state.NextRetryAt!.Value - Now;
            Assert.Equal(attempt, state.Attempts);
            Assert.True(wait >= previous);
            Assert.True(wait <= TimeSpan.FromHours(24));
            previous = wait;
        }

        Assert.Equal(TimeSpan.FromHours(24), previous);
    }

    [Fact]
    public void DisableAutoUpdatesMapsToTheExistingConfigFlag()
    {
        Assert.True(HeadlessUpdatePolicy.IsAutoUpdateEnabled(new AgentConfig()));
        Assert.False(HeadlessUpdatePolicy.IsAutoUpdateEnabled(new AgentConfig { DisableAutoUpdates = true }));
    }

    [Fact]
    public void DisabledNeverApplies()
    {
        var decision = HeadlessUpdatePolicy.Decide(false, "0.1.20.53", Published("0.1.20.60"), new HeadlessUpdateState(), Now);
        Assert.Equal(HeadlessUpdateDecisionKind.Disabled, decision.Kind);
    }

    [Fact]
    public void NewerPublishedVersionIsApplied()
    {
        var decision = HeadlessUpdatePolicy.Decide(true, "0.1.20.53", Published("0.1.20.54"), new HeadlessUpdateState(), Now);
        Assert.Equal(HeadlessUpdateDecisionKind.Apply, decision.Kind);
        Assert.Equal("0.1.20.54", decision.TargetVersion);
    }

    [Theory]
    [InlineData("0.1.20.52")]
    [InlineData("0.1.19.99")]
    [InlineData("0.0.1")]
    public void OlderPublishedVersionIsNeverInstalled(string published)
    {
        var decision = HeadlessUpdatePolicy.Decide(true, "0.1.20.53", Published(published), new HeadlessUpdateState(), Now);
        Assert.Equal(HeadlessUpdateDecisionKind.DowngradeRefused, decision.Kind);
    }

    [Fact]
    public void SamePublishedVersionIsUpToDate()
    {
        var decision = HeadlessUpdatePolicy.Decide(true, "0.1.20.53", Published("0.1.20.53"), new HeadlessUpdateState(), Now);
        Assert.Equal(HeadlessUpdateDecisionKind.UpToDate, decision.Kind);
    }

    [Fact]
    public void UnparseablePublishedVersionIsNotApplied()
    {
        var decision = HeadlessUpdatePolicy.Decide(true, "0.1.20.53", Published("latest"), new HeadlessUpdateState(), Now);
        Assert.NotEqual(HeadlessUpdateDecisionKind.Apply, decision.Kind);
    }

    [Fact]
    public void IncompleteManifestEntryIsNotApplied()
    {
        var noSize = Published("0.1.20.54") with { SizeBytes = 0 };
        var noHash = Published("0.1.20.54") with { Sha256 = string.Empty };
        Assert.Equal(HeadlessUpdateDecisionKind.ManifestIncomplete, HeadlessUpdatePolicy.Decide(true, "0.1.20.53", noSize, new HeadlessUpdateState(), Now).Kind);
        Assert.Equal(HeadlessUpdateDecisionKind.ManifestIncomplete, HeadlessUpdatePolicy.Decide(true, "0.1.20.53", noHash, new HeadlessUpdateState(), Now).Kind);
        Assert.Equal(HeadlessUpdateDecisionKind.ManifestUnavailable, HeadlessUpdatePolicy.Decide(true, "0.1.20.53", null, new HeadlessUpdateState(), Now).Kind);
    }

    [Fact]
    public void RejectedVersionIsSkippedUntilANewerOneIsPublished()
    {
        var state = new HeadlessUpdateState();
        HeadlessUpdatePolicy.RecordRejected(state, "0.1.20.54", "health gate: not_connected", Now);

        // Even long after any backoff has expired.
        var later = Now + TimeSpan.FromDays(30);
        Assert.Equal(HeadlessUpdateDecisionKind.Rejected, HeadlessUpdatePolicy.Decide(true, "0.1.20.53", Published("0.1.20.54"), state, later).Kind);
        Assert.Equal(HeadlessUpdateDecisionKind.Apply, HeadlessUpdatePolicy.Decide(true, "0.1.20.53", Published("0.1.20.55"), state, Now).Kind);
    }

    [Fact]
    public void RejectedListIsBounded()
    {
        var state = new HeadlessUpdateState();
        for (var index = 0; index < 40; index++)
            HeadlessUpdatePolicy.RecordRejected(state, $"0.1.20.{100 + index}", "x", Now);
        Assert.Equal(HeadlessUpdatePolicy.MaxRememberedRejections, state.RejectedVersions.Count);
        Assert.Contains("0.1.20.139", state.RejectedVersions);
    }

    [Fact]
    public void FailedAttemptBacksOffThenRetries()
    {
        var state = new HeadlessUpdateState();
        HeadlessUpdatePolicy.RecordFailure(state, "0.1.20.54", HeadlessUpdateResults.VerifyFailed, "hash mismatch", Now);

        Assert.Equal(HeadlessUpdateDecisionKind.BackingOff, HeadlessUpdatePolicy.Decide(true, "0.1.20.53", Published("0.1.20.54"), state, Now + TimeSpan.FromMinutes(10)).Kind);
        Assert.Equal(HeadlessUpdateDecisionKind.Apply, HeadlessUpdatePolicy.Decide(true, "0.1.20.53", Published("0.1.20.54"), state, Now + TimeSpan.FromMinutes(16)).Kind);
    }

    [Fact]
    public void ANewerVersionResetsTheAttemptCounter()
    {
        var state = new HeadlessUpdateState();
        for (var index = 0; index < 5; index++)
            HeadlessUpdatePolicy.RecordFailure(state, "0.1.20.54", HeadlessUpdateResults.DownloadFailed, "x", Now);
        Assert.Equal(5, state.Attempts);

        Assert.Equal(HeadlessUpdateDecisionKind.Apply, HeadlessUpdatePolicy.Decide(true, "0.1.20.53", Published("0.1.20.55"), state, Now).Kind);
        HeadlessUpdatePolicy.RecordFailure(state, "0.1.20.55", HeadlessUpdateResults.DownloadFailed, "x", Now);
        Assert.Equal(1, state.Attempts);
        Assert.Equal(Now + TimeSpan.FromMinutes(15), state.NextRetryAt);
    }

    [Fact]
    public void ApplyStartedCountsTheAttemptBeforeAnythingRestarts()
    {
        var state = new HeadlessUpdateState();
        HeadlessUpdatePolicy.RecordApplyStarted(state, "0.1.20.54", Now);

        Assert.Equal(1, state.Attempts);
        Assert.Equal(Now + TimeSpan.FromMinutes(15), state.NextRetryAt);
        Assert.Equal(HeadlessUpdateDecisionKind.ApplyInProgress, HeadlessUpdatePolicy.Decide(true, "0.1.20.53", Published("0.1.20.54"), state, Now + TimeSpan.FromMinutes(5)).Kind);

        // Detached steps that die without reporting back: each one was already
        // counted, so the retries still back off exponentially.
        var third = Now + TimeSpan.FromHours(2);
        HeadlessUpdatePolicy.RecordApplyStarted(state, "0.1.20.54", Now + TimeSpan.FromHours(1));
        HeadlessUpdatePolicy.RecordApplyStarted(state, "0.1.20.54", third);
        Assert.Equal(3, state.Attempts);
        Assert.Equal(third + TimeSpan.FromHours(1), state.NextRetryAt);
        var afterStale = third + HeadlessUpdatePolicy.ApplyStaleAfter + TimeSpan.FromMinutes(5);
        Assert.Equal(HeadlessUpdateDecisionKind.BackingOff, HeadlessUpdatePolicy.Decide(true, "0.1.20.53", Published("0.1.20.54"), state, afterStale).Kind);
        Assert.Equal(HeadlessUpdateDecisionKind.Apply, HeadlessUpdatePolicy.Decide(true, "0.1.20.53", Published("0.1.20.54"), state, third + TimeSpan.FromHours(1) + TimeSpan.FromMinutes(1)).Kind);
    }

    [Fact]
    public void ReconcileMarksAnApplyThatLandedAsApplied()
    {
        var state = new HeadlessUpdateState();
        HeadlessUpdatePolicy.RecordApplyStarted(state, "0.1.20.54", Now);

        Assert.True(HeadlessUpdatePolicy.Reconcile(state, "0.1.20.54", Now + TimeSpan.FromMinutes(3)));
        Assert.Equal(0, state.Attempts);
        Assert.Null(state.NextRetryAt);
        Assert.Equal(HeadlessUpdateResults.Applied, state.LastResult);
        Assert.Equal("0.1.20.54", state.LastAppliedVersion);
    }

    [Fact]
    public void ReconcileKeepsTheBackoffWhileTheOldBuildIsStillInstalled()
    {
        var state = new HeadlessUpdateState();
        HeadlessUpdatePolicy.RecordApplyStarted(state, "0.1.20.54", Now);

        HeadlessUpdatePolicy.Reconcile(state, "0.1.20.53", Now + TimeSpan.FromMinutes(3));
        Assert.Equal(1, state.Attempts);
        Assert.NotNull(state.NextRetryAt);
    }

    [Fact]
    public void NextDelayUsesADueRetryButNeverRunsHot()
    {
        var random = new Random(3);
        var state = new HeadlessUpdateState { NextRetryAt = Now + TimeSpan.FromMinutes(15) };
        var delay = HeadlessUpdatePolicy.NextDelay(state, Now, random);
        Assert.InRange(delay, TimeSpan.FromMinutes(15), TimeSpan.FromMinutes(16));

        state.NextRetryAt = Now - TimeSpan.FromHours(1);
        Assert.True(HeadlessUpdatePolicy.NextDelay(state, Now, random) >= TimeSpan.FromHours(5));

        state.NextRetryAt = Now + TimeSpan.FromSeconds(1);
        Assert.True(HeadlessUpdatePolicy.NextDelay(state, Now, random) >= HeadlessUpdatePolicy.MinimumDelay);
    }

    [Fact]
    public void StateRoundTripsAndToleratesCorruption()
    {
        var directory = Path.Combine(Path.GetTempPath(), "lkb-upd-state-" + Guid.NewGuid().ToString("N"));
        try
        {
            var path = Path.Combine(directory, "update-state.json");
            var state = new HeadlessUpdateState();
            HeadlessUpdatePolicy.RecordRejected(state, "0.1.20.54", "health gate", Now);
            HeadlessUpdateStateStore.Save(path, state);

            var text = File.ReadAllText(path);
            Assert.Contains("\"rejected_versions\"", text);
            Assert.Contains("\"next_retry_at\"", text);
            Assert.Contains("\"attempts\"", text);
            var loaded = HeadlessUpdateStateStore.Load(path);
            Assert.Equal(state.TargetVersion, loaded.TargetVersion);
            Assert.Equal(state.RejectedVersions, loaded.RejectedVersions);
            Assert.Equal(state.NextRetryAt, loaded.NextRetryAt);

            File.WriteAllText(path, "{ not json");
            var fallback = HeadlessUpdateStateStore.Load(path);
            Assert.Equal(0, fallback.Attempts);
            Assert.Empty(fallback.RejectedVersions);
        }
        finally
        {
            if (Directory.Exists(directory))
                Directory.Delete(directory, recursive: true);
        }
    }

    [Fact]
    public void ApplyLockIsExclusiveAndReleasedOnDispose()
    {
        var directory = Path.Combine(Path.GetTempPath(), "lkb-upd-lock-" + Guid.NewGuid().ToString("N"));
        try
        {
            var path = Path.Combine(directory, "apply.lock");
            using (var first = UpdateApplyLock.TryAcquire(path))
            {
                Assert.NotNull(first);
                Assert.Null(UpdateApplyLock.TryAcquire(path));
                Assert.True(UpdateApplyLock.IsHeld(path));
            }

            Assert.False(UpdateApplyLock.IsHeld(path));
            using var second = UpdateApplyLock.TryAcquire(path);
            Assert.NotNull(second);
        }
        finally
        {
            if (Directory.Exists(directory))
                Directory.Delete(directory, recursive: true);
        }
    }
}
