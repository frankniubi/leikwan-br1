using System.Net;
using System.Text;
using LeikwanBridge.Core.Models;
using LeikwanBridge.Core.Services;
using LeikwanBridge.Core.Services.Ultra;
using Xunit;

namespace LeikwanBridge.Core.Tests.Runtime;

/// <summary>
/// W1 (control-plane outage survival) on the desktop host: offline-validity
/// header, backoff, idle-aware health, and the listener readiness wait.
/// </summary>
public sealed class ControlPlaneHaTests
{
    // ---- backoff -------------------------------------------------------

    [Fact]
    public void Backoff_FullDrawDoublesFromFiveSecondsAndCapsAtSixty()
    {
        var clock = new ManualClock();
        var backoff = new ControlPlaneBackoff(clock, random: () => 1.0);

        var delays = Enumerable.Range(0, 7).Select(_ => backoff.RecordFailure().TotalSeconds).ToArray();

        Assert.Equal([5, 10, 20, 40, 60, 60, 60], delays);
        Assert.Equal(7, backoff.ConsecutiveFailures);
        Assert.True(backoff.IsBackingOff);
        Assert.Equal(TimeSpan.FromSeconds(60), backoff.RemainingDelay);
        clock.Advance(TimeSpan.FromSeconds(60));
        Assert.False(backoff.IsBackingOff);
    }

    [Fact]
    public void Backoff_JitterStaysWithinTheCeilingAndAboveTheFloor()
    {
        var random = new Random(1234);
        var backoff = new ControlPlaneBackoff(new ManualClock(), random: random.NextDouble);
        for (var failure = 1; failure <= 40; failure++)
        {
            var delay = backoff.RecordFailure();
            var ceiling = backoff.CeilingFor(failure);
            Assert.InRange(delay, ControlPlaneBackoff.DefaultMinimumDelay, ceiling);
            Assert.True(ceiling <= TimeSpan.FromSeconds(60));
        }
        // A zero draw never becomes an immediate retry.
        var zero = new ControlPlaneBackoff(new ManualClock(), random: () => 0.0);
        Assert.Equal(ControlPlaneBackoff.DefaultMinimumDelay, zero.RecordFailure());
    }

    [Fact]
    public void Backoff_SuccessResetsTheSequence()
    {
        var backoff = new ControlPlaneBackoff(new ManualClock(), random: () => 1.0);
        backoff.RecordFailure();
        backoff.RecordFailure();

        Assert.True(backoff.RecordSuccess());
        Assert.False(backoff.IsBackingOff);
        Assert.Equal(0, backoff.ConsecutiveFailures);
        Assert.Equal(TimeSpan.FromSeconds(5), backoff.RecordFailure());
        Assert.True(backoff.RecordSuccess());
        Assert.False(backoff.RecordSuccess());
    }

    [Theory]
    [InlineData(502, true)]
    [InlineData(503, true)]
    [InlineData(500, true)]
    [InlineData(530, true)]
    [InlineData(429, true)]
    [InlineData(401, false)]
    [InlineData(409, false)]
    [InlineData(404, false)]
    public void Backoff_ClassifiesControlPlaneFailures(int status, bool expected)
        => Assert.Equal(expected, ControlPlaneBackoff.IsControlPlaneFailure(new MasterApiException(status, "error")));

    [Fact]
    public void Backoff_TransportErrorsAndTimeoutsAreControlPlaneFailures()
    {
        Assert.True(ControlPlaneBackoff.IsControlPlaneFailure(new HttpRequestException("down")));
        Assert.True(ControlPlaneBackoff.IsControlPlaneFailure(new TimeoutException()));
        Assert.True(ControlPlaneBackoff.IsControlPlaneFailure(new TaskCanceledException()));
        Assert.True(ControlPlaneBackoff.IsControlPlaneFailure(
            new MasterApiException(200, "master_api_invalid_json_response (HTTP 200, content-type text/html)")));
        Assert.False(ControlPlaneBackoff.IsControlPlaneFailure(new InvalidOperationException("bug")));
    }

    // ---- offline-validity header ----------------------------------------

