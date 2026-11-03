using System.Net;
using System.Text;
using LeikwanBridge.Core.Models;
using LeikwanBridge.Core.Services;
using Xunit;

namespace LeikwanBridge.Core.Tests.Runtime;

/// <summary>
/// The panel refuses a device that is not already online when the client's
/// online device limit is full (HTTP 429 client_online_limit_reached). The
/// desktop must recognize it as a business answer, not as a network outage.
/// </summary>
public sealed class DeviceLimitReachedTests
{
    private const string FlatBody = """
        {"ok":false,"error":"client_online_limit_reached","protocol_version":1,"online_device_limit":3,"active_online_devices":3,"details":{"online_device_limit":9,"active_online_devices":9}}
        """;

    private const string NestedBody = """
        {"error":"client_online_limit_reached","details":{"client_id":27,"online_device_limit":2,"active_online_devices":2}}
        """;

    [Fact]
    public async Task PullConfigsAsync_ReadsCountsFromTopLevelFieldsFirst()
    {
        using var http = new HttpClient(new StaticHandler(HttpStatusCode.TooManyRequests, FlatBody));
        using var api = CreateApi(http);

        var limit = await Assert.ThrowsAsync<DeviceLimitReachedException>(() => api.PullConfigsAsync());

        Assert.Equal(429, limit.StatusCode);
        Assert.Equal(DeviceLimitReachedException.Code, limit.ErrorCode);
        Assert.Equal(3, limit.OnlineDeviceLimit);
        Assert.Equal(3, limit.ActiveOnlineDevices);
        Assert.StartsWith("Device limit reached (3 of 3 devices online).", limit.Message, StringComparison.Ordinal);
    }

    [Fact]
    public async Task PullConfigsAsync_FallsBackToNestedDetailsCounts()
    {
        using var http = new HttpClient(new StaticHandler(HttpStatusCode.TooManyRequests, NestedBody));
        using var api = CreateApi(http);

        var limit = await Assert.ThrowsAsync<DeviceLimitReachedException>(() => api.PullConfigsAsync());

        Assert.Equal(2, limit.OnlineDeviceLimit);
        Assert.Equal(2, limit.ActiveOnlineDevices);
        Assert.True(limit.HasCounts);
    }

    [Fact]
    public async Task PullConfigsAsync_OmitsCountsWhenThePanelSentNone()
    {
        using var http = new HttpClient(new StaticHandler(
            HttpStatusCode.TooManyRequests, """{"ok":false,"error":"client_online_limit_reached"}"""));
        using var api = CreateApi(http);

        var limit = await Assert.ThrowsAsync<DeviceLimitReachedException>(() => api.PullConfigsAsync());

        Assert.False(limit.HasCounts);
        Assert.StartsWith("Device limit reached. Disconnect another device", limit.Message, StringComparison.Ordinal);
    }

    [Fact]
    public async Task PullConfigsAsync_KeepsOtherErrorCodesAsPlainApiErrors()
    {
        using var http = new HttpClient(new StaticHandler(HttpStatusCode.TooManyRequests, """{"ok":false,"error":"rate_limited"}"""));
        using var api = CreateApi(http);

        var error = await Assert.ThrowsAsync<MasterApiException>(() => api.PullConfigsAsync());

        Assert.IsNotType<DeviceLimitReachedException>(error);
        Assert.Equal("rate_limited", error.Message);
        Assert.Equal("rate_limited", error.ErrorCode);
    }

    [Fact]
    public async Task PullConfigsAsync_DoesNotFailOverToTheMirrorOnDeviceLimit()
    {
        var handler = new StaticHandler(HttpStatusCode.TooManyRequests, FlatBody);
        using var http = new HttpClient(handler);
        using var api = new MasterApiClient(
            "https://panel.example.test",
            "agent-token",
            "device",
            httpClientFactory: () => http,
            ownsHttpClient: false,
            fallbackEndpoints: ["https://mirror.example.test"]);

        await Assert.ThrowsAsync<DeviceLimitReachedException>(() => api.PullConfigsAsync());
        await Assert.ThrowsAsync<DeviceLimitReachedException>(() => api.PullConfigsAsync());

        Assert.All(handler.Hosts, host => Assert.Equal("panel.example.test", host));
        Assert.Equal("panel.example.test", api.CurrentControlPlaneEndpoint.Host);
    }

