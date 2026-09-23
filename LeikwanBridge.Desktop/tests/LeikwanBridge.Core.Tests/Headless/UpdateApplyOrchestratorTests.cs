using LeikwanBridge.Core.Services;
using Xunit;

namespace LeikwanBridge.Core.Tests.Headless;

public sealed class UpdateApplyOrchestratorTests
{
    private const string Exe = "leikwan-bridge-headless.exe";

    [Fact]
    public async Task HealthyNewBuildIsKeptAndRecordedAsApplied()
    {
        using var workspace = new Workspace();
        workspace.Environment.NewBuildBehaviour = NewBuild.Healthy;

        var outcome = await workspace.Run();

        Assert.Equal(UpdateApplyOutcome.Applied, outcome);
        Assert.Equal("new", workspace.ReadInstall(Exe));
        Assert.Equal("added-by-new", workspace.ReadInstall(Path.Combine("bin", "extra.dll")));
        Assert.True(workspace.Environment.StopCalls >= 1);
        Assert.True(workspace.Environment.DrainCalls >= 1);
        var backup = Assert.Single(Directory.GetDirectories(workspace.BackupRoot));
        Assert.StartsWith("install-0.1.20.53-", Path.GetFileName(backup));
        Assert.Equal("old", File.ReadAllText(Path.Combine(backup, Exe)));
        var state = HeadlessUpdateStateStore.Load(workspace.StatePath);
        Assert.Equal(HeadlessUpdateResults.Applied, state.LastResult);
        Assert.Equal(0, state.Attempts);
        Assert.Equal("0.1.20.54", state.LastAppliedVersion);
    }

    [Fact]
    public async Task FilesAreSwappedOnlyAfterTheServiceAndItsProcessesAreGone()
    {
        using var workspace = new Workspace();
        workspace.Environment.NewBuildBehaviour = NewBuild.Healthy;
        workspace.Environment.OnDrain = directory =>
        {
            // At drain time nothing has been copied yet.
            Assert.Equal("old", File.ReadAllText(Path.Combine(directory, Exe)));
        };

        Assert.Equal(UpdateApplyOutcome.Applied, await workspace.Run());
        Assert.Equal(["stop", "drain", "start"], workspace.Environment.Calls.Take(3).ToArray());
    }

    [Theory]
    [InlineData(NewBuild.NeverWritesStatus)]
    [InlineData(NewBuild.Disconnected)]
    [InlineData(NewBuild.ServiceDies)]
    [InlineData(NewBuild.CrashesAfterFirstStatus)]
    public async Task UnhealthyNewBuildIsRolledBackAndRejected(NewBuild behaviour)
    {
        using var workspace = new Workspace();
        workspace.Environment.NewBuildBehaviour = behaviour;

        var outcome = await workspace.Run();

        Assert.Equal(UpdateApplyOutcome.RejectedRolledBack, outcome);
        Assert.Equal("old", workspace.ReadInstall(Exe));
        Assert.Equal("old-runtime", workspace.ReadInstall(Path.Combine("bin", "ultra-client.exe")));
        Assert.False(File.Exists(Path.Combine(workspace.Install, "bin", "extra.dll")));
        // Old service started again after the restore.
        Assert.Equal("start", workspace.Environment.Calls[^1]);
        var state = HeadlessUpdateStateStore.Load(workspace.StatePath);
        Assert.Equal(HeadlessUpdateResults.Rejected, state.LastResult);
        Assert.Contains("0.1.20.54", state.RejectedVersions);
        Assert.StartsWith("health gate:", state.LastError);
    }

    [Fact]
    public async Task ForwardRegressionIsRolledBackWhenTheOldBuildHadForwardsRunning()
    {
        using var workspace = new Workspace();
        workspace.Environment.BaselineForwardsRunning = true;
        workspace.Environment.NewBuildBehaviour = NewBuild.ConnectedWithoutForwards;

        Assert.Equal(UpdateApplyOutcome.RejectedRolledBack, await workspace.Run());
        Assert.Equal("old", workspace.ReadInstall(Exe));
    }

