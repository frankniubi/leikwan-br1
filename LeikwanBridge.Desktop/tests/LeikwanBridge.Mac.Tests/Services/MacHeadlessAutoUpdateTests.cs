using LeikwanBridge.Core.Services;
using LeikwanBridge.Mac.Services;
using Xunit;

namespace LeikwanBridge.Mac.Tests.Services;

/// <summary>
/// The daemon's self-update runs the shared scheduler/decision rules
/// (<see cref="HeadlessAutoUpdateCoordinator"/>) on top of the macOS update zip:
/// same first-check window, backoff, downgrade/rejection rules, config switch
/// and apply lock as the Windows service.
/// </summary>
public sealed class MacHeadlessAutoUpdateTests
{
    private static readonly DateTimeOffset Now = new(2026, 9, 24, 12, 0, 0, TimeSpan.Zero);

    [Fact]
    public async Task NewerPublishedBundleIsVerifiedAndHandedToADetachedHelper()
    {
        using var world = new World(installed: "1.0.46", published: "1.0.47");

        var result = await world.Coordinator().RunCycleAsync(CancellationToken.None);

        Assert.Equal(HeadlessUpdateDecisionKind.Apply, result.Decision);
        Assert.True(result.Launched, result.Detail);
        var spawn = Assert.Single(world.Spawner.Spawns);
        // The helper runs from a clone of the new bundle under the updates
        // directory, never from the bundle the swap renames.
        Assert.StartsWith(Path.Combine(world.UpdatesRoot, "applier-"), spawn.Executable, StringComparison.Ordinal);
        Assert.EndsWith(Path.Combine("Contents", "MacOS", "LeikwanBridge.Mac"), spawn.Executable, StringComparison.Ordinal);
        Assert.Equal(MacHeadlessUpdatePaths.ApplyCommand, spawn.Arguments[0]);
        Assert.Equal(world.Install, Arg(spawn.Arguments, "--install"));
        Assert.Equal("1.0.47", Arg(spawn.Arguments, "--version"));
        Assert.Equal("1.0.46", Arg(spawn.Arguments, "--previous-version"));
        var staged = Arg(spawn.Arguments, "--staged");
        Assert.Equal("1.0.47", MacBundleValidator.ReadBundleVersion(staged));
        Assert.Equal("new", MacUpdateFixtures.Marker(staged));
        // The attempt is counted and backed off before anything is touched.
        var state = HeadlessUpdateStateStore.Load(world.StatePath);
        Assert.Equal(HeadlessUpdateResults.ApplyStarted, state.LastResult);
        Assert.Equal(1, state.Attempts);
        Assert.Equal(Now + TimeSpan.FromMinutes(15), state.NextRetryAt);
        // The install itself is not touched by the check.
        Assert.Equal("old", MacUpdateFixtures.Marker(world.Install));
    }

    [Theory]
    [InlineData("1.0.46", HeadlessUpdateDecisionKind.UpToDate)]
    [InlineData("1.0.45", HeadlessUpdateDecisionKind.DowngradeRefused)]
    public async Task SameOrOlderPublishedVersionIsNeverApplied(string published, HeadlessUpdateDecisionKind expected)
    {
        using var world = new World(installed: "1.0.46", published: published);

        var result = await world.Coordinator().RunCycleAsync(CancellationToken.None);

        Assert.Equal(expected, result.Decision);
        Assert.Empty(world.Spawner.Spawns);
        Assert.Equal(0, world.Downloads);
    }

    [Fact]
    public async Task RejectedVersionIsNotRetried()
    {
        using var world = new World(installed: "1.0.46", published: "1.0.47");
        var state = new HeadlessUpdateState { RejectedVersions = ["1.0.47"] };
        HeadlessUpdateStateStore.Save(world.StatePath, state);

        var result = await world.Coordinator().RunCycleAsync(CancellationToken.None);

        Assert.Equal(HeadlessUpdateDecisionKind.Rejected, result.Decision);
        Assert.Empty(world.Spawner.Spawns);
    }

