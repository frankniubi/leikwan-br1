using System.Text.Json;
using LeikwanBridge.Core.Models;
using LeikwanBridge.Core.Services;
using LeikwanBridge.Headless;
using Xunit;

namespace LeikwanBridge.App.Tests.Headless;

public sealed class HeadlessRunnerTests
{
    [Fact]
    public void LoadConfigAppliesThePanelClientIdAndToken()
    {
        // The windowless client shares %ProgramData% with the GUI client, so an
        // explicit --panel/--client-id/--agent-token must always win over
        // whatever was persisted.
        using var workspace = new RunnerWorkspace();

        var config = workspace.BuildConfig("--headless", "--panel", "https://panel.example/", "--client-id", "7", "--agent-token", "tok");

        Assert.Equal("https://panel.example", config.MasterUrl);
        Assert.Equal(7, config.ActiveClientId);
        Assert.Equal("tok", config.AgentToken);
    }

    [Fact]
    public async Task OnceRunSucceedsWhenAForwardIsRunning()
    {
        using var workspace = new RunnerWorkspace();
        workspace.Stack.Session.Forwards = [Forward(1)];
        workspace.Stack.Session.Running.Add(1);

        var exit = await workspace.RunAsync("--headless", "--client-id", "7", "--agent-token", "tok", "--once");

        Assert.True(exit == 0, workspace.Diagnostics());
        Assert.Equal(1, workspace.Stack.Session.StartAllCalls);
        var status = workspace.ReadStatus();
        Assert.True(status.GetProperty("Forwards")[0].GetProperty("Running").GetBoolean());
    }

    [Fact]
    public async Task OnceRunFailsWhenNoForwardIsRunning()
    {
        using var workspace = new RunnerWorkspace();
        workspace.Stack.Session.Forwards = [Forward(1)];

        var exit = await workspace.RunAsync("--headless", "--client-id", "7", "--agent-token", "tok", "--once");

        Assert.Equal(1, exit);
    }

    [Fact]
    public async Task OnceRunStopsAfterThreeConnectAttempts()
    {
        using var workspace = new RunnerWorkspace();
        workspace.Stack.FailuresBeforeConnect = 99;

        var exit = await workspace.RunAsync("--headless", "--client-id", "7", "--agent-token", "tok", "--once");

        Assert.Equal(1, exit);
        Assert.Equal(3, workspace.Stack.ConnectCalls);
    }

    [Fact]
    public async Task SupervisedRunRestartsDroppedForwardsAndStopsOnSignal()
    {
        using var workspace = new RunnerWorkspace();
        workspace.Stack.Session.Forwards = [Forward(1)];
        workspace.Stack.Session.OnStartForward = () => workspace.Stack.Session.Running.Add(1);
        workspace.Delay = (_, token) =>
        {
            if (workspace.DelayCalls >= 2)
                throw new OperationCanceledException(token);
            return Task.CompletedTask;
        };

        var exit = await workspace.RunAsync("--headless", "--client-id", "7", "--agent-token", "tok", "--status-interval", "5");

        Assert.Equal(0, exit);
        Assert.Equal(1, workspace.Stack.Session.StartForwardCalls);
        Assert.Equal(1, workspace.Stack.Session.StopAllCalls);
        Assert.Equal(1, workspace.Stack.Session.StopCalls);
    }

    private static ForwardConfig Forward(int forwardId) => new()
    {
        ForwardId = forwardId,
        Target = "example.test:443",
        ListenPort = 1080,
        Mode = "single",
        ClientMode = "ultra",
        ServiceStatus = "active",
    };

    private sealed class RunnerWorkspace : IDisposable
    {
        public RunnerWorkspace()
        {
            Config = new AgentConfig
            {
                MasterUrl = "https://default.example",
                ClientId = 7,
                ActiveClientId = 7,
                AgentToken = "tok",
            };
        }

        public AgentConfig Config { get; }

        public FakeStack Stack { get; } = new();

        public Func<TimeSpan, CancellationToken, Task> Delay { get; set; } = (_, _) => Task.CompletedTask;

        public int DelayCalls { get; private set; }