    [Fact]
    public async Task BrokenOldBuildWithoutForwardsDoesNotBlockAConnectedFix()
    {
        using var workspace = new Workspace();
        workspace.Environment.BaselineForwardsRunning = false;
        workspace.Environment.NewBuildBehaviour = NewBuild.ConnectedWithoutForwards;

        Assert.Equal(UpdateApplyOutcome.Applied, await workspace.Run());
    }

    [Fact]
    public async Task ServiceThatWillNotStopLeavesFilesUntouched()
    {
        using var workspace = new Workspace();
        workspace.Environment.StopSucceeds = false;

        var outcome = await workspace.Run();

        Assert.Equal(UpdateApplyOutcome.StopFailed, outcome);
        Assert.Equal("old", workspace.ReadInstall(Exe));
        Assert.False(Directory.Exists(workspace.BackupRoot) && Directory.GetDirectories(workspace.BackupRoot).Length > 0);
        Assert.Equal("start", workspace.Environment.Calls[^1]);
        var state = HeadlessUpdateStateStore.Load(workspace.StatePath);
        Assert.Equal(HeadlessUpdateResults.StopFailed, state.LastResult);
        Assert.Empty(state.RejectedVersions); // not the artifact's fault
        Assert.NotNull(state.NextRetryAt);
    }

    [Fact]
    public async Task InstallDirectoryWithoutTheClientBinaryIsRefused()
    {
        using var workspace = new Workspace();
        File.Delete(Path.Combine(workspace.Install, Exe));

        Assert.Equal(UpdateApplyOutcome.InvalidRequest, await workspace.Run());
        Assert.Empty(workspace.Environment.Calls);
    }

    [Fact]
    public async Task OnlyTheNewestThreeBackupsAreKept()
    {
        using var workspace = new Workspace();
        Directory.CreateDirectory(workspace.BackupRoot);
        for (var index = 0; index < 4; index++)
        {
            var old = Directory.CreateDirectory(Path.Combine(workspace.BackupRoot, $"install-0.1.20.4{index}-2026090{index}T000000Z"));
            Directory.SetCreationTimeUtc(old.FullName, new DateTime(2020, 9, 1 + index, 0, 0, 0, DateTimeKind.Utc));
        }

        workspace.Environment.NewBuildBehaviour = NewBuild.Healthy;
        Assert.Equal(UpdateApplyOutcome.Applied, await workspace.Run());

        var names = Directory.GetDirectories(workspace.BackupRoot).Select(Path.GetFileName).OrderBy(name => name).ToList();
        Assert.Equal(3, names.Count);
        Assert.Contains(names, name => name!.StartsWith("install-0.1.20.53-", StringComparison.Ordinal));
        Assert.Contains("install-0.1.20.43-20260903T000000Z", names);
        Assert.Contains("install-0.1.20.42-20260902T000000Z", names);
    }