    [Fact]
    public async Task DisableAutoUpdatesIsReReadEveryCycle()
    {
        using var world = new World(installed: "1.0.46", published: "1.0.47");
        File.WriteAllText(world.ConfigPath, """{ "master_url": "https://panel.example.test", "disable_auto_updates": true }""");
        var coordinator = world.Coordinator();

        Assert.Equal(HeadlessUpdateDecisionKind.Disabled, (await coordinator.RunCycleAsync(CancellationToken.None)).Decision);
        Assert.Empty(world.Spawner.Spawns);

        File.WriteAllText(world.ConfigPath, """{ "disable_auto_updates": false }""");
        Assert.Equal(HeadlessUpdateDecisionKind.Apply, (await coordinator.RunCycleAsync(CancellationToken.None)).Decision);
        Assert.Single(world.Spawner.Spawns);
    }

    [Fact]
    public async Task WithoutAConsoleRequestTheDaemonLeavesAPublishedBundleAlone()
    {
        using var world = new World(installed: "1.0.46", published: "1.0.47") { RequestToken = "" };

        var result = await world.Coordinator().RunCycleAsync(CancellationToken.None);

        Assert.Equal(HeadlessUpdateDecisionKind.NotRequested, result.Decision);
        Assert.Equal(0, world.Downloads);
        Assert.Empty(world.Spawner.Spawns);
    }

    [Fact]
    public async Task AConsoleRequestIsInstalledOnceAndCompletedByTheRestartedBuild()
    {
        using var world = new World(installed: "1.0.46", published: "1.0.47") { RequestToken = "click-1" };

        Assert.True((await world.Coordinator().RunCycleAsync(CancellationToken.None)).Launched);
        Assert.Single(world.Spawner.Spawns);

        // The helper swapped the bundle in and restarted the daemon on 1.0.47.
        world.InstallVersion("1.0.47");
        var restarted = world.Coordinator();
        Assert.Equal(HeadlessUpdateDecisionKind.UpToDate, (await restarted.RunCycleAsync(CancellationToken.None)).Decision);
        Assert.Equal("click-1", HeadlessUpdateStateStore.Load(world.StatePath).HandledRequestToken);
        Assert.Equal(HeadlessUpdateDecisionKind.NotRequested, (await restarted.RunCycleAsync(CancellationToken.None)).Decision);
        Assert.Single(world.Spawner.Spawns);
    }

    [Fact]
    public async Task DisableAutoUpdatesBeatsAConsoleRequest()
    {
        using var world = new World(installed: "1.0.46", published: "1.0.47") { RequestToken = "click-1" };
        File.WriteAllText(world.ConfigPath, """{ "disable_auto_updates": true }""");

        var result = await world.Coordinator().RunCycleAsync(CancellationToken.None);

        Assert.Equal(HeadlessUpdateDecisionKind.Disabled, result.Decision);
        Assert.Equal(0, world.Downloads);
        Assert.Equal(string.Empty, HeadlessUpdateStateStore.Load(world.StatePath).HandledRequestToken);
    }

    [Fact]
    public async Task BundleWhoseVersionDiffersFromTheManifestIsRefusedAndBackedOff()
    {
        // The zip is well-formed and its sha/size match the manifest, but the
        // bundle inside says 1.0.46: installing it would loop forever.
        using var world = new World(installed: "1.0.46", published: "1.0.47", bundleVersion: "1.0.46");
        var coordinator = world.Coordinator();

        var first = await coordinator.RunCycleAsync(CancellationToken.None);

        Assert.Equal(HeadlessUpdateResults.VerifyFailed, first.Detail);
        Assert.False(first.Launched);
        var state = HeadlessUpdateStateStore.Load(world.StatePath);
        Assert.Equal(HeadlessUpdateResults.VerifyFailed, state.LastResult);
        Assert.Contains("bundle_version_invalid", state.LastError, StringComparison.Ordinal);
        Assert.Equal(Now + TimeSpan.FromMinutes(15), state.NextRetryAt);

        var second = await coordinator.RunCycleAsync(CancellationToken.None);
        Assert.Equal(HeadlessUpdateDecisionKind.BackingOff, second.Decision);
        Assert.Empty(world.Spawner.Spawns);
    }

