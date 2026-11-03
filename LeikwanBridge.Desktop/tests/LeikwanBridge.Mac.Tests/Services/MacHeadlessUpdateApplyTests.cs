using LeikwanBridge.Core.Services;
using LeikwanBridge.Mac.Services;
using Xunit;

namespace LeikwanBridge.Mac.Tests.Services;

/// <summary>
/// The macOS detached apply step: the shared <see cref="UpdateApplyOrchestrator"/>
/// with the bundle swapper and a simulated launchd/status file on a virtual
/// clock, plus the launchd environment itself against a scripted launchctl.
/// </summary>
public sealed class MacHeadlessUpdateApplyTests
{
    [Theory]
    [InlineData(true)]
    [InlineData(false)] // no RENAME_SWAP: two renames with undo
    public async Task HealthyNewBundleIsKeptBackedUpAndRecorded(bool atomicSwap)
    {
        using var world = new ApplyWorld();
        world.FileSystem.SupportsSwap = atomicSwap;

        var outcome = await world.RunAsync();

        Assert.Equal(UpdateApplyOutcome.Applied, outcome);
        Assert.Equal("new", MacUpdateFixtures.Marker(world.Install));
        Assert.Equal("1.0.47", MacBundleValidator.ReadBundleVersion(world.Install));
        Assert.False(Directory.Exists(world.Staged)); // moved in, not copied
        var backup = Assert.Single(Directory.GetDirectories(world.BackupRoot));
        Assert.StartsWith("install-1.0.46-", Path.GetFileName(backup), StringComparison.Ordinal);
        Assert.False(backup.EndsWith(".app", StringComparison.Ordinal)); // never registered by LaunchServices
        Assert.Equal("old", MacUpdateFixtures.Marker(backup));
        // The swapped-out bundle is parked (a CLI may still run from it), not left in /Applications.
        Assert.Single(Directory.GetDirectories(world.UpdatesRoot, MacBundleSwapper.RetiredPrefix + "*"));
        Assert.Single(Directory.GetDirectories(Path.GetDirectoryName(world.Install)!));
        Assert.Equal(["stop", "drain", "start"], world.Environment.Calls.Take(3).ToArray());
        var state = HeadlessUpdateStateStore.Load(world.StatePath);
        Assert.Equal(HeadlessUpdateResults.Applied, state.LastResult);
        Assert.Equal("1.0.47", state.LastAppliedVersion);
        Assert.Equal(0, state.Attempts);
    }

    [Theory]
    [InlineData(NewBuild.NeverWritesStatus)]
    [InlineData(NewBuild.Disconnected)]
    [InlineData(NewBuild.WrongVersion)]
    [InlineData(NewBuild.ServiceDies)]
    [InlineData(NewBuild.RestartsUnderKeepAlive)]
    public async Task UnhealthyNewBundleIsRolledBackRestartedAndRejected(NewBuild behaviour)
    {
        using var world = new ApplyWorld();
        world.Environment.Behaviour = behaviour;

        var outcome = await world.RunAsync();

        Assert.Equal(UpdateApplyOutcome.RejectedRolledBack, outcome);
        Assert.Equal("old", MacUpdateFixtures.Marker(world.Install));
        Assert.Equal("1.0.46", MacBundleValidator.ReadBundleVersion(world.Install));
        Assert.False(File.Exists(Path.Combine(world.Install, "Contents", "Resources", "added-by-new.txt")));
        Assert.Equal("start", world.Environment.Calls[^1]);
        Assert.Equal(2, world.Environment.Calls.Count(call => call == "start"));
        // The backup survives the restore, for the next attempt's rollback.
        Assert.Single(Directory.GetDirectories(world.BackupRoot));
        // No stray bundles left beside the install.
        Assert.Single(Directory.GetDirectories(Path.GetDirectoryName(world.Install)!));
        var state = HeadlessUpdateStateStore.Load(world.StatePath);
        Assert.Equal(HeadlessUpdateResults.Rejected, state.LastResult);
        Assert.Contains("1.0.47", state.RejectedVersions);
        Assert.StartsWith("health gate:", state.LastError, StringComparison.Ordinal);
    }

    [Fact]
    public async Task NewBundleWithoutForwardsIsRolledBackWhenTheOldOneHadSome()
    {
        using var world = new ApplyWorld();
        world.Environment.BaselineForwardsRunning = true;
        world.Environment.Behaviour = NewBuild.ConnectedWithoutForwards;

        Assert.Equal(UpdateApplyOutcome.RejectedRolledBack, await world.RunAsync());
        Assert.Equal("old", MacUpdateFixtures.Marker(world.Install));
    }

