using LeikwanBridge.Core.Services;
using Xunit;

namespace LeikwanBridge.Core.Tests.Headless;

public sealed class HeadlessCliTests
{
    [Fact]
    public async Task StatusPrintsServiceClientAndForwards()
    {
        using var workspace = new CliWorkspace();
        workspace.WriteStatus(connected: true, running: true);

        var (exit, output) = await workspace.RunAsync("status");

        Assert.Equal(0, exit);
        Assert.Contains("service  : LeikwanBridgeHeadless — running", output);
        Assert.Contains("client   : connected (Default Client)", output);
        Assert.Contains("#158", output);
        Assert.Contains("forwards : 1/1 running", output);
    }

    [Fact]
    public async Task StatusFailsWhenNoForwardRuns()
    {
        using var workspace = new CliWorkspace();
        workspace.WriteStatus(connected: true, running: false);

        var (exit, output) = await workspace.RunAsync("status");

        Assert.Equal(1, exit);
        Assert.Contains("forwards : 0/1 running", output);
    }

    [Fact]
    public async Task StatusReportsAMissingStatusFileWithoutCrashing()
    {
        using var workspace = new CliWorkspace();

        var (exit, output) = await workspace.RunAsync("status");

        Assert.Equal(0, exit);
        Assert.Contains("no status file yet", output);
    }

    [Fact]
    public async Task VersionPrintsTheControllerVersion()
    {
        using var workspace = new CliWorkspace();

        var (exit, output) = await workspace.RunAsync("version");

        Assert.Equal(0, exit);
        Assert.Equal("9.9.9", output.Trim());
    }

    [Theory]
    [InlineData("help")]
    [InlineData("--help")]
    public async Task HelpIsNotAnError(string argument)
    {
        using var workspace = new CliWorkspace();

        var (exit, output) = await workspace.RunAsync(argument);

        Assert.Equal(0, exit);
        Assert.Contains("usage: leikwan", output);
    }

    [Fact]
    public async Task UnknownCommandFails()
    {
        using var workspace = new CliWorkspace();

        var (exit, output) = await workspace.RunAsync("frobnicate");

        Assert.Equal(2, exit);
        Assert.Contains("unknown command", output);
    }

    [Fact]
    public async Task StartAndStopReportTheControllerResult()
    {
        using var workspace = new CliWorkspace();
        workspace.Controller.StartResult = true;
        workspace.Controller.StopResult = false;

        var (startExit, startOutput) = await workspace.RunAsync("start");
        var (stopExit, stopOutput) = await workspace.RunAsync("stop");

        Assert.Equal(0, startExit);
        Assert.Contains("start: ok", startOutput);
        Assert.Equal(1, stopExit);
        Assert.Contains("stop: failed", stopOutput);
    }

    [Fact]
    public async Task LogsPrintTheNewestFileTail()
    {
        using var workspace = new CliWorkspace();
        workspace.WriteLog(string.Join('\n', Enumerable.Range(1, 60).Select(index => $"line {index}")));

        var (exit, output) = await workspace.RunAsync("logs", "-n", "5");

        Assert.Equal(0, exit);
        Assert.Contains("line 60", output);
        Assert.DoesNotContain("line 54", output);
    }

    [Fact]
    public async Task UpdateCheckReportsAnAvailableBuild()
    {
        using var workspace = new CliWorkspace();
        workspace.Updater.Check = new ClientUpdateCheck("1.0.33", "1.0.34", true, "https://panel.example/artifact.zip");

        var (exit, output) = await workspace.RunAsync("update", "--check");

        Assert.Equal(0, exit);
        Assert.Contains("installed : 1.0.33", output);
        Assert.Contains("published : 1.0.34", output);
        Assert.Contains("available (run", output);
        Assert.Equal(0, workspace.Updater.Applied);
    }

    [Fact]
    public async Task UpdateAppliesWhenTheCallerDidNotAskForACheck()
    {
        using var workspace = new CliWorkspace();
        workspace.Updater.Check = new ClientUpdateCheck("1.0.33", "1.0.34", true, "https://panel.example/artifact.zip");
        workspace.Updater.ApplyResult = true;

        var (exit, output) = await workspace.RunAsync("update");

        Assert.Equal(0, exit);
        Assert.Equal(1, workspace.Updater.Applied);
        Assert.Contains("applied 1.0.34", output);
    }

