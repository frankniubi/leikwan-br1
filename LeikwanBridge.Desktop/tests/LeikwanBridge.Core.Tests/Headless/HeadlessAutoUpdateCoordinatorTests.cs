using LeikwanBridge.Core.Services;
using Xunit;

namespace LeikwanBridge.Core.Tests.Headless;

public sealed class HeadlessAutoUpdateCoordinatorTests
{
    [Fact]
    public async Task DisabledFlagSkipsTheManifestEntirely()
    {
        using var workspace = new Workspace("0.1.20.53") { Enabled = false };
        workspace.Source.Published = Published("0.1.20.54");

        var result = await workspace.Coordinator.RunCycleAsync(CancellationToken.None);

        Assert.Equal(HeadlessUpdateDecisionKind.Disabled, result.Decision);
        Assert.Equal(0, workspace.Source.Reads);
        Assert.Equal(0, workspace.Launcher.Launches);
    }

    [Fact]
    public async Task NewerVersionIsDownloadedVerifiedAndLaunched()
    {
        using var workspace = new Workspace("0.1.20.53");
        workspace.Source.Published = Published("0.1.20.54");

        var result = await workspace.Coordinator.RunCycleAsync(CancellationToken.None);

        Assert.True(result.Launched);
        Assert.Equal(1, workspace.Source.Downloads);
        Assert.Equal(("staged.zip", "0.1.20.54"), workspace.Launcher.Last);
        var state = workspace.State();
        Assert.Equal("0.1.20.54", state.TargetVersion);
        Assert.Equal(HeadlessUpdateResults.ApplyStarted, state.LastResult);
        Assert.Equal(1, state.Attempts);
        Assert.NotNull(state.NextRetryAt);
    }

    [Fact]
    public async Task OlderPublishedVersionIsNeverDownloaded()
    {
        using var workspace = new Workspace("0.1.20.53");
        workspace.Source.Published = Published("0.1.20.49");

        var result = await workspace.Coordinator.RunCycleAsync(CancellationToken.None);

        Assert.Equal(HeadlessUpdateDecisionKind.DowngradeRefused, result.Decision);
        Assert.Equal(0, workspace.Source.Downloads);
        Assert.Equal(0, workspace.Launcher.Launches);
    }

    [Fact]
    public async Task RejectedVersionIsSkippedButANewerOneIsTaken()
    {
        using var workspace = new Workspace("0.1.20.53");
        var state = new HeadlessUpdateState();
        HeadlessUpdatePolicy.RecordRejected(state, "0.1.20.54", "health gate: not_connected", workspace.Now - TimeSpan.FromDays(3));
        workspace.SaveState(state);
        workspace.Source.Published = Published("0.1.20.54");

        var skipped = await workspace.Coordinator.RunCycleAsync(CancellationToken.None);
        Assert.Equal(HeadlessUpdateDecisionKind.Rejected, skipped.Decision);
        Assert.Equal(0, workspace.Source.Downloads);

        workspace.Source.Published = Published("0.1.20.55");
        var taken = await workspace.Coordinator.RunCycleAsync(CancellationToken.None);
        Assert.True(taken.Launched);
        Assert.Equal("0.1.20.55", workspace.Launcher.Last.Version);
    }

    [Fact]
    public async Task HeldApplyLockPreventsAConcurrentApply()
    {
        using var workspace = new Workspace("0.1.20.53");
        workspace.Source.Published = Published("0.1.20.54");
        using var held = UpdateApplyLock.TryAcquire(workspace.LockPath);
        Assert.NotNull(held);

        var result = await workspace.Coordinator.RunCycleAsync(CancellationToken.None);

        Assert.Equal(HeadlessUpdateDecisionKind.ApplyInProgress, result.Decision);
        Assert.Equal(0, workspace.Source.Downloads);
        Assert.Equal(0, workspace.Launcher.Launches);
    }