    [Fact]
    public async Task ConnectedFixIsAcceptedWhenTheOldBundleHadNoForwardsRunning()
    {
        using var world = new ApplyWorld();
        world.Environment.BaselineForwardsRunning = false;
        world.Environment.Behaviour = NewBuild.ConnectedWithoutForwards;

        Assert.Equal(UpdateApplyOutcome.Applied, await world.RunAsync());
    }

    [Fact]
    public async Task DaemonThatWillNotStopLeavesTheBundleUntouched()
    {
        using var world = new ApplyWorld();
        world.Environment.StopSucceeds = false;

        Assert.Equal(UpdateApplyOutcome.StopFailed, await world.RunAsync());
        Assert.Equal("old", MacUpdateFixtures.Marker(world.Install));
        Assert.True(Directory.Exists(world.Staged));
        Assert.False(Directory.Exists(world.BackupRoot) && Directory.GetDirectories(world.BackupRoot).Length > 0);
        Assert.Equal("start", world.Environment.Calls[^1]);
        var state = HeadlessUpdateStateStore.Load(world.StatePath);
        Assert.Equal(HeadlessUpdateResults.StopFailed, state.LastResult);
        Assert.Empty(state.RejectedVersions);
    }

    [Fact]
    public async Task BackupFailureRestartsTheOldBundleWithoutSwapping()
    {
        using var world = new ApplyWorld();
        world.FileSystem.FailClone = source => source.TrimEnd('/', '\\') == world.Install;

        Assert.Equal(UpdateApplyOutcome.BackupFailed, await world.RunAsync());
        Assert.Equal("old", MacUpdateFixtures.Marker(world.Install));
        Assert.Equal("start", world.Environment.Calls[^1]);
    }

    [Fact]
    public async Task FailedSwapRestoresTheOldBundle()
    {
        using var world = new ApplyWorld();
        world.FileSystem.SupportsSwap = false;
        // The new bundle cannot be renamed into place.
        world.FileSystem.FailMove = (source, destination) => destination.TrimEnd('/', '\\') == world.Install && source.Contains(".incoming-", StringComparison.Ordinal);

        Assert.Equal(UpdateApplyOutcome.CopyFailedRestored, await world.RunAsync());
        Assert.Equal("old", MacUpdateFixtures.Marker(world.Install));
        Assert.Equal("start", world.Environment.Calls[^1]);
        Assert.Equal(HeadlessUpdateResults.CopyFailed, HeadlessUpdateStateStore.Load(world.StatePath).LastResult);
    }

    [Fact]
    public async Task OnlyTheNewestThreeBackupsAreKept()
    {
        using var world = new ApplyWorld();
        Directory.CreateDirectory(world.BackupRoot);
        for (var index = 0; index < 4; index++)
        {
            var old = Directory.CreateDirectory(Path.Combine(world.BackupRoot, $"install-1.0.4{index}-2026090{index}T000000Z"));
            Directory.SetCreationTimeUtc(old.FullName, new DateTime(2020, 9, 1 + index, 0, 0, 0, DateTimeKind.Utc));
        }

        Assert.Equal(UpdateApplyOutcome.Applied, await world.RunAsync());
        var names = Directory.GetDirectories(world.BackupRoot).Select(Path.GetFileName).ToList();
        Assert.Equal(3, names.Count);
        Assert.Contains(names, name => name!.StartsWith("install-1.0.46-", StringComparison.Ordinal));
    }

    [Fact]
    public async Task CrashInsideTheStepStillLeavesTheDaemonRunning()
    {
        using var world = new ApplyWorld();
        // The daemon is already booted out when the step blows up.
        world.Environment.ThrowOnDrain = true;

        var outcome = await world.RunAsync();

        Assert.Null(outcome);
        Assert.Equal(["stop", "drain", "start"], world.Environment.Calls.ToArray());
        Assert.Equal("old", MacUpdateFixtures.Marker(world.Install));
        Assert.Equal(HeadlessUpdateResults.RollbackFailed, HeadlessUpdateStateStore.Load(world.StatePath).LastResult);
    }