    [Fact]
    public void HealthGateRequiresAFreshConnectedStatusFromTheNewVersion()
    {
        var swapped = new DateTimeOffset(2026, 9, 24, 10, 0, 0, TimeSpan.Zero);
        var fresh = new ClientStatusDocument { Connected = true, Version = "0.1.20.54", UpdatedAt = swapped.AddSeconds(20) };

        Assert.True(UpdateHealthGate.IsHealthy(true, fresh, swapped, "0.1.20.54", 0));
        Assert.False(UpdateHealthGate.IsHealthy(false, fresh, swapped, "0.1.20.54", 0));
        Assert.False(UpdateHealthGate.IsHealthy(true, null, swapped, "0.1.20.54", 0));
        Assert.False(UpdateHealthGate.IsHealthy(true, new ClientStatusDocument { Connected = true, Version = "0.1.20.54", UpdatedAt = swapped.AddSeconds(-1) }, swapped, "0.1.20.54", 0));
        Assert.False(UpdateHealthGate.IsHealthy(true, new ClientStatusDocument { Connected = false, Version = "0.1.20.54", UpdatedAt = swapped.AddSeconds(20) }, swapped, "0.1.20.54", 0));
        Assert.False(UpdateHealthGate.IsHealthy(true, new ClientStatusDocument { Connected = true, Version = "0.1.20.53", UpdatedAt = swapped.AddSeconds(20) }, swapped, "0.1.20.54", 0));
        // Pre-change builds wrote the three-part version.
        Assert.True(UpdateHealthGate.IsHealthy(true, new ClientStatusDocument { Connected = true, Version = "0.1.20", UpdatedAt = swapped.AddSeconds(20) }, swapped, "0.1.20.54", 0));
        // Forwards only required when the old build had some running.
        Assert.False(UpdateHealthGate.IsHealthy(true, fresh, swapped, "0.1.20.54", 2));
        fresh.Forwards.Add(new ClientStatusForward { ForwardId = 1, Running = true });
        Assert.True(UpdateHealthGate.IsHealthy(true, fresh, swapped, "0.1.20.54", 2));
    }

    [Fact]
    public void HealthVerdictIsPendingUntilTheDeadline()
    {
        var swapped = new DateTimeOffset(2026, 9, 24, 10, 0, 0, TimeSpan.Zero);
        var deadline = swapped.AddSeconds(180);
        Assert.Equal(UpdateHealthVerdict.Pending, UpdateHealthGate.Evaluate(true, null, swapped, "1.0.0.1", 0, swapped.AddSeconds(179), deadline));
        Assert.Equal(UpdateHealthVerdict.Unhealthy, UpdateHealthGate.Evaluate(true, null, swapped, "1.0.0.1", 0, deadline, deadline));
    }

    public enum NewBuild
    {
        Healthy,
        NeverWritesStatus,
        Disconnected,
        ServiceDies,
        CrashesAfterFirstStatus,
        ConnectedWithoutForwards,
    }

    private sealed class Workspace : IDisposable
    {
        private readonly string _root = Path.Combine(Path.GetTempPath(), "lkb-apply-" + Guid.NewGuid().ToString("N"));

        public Workspace()
        {
            Write(Install, Exe, "old");
            Write(Install, Path.Combine("bin", "ultra-client.exe"), "old-runtime");
            Write(Install, "Core.dll", "old-core");
            Write(Staged, Exe, "new");
            Write(Staged, "Core.dll", "new-core");
            Write(Staged, Path.Combine("bin", "extra.dll"), "added-by-new");
            Environment = new FakeEnvironment();
        }

        public string Install => Path.Combine(_root, "install");
        public string Staged => Path.Combine(_root, "staged");
        public string BackupRoot => Path.Combine(_root, "backups");
        public string StatePath => Path.Combine(_root, "updates", "update-state.json");
        public FakeEnvironment Environment { get; }

        public Task<UpdateApplyOutcome> Run()
            => new UpdateApplyOrchestrator(Environment, new UpdateApplyOptions
            {
                StagedDirectory = Staged,
                InstallDirectory = Install,
                BackupRoot = BackupRoot,
                StatePath = StatePath,
                PreviousVersion = "0.1.20.53",
                NewVersion = "0.1.20.54",
            }).RunAsync(CancellationToken.None);

        public string ReadInstall(string relative) => File.ReadAllText(Path.Combine(Install, relative));

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

        private static void Write(string root, string relative, string content)
        {
            var path = Path.Combine(root, relative);
            Directory.CreateDirectory(Path.GetDirectoryName(path)!);
            File.WriteAllText(path, content);
        }
    }

