using System.Text.Json;
using LeikwanBridge.Core.Models;
using LeikwanBridge.Core.Services;
using LeikwanBridge.Mac.Services;
using Xunit;

namespace LeikwanBridge.Mac.Tests.Services;

public sealed class MacHeadlessHostTests
{
    [Fact]
    public void TryParseReadsTheAgentTokenFile()
    {
        var tokenFile = Path.Combine(Path.GetTempPath(), "lkb-headless-" + Guid.NewGuid().ToString("N") + ".token");
        File.WriteAllText(tokenFile, "  agent-token-value\n");
        try
        {
            var parsed = HeadlessClientOptions.TryParse(
                ["--headless", "--panel", "https://v3.leikwanhost.com/", "--client-id", "42",
                 "--agent-token-file", tokenFile, "--forwards", "7,9", "--status-file",
                 Path.Combine(Path.GetTempPath(), "status.json")],
                out var options,
                out var error);

            Assert.True(parsed, error);
            Assert.Equal("https://v3.leikwanhost.com", options.PanelUrl);
            Assert.Equal(42, options.ClientId);
            Assert.Equal("agent-token-value", options.AgentToken);
            Assert.Equal([7, 9], HeadlessClientOptions.ParseForwardSelection(options.Forwards));
            Assert.False(options.Once);
        }
        finally
        {
            File.Delete(tokenFile);
        }
    }

    [Theory]
    [InlineData("--headless", "--client-id", "1", "--agent-token", "t", "--nope", "1")]
    [InlineData("--headless", "--client-id", "0", "--agent-token", "t")]
    [InlineData("--headless", "--client-id", "1", "--agent-token", "t", "--panel", "v3.leikwanhost.com")]
    [InlineData("--headless", "--client-id", "1", "--agent-token", "t", "--status-file", "relative.json")]
    [InlineData("--headless", "--client-id", "1", "--agent-token", "t", "--once-window", "2")]
    [InlineData("--headless")]
    public void TryParseRejectsInvalidArguments(params string[] args)
    {
        Assert.False(HeadlessClientOptions.TryParse(args, out _, out var error));
        Assert.NotEmpty(error);
    }

    [Fact]
    public void ParseForwardSelectionTreatsAllAsEveryForward()
    {
        Assert.Empty(HeadlessClientOptions.ParseForwardSelection("all"));
        Assert.Empty(HeadlessClientOptions.ParseForwardSelection(""));
        Assert.Equal([3], HeadlessClientOptions.ParseForwardSelection("3,3"));
        Assert.Throws<InvalidOperationException>(() => HeadlessClientOptions.ParseForwardSelection("3,x"));
    }

    [Fact]
    public async Task OnceRunReportsSuccessWhenAForwardComesUp()
    {
        using var workspace = new HeadlessWorkspace();
        var client = workspace.Client;
        client.Controller.FailuresBeforeSuccess = 1;
        client.Controller.OnConnect = () => client.Controller.Snapshot = ConnectedSnapshot(running: true);

        var exit = await workspace.RunAsync("--headless", "--client-id", "5", "--agent-token", "t", "--once");

        Assert.True(exit == 0, $"exit={exit} errors={workspace.ErrorText} log={workspace.LogText()}");
        Assert.Equal(2, client.Controller.ConnectCalls);
        Assert.Equal(1, client.Controller.StartAllCalls);
        var status = workspace.ReadStatus();
        Assert.True(status.GetProperty("Connected").GetBoolean());
        Assert.True(status.GetProperty("Forwards")[0].GetProperty("Running").GetBoolean());
        Assert.Contains("headless_once_complete", workspace.LogText());
    }

    [Fact]
    public async Task OnceRunFailsWhenNoForwardIsRunning()
    {
        using var workspace = new HeadlessWorkspace();
        var client = workspace.Client;
        client.Controller.OnConnect = () => client.Controller.Snapshot = ConnectedSnapshot(running: false);

        var exit = await workspace.RunAsync("--headless", "--client-id", "5", "--agent-token", "t", "--once");

        Assert.Equal(1, exit);
        Assert.Contains("headless_once_complete", workspace.LogText());
    }

    [Fact]
    public async Task OnceRunStopsAfterThreeConnectAttempts()
    {
        using var workspace = new HeadlessWorkspace();
        var client = workspace.Client;
        client.Controller.FailuresBeforeSuccess = 99;

        var exit = await workspace.RunAsync("--headless", "--client-id", "5", "--agent-token", "t", "--once");

        Assert.True(exit == 1, $"exit={exit} errors={workspace.ErrorText} log={workspace.LogText()}");
        Assert.True(client.Controller.ConnectCalls == 3, $"connects={client.Controller.ConnectCalls} errors={workspace.ErrorText} log={workspace.LogText()}");
        Assert.Contains("headless_startup_failed", workspace.LogText());
    }

