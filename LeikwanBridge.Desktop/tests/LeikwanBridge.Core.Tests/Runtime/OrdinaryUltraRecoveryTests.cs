using System.Net;
using System.Text;
using LeikwanBridge.Core.Models;
using LeikwanBridge.Core.Services;
using LeikwanBridge.Core.Services.Ultra;
using Xunit;

namespace LeikwanBridge.Core.Tests.Runtime;

public sealed class OrdinaryUltraRecoveryTests
{
    [Fact]
    public async Task HttpTimeout_DoesNotEndBackgroundPolling_AndReportsFailure()
    {
        using var temp = new TempStateDirectory();
        using var handler = new TimeoutOnceHandler();
        using var http = new HttpClient(handler) { Timeout = TimeSpan.FromMilliseconds(100) };
        using var api = new MasterApiClient("http://127.0.0.1:1", "synthetic", httpClientFactory: () => http, ownsHttpClient: false);
        var state = new StateManager(temp.Path);
        using var runtime = new ForwardRuntimeManager(new AgentConfig { StateDir = temp.Path }, new ProcessSupervisor(), state);
        var service = new ConfigPollingService(api, runtime, state);
        var failures = new System.Collections.Concurrent.ConcurrentQueue<Exception>();
        service.SyncError += failures.Enqueue;
        try
        {
            await service.SyncOnceAsync();
            service.Start(1);
            await handler.Recovered.Task.WaitAsync(TimeSpan.FromSeconds(4));
            Assert.NotEmpty(failures);
            Assert.True(handler.Calls >= 3);
        }
        finally { await service.StopAsync(); }
    }

    [Fact]
    public async Task HealthDuringSlowUltraStart_DoesNotQueueDestructiveRestart()
    {
        using var temp = new TempStateDirectory();
        using var supervisor = new AlwaysRunningSupervisor();
        var host = new BlockingHost();
        var state = new StateManager(temp.Path);
        using var runtime = new ForwardRuntimeManager(new AgentConfig { StateDir = temp.Path }, supervisor, state, ultraRuntimeHost: host);
        using var health = new HealthCheckService(supervisor, runtime, state, portListeningProbe: _ => host.Ready);
        var forward = CreateUltraForward();
        forward.ServiceStatus = "active";
        var start = Task.Run(() => runtime.ApplyForward(forward, "contract", "config_sync"));
        Assert.True(host.Entered.Wait(TimeSpan.FromSeconds(3)));
        var check = Task.Run(() => health.RunCheckOnceAsync([forward]));
        try
        {
            await check.WaitAsync(TimeSpan.FromSeconds(1));
            Assert.Equal(0, host.Stops);
        }
        finally
        {
            host.Release.Set();
            await Task.WhenAll(start, check).WaitAsync(TimeSpan.FromSeconds(3));
        }
        Assert.True(host.Ready);
        Assert.Equal(1, host.Starts);
        Assert.Equal(0, host.Stops);
        Assert.Null(forward.RuntimeLease);
        Assert.Null(forward.UltraAccess!.GetValidationFailure(forward, DateTimeOffset.UtcNow));
    }

    [Fact]
    public async Task StopDuringHttpRequest_IsNotReportedAsNetworkFailure()
    {
        using var temp = new TempStateDirectory();
        using var handler = new TimeoutOnceHandler();
        using var http = new HttpClient(handler) { Timeout = TimeSpan.FromSeconds(10) };
        using var api = new MasterApiClient("http://127.0.0.1:1", "synthetic", httpClientFactory: () => http, ownsHttpClient: false);
        var state = new StateManager(temp.Path);
        using var runtime = new ForwardRuntimeManager(new AgentConfig { StateDir = temp.Path }, new ProcessSupervisor(), state);
        var service = new ConfigPollingService(api, runtime, state);
        int failures=0;
        service.SyncError += _ => Interlocked.Increment(ref failures);
        await service.SyncOnceAsync();
        service.Start(1);
        await handler.Blocked.Task.WaitAsync(TimeSpan.FromSeconds(3));
        await service.StopAsync().WaitAsync(TimeSpan.FromSeconds(3));
        Assert.Equal(0, failures);
        Assert.Equal(2, handler.Calls);
    }

    [Fact]
    public void ManualStartFromCatalog_RequestsFreshLease_WithoutStoppingCurrentRuntime()
    {
        using var temp = new TempStateDirectory();
        using var supervisor = new AlwaysRunningSupervisor();
        var host = new BlockingHost { Ready = true };
        var state = new StateManager(temp.Path);
        using var runtime = new ForwardRuntimeManager(new AgentConfig { StateDir = temp.Path }, supervisor, state,
            portListeningProbe: _ => host.Ready, ultraRuntimeHost: host);
        var forward = CreateUltraForward();
        forward.RuntimeLease!.Dispose();
        forward.RuntimeLease = null;
        var requests = new List<(int Id, string Reason)>();
        runtime.UltraRecoveryRequested += (id, reason) => requests.Add((id, reason));

        runtime.RestartForward(forward, force: true, reason: "manual_apply");

        Assert.Single(requests);
        Assert.Equal((forward.ForwardId, "manual_apply"), requests[0]);
        Assert.Equal(0, host.Stops);
        Assert.True(host.Ready);
    }