    [Fact]
    public async Task ConfigPull_SendsOfflineValidityHeader_OnPrimaryAndMirror()
    {
        var handler = new RecordingHandler(request => request.RequestUri!.Host == "primary.test"
            ? new HttpResponseMessage(HttpStatusCode.ServiceUnavailable)
            : Json("""{"ok":true,"client":{"id":1,"name":"c","display_host":"h","agent_config_json":{}},"forwards":[]}"""));
        using var http = new HttpClient(handler);
        using var api = new MasterApiClient("https://primary.test", "token",
            httpClientFactory: () => http, ownsHttpClient: false,
            fallbackEndpoints: ["https://mirror.test"]);

        var pull = await api.PullConfigsAsync(sinceRevision: "r1", waitSeconds: 10);

        Assert.True(pull.Ok);
        Assert.Equal(["primary.test", "mirror.test"], handler.Requests.Select(item => item.Host).ToArray());
        Assert.All(handler.Requests, item => Assert.Equal("1800", item.OfflineValidity));
    }

    [Fact]
    public async Task UltraLeaseResolve_SendsOfflineValidityHeader_OnPrimaryAndMirror()
    {
        var handler = new RecordingHandler(request => request.RequestUri!.Host == "primary.test"
            ? throw new HttpRequestException("connection reset")
            : Json("""{"ok":true,"leases":[]}"""));
        using var http = new HttpClient(handler);
        using var api = new MasterApiClient("https://primary.test", "token", "device-a",
            httpClientFactory: () => http, ownsHttpClient: false,
            fallbackEndpoints: ["https://mirror.test"]);

        var leases = await api.ResolveUltraLeasesAutoAsync(CreateUltraForward(TimeSpan.FromMinutes(30)));

        Assert.Empty(leases);
        Assert.Equal(["primary.test", "mirror.test"], handler.Requests.Select(item => item.Host).ToArray());
        Assert.All(handler.Requests, item =>
        {
            Assert.Equal("/api/client/ultra/lease", item.Path);
            Assert.Equal("1800", item.OfflineValidity);
        });
    }

    [Fact]
    public async Task Heartbeat_DoesNotCarryTheOfflineValidityHeader()
    {
        var handler = new RecordingHandler(_ => Json("{}"));
        using var http = new HttpClient(handler);
        using var api = new MasterApiClient("https://primary.test", "token",
            httpClientFactory: () => http, ownsHttpClient: false, fallbackEndpoints: []);

        await api.SendHeartbeatAsync(new HeartbeatPayload());

        Assert.Null(Assert.Single(handler.Requests).OfflineValidity);
    }

    // ---- runtime policy state -----------------------------------------------

    [Theory]
    [InlineData("""{"policy_state":"active"}""", UltraRuntimePolicyState.Active)]
    [InlineData("""{"policy_state":"awaiting","error_code":"awaiting_policy"}""", UltraRuntimePolicyState.Awaiting)]
    [InlineData("""{"policy_state":"lapsed","error_code":"policy_lapsed"}""", UltraRuntimePolicyState.Lapsed)]
    [InlineData("""{"policy_state":"revoked"}""", UltraRuntimePolicyState.Revoked)]
    [InlineData("""{"error_code":"awaiting_policy"}""", UltraRuntimePolicyState.Awaiting)]
    [InlineData("""{"error_code":"policy_lapsed"}""", UltraRuntimePolicyState.Lapsed)]
    [InlineData("""{"last_error":"policy_lapsed"}""", UltraRuntimePolicyState.Lapsed)]
    [InlineData("""{"error_code":"runtime_stopped"}""", UltraRuntimePolicyState.Unknown)]
    [InlineData("""{"policy_state":"active","error_code":"awaiting_policy"}""", UltraRuntimePolicyState.Active)]
    [InlineData("""{"state":"ready"}""", UltraRuntimePolicyState.Unknown)]
    public void RuntimeStatus_ReadsNewAndLegacyPolicyState(string json, UltraRuntimePolicyState expected)
    {
        using var temp = new TempStateDirectory();
        var path = Path.Combine(temp.Path, "status.json");
        File.WriteAllText(path, json);

        Assert.Equal(expected, UltraRuntimeStatus.ReadPolicyState(path));
    }