    [Fact]
    public void RequestParsingRequiresAbsoluteBundlePaths()
    {
        Assert.True(MacHeadlessUpdateApply.TryParse(
            ["--staged", "/Library/Application Support/Leikwan/ClientAgent/updates/stage-1/LeiKwan Bridge.app",
             "--install", "/Applications/LeiKwan Bridge.app", "--version", "1.0.47", "--previous-version", "1.0.46",
             "--request-id", "abc", "--exclude-pid", "321"],
            out var request,
            out var error), error);
        Assert.Equal("/Applications/LeiKwan Bridge.app", request!.Install);
        Assert.Equal("1.0.46", request.PreviousVersion);
        Assert.Equal([321], request.ExcludePids);
        Assert.Equal("abc", request.RequestId);

        Assert.False(MacHeadlessUpdateApply.TryParse(["--staged", "relative.app", "--install", "/Applications/LeiKwan Bridge.app", "--version", "1.0.47"], out _, out _));
        Assert.False(MacHeadlessUpdateApply.TryParse(["--staged", "/tmp/x.app", "--install", "/Applications/LeiKwan Bridge", "--version", "1.0.47"], out _, out _));
        Assert.False(MacHeadlessUpdateApply.TryParse(["--staged", "/tmp/x.app", "--install", "/Applications/LeiKwan Bridge.app"], out _, out _));
    }

    [Fact]
    public async Task EnvironmentBootsTheDaemonOutAndBootstrapsItAgain()
    {
        var launchctl = new ScriptedLaunchctl { Loaded = true, Running = true, Pid = 100 };
        var environment = launchctl.Environment();

        Assert.True(await environment.StopServiceAsync(TimeSpan.FromSeconds(60), CancellationToken.None));
        Assert.Contains("bootout system/com.leikwan.bridge.headless", launchctl.Commands);
        Assert.False(launchctl.Loaded);

        Assert.True(await environment.StartServiceAsync(CancellationToken.None));
        var start = launchctl.Commands.SkipWhile(command => !command.StartsWith("enable", StringComparison.Ordinal)).ToList();
        Assert.Equal("enable system/com.leikwan.bridge.headless", start[0]);
        Assert.Contains("bootstrap system /Library/LaunchDaemons/com.leikwan.bridge.headless.plist", start);
        Assert.Contains("kickstart system/com.leikwan.bridge.headless", start);
        Assert.DoesNotContain(start, command => command.StartsWith("kickstart -k", StringComparison.Ordinal));
    }

    [Fact]
    public async Task EnvironmentKickstartsALoadedDaemon()
    {
        var launchctl = new ScriptedLaunchctl { Loaded = true, Running = true, Pid = 100 };

        Assert.True(await launchctl.Environment().StartServiceAsync(CancellationToken.None));
        Assert.Contains("kickstart -k system/com.leikwan.bridge.headless", launchctl.Commands);
    }

    [Fact]
    public async Task EnvironmentKillsADaemonThatIgnoresBootout()
    {
        var launchctl = new ScriptedLaunchctl { Loaded = true, Running = true, Pid = 100, IgnoreBootout = true };
        var signals = new List<(int Pid, int Signal)>();
        var environment = launchctl.Environment(signal: (pid, signal) =>
        {
            signals.Add((pid, signal));
            launchctl.Loaded = false;
            return 0;
        });

        Assert.True(await environment.StopServiceAsync(TimeSpan.FromSeconds(10), CancellationToken.None));
        Assert.Contains((100, MacProcessSupervisor.SigKill), signals);
    }

    [Fact]
    public async Task EnvironmentTreatsAPidChangeAsARestart()
    {
        var launchctl = new ScriptedLaunchctl { Loaded = true, Running = true, Pid = 100 };
        var environment = launchctl.Environment();

        Assert.True(await environment.IsServiceRunningAsync(CancellationToken.None));
        Assert.True(await environment.IsServiceRunningAsync(CancellationToken.None));
        launchctl.Pid = 101; // KeepAlive restarted a crashed daemon
        Assert.False(await environment.IsServiceRunningAsync(CancellationToken.None));
        Assert.True(await environment.IsServiceRunningAsync(CancellationToken.None));
        launchctl.Running = false;
        Assert.False(await environment.IsServiceRunningAsync(CancellationToken.None));
    }

