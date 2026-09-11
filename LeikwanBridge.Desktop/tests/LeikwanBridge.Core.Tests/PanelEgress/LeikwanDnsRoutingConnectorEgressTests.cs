using System.Net;
using System.Net.Sockets;
using LeikwanBridge.Core.Services;
using LeikwanBridge.Core.Services.PanelEgress;
using Xunit;

namespace LeikwanBridge.Core.Tests.PanelEgress;

public sealed class LeikwanDnsRoutingConnectorEgressTests
{
    private sealed class FakeCoordinator(
        Func<string, IReadOnlyList<IPAddress>> resolve,
        Func<string, bool>? isDirect = null) : IPanelDirectEgressCoordinator
    {
        public List<string> ResolvedHosts { get; } = [];

        public bool IsDirectEgressHost(string host)
            => isDirect?.Invoke(host) ?? true;

        public bool IsDirectEgressUri(Uri uri) => IsDirectEgressHost(uri.IdnHost);

        public Task<IReadOnlyList<IPAddress>> ResolveHostAsync(string host, CancellationToken ct = default)
        {
            ResolvedHosts.Add(host);
            return Task.FromResult(resolve(host));
        }

        public void BindSocket(Socket socket)
        {
        }
    }

    [Fact]
    public async Task DirectEgressHost_ResolvesThroughCoordinator()
    {
        var coordinator = new FakeCoordinator(_ => [IPAddress.Parse("203.0.113.7")]);
        var ipConnects = new List<string>();
        var connector = new LeikwanDnsRoutingConnector(
            new FakeDnsResolver(),
            connectToHostAsync: (host, port, _) =>
                throw new InvalidOperationException("host connect must not be used"),
            connectToIpAsync: (address, port, _) =>
            {
                ipConnects.Add($"{address}:{port}");
                return ValueTask.FromResult<Stream>(new MemoryStream());
            },
            egress: coordinator);

        await using var stream = await connector.ConnectAsync(
            new Uri("https://update-cdn.example.net/leikwan-bridge-setup.exe"),
            "update-cdn.example.net",
            443,
            CancellationToken.None);

        Assert.Equal(["203.0.113.7:443"], ipConnects);
        Assert.Equal(["update-cdn.example.net"], coordinator.ResolvedHosts);
    }

    [Fact]
    public async Task IpConnectPath_ConfiguresSocketBeforeConnecting()
    {
        // Default socket factory: the configure hook must fire on the real
        // socket before the (here cancelled) connect attempt.
        var configuredFamilies = new List<AddressFamily?>();
        var connector = new LeikwanDnsRoutingConnector(
            new FakeDnsResolver(IPAddress.Parse("203.0.113.7")),
            egress: null,
            configureSocket: socket => configuredFamilies.Add(socket.AddressFamily));

        using var timeout = new CancellationTokenSource(TimeSpan.FromMilliseconds(500));
        try
        {
            await connector.ConnectAsync(
                new Uri("https://v3-mirror.leikwanhost.com/api/client/configs/pull"),
                "v3-mirror.leikwanhost.com",
                443,
                timeout.Token);
        }
        catch (Exception ex) when (ex is SocketException or OperationCanceledException or InvalidOperationException)
        {
        }

        Assert.Equal([AddressFamily.InterNetwork], configuredFamilies);
    }

    [Fact]
    public async Task NonDirectHost_UsesPlainHostConnectWithoutSocketConfig()
    {
        var coordinator = new FakeCoordinator(
            _ => [],
            isDirect: host => false);
        var hostConnects = new List<string>();
        var configured = 0;
        var connector = new LeikwanDnsRoutingConnector(
            new FakeDnsResolver(),
            connectToHostAsync: (host, port, _) =>
            {
                hostConnects.Add($"{host}:{port}");
                return ValueTask.FromResult<Stream>(new MemoryStream());
            },
            connectToIpAsync: (_, _, _) => throw new InvalidOperationException("ip connect must not be used"),
            egress: coordinator,
            configureSocket: _ => configured++);

        await using var stream = await connector.ConnectAsync(
            new Uri("https://cdn.example.com/setup.exe"),
            "cdn.example.com",
            443,
            CancellationToken.None);

        Assert.Equal(["cdn.example.com:443"], hostConnects);
        Assert.Empty(coordinator.ResolvedHosts);
        Assert.Equal(0, configured);
    }

    [Fact]
    public async Task PolicyHostWithoutEgress_StillUsesInjectedResolver()
    {
        var resolver = new FakeDnsResolver(IPAddress.Parse("203.0.113.7"));
        var ipConnects = new List<string>();
        var connector = new LeikwanDnsRoutingConnector(
            resolver,
            connectToIpAsync: (address, port, _) =>
            {
                ipConnects.Add($"{address}:{port}");
                return ValueTask.FromResult<Stream>(new MemoryStream());
            });

        await using var stream = await connector.ConnectAsync(
            new Uri("https://v3-mirror.leikwanhost.com/api/client/configs/pull"),
            "v3-mirror.leikwanhost.com",
            443,
            CancellationToken.None);

        Assert.Equal(["v3-mirror.leikwanhost.com"], resolver.Hosts);
        Assert.Equal(["203.0.113.7:443"], ipConnects);
    }

    [Fact]
    public async Task LocalhostTarget_StaysOnHostConnectPath()
    {
        var coordinator = new FakeCoordinator(_ => [], isDirect: host => false);
        var hostConnects = new List<string>();
        var connector = new LeikwanDnsRoutingConnector(
            new FakeDnsResolver(),
            connectToHostAsync: (host, port, _) =>
            {
                hostConnects.Add($"{host}:{port}");
                return ValueTask.FromResult<Stream>(new MemoryStream());
            },
            egress: coordinator);

        await using var stream = await connector.ConnectAsync(
            new Uri("http://127.0.0.1:2080/health"),
            "127.0.0.1",
            2080,
            CancellationToken.None);

        Assert.Equal(["127.0.0.1:2080"], hostConnects);
        Assert.Empty(coordinator.ResolvedHosts);
    }
}