    [Fact]
    public void RuntimeStatus_IgnoresASnapshotWrittenBeforeTheLastLeaseDelivery()
    {
        using var temp = new TempStateDirectory();
        var path = Path.Combine(temp.Path, "status.json");
        var delivered = new DateTimeOffset(2026, 9, 24, 10, 0, 0, 500, TimeSpan.Zero);
        File.WriteAllText(path, """{"policy_state":"lapsed","updated_at":"2026-09-24T09:59:57Z"}""");
        Assert.Equal(UltraRuntimePolicyState.Unknown, UltraRuntimeStatus.ReadPolicyState(path, delivered));

        // Same whole second as the delivery (the runtime stamps seconds) counts.
        File.WriteAllText(path, """{"policy_state":"lapsed","updated_at":"2026-09-24T10:00:00Z"}""");
        Assert.Equal(UltraRuntimePolicyState.Lapsed, UltraRuntimeStatus.ReadPolicyState(path, delivered));
        Assert.Equal(UltraRuntimePolicyState.Lapsed, UltraRuntimeStatus.ReadPolicyState(path, notBefore: null));
    }

    [Fact]
    public void RestartForward_IdleUltraRuntime_RequestsALeaseInsteadOfARestart()
    {
        using var temp = new TempStateDirectory();
        var host = new StatusHost(Path.Combine(temp.Path, "status.json"));
        File.WriteAllText(host.StatusPath, """{"policy_state":"lapsed","error_code":"policy_lapsed"}""");
        using var runtime = CreateRuntime(temp, host);
        var recoveries = new List<string>();
        var refreshes = new List<string>();
        runtime.UltraRecoveryRequested += (_, reason) => recoveries.Add(reason);
        runtime.UltraLeaseRefreshRequested += (_, reason) => refreshes.Add(reason);
        var forward = CreateUltraForward(TimeSpan.FromMinutes(30));

        Assert.True(runtime.IsUltraRuntimeIdleOnPurpose(forward.ForwardId));
        runtime.RestartForward(forward, force: true, reason: "port_not_listening");

        Assert.Empty(recoveries);
        Assert.Equal(["port_not_listening"], refreshes);
        Assert.Equal(0, host.Starts);
        Assert.Equal(0, host.Stops);
    }

    [Theory]
    [InlineData("""{"policy_state":"active"}""", "port_not_listening")]
    [InlineData("""{"policy_state":"revoked"}""", "port_not_listening")]
    [InlineData("""{"policy_state":"lapsed"}""", ForwardRuntimeManager.IdleRuntimeUnresponsiveReason)]
    public void RestartForward_BrokenOrUnresponsiveRuntime_KeepsTheLeaseAwareRestartPath(string status, string reason)
    {
        using var temp = new TempStateDirectory();
        var host = new StatusHost(Path.Combine(temp.Path, "status.json"));
        File.WriteAllText(host.StatusPath, status);
        using var runtime = CreateRuntime(temp, host);
        var recoveries = new List<string>();
        var refreshes = 0;
        runtime.UltraRecoveryRequested += (_, value) => recoveries.Add(value);
        runtime.UltraLeaseRefreshRequested += (_, _) => refreshes++;

        runtime.RestartForward(CreateUltraForward(TimeSpan.FromMinutes(4)), force: true, reason: reason);

        Assert.Equal([reason], recoveries);
        Assert.Equal(0, refreshes);
    }

    // ---- idle-aware health ----------------------------------------------------

    [Fact]
    public async Task HealthCheck_IdleUltraRuntime_IsNotRestartedButGetsSpacedLeaseRequests()
    {
        using var temp = new TempStateDirectory();
        var (service, runtime, supervisor, clock, forward) = CreateHealth(temp, idle: true);

        await service.RunCheckOnceAsync([forward]);
        await service.RunCheckOnceAsync([forward]);
        Assert.Empty(runtime.Restarts);
        Assert.Single(runtime.LeaseRefreshes);

        clock.Advance(HealthCheckService.IdleLeaseRefreshInterval);
        await service.RunCheckOnceAsync([forward]);
        clock.Advance(HealthCheckService.IdleLeaseRefreshInterval);
        await service.RunCheckOnceAsync([forward]);
        Assert.Empty(runtime.Restarts);
        Assert.Equal(HealthCheckService.MaxIdleLeaseRefreshes, runtime.LeaseRefreshes.Count);

        // Deliveries did not bring the listener back: fall back to the
        // lease-aware restart path (which itself never kills without a lease).
        clock.Advance(HealthCheckService.IdleLeaseRefreshInterval);
        await service.RunCheckOnceAsync([forward]);
        Assert.Equal([ForwardRuntimeManager.IdleRuntimeUnresponsiveReason], runtime.Restarts);
        GC.KeepAlive(supervisor);
    }