    [Fact]
    public void ManualStartWithFreshLease_StartsAndWipesIt()
    {
        using var temp = new TempStateDirectory();
        using var supervisor = new AlwaysRunningSupervisor();
        var host = new BlockingHost();
        host.Release.Set();
        var state = new StateManager(temp.Path);
        using var runtime = new ForwardRuntimeManager(new AgentConfig { StateDir = temp.Path }, supervisor, state,
            portListeningProbe: _ => host.Ready, ultraRuntimeHost: host);
        var forward = CreateUltraForward();
        var requests = 0;
        runtime.UltraRecoveryRequested += (_, _) => requests++;

        runtime.RestartForward(forward, force: true, reason: "manual_apply");

        Assert.Equal(1, host.Starts);
        Assert.Equal(0, requests);
        Assert.True(host.Ready);
        Assert.Empty(forward.RuntimeLease!.LeaseId);
        Assert.All(forward.RuntimeLease.Password, value => Assert.Equal(0, value));
    }

    [Fact]
    public void LateHealthRestart_DoesNotDisturbAlreadyRecoveredRuntime()
    {
        using var temp = new TempStateDirectory();
        using var supervisor = new AlwaysRunningSupervisor();
        var host = new BlockingHost { Ready = true };
        var state = new StateManager(temp.Path);
        using var runtime = new ForwardRuntimeManager(new AgentConfig { StateDir = temp.Path }, supervisor, state,
            portListeningProbe: _ => host.Ready, ultraRuntimeHost: host);
        var requests = 0;
        runtime.UltraRecoveryRequested += (_, _) => requests++;
        var forward = CreateUltraForward();
        forward.RuntimeLease!.Dispose();
        forward.RuntimeLease = null;

        runtime.RestartForward(forward, force: true, reason: "port_not_listening");

        Assert.Equal(0, requests);
        Assert.Equal(0, host.Stops);
        Assert.True(host.Ready);
    }

    private static ForwardConfig CreateUltraForward(int forwardId = 880, int listenPort = 11880)
    {
        var now = DateTimeOffset.UtcNow;
        const string target = "198.51.100.10:443";
        var generation = new string('a', 64);
        var digest = UltraPolicy.ComputeTargetDigest(target);
        return new ForwardConfig
        {
            ForwardId = forwardId,
            BackendMode = "ultra",
            ContractVersion = 2,
            ClientMode = "single",
            ActiveFamily = "ultra",
            TargetContract = "ultra_direct",
            Mode = "single",
            ListenPort = listenPort,
            Target = target,
            Transport = "tcp",
            Endpoints = [],
            UltraAccess = new UltraPolicy
            {
                ProtocolValue = UltraPolicy.Protocol,
                ForwardId = forwardId,
                PolicyGeneration = generation,
                SessionTicket = "ticket-a",
                IssuedAt = now,
                RenewAfter = now.AddMinutes(2),
                ExpiresAt = now.AddMinutes(4),
                ServiceState = "active",
                Transport = "tcp",
                RateLimitMbps = 20,
                QuotaRemainingBytes = 1024 * 1024,
                TargetDigest = digest,
            },
            RuntimeLease = new UltraLease
            {
                Protocol = UltraPolicy.Protocol,
                LeaseId = "lease-a",
                SessionTicket = "ticket-a",
                ForwardId = forwardId,
                PolicyGeneration = generation,
                TargetDigest = digest,
                ExpiresAt = now.AddMinutes(3),
                Transport = "tcp",
                RateLimitMbps = 20,
                QuotaRemainingBytes = 1024 * 1024,
                SelectionRef = new string('r', 43),
                NonceHash = new string('b', 64),
                ProviderIp = "203.0.113.20",
                ProviderPort = 5438,
                User = "line-user"u8.ToArray(),
                Password = "line-password"u8.ToArray(),
            },
        };
    }



    private sealed class TimeoutOnceHandler : HttpMessageHandler
    {
        public int Calls;
        public TaskCompletionSource Blocked = new(TaskCreationOptions.RunContinuationsAsynchronously);
        public TaskCompletionSource Recovered = new(TaskCreationOptions.RunContinuationsAsynchronously);
        protected override async Task<HttpResponseMessage> SendAsync(HttpRequestMessage request, CancellationToken ct)
        {
            var count=Interlocked.Increment(ref Calls);
            if(count==2) { Blocked.TrySetResult(); await Task.Delay(Timeout.Infinite,ct); }
            if(count>=3) Recovered.TrySetResult();
            return new HttpResponseMessage(HttpStatusCode.OK) { Content = new StringContent("{\"ok\":true,\"client\":{\"id\":1},\"forwards\":[]}", Encoding.UTF8,"application/json") };
        }
    }
    private sealed class AlwaysRunningSupervisor : ProcessSupervisor
    {
        public override bool IsRunning(int id) => true;
        public override void StopAll(int id) { }
    }
    private sealed class BlockingHost : IUltraRuntimeHost
    {
        public ManualResetEventSlim Entered = new(false), Release = new(false);
        public volatile bool Ready;
        public int Starts, Stops;
        public void Start(ForwardConfig f, UltraLease lease, string signature, string reason)
        {
            Starts++; Entered.Set();
            if(!Release.Wait(TimeSpan.FromSeconds(5))) throw new TimeoutException();
            Ready=true;
        }
        public bool Refresh(ForwardConfig f, UltraLease lease) => true;
        public void Stop(int id) { Stops++; Ready=false; }
    }
}