        private readonly StringWriter _log = new();
        private readonly string _statusPath = Path.Combine(Path.GetTempPath(), "lkb-headless-" + Guid.NewGuid().ToString("N") + ".json");

        public AgentConfig BuildConfig(params string[] args)
        {
            Assert.True(HeadlessClientOptions.TryParse(args, out var options, out var error), error);
            return HeadlessRunner.ApplyIdentity(Config.Clone(), options);
        }

        public Task<int> RunAsync(params string[] args)
        {
            Assert.True(HeadlessClientOptions.TryParse([.. args, "--status-file", _statusPath], out var options, out var error), error);
            return HeadlessRunner.RunAsync(
                options,
                CancellationToken.None,
                (_, _, _) => Stack.ConnectAsync(),
                _log,
                (span, token) =>
                {
                    DelayCalls++;
                    return Delay(span, token);
                },
                _ => Config);
        }

        public JsonElement ReadStatus()
        {
            using var document = JsonDocument.Parse(File.ReadAllText(_statusPath));
            return document.RootElement.Clone();
        }

        public string Diagnostics() => _log.ToString();

        public void Dispose()
        {
            try
            {
                File.Delete(_statusPath);
            }
            catch (IOException)
            {
            }
        }
    }

    private sealed class FakeStack : IHeadlessClientStack
    {
        public FakeSession Session { get; } = new();

        IDesktopRuntimeSession IHeadlessClientStack.Session => Session;

        public AgentConfig Config { get; } = new();

        public int FailuresBeforeConnect { get; set; }
        public int ConnectCalls { get; private set; }
        public Action? OnStartForward { get; set; }

        public Task<IHeadlessClientStack> ConnectAsync()
        {
            ConnectCalls++;
            if (ConnectCalls <= FailuresBeforeConnect)
                throw new InvalidOperationException("connect_failed");
            return Task.FromResult<IHeadlessClientStack>(this);
        }

        public ValueTask DisposeAsync() => ValueTask.CompletedTask;
    }

    private sealed class FakeSession : IDesktopRuntimeSession
    {
        public event Action<IReadOnlyList<ForwardConfig>>? ForwardsChanged;
        public event Action<NativeOutboundCapability?>? NativeOutboundChanged;
        public event Action<int, string>? ForwardRejected;
        public event Action<string>? LogRaised;

        public IReadOnlyList<ForwardConfig> Forwards { get; set; } = [];

        public HashSet<int> Running { get; } = [];

        public Action? OnStartForward { get; set; }

        public NativeOutboundCapability? NativeOutbound => null;

        public int StopCalls { get; private set; }
        public int StopAllCalls { get; private set; }
        public int StartAllCalls { get; private set; }
        public int StartForwardCalls { get; private set; }

        public bool IsForwardRunning(int forwardId) => Running.Contains(forwardId);

        public Task StartAsync(CancellationToken ct) => Task.CompletedTask;

        public Task RefreshAsync(CancellationToken ct) => Task.CompletedTask;

        public Task StopAsync()
        {
            StopCalls++;
            return Task.CompletedTask;
        }

        public Task StartForwardAsync(int forwardId, CancellationToken ct)
        {
            StartForwardCalls++;
            OnStartForward?.Invoke();
            return Task.CompletedTask;
        }

        public Task StopForwardAsync(int forwardId, CancellationToken ct) => Task.CompletedTask;

        public Task StartAllAsync(CancellationToken ct)
        {
            StartAllCalls++;
            return Task.CompletedTask;
        }

        public Task StopAllAsync(CancellationToken ct)
        {
            StopAllCalls++;
            return Task.CompletedTask;
        }

        public Task<NativeOutboundLease?> ResolveNativeOutboundLeaseAsync(int forwardId, string routeScope, CancellationToken ct)
            => Task.FromResult<NativeOutboundLease?>(null);

        // DesktopRuntimeSession.DisposeAsync stops the session first; the fake
        // mirrors that so the runner's teardown is actually asserted.
        public async ValueTask DisposeAsync() => await StopAsync();

        public void RaiseForwardsChanged() => ForwardsChanged?.Invoke(Forwards);
    }
}
