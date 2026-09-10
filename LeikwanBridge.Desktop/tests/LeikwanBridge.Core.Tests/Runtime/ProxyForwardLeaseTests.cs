using System.Net;
using System.Net.Sockets;
using LeikwanBridge.Core.Models;
using LeikwanBridge.Core.Services;
using Xunit;

namespace LeikwanBridge.Core.Tests.Runtime;

public sealed class ProxyForwardLeaseTests
{
    [Theory]
    [InlineData(false)]
    [InlineData(true)]
    public async Task RuntimeSessionPublishesAcquiredAndReleasedForwardState(bool alreadyRunning)
    {
        using var temp = new TempStateDirectory();
        using var tcp = new TcpListener(IPAddress.Loopback, 0);
        tcp.Start();
        var forward = Forward();
        forward.ListenPort = ((IPEndPoint)tcp.LocalEndpoint).Port;
        using var supervisor = new ReadySupervisor { Running = alreadyRunning };
        var state = new StateManager(temp.Path);
        using var runtime = new ListenerRuntime(supervisor, state);
        using var api = new MasterApiClient("https://panel.example.test", "test-token", "test-device");
        var polling = new ConfigPollingService(api, runtime, state);
        polling.LatestForwards.Add(forward);
        using var health = new HealthCheckService(supervisor, runtime, state);
        await using var session = new DesktopRuntimeSession(api, polling, new HeartbeatService(api, state), health, runtime);
        var published = new List<bool>();
        session.ForwardsChanged += forwards => published.Add(session.IsForwardRunning(forwards.Single().ForwardId));
        using var deadline = new CancellationTokenSource(TimeSpan.FromSeconds(5));

        var lease = await session.AcquireProxyForwardAsync(forward.ForwardId, deadline.Token);

        Assert.Equal(new[] { true }, published);
        Assert.Equal(!alreadyRunning, lease.OwnsForward);
        await lease.DisposeAsync();
        Assert.Equal(alreadyRunning ? new[] { true } : new[] { true, false }, published);
        Assert.Equal(alreadyRunning, supervisor.Running);
    }

    [Fact]
    public async Task RuntimeCannotAcceptForeignListenerWhileItsOwnProcessIsAlive()
    {
        using var temp = new TempStateDirectory();
        using var tcp = new TcpListener(IPAddress.Loopback, 0);
        tcp.Start();
        var forward = Forward();
        forward.ListenPort = ((IPEndPoint)tcp.LocalEndpoint).Port;
        using var supervisor = new WrongOwnerSupervisor();
        using var runtime = new ForwardRuntimeManager(new AgentConfig(), supervisor, new StateManager(temp.Path));
        using var deadline = new CancellationTokenSource(TimeSpan.FromMilliseconds(300));
        await Assert.ThrowsAnyAsync<OperationCanceledException>(() => runtime.AcquireProxyForwardAsync(forward, deadline.Token));
    }

    [Fact]
    public async Task ReusesReadyForwardAndDoesNotStopItOnDispose()
    {
        var starts = 0;
        var stops = 0;
        var forward = Forward();
        var lease = await ProxyForwardLease.AcquireAsync(forward, () => true,
            _ => { starts++; return Task.CompletedTask; },
            _ => { stops++; return Task.CompletedTask; },
            readinessProbe: (_, _) => Task.FromResult(true));
        Assert.False(lease.OwnsForward);
        Assert.Equal(11880, lease.ListenPort);
        Assert.Equal("198.51.100.9:443", forward.Target);
        await lease.DisposeAsync();
        Assert.Equal(0, starts);
        Assert.Equal(0, stops);
    }

    [Fact]
    public async Task StartsBeforeProbingAndStopsOwnedForwardExactlyOnce()
    {
        var running = false;
        var stops = 0;
        var events = new List<string>();
        var lease = await ProxyForwardLease.AcquireAsync(Forward(), () => running,
            _ => { events.Add("start"); running = true; return Task.CompletedTask; },
            _ => { stops++; running = false; return Task.CompletedTask; },
            readinessProbe: (_, _) => { events.Add("probe"); return Task.FromResult(true); });
        Assert.Equal(new[] { "start", "probe" }, events);
        Assert.True(lease.OwnsForward);
        await lease.DisposeAsync();
        await lease.DisposeAsync();
        Assert.Equal(1, stops);
        Assert.False(running);
    }

