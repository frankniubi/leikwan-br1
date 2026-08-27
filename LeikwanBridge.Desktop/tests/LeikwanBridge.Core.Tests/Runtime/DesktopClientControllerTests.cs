using LeikwanBridge.Core.Helpers;
using LeikwanBridge.Core.Models;
using LeikwanBridge.Core.Services;
using Xunit;

namespace LeikwanBridge.Core.Tests.Runtime;

public sealed class DesktopClientControllerTests
{
    [Fact]
    public async Task LoginSelectConnectAndLifecyclePublishCredentialFreeSnapshots()
    {
        var config = new AgentConfig();
        var saves = 0;
        var runtime = new FakeRuntimeSession
        {
            InitialForwards =
            [
                Forward(1, "current_mode", "single", 12001),
                Forward(2, "current_mode", "bond", 12002),
                Forward(3, "ultra", "single", 12003),
            ],
        };
        var controller = CreateController(
            config,
            runtime,
            save: (_, _) =>
            {
                saves++;
                return Task.CompletedTask;
            });

        await controller.LoginAsync("https://panel.example.test/", "user@example.test", "password-canary");

        Assert.True(controller.Snapshot.IsSignedIn);
        Assert.True(controller.Snapshot.RequiresClientSelection);
        Assert.Equal(2, controller.Snapshot.AvailableClients.Count);
        await controller.SelectClientAsync(22);
        Assert.Equal("Client 22", controller.Snapshot.ActiveClientName);
        Assert.Equal("agent-token-22", config.AgentToken);

        await controller.ConnectAsync();

        Assert.True(controller.Snapshot.IsConnected);
        Assert.Equal(["single", "bond", "ultra"], controller.Snapshot.Forwards.Select(forward => forward.Mode).ToArray());
        Assert.DoesNotContain("password-canary", System.Text.Json.JsonSerializer.Serialize(controller.Snapshot));
        await controller.StartForwardAsync(1);
        await controller.StopForwardAsync(1);
        await controller.StartAllAsync();
        await controller.StopAllAsync();
        Assert.Equal(1, runtime.StartForwardCalls);
        Assert.Equal([1], runtime.Stopped);
        Assert.Equal(1, runtime.StartAllCalls);
        Assert.Equal(1, runtime.StopAllCalls);

        await controller.SignOutAsync();

        Assert.False(controller.Snapshot.IsSignedIn);
        Assert.False(controller.Snapshot.IsConnected);
        Assert.Equal(string.Empty, config.AgentToken);
        Assert.True(runtime.StopCalls >= 1);
        Assert.True(saves >= 3);
        await controller.DisposeAsync();
    }

    [Fact]
    public async Task ConnectRaisesExactUnsupportedNoticeBeforePublishingAnyForward()
    {
        var config = SignedInConfig();
        var runtime = new FakeRuntimeSession { RejectionNotice = MacForwardPolicy.UnsupportedModeNotice };
        var controller = CreateController(config, runtime);
        var notices = new List<string>();
        controller.NoticeRaised += notices.Add;

        await controller.ConnectAsync();

        Assert.Equal([MacForwardPolicy.UnsupportedModeNotice], notices);
        Assert.Empty(controller.Snapshot.Forwards);
        Assert.Equal(0, runtime.StartForwardCalls);
        await controller.DisposeAsync();
    }

    [Fact]
    public async Task ConnectCancellationStopsAndDisposesThePartialRuntime()
    {
        var config = SignedInConfig();
        var runtime = new FakeRuntimeSession { BlockStart = true };
        var controller = CreateController(config, runtime);
        using var cts = new CancellationTokenSource(TimeSpan.FromMilliseconds(50));

        await Assert.ThrowsAnyAsync<OperationCanceledException>(() => controller.ConnectAsync(cts.Token));

        Assert.False(controller.Snapshot.IsConnected);
        Assert.True(runtime.StopCalls >= 1);
        Assert.True(runtime.DisposeCalls >= 1);
        await controller.DisposeAsync();
    }