    [Fact]
    public async Task SupervisedRunRestartsDroppedForwardsAndStopsOnSignal()
    {
        using var workspace = new HeadlessWorkspace();
        var client = workspace.Client;
        client.Controller.OnConnect = () => client.Controller.Snapshot = ConnectedSnapshot(running: false);
        client.Controller.OnEnsureForward = () => client.Controller.Snapshot = ConnectedSnapshot(running: true);
        // Two supervise ticks, then the supervisor asks the process to stop.
        workspace.Delay = (_, token) =>
        {
            if (workspace.DelayCalls >= 2)
                throw new OperationCanceledException(token);
            return Task.CompletedTask;
        };

        var exit = await workspace.RunAsync("--headless", "--client-id", "5", "--agent-token", "t", "--status-interval", "5");

        Assert.Equal(0, exit);
        Assert.Equal(1, client.Controller.EnsureForwardCalls);
        Assert.Equal(0, client.Controller.StartForwardCalls);
        Assert.Equal(1, client.Controller.StopAllCalls);
        Assert.Equal(1, client.Controller.DisconnectCalls);
        Assert.Equal(1, client.Disposed);
        Assert.Contains("headless_stopped", workspace.LogText());
    }

    [Fact]
    public async Task SupervisedRunRunsTheSelfUpdateLoopBesideTheClientAndStopsIt()
    {
        using var workspace = new HeadlessWorkspace();
        var client = workspace.Client;
        client.Controller.OnConnect = () => client.Controller.Snapshot = ConnectedSnapshot(running: true);
        var loopStarted = new TaskCompletionSource();
        string? panelSeen = null;
        var loopCancelled = false;
        workspace.AutoUpdate = async (_, options, panelUrl, ct) =>
        {
            Assert.False(options.Once);
            panelSeen = panelUrl();
            loopStarted.TrySetResult();
            try
            {
                await Task.Delay(Timeout.Infinite, ct);
            }
            catch (OperationCanceledException)
            {
                loopCancelled = true;
            }
        };
        workspace.Delay = async (_, token) =>
        {
            await loopStarted.Task.WaitAsync(TimeSpan.FromSeconds(10));
            throw new OperationCanceledException(token);
        };

        var exit = await workspace.RunAsync("--headless", "--client-id", "5", "--agent-token", "t", "--panel", "https://panel.example/");

        Assert.Equal(0, exit);
        Assert.Equal("https://panel.example", panelSeen);
        Assert.True(loopCancelled);
    }

    [Fact]
    public void ProductionDependenciesWireTheDaemonSelfUpdate()
        => Assert.NotNull(MacHeadlessDependencies.Default.AutoUpdate);

    [Fact]
    public async Task LoginWithAgentTokenForwardsThePanelIdentity()
    {
        using var workspace = new HeadlessWorkspace();
        var client = workspace.Client;

        var exit = await workspace.RunAsync("--headless", "--client-id", "9", "--agent-token", "secret", "--once", "--panel", "https://panel.example/");

        Assert.True(exit is 0 or 1);
        var login = Assert.Single(client.Controller.Logins);
        Assert.Equal("https://panel.example", login.PanelUrl);
        Assert.Equal(9, login.ClientId);
        Assert.Equal("secret", login.AgentToken);
        Assert.Equal("https://panel.example", client.Config.MasterUrl);
        Assert.Equal(9, client.Config.ActiveClientId);
    }

    private static DesktopClientSnapshot ConnectedSnapshot(bool running) => new()
    {
        IsSignedIn = true,
        IsConnected = true,
        ConnectionStatus = "connected",
        ActiveClientName = "Test Client",
        Forwards =
        [
            new DesktopForwardSnapshot(1, "Forward 1", "single", 1080, running, running ? "running" : "stopped"),
        ],
    };

    private sealed class HeadlessWorkspace : IDisposable
    {
        private readonly string _root = Path.Combine(Path.GetTempPath(), "lkb-headless-" + Guid.NewGuid().ToString("N"));

        public HeadlessWorkspace()
        {
            Directory.CreateDirectory(_root);
        }

        public string LogDirectory => Path.Combine(_root, "Library", "Logs", "LeiKwan", "ClientAgent");

        private readonly StringWriter _errors = new();

        public string ErrorText => _errors.ToString();

        public Func<TimeSpan, CancellationToken, Task> Delay { get; set; } = (_, _) => Task.CompletedTask;

        public Func<MacPlatformPaths, HeadlessClientOptions, Func<string>, CancellationToken, Task>? AutoUpdate { get; set; }

        public int DelayCalls { get; private set; }

        public FakeHeadlessClient Client { get; } = new();

        public Task<int> RunAsync(params string[] args)
        {
            var client = Client;
            var dependencies = new MacHeadlessDependencies(
                (_, _) => Task.FromResult<IMacHeadlessClient>(client),
                () => new MacPlatformPaths(_root, _root, ensureDirectories: true),
                TextWriter.Null,
                _errors,
                (delay, token) =>
                {
                    DelayCalls++;
                    return Delay(delay, token);
                })
            {
                AutoUpdate = AutoUpdate,
            };
            return MacHeadlessHost.RunAsync([.. args, "--status-file", StatusPath], dependencies);
        }