    [Fact]
    public async Task HealthCheck_NotIdleAndNotListening_RestartsAsBefore()
    {
        using var temp = new TempStateDirectory();
        var (service, runtime, _, _, forward) = CreateHealth(temp, idle: false);

        await service.RunCheckOnceAsync([forward]);

        Assert.Equal(["port_not_listening"], runtime.Restarts);
        Assert.Empty(runtime.LeaseRefreshes);
    }

    [Fact]
    public async Task HealthCheck_ExitedProcess_IsRestartedEvenIfItsLastStatusWasIdle()
    {
        using var temp = new TempStateDirectory();
        var (service, runtime, supervisor, _, forward) = CreateHealth(temp, idle: true);
        supervisor.RunningRoles.Clear();

        await service.RunCheckOnceAsync([forward]);

        Assert.Equal(["process_dead"], runtime.Restarts);
        Assert.Empty(runtime.LeaseRefreshes);
    }

    // ---- listener readiness ---------------------------------------------------

    [Fact]
    public void WaitForPort_ReturnsEarlyWhenTheRuntimeProcessDies()
    {
        var aliveChecks = 0;
        var started = DateTime.UtcNow;

        var ready = WindowsUltraRuntimeHost.WaitForPort(
            12345,
            _ => false,
            TimeSpan.FromSeconds(20),
            () => Interlocked.Increment(ref aliveChecks) < 3);

        Assert.False(ready);
        Assert.True(DateTime.UtcNow - started < TimeSpan.FromSeconds(5));
        Assert.Equal(3, aliveChecks);
    }

    [Fact]
    public void WaitForPort_SucceedsWhenTheListenerAppearsLate()
    {
        var bindAt = DateTime.UtcNow + TimeSpan.FromMilliseconds(400);

        var ready = WindowsUltraRuntimeHost.WaitForPort(
            12345,
            _ => DateTime.UtcNow >= bindAt,
            TimeSpan.FromSeconds(10),
            () => true);

        Assert.True(ready);
        Assert.True(DateTime.UtcNow >= bindAt);
    }

    [Fact]
    public void WaitForPort_ListenerBoundJustAsTheProcessExits_IsStillReported()
    {
        var probes = 0;
        var ready = WindowsUltraRuntimeHost.WaitForPort(
            12345,
            _ => Interlocked.Increment(ref probes) >= 2,
            TimeSpan.FromSeconds(10),
            () => false);

        Assert.True(ready);
    }

    [Fact]
    public void WaitForPort_TimesOutWhenTheListenerNeverAppears()
    {
        var started = DateTime.UtcNow;
        var ready = WindowsUltraRuntimeHost.WaitForPort(12345, _ => false, TimeSpan.FromMilliseconds(200), () => true);

        Assert.False(ready);
        Assert.True(DateTime.UtcNow - started >= TimeSpan.FromMilliseconds(190));
        Assert.True(WindowsUltraRuntimeHost.ListenerReadyTimeout >= TimeSpan.FromSeconds(20));
    }

    // ---- helpers ----------------------------------------------------------------

    private static ForwardRuntimeManager CreateRuntime(TempStateDirectory temp, IUltraRuntimeHost host)
        => new(new AgentConfig { StateDir = temp.Path }, new AlwaysRunningSupervisor(), new StateManager(temp.Path),
            binaryExists: _ => true, portListeningProbe: _ => false, ultraRuntimeHost: host);

    private static (HealthCheckService Service, IdleRecordingRuntime Runtime, TestProcessSupervisor Supervisor, ManualClock Clock, ForwardConfig Forward)
        CreateHealth(TempStateDirectory temp, bool idle)
    {
        var supervisor = new TestProcessSupervisor();
        supervisor.RunningRoles.Add("ultra-runtime");
        var state = new StateManager(temp.Path);
        var forward = CreateUltraForward(TimeSpan.FromMinutes(30));
        state.SaveApplied(new Dictionary<string, AppliedForwardState>
        {
            [forward.ForwardId.ToString()] = new()
            {
                ForwardId = forward.ForwardId,
                Mode = "ultra",
                ListenPort = forward.ListenPort,
                ProcessRoles = ["ultra-runtime"],
            },
        });
        var runtime = new IdleRecordingRuntime(state, supervisor) { Idle = idle };
        var clock = new ManualClock();
        var service = new HealthCheckService(supervisor, runtime, state, portListeningProbe: _ => false,
            clock: () => clock.GetUtcNow());
        return (service, runtime, supervisor, clock, forward);
    }