    [Fact]
    public async Task AgentTokenLoginValidatesPullClientAndPersistsTokenWithoutDesktopSession()
    {
        var config = new AgentConfig();
        var saves = 0;
        var runtime = new FakeRuntimeSession();
        var controller = CreateController(
            config,
            runtime,
            save: (_, _) =>
            {
                saves++;
                return Task.CompletedTask;
            },
            tokenPull: (_, token, clientId, _) =>
            {
                Assert.Equal("agent-token-27", token);
                Assert.Equal(27, clientId);
                return Task.FromResult(new PullResponse
                {
                    Ok = true,
                    Client = new ClientMeta { Id = 27, Name = "Mac client 27" },
                });
            });

        await controller.LoginWithAgentTokenAsync(
            "https://panel.example.test/",
            27,
            "agent-token-27");

        Assert.True(controller.Snapshot.IsSignedIn);
        Assert.False(controller.Snapshot.RequiresClientSelection);
        Assert.Equal("Mac client 27", controller.Snapshot.ActiveClientName);
        Assert.Equal("agent-token-27", config.AgentToken);
        Assert.Equal(27, config.ActiveClientId);
        Assert.Equal(string.Empty, config.DesktopSessionToken);
        Assert.True(saves >= 1);
        await controller.DisposeAsync();
    }

    [Fact]
    public async Task RefreshAsyncSyncsTheExistingRuntimeWithoutDisconnectingOrRecreatingIt()
    {
        var config = SignedInConfig();
        var runtime = new FakeRuntimeSession
        {
            InitialForwards = [Forward(1, "current_mode", "single", 12001)],
        };
        var runtimeFactoryCalls = 0;
        var identity = new ClientPlatformIdentity(
            "macos-desktop", "device", "Mac", "Leikwan-Mac-GUI", "1.0.0", SupportsMptcp: false);
        var controller = new DesktopClientController(
            config,
            identity,
            (_, _) => Task.CompletedTask,
            (_, _) =>
            {
                runtimeFactoryCalls++;
                return runtime;
            });

        await controller.ConnectAsync();
        await controller.RefreshAsync();

        Assert.Equal(1, runtimeFactoryCalls);
        Assert.Equal(0, runtime.StopCalls);
        Assert.Equal(1, runtime.RefreshCalls);
        Assert.True(controller.Snapshot.IsConnected);
        Assert.False(controller.Snapshot.IsBusy);
        await controller.DisposeAsync();
    }

    [Fact]
    public async Task RefreshAsyncFailureKeepsConnectedRuntimeAndClearsBusyState()
    {
        var config = SignedInConfig();
        var runtime = new FakeRuntimeSession
        {
            InitialForwards = [Forward(1, "current_mode", "single", 12001)],
            ThrowOnRefresh = true,
        };
        var controller = CreateController(config, runtime);

        await controller.ConnectAsync();

        await Assert.ThrowsAsync<InvalidOperationException>(() => controller.RefreshAsync());

        Assert.True(controller.Snapshot.IsConnected);
        Assert.False(controller.Snapshot.IsBusy);
        Assert.Equal("connected", controller.Snapshot.ConnectionStatus);
        Assert.Equal("refresh_failed", controller.Snapshot.LastErrorCode);
        Assert.Equal(0, runtime.StopCalls);
        await controller.DisposeAsync();
    }

    private static DesktopClientController CreateController(
        AgentConfig config,
        FakeRuntimeSession runtime,
        Func<AgentConfig, CancellationToken, Task>? save = null,
        Func<string, string, int, CancellationToken, Task<PullResponse>>? tokenPull = null)
    {
        var identity = new ClientPlatformIdentity(
            "macos-desktop", "device", "Mac", "Leikwan-Mac-GUI", "1.0.0", SupportsMptcp: false);
        return new DesktopClientController(
            config,
            identity,
            save ?? ((_, _) => Task.CompletedTask),
            (_, _) => runtime,
            login: (_, _, _, _) => Task.FromResult(new DesktopLoginResponse
            {
                Ok = true,
                SessionToken = "desktop-session",
                UserId = 7,
                Username = "User",
                Email = "user@example.test",
                Clients =
                [
                    new DesktopClientChoice { Id = 21, Name = "Client 21" },
                    new DesktopClientChoice { Id = 22, Name = "Client 22" },
                ],
            }),
            selectClient: (_, _, clientId, _) => Task.FromResult(new ClientSelectionResponse
            {
                Ok = true,
                ClientId = clientId,
                ClientName = $"Client {clientId}",
                AgentToken = $"agent-token-{clientId}",
            }),
            agentTokenPull: tokenPull);
    }

