using System.Net;
using System.Net.Sockets;
using LeikwanBridge.Core.Services.PanelEgress;
using Xunit;

namespace LeikwanBridge.Core.Tests.PanelEgress;

public sealed class PanelDirectEgressEngineTests
{
    private static readonly IPAddress PanelIp = IPAddress.Parse("203.0.113.7");

    private static (PanelDirectEgressEngine Engine, FakeDnsResolver Resolver, FakeInterfaceProvider Provider, RecordingRouteExecutor Routes) Create(
        IReadOnlyList<IPAddress>? addresses = null,
        bool tunPresent = true)
    {
        var resolver = new FakeDnsResolver((addresses ?? [PanelIp]).ToArray());
        var provider = new FakeInterfaceProvider
        {
            V4 = FakeInterfaceProvider.PhysicalV4(),
            DefaultRoutePhysicalV4 = !tunPresent,
        };
        var routes = new RecordingRouteExecutor();
        var engine = new PanelDirectEgressEngine(resolver, provider, routes);
        return (engine, resolver, provider, routes);
    }

    [Fact]
    public async Task ResolveHostAsync_ResolvesOnceAndCaches()
    {
        var (engine, resolver, _, _) = Create();

        var first = await engine.ResolveHostAsync("v3.leikwanhost.com");
        var second = await engine.ResolveHostAsync("v3.leikwanhost.com");

        Assert.Equal([PanelIp], first);
        Assert.Equal(first, second);
        Assert.Equal(1, resolver.CallCount);
    }

    [Fact]
    public async Task ResolveHostAsync_NonDirectHostsSkipResolution()
    {
        var (engine, resolver, _, _) = Create();

        var addresses = await engine.ResolveHostAsync("cdn.example.com");

        Assert.Empty(addresses);
        Assert.Equal(0, resolver.CallCount);
    }

    [Fact]
    public async Task ResolveHostAsync_IpLiteralsReturnDirectlyWithoutResolver()
    {
        var (engine, resolver, _, _) = Create();

        Assert.Equal([IPAddress.Parse("203.0.113.5")], await engine.ResolveHostAsync("203.0.113.5"));
        Assert.Empty(await engine.ResolveHostAsync("127.0.0.1"));
        Assert.Equal(0, resolver.CallCount);
    }

    [Fact]
    public async Task TunPresent_AddsHostRoutesThroughPhysicalGateway()
    {
        var (engine, resolver, _, routes) = Create(tunPresent: true);

        await engine.ResolveHostAsync("v3.leikwanhost.com");

        var entry = Assert.Single(routes.Additions);
        Assert.Equal(PanelIp, entry.Address);
        Assert.Equal(IPAddress.Parse("192.168.1.1"), entry.Gateway);
        Assert.Equal(12, entry.InterfaceIndex);
        Assert.Equal("Ethernet", entry.InterfaceName);
        Assert.Empty(routes.Deletions);
        Assert.Equal(1, engine.AppliedRouteCount);
    }

    [Fact]
    public async Task NoTun_ZeroSideEffects_NoRoutesAdded()
    {
        var (engine, resolver, _, routes) = Create(tunPresent: false);

        await engine.ResolveHostAsync("v3.leikwanhost.com");

        Assert.Empty(routes.Additions);
        Assert.Empty(routes.Deletions);
        Assert.Equal(0, engine.AppliedRouteCount);
    }

    [Fact]
    public async Task RefreshAsync_ReplansOnAddressChange()
    {
        var (engine, resolver, _, routes) = Create([PanelIp], tunPresent: true);
        await engine.ResolveHostAsync("v3.leikwanhost.com");
        routes.Clear();
        resolver.Resolve = _ => [IPAddress.Parse("198.51.100.9")];

        await engine.RefreshAsync();

        Assert.Single(routes.Additions);
        Assert.Equal(IPAddress.Parse("198.51.100.9"), routes.Additions[0].Address);
        Assert.Single(routes.Deletions);
        Assert.Equal(PanelIp, routes.Deletions[0].Address);
        Assert.Equal(1, engine.AppliedRouteCount);
    }

    [Fact]
    public async Task RefreshAsync_PrunesRoutesWhenHostFailsToResolve()
    {
        var (engine, resolver, _, routes) = Create([PanelIp], tunPresent: true);
        await engine.ResolveHostAsync("v3.leikwanhost.com");
        routes.Clear();
        resolver.Resolve = _ => throw new InvalidOperationException("doh down");

        await engine.RefreshAsync();

        Assert.Empty(routes.Additions);
        Assert.Single(routes.Deletions);
        Assert.Equal(PanelIp, routes.Deletions[0].Address);
        Assert.Equal(0, engine.AppliedRouteCount);
    }

    [Fact]
    public async Task RouteAddFailure_IsNotRecordedAndRetriedOnRefresh()
    {
        var (engine, _, _, routes) = Create(tunPresent: true);
        routes.AddResult = RouteOperationResult.Failed;

        await engine.ResolveHostAsync("v3.leikwanhost.com");
        Assert.Equal(0, engine.AppliedRouteCount);
        Assert.Single(routes.Additions);

        routes.Clear();
        routes.AddResult = RouteOperationResult.Applied;
        await engine.RefreshAsync();

        Assert.Single(routes.Additions);
        Assert.Equal(1, engine.AppliedRouteCount);
    }

    [Fact]
    public async Task AlreadyPresent_TreatedAsApplied()
    {
        var (engine, _, _, routes) = Create(tunPresent: true);
        routes.AddResult = RouteOperationResult.AlreadyPresent;

        await engine.ResolveHostAsync("v3.leikwanhost.com");

        Assert.Equal(1, engine.AppliedRouteCount);
    }

    [Fact]
    public async Task Dispose_DeletesAllAppliedRoutes()
    {
        var (engine, _, _, routes) = Create(tunPresent: true);
        await engine.ResolveHostAsync("v3.leikwanhost.com");
        routes.Clear();

        engine.Dispose();

        var entry = Assert.Single(routes.Deletions);
        Assert.Equal(PanelIp, entry.Address);
        Assert.Equal(0, engine.AppliedRouteCount);
    }

    [Fact]
    public void BindSocket_SmokeTest_NoThrowOnRealSocket()
    {
        var (engine, _, provider, _) = Create(tunPresent: true);
        provider.V4 = new PhysicalEgressInterface(
            "Ethernet", 1, AddressFamily.InterNetwork, IPAddress.Parse("192.168.1.1"), true);
        using var socket = new Socket(AddressFamily.InterNetwork, SocketType.Stream, ProtocolType.Tcp);

        engine.BindSocket(socket);
        engine.BindSocket(null!);

        // Disposed engines and non-IP sockets are safe no-ops.
        engine.Dispose();
        using var unixSocket = new Socket(AddressFamily.Unix, SocketType.Stream, ProtocolType.Unspecified);
        engine.BindSocket(unixSocket);
    }

    [Fact]
    public void BindSocket_NoPhysicalInterface_IsNoOp()
    {
        var (engine, _, provider, _) = Create(tunPresent: true);
        provider.V4 = null;
        using var socket = new Socket(AddressFamily.InterNetwork, SocketType.Stream, ProtocolType.Tcp);

        engine.BindSocket(socket);
    }
}