    [Fact]
    public async Task PackageThatDoesNotMatchTheManifestHashIsRefused()
    {
        using var world = new World(installed: "1.0.46", published: "1.0.47", manifestSha: new string('b', 64));

        var result = await world.Coordinator().RunCycleAsync(CancellationToken.None);

        Assert.Equal(HeadlessUpdateResults.VerifyFailed, result.Detail);
        Assert.Contains("hash", HeadlessUpdateStateStore.Load(world.StatePath).LastError, StringComparison.Ordinal);
        Assert.Empty(world.Spawner.Spawns);
    }

    [Fact]
    public async Task HeldApplyLockSkipsTheCycle()
    {
        using var world = new World(installed: "1.0.46", published: "1.0.47");
        Directory.CreateDirectory(world.UpdatesRoot);
        using var held = UpdateApplyLock.TryAcquire(world.LockPath);
        Assert.NotNull(held);

        var result = await world.Coordinator().RunCycleAsync(CancellationToken.None);

        Assert.Equal(HeadlessUpdateDecisionKind.ApplyInProgress, result.Decision);
        Assert.Equal(0, world.Downloads);
    }

    [Fact]
    public async Task HelperThatDoesNotStartIsRecordedAsALaunchFailure()
    {
        using var world = new World(installed: "1.0.46", published: "1.0.47");
        world.Spawner.Pid = -1;

        var result = await world.Coordinator().RunCycleAsync(CancellationToken.None);

        Assert.Equal(HeadlessUpdateResults.LaunchFailed, result.Detail);
        var state = HeadlessUpdateStateStore.Load(world.StatePath);
        Assert.Equal(HeadlessUpdateResults.LaunchFailed, state.LastResult);
        Assert.Equal(1, state.Attempts);
    }

    [Fact]
    public async Task LoopUsesTheSharedFirstCheckWindowAndRegularInterval()
    {
        using var world = new World(installed: "1.0.46", published: "1.0.46");
        var delays = new List<TimeSpan>();
        using var stop = new CancellationTokenSource();

        await world.Coordinator().RunLoopAsync(new Random(7), (delay, ct) =>
        {
            delays.Add(delay);
            if (delays.Count >= 2)
                stop.Cancel();
            ct.ThrowIfCancellationRequested();
            return Task.CompletedTask;
        }, stop.Token);

        Assert.Equal(2, delays.Count);
        Assert.InRange(delays[0], TimeSpan.FromMinutes(1), TimeSpan.FromMinutes(10));
        Assert.InRange(delays[1], TimeSpan.FromHours(5.5), TimeSpan.FromHours(6.5));
    }

    [Fact]
    public void OnlyTheLaunchdDaemonRunsTheLoop()
    {
        var service = Options();
        var once = Options("--once");
        const string bundle = "/Applications/LeiKwan Bridge.app";

        Assert.True(MacHeadlessAutoUpdate.ShouldRun(service, bundle, effectiveUid: 0, parentPid: 1));
        Assert.False(MacHeadlessAutoUpdate.ShouldRun(once, bundle, 0, 1));
        Assert.False(MacHeadlessAutoUpdate.ShouldRun(service, bundle, 501, 1)); // user-run
        Assert.False(MacHeadlessAutoUpdate.ShouldRun(service, bundle, 0, 4242)); // sudo in a terminal
        Assert.False(MacHeadlessAutoUpdate.ShouldRun(service, "/Users/dev/build/publish", 0, 1)); // not a bundle
    }

    [Fact]
    public void DaemonContextKeepsStateUnderTheRootOnlyDirectory()
    {
        using var root = new TempRoot();
        var paths = new MacPlatformPaths(root.Path, "/Applications/LeiKwan Bridge.app", ensureDirectories: false);

        var context = MacHeadlessUpdateContext.ForDaemon(paths);

        Assert.Equal("/Library/Application Support/Leikwan/ClientAgent/updates", context.UpdatesRoot);
        Assert.Equal("/Library/Application Support/Leikwan/ClientAgent/updates/update-state.json", context.StatePath);
        Assert.Equal("/Library/Application Support/Leikwan/ClientAgent/updates/apply.lock", context.LockPath);
        Assert.Equal("/Library/Application Support/Leikwan/ClientAgent/backups", MacHeadlessUpdatePaths.BackupRoot);
        Assert.Contains(paths.ConfigPath, context.ConfigPaths);
    }