    private static AgentConfig SignedInConfig() => new()
    {
        MasterUrl = "https://panel.example.test",
        DesktopSessionToken = "desktop-session",
        AgentToken = "agent-token-42",
        ClientId = 42,
        ActiveClientId = 42,
        ActiveClientName = "Client 42",
    };

    private static ForwardConfig Forward(int id, string backend, string mode, int port) => new()
    {
        ForwardId = id,
        BackendMode = backend,
        Mode = mode,
        ClientMode = mode,
        ListenPort = port,
        ServiceStatus = "active",
    };

    private sealed class FakeRuntimeSession : IDesktopRuntimeSession
    {
        private List<ForwardConfig> _forwards = [];

        public event Action<IReadOnlyList<ForwardConfig>>? ForwardsChanged;
        public event Action<int, string>? ForwardRejected;
        public event Action<string>? LogRaised { add { } remove { } }

        public IReadOnlyList<ForwardConfig> InitialForwards { get; init; } = [];
        public string RejectionNotice { get; init; } = string.Empty;
        public bool BlockStart { get; init; }
        public bool ThrowOnRefresh { get; init; }
        public IReadOnlyList<ForwardConfig> Forwards => _forwards;
        public List<int> Started { get; } = [];
        public List<int> Stopped { get; } = [];
        public int StartForwardCalls { get; private set; }
        public int StartAllCalls { get; private set; }
        public int StopAllCalls { get; private set; }
        public int StopCalls { get; private set; }
        public int DisposeCalls { get; private set; }
        public int RefreshCalls { get; private set; }

        public bool IsForwardRunning(int forwardId) => Started.Contains(forwardId);

        public async Task StartAsync(CancellationToken ct)
        {
            if (BlockStart)
                await Task.Delay(Timeout.InfiniteTimeSpan, ct);
            if (!string.IsNullOrWhiteSpace(RejectionNotice))
                ForwardRejected?.Invoke(99, RejectionNotice);
            _forwards = InitialForwards.ToList();
            ForwardsChanged?.Invoke(_forwards);
        }

        public Task StopAsync()
        {
            StopCalls++;
            return Task.CompletedTask;
        }

        public Task RefreshAsync(CancellationToken ct)
        {
            ct.ThrowIfCancellationRequested();
            RefreshCalls++;
            if (ThrowOnRefresh)
                throw new InvalidOperationException("refresh_failed");
            return Task.CompletedTask;
        }

        public Task StartForwardAsync(int forwardId, CancellationToken ct)
        {
            StartForwardCalls++;
            Started.Add(forwardId);
            ForwardsChanged?.Invoke(_forwards);
            return Task.CompletedTask;
        }

        public Task StopForwardAsync(int forwardId, CancellationToken ct)
        {
            Stopped.Add(forwardId);
            Started.Remove(forwardId);
            ForwardsChanged?.Invoke(_forwards);
            return Task.CompletedTask;
        }

        public Task StartAllAsync(CancellationToken ct)
        {
            StartAllCalls++;
            Started.AddRange(_forwards.Select(forward => forward.ForwardId));
            ForwardsChanged?.Invoke(_forwards);
            return Task.CompletedTask;
        }

        public Task StopAllAsync(CancellationToken ct)
        {
            StopAllCalls++;
            Started.Clear();
            ForwardsChanged?.Invoke(_forwards);
            return Task.CompletedTask;
        }

        public ValueTask DisposeAsync()
        {
            DisposeCalls++;
            return ValueTask.CompletedTask;
        }
    }
}