    [Fact]
    public async Task SendHeartbeatAsync_RecognizesDeviceLimitInsteadOfLosingTheBody()
    {
        using var http = new HttpClient(new StaticHandler(HttpStatusCode.TooManyRequests, FlatBody));
        using var api = CreateApi(http);

        var limit = await Assert.ThrowsAsync<DeviceLimitReachedException>(
            () => api.SendHeartbeatAsync(new HeartbeatPayload()));

        Assert.Equal(3, limit.OnlineDeviceLimit);
    }

    [Fact]
    public async Task SendHeartbeatAsync_OtherFailuresStayHttpRequestExceptions()
    {
        using var http = new HttpClient(new StaticHandler(HttpStatusCode.TooManyRequests, """{"error":"rate_limited"}"""));
        using var api = CreateApi(http);

        await Assert.ThrowsAsync<HttpRequestException>(() => api.SendHeartbeatAsync(new HeartbeatPayload()));
    }

    [Fact]
    public async Task SubmitDiagnosticsAsync_RecognizesDeviceLimit()
    {
        using var http = new HttpClient(new StaticHandler(HttpStatusCode.TooManyRequests, NestedBody));
        using var api = CreateApi(http);

        await Assert.ThrowsAsync<DeviceLimitReachedException>(
            () => api.SubmitDiagnosticsAsync("token", [], string.Empty));
    }

    [Fact]
    public async Task TestConnectionAsync_ExposesTheTypedRefusal()
    {
        using var http = new HttpClient(new StaticHandler(HttpStatusCode.TooManyRequests, FlatBody));
        using var api = CreateApi(http);

        Assert.False(await api.TestConnectionAsync());

        Assert.NotNull(api.LastDeviceLimit);
        Assert.Equal(3, api.LastDeviceLimit!.OnlineDeviceLimit);
        Assert.Equal(api.LastDeviceLimit.Message, api.LastErrorMessage);
    }

    [Fact]
    public void DeviceLimitIsNotAControlPlaneFailure()
    {
        Assert.False(ControlPlaneBackoff.IsControlPlaneFailure(new DeviceLimitReachedException(429, 1, 1)));
        Assert.False(ControlPlaneBackoff.IsControlPlaneFailure(
            new InvalidOperationException("wrapped", new DeviceLimitReachedException(429, 1, 1))));
        Assert.True(ControlPlaneBackoff.IsControlPlaneFailure(new MasterApiException(429, "rate_limited")));
    }

    [Fact]
    public async Task PollingHoldsDeviceLimitOnASteadyCadenceWithoutRamping()
    {
        using var tempDir = new TempStateDirectory();
        var handler = new StaticHandler(HttpStatusCode.TooManyRequests, FlatBody);
        using var http = new HttpClient(handler);
        using var api = CreateApi(http);
        var state = new StateManager(tempDir.Path);
        var config = new AgentConfig { MasterUrl = "https://panel.example.test", AgentToken = "agent-token", StateDir = tempDir.Path };
        using var runtime = new ForwardRuntimeManager(config, new TestProcessSupervisor(), state);
        var pullBackoff = new ControlPlaneBackoff(random: () => 0.5);
        var service = new ConfigPollingService(api, runtime, state, null, TimeProvider.System, pullBackoff, leaseBackoff: null);

        for (var attempt = 1; attempt <= 3; attempt++)
        {
            await Assert.ThrowsAsync<DeviceLimitReachedException>(() => service.SyncOnceAsync());

            // 60 s + half of the 10 s jitter, every time: no exponential ramp
            // and no 5 s first retry as for an outage.
            var remaining = pullBackoff.RemainingDelay;
            Assert.InRange(remaining, TimeSpan.FromSeconds(60), TimeSpan.FromSeconds(65));
            Assert.Equal(attempt, pullBackoff.ConsecutiveFailures);
            Assert.True(service.IsControlPlaneBackingOff);
        }
        Assert.Equal(3, handler.Hosts.Count);
    }