    [Fact]
    public async Task LockIsReleasedBeforeTheDetachedStepStarts()
    {
        using var workspace = new Workspace("0.1.20.53");
        workspace.Source.Published = Published("0.1.20.54");
        workspace.Launcher.OnLaunch = () =>
        {
            // The detached apply step must be able to take the lock.
            using var mine = UpdateApplyLock.TryAcquire(workspace.LockPath);
            Assert.NotNull(mine);
        };

        var result = await workspace.Coordinator.RunCycleAsync(CancellationToken.None);
        Assert.True(result.Launched);
    }

    [Fact]
    public async Task VerificationFailureBacksOffInsteadOfLooping()
    {
        using var workspace = new Workspace("0.1.20.53");
        workspace.Source.Published = Published("0.1.20.54");
        workspace.Source.DownloadError = new ClientUpdateVerificationException("hash mismatch (aaaa != bbbb)");

        var first = await workspace.Coordinator.RunCycleAsync(CancellationToken.None);
        Assert.False(first.Launched);
        var state = workspace.State();
        Assert.Equal(HeadlessUpdateResults.VerifyFailed, state.LastResult);
        Assert.Equal(1, state.Attempts);
        Assert.Equal(workspace.Now + TimeSpan.FromMinutes(15), state.NextRetryAt);

        // Immediately again: backing off, no second download.
        var second = await workspace.Coordinator.RunCycleAsync(CancellationToken.None);
        Assert.Equal(HeadlessUpdateDecisionKind.BackingOff, second.Decision);
        Assert.Equal(1, workspace.Source.Downloads);

        // After the backoff the retry happens and doubles the next wait.
        workspace.Now += TimeSpan.FromMinutes(16);
        await workspace.Coordinator.RunCycleAsync(CancellationToken.None);
        Assert.Equal(2, workspace.Source.Downloads);
        Assert.Equal(workspace.Now + TimeSpan.FromMinutes(30), workspace.State().NextRetryAt);
        Assert.Equal(0, workspace.Launcher.Launches);
    }

    [Fact]
    public async Task DownloadErrorIsRecordedWithBackoff()
    {
        using var workspace = new Workspace("0.1.20.53");
        workspace.Source.Published = Published("0.1.20.54");
        workspace.Source.DownloadError = new HttpRequestException("reset");

        await workspace.Coordinator.RunCycleAsync(CancellationToken.None);

        var state = workspace.State();
        Assert.Equal(HeadlessUpdateResults.DownloadFailed, state.LastResult);
        Assert.Equal(1, state.Attempts);
        Assert.DoesNotContain("reset", state.LastError); // type only, never a server message
    }

    [Fact]
    public async Task UnreachablePanelIsNotCountedAsAnAttempt()
    {
        using var workspace = new Workspace("0.1.20.53");
        workspace.Source.ReadError = new HttpRequestException("dns");

        var result = await workspace.Coordinator.RunCycleAsync(CancellationToken.None);

        Assert.Equal(HeadlessUpdateDecisionKind.ManifestUnavailable, result.Decision);
        Assert.Equal(0, workspace.State().Attempts);
    }

    [Fact]
    public async Task LaunchFailureBacksOff()
    {
        using var workspace = new Workspace("0.1.20.53");
        workspace.Source.Published = Published("0.1.20.54");
        workspace.Launcher.Result = false;

        var result = await workspace.Coordinator.RunCycleAsync(CancellationToken.None);

        Assert.False(result.Launched);
        var state = workspace.State();
        Assert.Equal(HeadlessUpdateResults.LaunchFailed, state.LastResult);
        Assert.Equal(1, state.Attempts);
        Assert.Equal(HeadlessUpdateDecisionKind.BackingOff, (await workspace.Coordinator.RunCycleAsync(CancellationToken.None)).Decision);
    }

    [Fact]
    public async Task ApplyInProgressIsNotRestarted()
    {
        using var workspace = new Workspace("0.1.20.53");
        workspace.Source.Published = Published("0.1.20.54");
        await workspace.Coordinator.RunCycleAsync(CancellationToken.None);
        Assert.Equal(1, workspace.Launcher.Launches);

        // The detached step is still working (service not yet restarted).
        workspace.Now += TimeSpan.FromMinutes(2);
        var again = await workspace.Coordinator.RunCycleAsync(CancellationToken.None);
        Assert.Equal(HeadlessUpdateDecisionKind.ApplyInProgress, again.Decision);
        Assert.Equal(1, workspace.Launcher.Launches);
    }

