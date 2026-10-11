using LeikwanBridge.Core.Models;
using LeikwanBridge.Core.Services;
using LeikwanBridge.Mac.Services;
using Xunit;

namespace LeikwanBridge.Mac.Tests.Services;

/// <summary>
/// `leikwan update` with the LaunchDaemon installed must restart the daemon on
/// the new bundle through the health-gated apply step, instead of the windowed
/// client's helper (which relaunched the GUI and left the daemon on old code).
/// </summary>
public sealed class MacCliDaemonUpdateTests
{
    [Fact]
    public async Task InstalledDaemonIsUpdatedThroughTheHealthGatedApplyStep()
    {
        using var world = new CliWorld();
        world.Headless.Result = Outcome(nameof(UpdateApplyOutcome.Applied));

        var (exit, output) = await world.RunUpdateAsync();

        Assert.Equal(0, exit);
        var prepared = Assert.Single(world.Headless.Applied);
        Assert.Equal("90.0.1", prepared.Artifact.Version);
        Assert.Equal("90.0.1", MacBundleValidator.ReadBundleVersion(prepared.StagedBundlePath));
        // The GUI helper path (swap + "open -n" relaunch) is not used.
        Assert.Empty(world.GuiLauncher.Started);
        Assert.Contains("daemon restarted on 90.0.1 and passed the health gate", output, StringComparison.Ordinal);
    }

    [Fact]
    public async Task RolledBackDaemonUpdateFailsTheCommand()
    {
        using var world = new CliWorld();
        world.Headless.Result = Outcome(nameof(UpdateApplyOutcome.RejectedRolledBack));

        var (exit, output) = await world.RunUpdateAsync();

        Assert.NotEqual(0, exit);
        Assert.Contains("90.0.1 failed the health gate; rolled back to 90.0.0", output, StringComparison.Ordinal);
    }

    [Fact]
    public async Task NonRootCallerIsToldToUseSudoBeforeAnythingIsDownloaded()
    {
        using var world = new CliWorld();
        world.Headless.Precondition = "the daemon update must run as root; use: sudo leikwan update";

        var (exit, output) = await world.RunUpdateAsync();

        Assert.NotEqual(0, exit);
        Assert.Contains("sudo leikwan update", output, StringComparison.Ordinal);
        Assert.Empty(world.Headless.Applied);
        Assert.Equal(0, world.PackageDownloads);
    }

    [Fact]
    public async Task WithoutTheDaemonTheWindowedUpdatePathIsKept()
    {
        using var world = new CliWorld();
        world.Headless.Installed = false;

        await world.RunUpdateAsync();

        Assert.Empty(world.Headless.Applied);
        Assert.Single(world.GuiLauncher.Started);
    }

    [Fact]
    public async Task CliInstallerLaunchesTheHelperAndWaitsForItsOutcome()
    {
        using var root = new TempRoot();
        var install = MacUpdateFixtures.CreateBundle(root.Sub("Applications"), "1.0.46", "old");
        var staged = MacUpdateFixtures.CreateBundle(root.Sub("updates", "stage-1"), "1.0.47", "new");
        var resultPath = root.Sub("updates", "headless-apply-result.json");
        var spawner = new MacHeadlessAutoUpdateTests.FakeSpawner();
        // The fake helper publishes its outcome for the request it was given.
        spawner.OnSpawn = arguments =>
        {
            var index = arguments.ToList().IndexOf("--request-id");
            MacHeadlessApplyResult.Write(resultPath, new MacHeadlessApplyResult
            {
                RequestId = arguments[index + 1],
                Outcome = nameof(UpdateApplyOutcome.Applied),
                PreviousVersion = "1.0.46",
                NewVersion = "1.0.47",
                At = DateTimeOffset.UtcNow,
            });
        };
        MacHeadlessApplyResult.Write(resultPath, new MacHeadlessApplyResult { RequestId = "stale", Outcome = "RejectedRolledBack" });
        var installer = new MacHeadlessCliUpdateInstaller(
            new MacHeadlessUpdateLauncher(install, root.Sub("updates"), root.Sub("logs"), spawner, new ManagedBundleFileSystem()),
            root.Sub("com.leikwan.bridge.headless.plist"),
            resultPath,
            () => 0,
            TimeSpan.FromSeconds(30),
            (_, _) => Task.CompletedTask);
        var prepared = new PreparedAppUpdate(new ClientAppArtifact { Version = "1.0.47" }, "unused.zip", staged, new string('A', 64));

        var result = await installer.ApplyAsync(prepared, TextWriter.Null, CancellationToken.None);

        Assert.NotNull(result);
        Assert.True(result!.Applied);
        var spawn = Assert.Single(spawner.Spawns);
        // This CLI runs from the installed bundle: it must be exempt from the drain.
        Assert.Equal(Environment.ProcessId.ToString(System.Globalization.CultureInfo.InvariantCulture), spawn.Arguments[spawn.Arguments.ToList().IndexOf("--exclude-pid") + 1]);
        Assert.False(installer.IsDaemonInstalled);
        Assert.Null(installer.PreconditionError());
        var nonRoot = new MacHeadlessCliUpdateInstaller(
            new MacHeadlessUpdateLauncher(install, root.Sub("updates"), root.Sub("logs"), spawner, new ManagedBundleFileSystem()),
            root.Sub("com.leikwan.bridge.headless.plist"),
            resultPath,
            () => 501);
        Assert.Contains("sudo", nonRoot.PreconditionError(), StringComparison.Ordinal);
    }