    /// <summary>Simulated SCM + status file on a virtual clock.</summary>
    private sealed class FakeEnvironment : IUpdateApplyEnvironment
    {
        private int _starts;
        private DateTimeOffset _startedAt;
        private bool _running = true;

        public DateTimeOffset UtcNow { get; private set; } = new(2026, 9, 24, 10, 0, 0, TimeSpan.Zero);
        public NewBuild NewBuildBehaviour { get; set; } = NewBuild.Healthy;
        public bool StopSucceeds { get; set; } = true;
        public bool BaselineForwardsRunning { get; set; }
        public int StopCalls { get; private set; }
        public int DrainCalls { get; private set; }
        public List<string> Calls { get; } = [];
        public Action<string>? OnDrain { get; set; }

        public Task DelayAsync(TimeSpan delay, CancellationToken ct)
        {
            UtcNow += delay;
            return Task.CompletedTask;
        }

        public Task<bool> StopServiceAsync(TimeSpan timeout, CancellationToken ct)
        {
            StopCalls++;
            Calls.Add("stop");
            if (StopSucceeds)
                _running = false;
            return Task.FromResult(StopSucceeds);
        }

        public Task<bool> WaitForInstallProcessesExitAsync(string installDirectory, TimeSpan timeout, CancellationToken ct)
        {
            DrainCalls++;
            Calls.Add("drain");
            OnDrain?.Invoke(installDirectory);
            OnDrain = null;
            return Task.FromResult(true);
        }

        public Task<bool> StartServiceAsync(CancellationToken ct)
        {
            Calls.Add("start");
            _starts++;
            _running = true;
            _startedAt = UtcNow;
            UtcNow += TimeSpan.FromSeconds(2);
            return Task.FromResult(true);
        }

        public Task<bool> IsServiceRunningAsync(CancellationToken ct)
        {
            if (_starts == 1 && NewBuildBehaviour == NewBuild.ServiceDies)
                return Task.FromResult(false);
            if (_starts == 1 && NewBuildBehaviour == NewBuild.CrashesAfterFirstStatus && UtcNow - _startedAt > TimeSpan.FromSeconds(25))
                return Task.FromResult(false);
            return Task.FromResult(_running);
        }

        public ClientStatusDocument? ReadStatus()
        {
            if (_starts == 0)
            {
                // What the old build last wrote.
                var baseline = new ClientStatusDocument { Connected = true, Version = "0.1.20.53", UpdatedAt = UtcNow.AddSeconds(-10) };
                if (BaselineForwardsRunning)
                    baseline.Forwards.Add(new ClientStatusForward { ForwardId = 7, Running = true });
                return baseline;
            }

            if (_starts > 1)
                return new ClientStatusDocument { Connected = true, Version = "0.1.20.53", UpdatedAt = UtcNow };

            var sinceStart = UtcNow - _startedAt;
            return NewBuildBehaviour switch
            {
                NewBuild.NeverWritesStatus => new ClientStatusDocument { Connected = true, Version = "0.1.20.53", UpdatedAt = _startedAt.AddMinutes(-1) },
                NewBuild.ServiceDies => null,
                _ when sinceStart < TimeSpan.FromSeconds(15) => new ClientStatusDocument { Connected = true, Version = "0.1.20.53", UpdatedAt = _startedAt.AddMinutes(-1) },
                NewBuild.Disconnected => new ClientStatusDocument { Connected = false, Version = "0.1.20.54", UpdatedAt = UtcNow },
                NewBuild.ConnectedWithoutForwards => new ClientStatusDocument { Connected = true, Version = "0.1.20.54", UpdatedAt = UtcNow },
                _ => Healthy(),
            };
        }

        private ClientStatusDocument Healthy()
        {
            var status = new ClientStatusDocument { Connected = true, Version = "0.1.20.54", UpdatedAt = UtcNow };
            status.Forwards.Add(new ClientStatusForward { ForwardId = 7, Running = true });
            return status;
        }
    }
}