    [Fact]
    public async Task LauncherRefusesAStagedBundleWithAnotherVersion()
    {
        using var root = new TempRoot();
        var install = MacUpdateFixtures.CreateBundle(root.Sub("Applications"), "1.0.46", "old");
        var staged = MacUpdateFixtures.CreateBundle(root.Sub("stage"), "1.0.46", "new");
        var spawner = new FakeSpawner();
        var launcher = new MacHeadlessUpdateLauncher(install, root.Sub("updates"), root.Sub("logs"), spawner, new ManagedBundleFileSystem());

        Assert.False(await launcher.LaunchAsync(staged, "1.0.47", CancellationToken.None));
        Assert.Empty(spawner.Spawns);
    }

    private static HeadlessClientOptions Options(params string[] extra)
    {
        Assert.True(HeadlessClientOptions.TryParse(["--headless", "--client-id", "1", "--agent-token", "t", .. extra], out var options, out var error), error);
        return options;
    }

    private static string Arg(IReadOnlyList<string> arguments, string name)
    {
        var index = arguments.ToList().IndexOf(name);
        Assert.True(index >= 0 && index + 1 < arguments.Count, name);
        return arguments[index + 1];
    }

    internal sealed class FakeSpawner : IMacDetachedSpawner
    {
        public int Pid { get; set; } = 4242;
        public List<(string Executable, IReadOnlyList<string> Arguments, string Log)> Spawns { get; } = [];
        public Action<IReadOnlyList<string>>? OnSpawn { get; set; }

        public int Spawn(string executable, IReadOnlyList<string> arguments, string outputLogPath)
        {
            if (Pid <= 0)
                return Pid;
            Spawns.Add((executable, arguments, outputLogPath));
            OnSpawn?.Invoke(arguments);
            return Pid;
        }
    }

    private sealed class World : IDisposable
    {
        private readonly TempRoot _root = new();
        private readonly HttpClient _http;
        private readonly MacAppUpdateService _updater;

        public World(string installed, string published, string? bundleVersion = null, string? manifestSha = null)
        {
            Install = MacUpdateFixtures.CreateBundle(_root.Sub("Applications"), installed, "old");
            var source = MacUpdateFixtures.CreateBundle(_root.Sub("build"), bundleVersion ?? published, "new");
            var package = MacUpdateFixtures.ZipBundle(source);
            _http = MacUpdateFixtures.Panel(
                () => MacUpdateFixtures.Manifest(published, package, manifestSha),
                () =>
                {
                    Downloads++;
                    return package;
                });
            _updater = MacUpdateFixtures.Updater(UpdatesRoot, Install, _http);
            Directory.CreateDirectory(Path.GetDirectoryName(ConfigPath)!);
        }

        public string Install { get; }
        public string UpdatesRoot => _root.Sub("state", "updates");
        public string StatePath => Path.Combine(UpdatesRoot, "update-state.json");
        public string LockPath => Path.Combine(UpdatesRoot, "apply.lock");
        public string ConfigPath => _root.Sub("home", "config.json");
        public FakeSpawner Spawner { get; } = new();
        public int Downloads { get; private set; }

        /// <summary>The panel's pending console update request (pending by default).</summary>
        public string RequestToken { get; set; } = "console-request-1";

        /// <summary>Rewrites the installed bundle as another version, as the
        /// detached helper's swap would.</summary>
        public void InstallVersion(string version)
            => MacUpdateFixtures.CreateBundle(_root.Sub("Applications"), version, "new");

        public HeadlessAutoUpdateCoordinator Coordinator()
            => MacHeadlessAutoUpdate.Create(
                new MacHeadlessUpdateContext(Install, UpdatesRoot, StatePath, LockPath, _root.Sub("logs"), [ConfigPath]),
                () => "https://panel.example.test",
                _updater,
                Spawner,
                new ManagedBundleFileSystem(),
                () => Now,
                () => RequestToken);

        public void Dispose()
        {
            _updater.Dispose();
            _http.Dispose();
            _root.Dispose();
        }
    }
}