    [Fact]
    public async Task CliInstallerGivesUpWhenTheHelperNeverReports()
    {
        using var root = new TempRoot();
        var install = MacUpdateFixtures.CreateBundle(root.Sub("Applications"), "1.0.46", "old");
        var staged = MacUpdateFixtures.CreateBundle(root.Sub("updates", "stage-1"), "1.0.47", "new");
        var installer = new MacHeadlessCliUpdateInstaller(
            new MacHeadlessUpdateLauncher(install, root.Sub("updates"), root.Sub("logs"), new MacHeadlessAutoUpdateTests.FakeSpawner(), new ManagedBundleFileSystem()),
            root.Sub("missing.plist"),
            root.Sub("updates", "headless-apply-result.json"),
            () => 0,
            TimeSpan.FromSeconds(5),
            (_, _) => Task.CompletedTask);
        var prepared = new PreparedAppUpdate(new ClientAppArtifact { Version = "1.0.47" }, "unused.zip", staged, new string('A', 64));

        Assert.Null(await installer.ApplyAsync(prepared, TextWriter.Null, CancellationToken.None));
    }

    private static MacHeadlessApplyResult Outcome(string outcome) => new()
    {
        RequestId = "r",
        Outcome = outcome,
        PreviousVersion = "90.0.0",
        NewVersion = "90.0.1",
        At = DateTimeOffset.UtcNow,
    };

    private sealed class CliWorld : IDisposable
    {
        private readonly TempRoot _root = new();
        private readonly HttpClient _http;
        private readonly MacAppUpdateService _updater;
        private readonly MacServiceController _controller;

        public CliWorld()
        {
            // Above the test assembly's own version, which the CLI compares against.
            var install = MacUpdateFixtures.CreateBundle(_root.Sub("Applications"), "90.0.0", "old");
            var source = MacUpdateFixtures.CreateBundle(_root.Sub("build"), "90.0.1", "new");
            var package = MacUpdateFixtures.ZipBundle(source);
            _http = MacUpdateFixtures.Panel(
                () => MacUpdateFixtures.Manifest("90.0.1", package),
                () =>
                {
                    PackageDownloads++;
                    return package;
                });
            _updater = new MacAppUpdateService(
                _root.Sub("updates"),
                install,
                "arm64",
                _ => _http,
                ownsHttpClient: false,
                launcher: GuiLauncher,
                directoryWritable: _ => true,
                waitForCallerExit: false);
            _controller = new MacServiceController(new MacPlatformPaths(_root.Sub("home"), install));
        }

        public FakeHeadlessInstaller Headless { get; } = new();
        public RecordingLauncher GuiLauncher { get; } = new();
        public int PackageDownloads { get; private set; }

        public async Task<(int Exit, string Output)> RunUpdateAsync()
        {
            var service = new MacClientUpdateService(
                _updater,
                _controller,
                "https://panel.example.test",
                TimeSpan.FromMilliseconds(300),
                Headless);
            var output = new StringWriter();
            var exit = await HeadlessCli.RunAsync(["update"], _controller, service, output, output, CancellationToken.None);
            return (exit, output.ToString());
        }

        public void Dispose()
        {
            _updater.Dispose();
            _http.Dispose();
            _root.Dispose();
        }
    }

    internal sealed class FakeHeadlessInstaller : IMacHeadlessUpdateInstaller
    {
        public bool Installed { get; set; } = true;
        public string? Precondition { get; set; }
        public MacHeadlessApplyResult? Result { get; set; }
        public List<PreparedAppUpdate> Applied { get; } = [];

        public bool IsDaemonInstalled => Installed;

        public string? PreconditionError() => Precondition;

        public Task<MacHeadlessApplyResult?> ApplyAsync(PreparedAppUpdate prepared, TextWriter output, CancellationToken ct)
        {
            Applied.Add(prepared);
            return Task.FromResult(Result);
        }
    }

    internal sealed class RecordingLauncher : IMacUpdateLauncher
    {
        public List<string> Started { get; } = [];

        public bool StartProcess(string executable, IReadOnlyList<string> arguments)
        {
            Started.Add(executable);
            return true;
        }

        public bool OpenApplication(string bundlePath) => true;

        public bool OpenFile(string path) => true;
    }
}