    [Fact]
    public async Task CancelledStartupCleansOwnedForwardUsingUncancelledToken()
    {
        using var cancellation = new CancellationTokenSource();
        var stops = 0;
        await Assert.ThrowsAnyAsync<OperationCanceledException>(() => ProxyForwardLease.AcquireAsync(
            Forward(), () => false,
            _ => { cancellation.Cancel(); return Task.CompletedTask; },
            ct => { Assert.False(ct.IsCancellationRequested); stops++; return Task.CompletedTask; },
            cancellation.Token, readinessProbe: (_, _) => Task.FromResult(false)));
        Assert.Equal(1, stops);
    }

    [Theory]
    [InlineData(false, 1)]
    [InlineData(true, 0)]
    public async Task TimeoutDoesNotAcceptAnotherProcessesListener(bool alreadyRunning, int expectedStops)
    {
        var stops = 0;
        await Assert.ThrowsAsync<TimeoutException>(() => ProxyForwardLease.AcquireAsync(
            Forward(), () => alreadyRunning,
            _ => Task.CompletedTask,
            _ => { stops++; return Task.CompletedTask; },
            readinessProbe: (_, _) => Task.FromResult(!alreadyRunning),
            timeout: TimeSpan.FromMilliseconds(40)));
        Assert.Equal(expectedStops, stops);
    }

    [Fact]
    public async Task FailedStartIsCleanedUpAndCannotPublishReadyLease()
    {
        var stopped = false;
        var error = await Assert.ThrowsAsync<InvalidOperationException>(() => ProxyForwardLease.AcquireAsync(
            Forward(), () => false,
            _ => throw new InvalidOperationException("start_failed"),
            _ => { stopped = true; return Task.CompletedTask; },
            readinessProbe: (_, _) => Task.FromResult(true)));
        Assert.Equal("start_failed", error.Message);
        Assert.True(stopped);
    }

    [Theory]
    [InlineData(0, 1234)]
    [InlineData(1, 0)]
    [InlineData(1, 65536)]
    public async Task RejectsInvalidForwardBeforeStarting(int id, int port)
    {
        var forward = Forward();
        forward.ForwardId = id;
        forward.ListenPort = port;
        await Assert.ThrowsAsync<InvalidOperationException>(() => ProxyForwardLease.AcquireAsync(
            forward, () => false, _ => throw new Exception("must_not_start"), _ => Task.CompletedTask));
    }

    [Fact]
    public async Task UdpOnlyReadinessRequiresUdpListenerNotTcpListener()
    {
        using var tcp = new TcpListener(IPAddress.Loopback, 0);
        tcp.Start();
        var forward = Forward();
        forward.ListenPort = ((IPEndPoint)tcp.LocalEndpoint).Port;
        forward.Transport = "udp";
        Assert.False(await ProxyForwardLease.IsListenerReadyAsync(forward));
        using var udp = new UdpClient(new IPEndPoint(IPAddress.Loopback, forward.ListenPort));
        Assert.True(await ProxyForwardLease.IsListenerReadyAsync(forward));
    }

    [Fact]
    public void PairingConfigClonesAndRoundTripsWithoutLosingOtherPreferences()
    {
        var config = new AgentConfig
        {
            ProxySelectedForwardId = 180,
            ProxyProfileIdsByForwardId = new() { [180] = 5 },
            RememberLoginInfo = false,
        };
        var clone = config.Clone();
        Assert.Equal(180, clone.ProxySelectedForwardId);
        Assert.Equal(5, clone.ProxyProfileIdsByForwardId[180]);
        Assert.False(clone.RememberLoginInfo);
        clone.ProxyProfileIdsByForwardId[180] = 8;
        Assert.Equal(5, config.ProxyProfileIdsByForwardId[180]);
    }

    private static ForwardConfig Forward() => new()
    {
        ForwardId = 180,
        ListenPort = 11880,
        Target = "198.51.100.9:443",
        Transport = "tcp",
    };

    private sealed class WrongOwnerSupervisor : ProcessSupervisor
    {
        public override bool IsRunning(int forwardId) => true;
        public override IReadOnlyList<int> GetProcessIds(int forwardId) => [int.MaxValue];
    }

    private sealed class ReadySupervisor : ProcessSupervisor
    {
        public bool Running { get; set; }
        public override bool IsRunning(int forwardId) => Running;
        public override IReadOnlyList<int> GetProcessIds(int forwardId) => Running ? [Environment.ProcessId] : [];
    }

    private sealed class ListenerRuntime(ReadySupervisor supervisor, StateManager state)
        : ForwardRuntimeManager(new AgentConfig(), supervisor, state,
            proxyPortOwners: port => [new PortOwner("tcp", "127.0.0.1", port, Environment.ProcessId)])
    {
        public override void RestartForward(ForwardConfig forward, bool force, string reason) => supervisor.Running = true;
        public override void StopForward(int forwardId) => supervisor.Running = false;
    }
}