    [Fact]
    public async Task LoopHonoursInitialDelayAndStopsOnCancellation()
    {
        using var workspace = new Workspace("0.1.20.53");
        workspace.Source.Published = Published("0.1.20.53");
        var delays = new List<TimeSpan>();
        using var cts = new CancellationTokenSource();
        await workspace.Coordinator.RunLoopAsync(
            new Random(5),
            (span, token) =>
            {
                delays.Add(span);
                if (delays.Count >= 3)
                    cts.Cancel();
                token.ThrowIfCancellationRequested();
                return Task.CompletedTask;
            },
            cts.Token);

        Assert.InRange(delays[0], TimeSpan.FromMinutes(1), TimeSpan.FromMinutes(10));
        Assert.InRange(delays[1], TimeSpan.FromHours(5.5), TimeSpan.FromHours(6.5));
        Assert.Equal(2, workspace.Source.Reads);
    }

    private static PublishedClientUpdate Published(string version)
        => new(version, "https://panel.example/artifacts/windows/leikwan-bridge-headless-win-x64.zip", new string('b', 64), 4096, "leikwan-bridge-headless-win-x64.zip");

    private sealed class Workspace : IDisposable
    {
        private readonly string _root = Path.Combine(Path.GetTempPath(), "lkb-auto-upd-" + Guid.NewGuid().ToString("N"));

        public Workspace(string installed)
        {
            Directory.CreateDirectory(_root);
            Coordinator = new HeadlessAutoUpdateCoordinator(
                installed,
                StatePath,
                LockPath,
                () => Enabled,
                Source,
                Launcher,
                () => Now);
        }

        public DateTimeOffset Now { get; set; } = new(2026, 9, 24, 8, 0, 0, TimeSpan.Zero);

        public bool Enabled { get; set; } = true;

        public FakeSource Source { get; } = new();

        public FakeLauncher Launcher { get; } = new();

        public HeadlessAutoUpdateCoordinator Coordinator { get; }

        public string StatePath => Path.Combine(_root, "updates", "update-state.json");

        public string LockPath => Path.Combine(_root, "updates", "apply.lock");

        public HeadlessUpdateState State() => HeadlessUpdateStateStore.Load(StatePath);

        public void SaveState(HeadlessUpdateState state) => HeadlessUpdateStateStore.Save(StatePath, state);

        public void Dispose()
        {
            try
            {
                Directory.Delete(_root, recursive: true);
            }
            catch (IOException)
            {
            }
        }
    }

    private sealed class FakeSource : IClientUpdateSource
    {
        public PublishedClientUpdate? Published { get; set; }
        public Exception? ReadError { get; set; }
        public Exception? DownloadError { get; set; }
        public int Reads { get; private set; }
        public int Downloads { get; private set; }

        public Task<PublishedClientUpdate?> ReadPublishedAsync(CancellationToken ct)
        {
            Reads++;
            if (ReadError is not null)
                throw ReadError;
            return Task.FromResult(Published);
        }

        public Task<string> DownloadVerifiedAsync(PublishedClientUpdate update, CancellationToken ct)
        {
            Downloads++;
            if (DownloadError is not null)
                throw DownloadError;
            return Task.FromResult("staged.zip");
        }
    }

    private sealed class FakeLauncher : IHeadlessUpdateLauncher
    {
        public bool Result { get; set; } = true;
        public int Launches { get; private set; }
        public (string File, string Version) Last { get; private set; }
        public Action? OnLaunch { get; set; }

        public Task<bool> LaunchAsync(string stagedFile, string version, CancellationToken ct)
        {
            Launches++;
            Last = (stagedFile, version);
            OnLaunch?.Invoke();
            return Task.FromResult(Result);
        }
    }
}