    [Fact]
    public async Task PollingStillTreatsOtherRateLimitsAsTransientOutages()
    {
        using var tempDir = new TempStateDirectory();
        using var http = new HttpClient(new StaticHandler(HttpStatusCode.TooManyRequests, """{"error":"rate_limited"}"""));
        using var api = CreateApi(http);
        var state = new StateManager(tempDir.Path);
        var config = new AgentConfig { MasterUrl = "https://panel.example.test", AgentToken = "agent-token", StateDir = tempDir.Path };
        using var runtime = new ForwardRuntimeManager(config, new TestProcessSupervisor(), state);
        var pullBackoff = new ControlPlaneBackoff(random: () => 0.5);
        var service = new ConfigPollingService(api, runtime, state, null, TimeProvider.System, pullBackoff, leaseBackoff: null);

        await Assert.ThrowsAsync<MasterApiException>(() => service.SyncOnceAsync());

        Assert.InRange(pullBackoff.RemainingDelay, TimeSpan.FromSeconds(1), TimeSpan.FromSeconds(2.5));
    }

    [Fact]
    public async Task DesktopRuntimeSessionStartsPollingInsteadOfFailingWhenRefused()
    {
        using var tempDir = new TempStateDirectory();
        var handler = new StaticHandler(HttpStatusCode.TooManyRequests, FlatBody);
        using var http = new HttpClient(handler);
        using var api = CreateApi(http);
        using var supervisor = new TestProcessSupervisor();
        var state = new StateManager(tempDir.Path);
        using var runtime = new ForwardRuntimeManager(new AgentConfig { StateDir = tempDir.Path }, supervisor, state);
        var polling = new ConfigPollingService(api, runtime, state);
        using var health = new HealthCheckService(supervisor, runtime, state);
        await using var session = new DesktopRuntimeSession(api, polling, new HeartbeatService(api, state), health, runtime);
        var raised = new List<DeviceLimitReachedException?>();
        session.DeviceLimitChanged += raised.Add;

        await session.StartAsync(CancellationToken.None);

        Assert.NotNull(session.DeviceLimit);
        Assert.Equal(3, session.DeviceLimit!.OnlineDeviceLimit);
        Assert.NotEmpty(raised);
        Assert.NotNull(raised[0]);
        // No 1 s / 3 s / 7 s startup retries: the loop owns the steady retry.
        await Task.Delay(300);
        Assert.InRange(handler.Hosts.Count, 1, 2);
        await session.StopAsync();
    }

    [Fact]
    public async Task ControllerShowsDeviceLimitAndClearsItOnTheNextAdmittedPull()
    {
        var runtime = new DeviceLimitRuntimeSession { InitialLimit = new DeviceLimitReachedException(429, 2, 2) };
        var controller = CreateController(runtime);

        await controller.ConnectAsync();

        Assert.True(controller.Snapshot.IsConnected);
        Assert.Equal(DesktopClientController.DeviceLimitReachedCode, controller.Snapshot.ConnectionStatus);
        Assert.Equal(DesktopClientController.DeviceLimitReachedCode, controller.Snapshot.LastErrorCode);
        Assert.Equal(2, controller.Snapshot.OnlineDeviceLimit);
        Assert.Equal(2, controller.Snapshot.ActiveOnlineDevices);
        Assert.Equal(0, runtime.StopCalls);

        runtime.RaiseDeviceLimit(null);

        Assert.True(controller.Snapshot.IsConnected);
        Assert.Equal("connected", controller.Snapshot.ConnectionStatus);
        Assert.Equal(string.Empty, controller.Snapshot.LastErrorCode);
        Assert.Equal(0, controller.Snapshot.OnlineDeviceLimit);
        await controller.DisposeAsync();
    }

    [Fact]
    public async Task ControllerRefreshRefusedByDeviceLimitKeepsTheSession()
    {
        var runtime = new DeviceLimitRuntimeSession { RefreshLimit = new DeviceLimitReachedException(429, 1, 1) };
        var controller = CreateController(runtime);
        await controller.ConnectAsync();

        await Assert.ThrowsAsync<DeviceLimitReachedException>(() => controller.RefreshAsync());

        Assert.True(controller.Snapshot.IsConnected);
        Assert.Equal(DesktopClientController.DeviceLimitReachedCode, controller.Snapshot.ConnectionStatus);
        Assert.Equal(0, runtime.StopCalls);
        await controller.DisposeAsync();
    }