        public string StatusPath => Path.Combine(_root, "status.json");

        public JsonElement ReadStatus()
        {
            using var document = JsonDocument.Parse(File.ReadAllText(StatusPath));
            return document.RootElement.Clone();
        }

        public string LogText()
        {
            if (!Directory.Exists(LogDirectory))
                return string.Empty;
            return string.Join(
                "\n",
                Directory.EnumerateFiles(LogDirectory, "headless-*.log").Select(File.ReadAllText));
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

    private sealed class FakeHeadlessClient : IMacHeadlessClient
    {
        public FakeHeadlessController Controller { get; } = new();

        IDesktopClientController IMacHeadlessClient.Controller => Controller;

        public AgentConfig Config { get; } = new();

        public int Disposed { get; private set; }

        public ValueTask DisposeAsync()
        {
            Disposed++;
            return ValueTask.CompletedTask;
        }
    }

    private sealed class FakeHeadlessController : IDesktopClientController
    {
        private DesktopClientSnapshot _snapshot = new();

        public event Action<DesktopClientSnapshot>? SnapshotChanged;
        public event Action<IReadOnlyList<DesktopForwardSnapshot>>? ForwardsChanged;
        public event Action<NativeOutboundCapability?>? NativeOutboundChanged;
        public event Action<string>? NoticeRaised;
        public event Action<string>? LogRaised;
        public event Action<string>? UpdateAvailable;

        public DesktopClientSnapshot Snapshot
        {
            get => _snapshot;
            set
            {
                _snapshot = value;
                SnapshotChanged?.Invoke(value);
            }
        }

        public NativeOutboundCapability? NativeOutbound => null;

        public int FailuresBeforeSuccess { get; set; }
        public int ConnectCalls { get; private set; }
        public int StartAllCalls { get; private set; }
        public int StartForwardCalls { get; private set; }
        public int EnsureForwardCalls { get; private set; }
        public int StopAllCalls { get; private set; }
        public int DisconnectCalls { get; private set; }
        public List<(string PanelUrl, int ClientId, string AgentToken)> Logins { get; } = [];
        public Action? OnStartForward { get; set; }
        public Action? OnEnsureForward { get; set; }
        public Action? OnConnect { get; set; }

        public Task LoginAsync(string panelUrl, string email, string password, CancellationToken ct = default)
            => Task.CompletedTask;

        public Task LoginWithAgentTokenAsync(string panelUrl, int clientId, string agentToken, CancellationToken ct = default)
            => LoginWithAgentTokenAsync(panelUrl, clientId, agentToken, rememberCredentials: true, ct);

        public Task LoginWithAgentTokenAsync(
            string panelUrl,
            int clientId,
            string agentToken,
            bool rememberCredentials,
            CancellationToken ct = default)
        {
            Logins.Add((panelUrl, clientId, agentToken));
            Snapshot = new DesktopClientSnapshot { IsSignedIn = true, ConnectionStatus = "disconnected" };
            return Task.CompletedTask;
        }

        public Task SelectClientAsync(int clientId, CancellationToken ct = default) => Task.CompletedTask;

        public Task ConnectAsync(CancellationToken ct = default)
        {
            ConnectCalls++;
            if (ConnectCalls <= FailuresBeforeSuccess)
                throw new InvalidOperationException("connection_failed");
            OnConnect?.Invoke();
            return Task.CompletedTask;
        }

        public Task RefreshAsync(CancellationToken ct = default) => Task.CompletedTask;

        public Task DisconnectAsync(CancellationToken ct = default)
        {
            DisconnectCalls++;
            return Task.CompletedTask;
        }

        public Task StartForwardAsync(int forwardId, CancellationToken ct = default)
        {
            StartForwardCalls++;
            OnStartForward?.Invoke();
            return Task.CompletedTask;
        }

        public Task EnsureForwardAsync(int forwardId, CancellationToken ct = default)
        {
            EnsureForwardCalls++;
            OnEnsureForward?.Invoke();
            return Task.CompletedTask;
        }

        public Task StopForwardAsync(int forwardId, CancellationToken ct = default) => Task.CompletedTask;

        public Task StartAllAsync(CancellationToken ct = default)
        {
            StartAllCalls++;
            return Task.CompletedTask;
        }

        public Task StopAllAsync(CancellationToken ct = default)
        {
            StopAllCalls++;
            return Task.CompletedTask;
        }

        public Task<NativeOutboundLease?> ResolveNativeOutboundLeaseAsync(int forwardId, string routeScope, CancellationToken ct = default)
            => Task.FromResult<NativeOutboundLease?>(null);

        public Task SignOutAsync(CancellationToken ct = default) => Task.CompletedTask;

        public ValueTask DisposeAsync() => ValueTask.CompletedTask;
    }
}