    private static ForwardConfig CreateUltraForward(TimeSpan validity)
    {
        var now = DateTimeOffset.UtcNow;
        const string target = "198.51.100.10:443";
        return new ForwardConfig
        {
            ForwardId = 880,
            ServiceStatus = "active",
            BackendMode = "ultra",
            Mode = "single",
            ListenPort = 11880,
            Target = target,
            Transport = "tcp",
            UltraAccess = new UltraPolicy
            {
                ProtocolValue = UltraPolicy.Protocol,
                ForwardId = 880,
                PolicyGeneration = new string('a', 64),
                SessionTicket = "ticket-a",
                IssuedAt = now,
                RenewAfter = now.AddMinutes(4),
                ExpiresAt = now + validity,
                ServiceState = "active",
                Transport = "tcp",
                RateLimitMbps = 20,
                QuotaRemainingBytes = 1024 * 1024,
                TargetDigest = UltraPolicy.ComputeTargetDigest(target),
            },
        };
    }

    private static HttpResponseMessage Json(string body)
        => new(HttpStatusCode.OK) { Content = new StringContent(body, Encoding.UTF8, "application/json") };

    private sealed record RecordedRequest(string Host, string Path, string? OfflineValidity);

    private sealed class RecordingHandler(Func<HttpRequestMessage, HttpResponseMessage> respond) : HttpMessageHandler
    {
        public List<RecordedRequest> Requests { get; } = [];

        protected override Task<HttpResponseMessage> SendAsync(HttpRequestMessage request, CancellationToken cancellationToken)
        {
            var header = request.Headers.TryGetValues(UltraGrantValidity.OfflineValidityHeader, out var values)
                ? string.Join(",", values)
                : null;
            lock (Requests)
                Requests.Add(new RecordedRequest(request.RequestUri!.Host, request.RequestUri.AbsolutePath, header));
            var response = respond(request);
            response.RequestMessage = request;
            return Task.FromResult(response);
        }
    }

    private sealed class ManualClock : TimeProvider
    {
        private DateTimeOffset _now = new(2026, 9, 24, 12, 0, 0, TimeSpan.Zero);
        public override DateTimeOffset GetUtcNow() => _now;
        public void Advance(TimeSpan duration) => _now += duration;
    }

    private sealed class AlwaysRunningSupervisor : ProcessSupervisor
    {
        public override bool IsRunning(int forwardId) => true;
        public override void StopAll(int forwardId) { }
    }

    private sealed class StatusHost(string statusPath) : IUltraRuntimeHost
    {
        public string StatusPath { get; } = statusPath;
        public int Starts { get; private set; }
        public int Stops { get; private set; }
        public void Start(ForwardConfig forward, UltraLease lease, string contractSignature, string applyReason) => Starts++;
        public bool Refresh(ForwardConfig forward, UltraLease lease) => true;
        public void Stop(int forwardId) => Stops++;
        public string? GetStatusPath(int forwardId) => StatusPath;
    }

    private sealed class IdleRecordingRuntime(StateManager state, ProcessSupervisor supervisor)
        : ForwardRuntimeManager(new AgentConfig(), supervisor, state, binaryExists: _ => true, portListeningProbe: _ => false)
    {
        public bool Idle { get; set; }
        public List<string> Restarts { get; } = [];
        public List<string> LeaseRefreshes { get; } = [];

        public override bool IsUltraRuntimeIdleOnPurpose(int forwardId) => Idle && IsForwardRunning(forwardId);

        public override UltraRuntimePolicyState GetUltraRuntimePolicyState(int forwardId)
            => Idle ? UltraRuntimePolicyState.Lapsed : UltraRuntimePolicyState.Active;

        public override void RequestUltraLeaseRefresh(int forwardId, string reason) => LeaseRefreshes.Add(reason);

        public override void RestartForward(ForwardConfig forward, bool force, string reason) => Restarts.Add(reason);
    }
}