    [Fact]
    public async Task DrainWaitsForRootProcessesFromTheBundleOnly()
    {
        const string install = "/Applications/LeiKwan Bridge.app";
        var processes = new List<MacProcessEntry>
        {
            new(200, 1, 0, install + "/Contents/Resources/bin/ultra-client --config-stdin"),
            new(201, 1, 501, install + "/Contents/MacOS/LeikwanBridge.Mac"), // a user's windowed client
            new(202, 1, 0, install + "/Contents/MacOS/LeikwanBridge.Mac --cli update"), // the waiting CLI
            new(203, 1, 0, "/Applications/LeiKwan Bridge.app.other/Contents/MacOS/x"),
        };
        var signals = new List<(int Pid, int Signal)>();
        var launchctl = new ScriptedLaunchctl();
        var environment = launchctl.Environment(
            excludePids: [202],
            listProcesses: () => processes,
            signal: (pid, signal) =>
            {
                signals.Add((pid, signal));
                if (signal == MacProcessSupervisor.SigTerm)
                    processes.RemoveAll(process => process.Pid == pid);
                return 0;
            });

        Assert.True(await environment.WaitForInstallProcessesExitAsync(install, TimeSpan.FromSeconds(5), CancellationToken.None));
        Assert.Equal(new[] { (200, MacProcessSupervisor.SigTerm) }, signals);
    }

    [Fact]
    public void LaunchctlPrintIsParsed()
    {
        var (running, pid) = MacUpdateApplyEnvironment.ParsePrint("system/com.leikwan.bridge.headless = {\n\tstate = running\n\tpid = 4711\n\tlast exit code = 0\n}");
        Assert.True(running);
        Assert.Equal(4711, pid);
        Assert.Equal((false, 0), MacUpdateApplyEnvironment.ParsePrint("state = spawn scheduled\n"));
    }

    public enum NewBuild
    {
        Healthy,
        NeverWritesStatus,
        Disconnected,
        WrongVersion,
        ServiceDies,
        RestartsUnderKeepAlive,
        ConnectedWithoutForwards,
    }

    private sealed class ApplyWorld : IDisposable
    {
        private readonly TempRoot _root = new();

        public ApplyWorld()
        {
            Install = MacUpdateFixtures.CreateBundle(_root.Sub("Applications"), "1.0.46", "old");
            Staged = MacUpdateFixtures.CreateBundle(_root.Sub("state", "updates", "stage-1"), "1.0.47", "new");
            File.WriteAllText(Path.Combine(Staged, "Contents", "Resources", "added-by-new.txt"), "new only");
            Directory.CreateDirectory(BackupRoot);
        }

        public string Install { get; }
        public string Staged { get; }
        public string UpdatesRoot => _root.Sub("state", "updates");
        public string BackupRoot => _root.Sub("state", "backups");
        public string StatePath => Path.Combine(UpdatesRoot, "update-state.json");
        public ManagedBundleFileSystem FileSystem { get; } = new();
        public FakeLaunchd Environment { get; } = new();

        public Task<UpdateApplyOutcome?> RunAsync()
        {
            // Backup root starts empty so the tests can count what the step made.
            if (Directory.GetDirectories(BackupRoot).Length == 0)
                Directory.Delete(BackupRoot);
            return MacHeadlessUpdateApply.ApplyAsync(
                new MacHeadlessUpdateApply.Request(Staged, Install, "1.0.47", "1.0.46", "req", []),
                Environment,
                new MacBundleSwapper(FileSystem, UpdatesRoot),
                BackupRoot,
                StatePath,
                CancellationToken.None);
        }

        public void Dispose() => _root.Dispose();
    }

    /// <summary>Simulated launchd job + status file on a virtual clock.</summary>
    private sealed class FakeLaunchd : IUpdateApplyEnvironment
    {
        private int _starts;
        private DateTimeOffset _startedAt;
        private bool _running = true;
        private int _polls;

        public DateTimeOffset UtcNow { get; private set; } = new(2026, 9, 24, 10, 0, 0, TimeSpan.Zero);
        public NewBuild Behaviour { get; set; } = NewBuild.Healthy;
        public bool StopSucceeds { get; set; } = true;
        public bool BaselineForwardsRunning { get; set; } = true;
        public bool ThrowOnDrain { get; set; }
        public List<string> Calls { get; } = [];

        public Task DelayAsync(TimeSpan delay, CancellationToken ct)
        {
            UtcNow += delay;
            return Task.CompletedTask;
        }

        public Task<bool> StopServiceAsync(TimeSpan timeout, CancellationToken ct)
        {
            Calls.Add("stop");
            if (StopSucceeds)
                _running = false;
            return Task.FromResult(StopSucceeds);
        }

        public Task<bool> WaitForInstallProcessesExitAsync(string installDirectory, TimeSpan timeout, CancellationToken ct)
        {
            Calls.Add("drain");
            if (ThrowOnDrain)
                throw new InvalidOperationException("process table unreadable (test)");
            return Task.FromResult(true);
        }