    [Fact]
    public async Task AgentTokenLoginRefusedByDeviceLimitReportsADistinctCode()
    {
        var controller = new DesktopClientController(
            new AgentConfig(),
            Identity(),
            (_, _) => Task.CompletedTask,
            (_, _) => new DeviceLimitRuntimeSession(),
            agentTokenPull: (_, _, _, _) => throw new DeviceLimitReachedException(429, 1, 1));

        await Assert.ThrowsAsync<DeviceLimitReachedException>(
            () => controller.LoginWithAgentTokenAsync("https://panel.example.test", 42, "agent-token"));

        Assert.Equal(DesktopClientController.DeviceLimitReachedCode, controller.Snapshot.LastErrorCode);
        Assert.Equal(1, controller.Snapshot.OnlineDeviceLimit);
        await controller.DisposeAsync();
    }

    private static MasterApiClient CreateApi(HttpClient http)
        => new("https://panel.example.test", "agent-token", "device", httpClientFactory: () => http, ownsHttpClient: false);

    private static ClientPlatformIdentity Identity()
        => new("macos-desktop", "device", "Mac", "Leikwan-Mac-GUI", "1.0.0", SupportsMptcp: false);

    private static DesktopClientController CreateController(DeviceLimitRuntimeSession runtime)
        => new(
            new AgentConfig
            {
                MasterUrl = "https://panel.example.test",
                AgentToken = "agent-token-42",
                ClientId = 42,
                ActiveClientId = 42,
                ActiveClientName = "Client 42",
            },
            Identity(),
            (_, _) => Task.CompletedTask,
            (_, _) => runtime);

    private sealed class StaticHandler(HttpStatusCode status, string body) : HttpMessageHandler
    {
        private readonly object _gate = new();
        private readonly List<string> _hosts = [];

        public IReadOnlyList<string> Hosts
        {
            get { lock (_gate) return _hosts.ToList(); }
        }

        protected override Task<HttpResponseMessage> SendAsync(HttpRequestMessage request, CancellationToken cancellationToken)
        {
            lock (_gate)
                _hosts.Add(request.RequestUri!.Host);
            return Task.FromResult(new HttpResponseMessage(status)
            {
                Content = new StringContent(body, Encoding.UTF8, "application/json"),
            });
        }
    }

    private sealed class DeviceLimitRuntimeSession : IDesktopRuntimeSession
    {
        private DeviceLimitReachedException? _limit;

        public event Action<IReadOnlyList<ForwardConfig>>? ForwardsChanged { add { } remove { } }
        public event Action<NativeOutboundCapability?>? NativeOutboundChanged { add { } remove { } }
        public event Action<int, string>? ForwardRejected { add { } remove { } }
        public event Action<string>? LogRaised { add { } remove { } }
        public event Action<DeviceLimitReachedException?>? DeviceLimitChanged;

        public DeviceLimitReachedException? InitialLimit { get; init; }
        public DeviceLimitReachedException? RefreshLimit { get; init; }
        public int StopCalls { get; private set; }
        public IReadOnlyList<ForwardConfig> Forwards => [];
        public NativeOutboundCapability? NativeOutbound => null;
        public DeviceLimitReachedException? DeviceLimit => _limit;

        public void RaiseDeviceLimit(DeviceLimitReachedException? limit)
        {
            _limit = limit;
            DeviceLimitChanged?.Invoke(limit);
        }

        public bool IsForwardRunning(int forwardId) => false;

        public Task StartAsync(CancellationToken ct)
        {
            if (InitialLimit is not null)
                RaiseDeviceLimit(InitialLimit);
            return Task.CompletedTask;
        }

        public Task RefreshAsync(CancellationToken ct)
        {
            if (RefreshLimit is not null)
            {
                RaiseDeviceLimit(RefreshLimit);
                throw RefreshLimit;
            }
            return Task.CompletedTask;
        }

        public Task StopAsync()
        {
            StopCalls++;
            return Task.CompletedTask;
        }

        public Task StartForwardAsync(int forwardId, CancellationToken ct) => Task.CompletedTask;
        public Task StopForwardAsync(int forwardId, CancellationToken ct) => Task.CompletedTask;
        public Task StartAllAsync(CancellationToken ct) => Task.CompletedTask;
        public Task StopAllAsync(CancellationToken ct) => Task.CompletedTask;

        public Task<NativeOutboundLease?> ResolveNativeOutboundLeaseAsync(int forwardId, string routeScope, CancellationToken ct)
            => Task.FromResult<NativeOutboundLease?>(null);

        public ValueTask DisposeAsync() => ValueTask.CompletedTask;
    }
}