    [Fact]
    public async Task UpdateIsANoOpWhenThePublishedVersionMatches()
    {
        using var workspace = new CliWorkspace();
        workspace.Updater.Check = new ClientUpdateCheck("1.0.34", "1.0.34", false, string.Empty);

        var (exit, output) = await workspace.RunAsync("update");

        Assert.Equal(0, exit);
        Assert.Contains("already current", output);
        Assert.Equal(0, workspace.Updater.Applied);
    }

    private sealed class CliWorkspace : IDisposable
    {
        private readonly string _root = Path.Combine(Path.GetTempPath(), "lkb-cli-" + Guid.NewGuid().ToString("N"));

        public CliWorkspace()
        {
            Directory.CreateDirectory(_root);
            Controller = new FakeController
            {
                StatusFilePath = Path.Combine(_root, "headless-status.json"),
                LogDirectory = _root,
            };
        }

        public FakeController Controller { get; }

        public FakeUpdater Updater { get; } = new();

        public void WriteStatus(bool connected, bool running)
        {
            var document = new ClientStatusDocument
            {
                Version = "0.1.20.48",
                Connected = connected,
                ConnectionStatus = connected ? "connected" : "disconnected",
                ClientName = "Default Client",
                Forwards =
                [
                    new ClientStatusForward
                    {
                        ForwardId = 158,
                        Name = "Forward #158",
                        Mode = "ultra",
                        ListenPort = 30522,
                        Running = running,
                        Status = running ? "running" : "stopped",
                    },
                ],
            };
            File.WriteAllText(Path.Combine(_root, "headless-status.json"),
                System.Text.Json.JsonSerializer.Serialize(document));
        }

        public void WriteLog(string content)
        {
            var path = Path.Combine(_root, "headless-20260914.log");
            Directory.CreateDirectory(Path.GetDirectoryName(path)!);
            File.WriteAllText(path, content);
            File.SetLastWriteTimeUtc(path, DateTime.UtcNow);
        }

        public async Task<(int ExitCode, string Output)> RunAsync(params string[] args)
        {
            var output = new StringWriter();
            var error = new StringWriter();
            var exit = await HeadlessCli.RunAsync(
                args,
                Controller,
                Updater,
                output,
                error,
                CancellationToken.None);
            return (exit, output.ToString() + error.ToString());
        }

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

    private sealed class FakeController : IClientServiceController
    {
        public string PlatformName => "test";

        public string ServiceLabel => "LeikwanBridgeHeadless";

        public string StatusFilePath { get; set; } = string.Empty;

        public string LogDirectory { get; set; } = string.Empty;

        public string LogFilePattern => "headless-*.log";

        public string Version => "9.9.9";

        public bool StartResult { get; set; } = true;

        public bool StopResult { get; set; } = true;

        public Task<ClientServiceState> QueryAsync(CancellationToken ct)
            => Task.FromResult(new ClientServiceState(ServiceLabel, true, true, "state RUNNING"));

        public Task<bool> StartAsync(CancellationToken ct) => Task.FromResult(StartResult);

        public Task<bool> StopAsync(CancellationToken ct) => Task.FromResult(StopResult);

        public Task<bool> RestartAsync(CancellationToken ct) => Task.FromResult(StartResult);
    }

    private sealed class FakeUpdater : IClientUpdateService
    {
        public ClientUpdateCheck Check { get; set; } = new("1.0.0", null, false, string.Empty);

        public bool ApplyResult { get; set; }

        public int Applied { get; private set; }

        public Task<ClientUpdateCheck> CheckAsync(CancellationToken ct) => Task.FromResult(Check);

        public Task<string> DescribeLatestAsync(CancellationToken ct) => Task.FromResult(Check.PublishedVersion ?? "unknown");

        public Task<bool> ApplyAsync(ClientUpdateCheck check, IClientServiceController service, TextWriter output, CancellationToken ct)
        {
            Applied++;
            return Task.FromResult(ApplyResult);
        }
    }
}