        public Task<bool> StartServiceAsync(CancellationToken ct)
        {
            Calls.Add("start");
            _starts++;
            _running = true;
            _startedAt = UtcNow;
            _polls = 0;
            UtcNow += TimeSpan.FromSeconds(2);
            return Task.FromResult(true);
        }

        public Task<bool> IsServiceRunningAsync(CancellationToken ct)
        {
            _polls++;
            if (_starts == 1 && Behaviour == NewBuild.ServiceDies)
                return Task.FromResult(false);
            // A crash-looping build: each restart is a new pid, which the real
            // environment reports as "not running" for that poll.
            if (_starts == 1 && Behaviour == NewBuild.RestartsUnderKeepAlive && _polls % 3 == 0)
                return Task.FromResult(false);
            return Task.FromResult(_running);
        }

        public ClientStatusDocument? ReadStatus()
        {
            if (_starts == 0)
            {
                var baseline = new ClientStatusDocument { Connected = true, Version = "1.0.46", UpdatedAt = UtcNow.AddSeconds(-10) };
                if (BaselineForwardsRunning)
                    baseline.Forwards.Add(new ClientStatusForward { ForwardId = 7, Running = true });
                return baseline;
            }

            if (_starts > 1)
                return Healthy("1.0.46");
            if (UtcNow - _startedAt < TimeSpan.FromSeconds(15))
                return new ClientStatusDocument { Connected = true, Version = "1.0.46", UpdatedAt = _startedAt.AddMinutes(-1) };
            return Behaviour switch
            {
                NewBuild.NeverWritesStatus => new ClientStatusDocument { Connected = true, Version = "1.0.46", UpdatedAt = _startedAt.AddMinutes(-1) },
                NewBuild.ServiceDies => null,
                NewBuild.Disconnected => new ClientStatusDocument { Connected = false, Version = "1.0.47", UpdatedAt = UtcNow },
                NewBuild.WrongVersion => Healthy("1.0.45"),
                NewBuild.ConnectedWithoutForwards => new ClientStatusDocument { Connected = true, Version = "1.0.47", UpdatedAt = UtcNow },
                _ => Healthy("1.0.47"),
            };
        }

        private ClientStatusDocument Healthy(string version)
        {
            var status = new ClientStatusDocument { Connected = true, Version = version, UpdatedAt = UtcNow };
            status.Forwards.Add(new ClientStatusForward { ForwardId = 7, Running = true });
            return status;
        }
    }

    /// <summary>A launchctl that keeps just enough job state to answer print.</summary>
    private sealed class ScriptedLaunchctl
    {
        public bool Loaded { get; set; }
        public bool Running { get; set; }
        public int Pid { get; set; }
        public bool IgnoreBootout { get; set; }
        public List<string> Commands { get; } = [];

        public MacUpdateApplyEnvironment Environment(
            IEnumerable<int>? excludePids = null,
            Func<IReadOnlyList<MacProcessEntry>>? listProcesses = null,
            Func<int, int, int>? signal = null)
        {
            var clock = new DateTimeOffset(2026, 9, 24, 10, 0, 0, TimeSpan.Zero);
            return new MacUpdateApplyEnvironment(
                MacHeadlessUpdatePaths.Label,
                MacHeadlessUpdatePaths.PlistPath,
                "/nonexistent/headless-status.json",
                excludePids,
                RunAsync,
                listProcesses ?? (() => []),
                signal ?? ((_, _) => 0),
                (delay, _) =>
                {
                    clock += delay;
                    return Task.CompletedTask;
                },
                () => clock);
        }

        private Task<(int ExitCode, string Output)> RunAsync(IReadOnlyList<string> arguments, CancellationToken ct)
        {
            var command = string.Join(' ', arguments);
            Commands.Add(command);
            switch (arguments[0])
            {
                case "print":
                    return Task.FromResult(Loaded
                        ? (0, $"state = {(Running ? "running" : "waiting")}\n" + (Running ? $"pid = {Pid}\n" : string.Empty))
                        : (113, "Could not find service"));
                case "bootout":
                    if (!IgnoreBootout)
                    {
                        Loaded = false;
                        Running = false;
                    }

                    return Task.FromResult((0, string.Empty));
                case "bootstrap":
                    Loaded = true;
                    Running = true;
                    Pid = 300;
                    return Task.FromResult((0, string.Empty));
                case "kickstart":
                    if (!Loaded)
                        return Task.FromResult((113, "Could not find service"));
                    Running = true;
                    if (arguments.Contains("-k"))
                        Pid++;
                    return Task.FromResult((0, string.Empty));
                default:
                    return Task.FromResult((0, string.Empty));
            }
        }
    }
}
