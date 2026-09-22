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

    [Fact]
    public void AutomaticStartupFailure_IsPerForwardAndBacksOffAcrossPolls()
    {
        using var temp = new TempStateDirectory();
        var now = DateTimeOffset.UtcNow;
        var host = new FailingStartupHost();
        using var runtime = new ForwardRuntimeManager(new AgentConfig { StateDir = temp.Path },
            new ProcessSupervisor(), new StateManager(temp.Path), ultraRuntimeHost: host, startupRetryClock: () => now);
        var first = CreateUltraForward();
        var firstLease = first.RuntimeLease!;
        runtime.ApplyForward(first, "contract-a", "config_sync");
        for (var second = 1; second < 30; second++)
        {
            now = now.AddSeconds(1);
            var retry = CreateUltraForward();
            var retryLease = retry.RuntimeLease!;
            runtime.ApplyForward(retry, "contract-a", "config_sync");
            Assert.Null(retry.RuntimeLease);
            Assert.Empty(retryLease.LeaseId);
        }
        Assert.Equal(1, host.Starts.GetValueOrDefault(880));
        Assert.Empty(firstLease.LeaseId);
        host.FailingForwardIds.Remove(881);
        runtime.ApplyForward(CreateUltraForward(881, 11881), "sibling-contract", "config_sync");
        Assert.Equal(1, host.Starts.GetValueOrDefault(881));
        Assert.DoesNotContain(881, host.Stopped);

        now = now.AddSeconds(1);
        runtime.ApplyForward(CreateUltraForward(), "contract-a", "config_sync");
        Assert.Equal(2, host.Starts[880]);
        now = now.AddSeconds(59);
        runtime.ApplyForward(CreateUltraForward(), "contract-a", "config_sync");
        Assert.Equal(2, host.Starts[880]);
        now = now.AddSeconds(1);
        runtime.ApplyForward(CreateUltraForward(), "contract-a", "config_sync");
        Assert.Equal(3, host.Starts[880]);
    }

    [Fact]
    public void StartupCooldown_ContractChangeAndManualRetryResetIt()
    {
        using var temp = new TempStateDirectory();
        var now = DateTimeOffset.UtcNow;
        var host = new FailingStartupHost();
        using var runtime = new ForwardRuntimeManager(new AgentConfig { StateDir = temp.Path },
            new ProcessSupervisor(), new StateManager(temp.Path), ultraRuntimeHost: host, startupRetryClock: () => now);
        runtime.ApplyForward(CreateUltraForward(), "contract-a", "config_sync");
        runtime.ApplyForward(CreateUltraForward(), "contract-b", "config_sync");
        Assert.Equal(2, host.Starts[880]);
        runtime.ApplyForward(CreateUltraForward(), "contract-b", "config_sync");
        Assert.Equal(2, host.Starts[880]);
        runtime.ApplyForward(CreateUltraForward(), "contract-b", "manual_apply");
        Assert.Equal(3, host.Starts[880]);

        // A UI/catalog retry has no one-shot lease, but still clears the retry
        // gate before the requested fresh-lease reconciliation.
        var catalog = CreateUltraForward();
        catalog.RuntimeLease!.Dispose();
        catalog.RuntimeLease = null;
        runtime.RestartForward(catalog, force: true, reason: "manual_apply");
        runtime.ApplyForward(CreateUltraForward(), "contract-b", "config_sync");
        Assert.Equal(4, host.Starts[880]);
    }

    [Fact]
    public void AutomaticEnsureFromCatalog_DoesNotResetStartupCooldown()
    {
        using var temp = new TempStateDirectory();
        var now = DateTimeOffset.UtcNow;
        var host = new FailingStartupHost();
        using var runtime = new ForwardRuntimeManager(new AgentConfig { StateDir = temp.Path },
            new ProcessSupervisor(), new StateManager(temp.Path), ultraRuntimeHost: host, startupRetryClock: () => now);
        runtime.ApplyForward(CreateUltraForward(), "contract", "config_sync");
        now = now.AddSeconds(15);
        var catalog = CreateUltraForward();
        catalog.RuntimeLease!.Dispose();
        catalog.RuntimeLease = null;
        var reasons = new List<string>();
        runtime.UltraRecoveryRequested += (_, reason) => reasons.Add(reason);
        runtime.RestartForward(catalog, force: true, reason: "automatic_ensure");
        runtime.ApplyForward(CreateUltraForward(), "contract", "config_sync");
        Assert.Equal(["automatic_ensure"], reasons);
        Assert.Equal(1, host.Starts[880]);
        now = now.AddSeconds(15);
        runtime.ApplyForward(CreateUltraForward(), "contract", "config_sync");
        Assert.Equal(2, host.Starts[880]);
    }

    [Fact]
    public void SuccessfulStartup_ClearsFailureBackoff()
    {
        using var temp = new TempStateDirectory();
        var now = DateTimeOffset.UtcNow;
        var host = new FailingStartupHost();
        using var runtime = new ForwardRuntimeManager(new AgentConfig { StateDir = temp.Path },
            new ProcessSupervisor(), new StateManager(temp.Path), ultraRuntimeHost: host, startupRetryClock: () => now);
        runtime.ApplyForward(CreateUltraForward(), "contract", "config_sync");
        now = now.AddSeconds(30);
        host.FailingForwardIds.Clear();
        runtime.ApplyForward(CreateUltraForward(), "contract", "config_sync");
        Assert.Equal(2, host.Starts[880]);
        host.FailingForwardIds.Add(880);
        runtime.ApplyForward(CreateUltraForward(), "contract", "config_sync");
        Assert.Equal(3, host.Starts[880]);
        now = now.AddSeconds(30);
        runtime.ApplyForward(CreateUltraForward(), "contract", "config_sync");
        Assert.Equal(4, host.Starts[880]);
    }

    [Fact]
    public void StartupCooldown_DoesNotSuppressPolicyRejection()
    {
        using var temp = new TempStateDirectory();
        var host = new FailingStartupHost();
        using var runtime = new ForwardRuntimeManager(new AgentConfig { StateDir = temp.Path },
            new ProcessSupervisor(), new StateManager(temp.Path), ultraRuntimeHost: host);
        runtime.ApplyForward(CreateUltraForward(), "contract", "config_sync");
        Assert.Single(host.Stopped);
        var revoked = CreateUltraForward(serviceState: "suspended");
        runtime.ApplyForward(revoked, "contract", "config_sync");
        Assert.Equal(1, host.Starts[880]);
        Assert.Equal(2, host.Stopped.Count);
        Assert.Null(revoked.RuntimeLease);
    }

    [Fact]
    public void StaleLeaseExpiry_StopsUnverifiedRuntimeAndRequestsFreshPolicy()
    {
        using var temp = new TempStateDirectory();
        using var supervisor = new AlwaysRunningSupervisor();
        var host = new BlockingHost { Ready = true };
        var state = new StateManager(temp.Path);
        using var runtime = new ForwardRuntimeManager(
            new AgentConfig { StateDir = temp.Path },
            supervisor,
            state,
            portListeningProbe: _ => true,
            ultraRuntimeHost: host);
        var forward = CreateUltraForward();
        var policy = forward.UltraAccess!;
        forward.RuntimeLease!.Dispose();
        forward.RuntimeLease = new UltraLease
        {
            Protocol = UltraPolicy.Protocol,
            LeaseId = "lease-newer-than-cached-policy",
            SessionTicket = policy.SessionTicket,
            ForwardId = forward.ForwardId,
            PolicyGeneration = policy.PolicyGeneration,
            TargetDigest = policy.TargetDigest,
            ExpiresAt = policy.ExpiresAt.AddSeconds(30),
            Transport = policy.Transport,
            RateLimitMbps = 20,
            QuotaRemainingBytes = 1024 * 1024,
            SelectionRef = new string('r', 43),
            SelectionTier = UltraLease.SelectionTierPaid,
            NonceHash = new string('b', 64),
            ProviderIp = "203.0.113.20",
            ProviderPort = 5438,
            User = "line-user"u8.ToArray(),
            Password = "line-password"u8.ToArray(),
        };
        var requests = new List<(int Id, string Reason)>();
        runtime.UltraRecoveryRequested += (id, reason) => requests.Add((id, reason));

        runtime.ApplyForward(forward, "contract-stale-expiry", "config_sync");

        Assert.Equal([(forward.ForwardId, "stale_policy_or_lease")], requests);
        Assert.Equal(0, host.Starts);
        Assert.Equal(1, host.Stops);
        Assert.False(host.Ready);
        Assert.Null(forward.RuntimeLease);
    }

    private sealed class FailingStartupHost : IUltraRuntimeHost
    {
        public Dictionary<int, int> Starts { get; } = [];
        public List<int> Stopped { get; } = [];
        public HashSet<int> FailingForwardIds { get; } = [880, 881];
        public void Start(ForwardConfig forward, UltraLease lease, string signature, string reason)
        {
            Starts[forward.ForwardId] = Starts.GetValueOrDefault(forward.ForwardId) + 1;
            if (FailingForwardIds.Contains(forward.ForwardId))
                throw new InvalidOperationException("synthetic_start_failure");
        }
        public bool Refresh(ForwardConfig forward, UltraLease lease) => true;
        public void Stop(int id) => Stopped.Add(id);
    }

    private static ForwardConfig CreateUltraForward(int forwardId = 880, int listenPort = 11880, string serviceState = "active")
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
                ServiceState = serviceState,
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
