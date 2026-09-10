using System.Net;
using System.Text;
using LeikwanBridge.Core.Services;
using Xunit;

namespace LeikwanBridge.Core.Tests.Runtime;

public sealed class AntiPoisonHttpTests
{
    [Fact]
    public void DohProviders_MirrorAndroidChinaFirstOrderAndBootstrapIps()
    {
        var providers = StrictDohResolver.DefaultProviders;

        Assert.Collection(
            providers,
            provider =>
            {
                Assert.Equal("https://dns.alidns.com/dns-query", provider.Endpoint.ToString());
                Assert.Equal(["223.5.5.5", "223.6.6.6"], provider.BootstrapAddresses.Select(ip => ip.ToString()).ToArray());
            },
            provider =>
            {
                Assert.Equal("https://doh.pub/dns-query", provider.Endpoint.ToString());
                Assert.Equal(["1.12.12.12", "120.53.53.53"], provider.BootstrapAddresses.Select(ip => ip.ToString()).ToArray());
            },
            provider =>
            {
                Assert.Equal("https://doh.360.cn/dns-query", provider.Endpoint.ToString());
                Assert.Equal(["101.226.4.6", "218.30.118.6"], provider.BootstrapAddresses.Select(ip => ip.ToString()).ToArray());
            });
    }

    [Fact]
    public void DnsWireMessage_EncodesAQueryAndParsesCompressedAResponse()
    {
        var query = DnsWireMessage.EncodeAQuery("v3-mirror.leikwanhost.com", transactionId: 0x1234);

        Assert.Equal(0x12, query[0]);
        Assert.Equal(0x34, query[1]);
        Assert.Equal(0x01, query[2]);
        Assert.Equal(0x00, query[3]);
        Assert.Equal(0x00, query[^4]);
        Assert.Equal(0x01, query[^3]);
        Assert.Equal(0x00, query[^2]);
        Assert.Equal(0x01, query[^1]);

        var response = BuildDnsResponse(query,
            [IPAddress.Parse("185.220.238.143"), IPAddress.Parse("161.248.136.183")]);

        var addresses = DnsWireMessage.ParseAResponse(response, expectedTransactionId: 0x1234);

        Assert.Equal(["185.220.238.143", "161.248.136.183"], addresses.Select(ip => ip.ToString()).ToArray());
    }

    [Theory]
    [InlineData("https://v3-mirror.leikwanhost.com/artifacts/client-manifest.json", true)]
    [InlineData("https://v3.leikwanhost.com/api/client/configs/pull", true)]
    [InlineData("https://artifacts.leikwanhost.com/windows/setup.exe", true)]
    [InlineData("https://a.b.leikwanhost.com/api", true)]
    [InlineData("http://v3-mirror.leikwanhost.com/api/client/configs/pull", false)]
    [InlineData("https://localhost/api/client/configs/pull", false)]
    [InlineData("http://127.0.0.1:2080/api/client/configs/pull", false)]
    [InlineData("https://127.0.0.1/api/client/configs/pull", false)]
    [InlineData("https://[::1]/api/client/configs/pull", false)]
    [InlineData("https://10.0.0.1/api/client/configs/pull", false)]
    [InlineData("https://172.16.0.2/api/client/configs/pull", false)]
    [InlineData("https://192.168.1.10/api/client/configs/pull", false)]
    [InlineData("https://169.254.1.10/api/client/configs/pull", false)]
    [InlineData("https://cdn.example.com/leikwan-bridge-setup.exe", false)]
    [InlineData("https://leikwanhost.com/api", false)]
    public void HostPolicy_OnlyProtectsHttpsPublicLeikwanSubdomains(string url, bool expected)
    {
        Assert.Equal(expected, LeikwanHttpHostPolicy.ShouldResolveWithDoh(new Uri(url)));
    }

    [Fact]
    public async Task RoutingConnector_ProtectedLeikwanHttpsHostsUseResolverIpWithoutRewritingHost()
    {
        var resolver = new RecordingResolver(IPAddress.Parse("203.0.113.7"));
        var hostConnects = new List<string>();
        var ipConnects = new List<string>();
        var connector = new LeikwanDnsRoutingConnector(
            resolver,
            connectToHostAsync: (host, port, _) =>
            {
                hostConnects.Add($"{host}:{port}");
                return ValueTask.FromResult<Stream>(new MemoryStream());
            },
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
        Assert.Empty(hostConnects);
        Assert.Equal(["203.0.113.7:443"], ipConnects);
    }

    [Fact]
    public async Task RoutingConnector_LocalhostAndCustomHostsBypassResolver()
    {
        var resolver = new RecordingResolver(IPAddress.Parse("203.0.113.7"));
        var hostConnects = new List<string>();
        var ipConnects = new List<string>();
        var connector = new LeikwanDnsRoutingConnector(
            resolver,
            connectToHostAsync: (host, port, _) =>
            {
                hostConnects.Add($"{host}:{port}");
                return ValueTask.FromResult<Stream>(new MemoryStream());
            },
            connectToIpAsync: (address, port, _) =>
            {
                ipConnects.Add($"{address}:{port}");
                return ValueTask.FromResult<Stream>(new MemoryStream());
            });

        await using var localhostStream = await connector.ConnectAsync(
            new Uri("http://127.0.0.1:2080/api/client/configs/pull"),
            "127.0.0.1",
            2080,
            CancellationToken.None);
        await using var customStream = await connector.ConnectAsync(
            new Uri("https://cdn.example.com/leikwan-bridge-setup.exe"),
            "cdn.example.com",
            443,
            CancellationToken.None);

        Assert.Empty(resolver.Hosts);
        Assert.Equal(["127.0.0.1:2080", "cdn.example.com:443"], hostConnects);
        Assert.Empty(ipConnects);
    }

    private static byte[] BuildDnsResponse(byte[] query, IReadOnlyList<IPAddress> addresses)
    {
        using var stream = new MemoryStream();
        stream.Write(query.AsSpan(0, 2));
        stream.Write([0x81, 0x80]);
        stream.Write([0x00, 0x01]);
        stream.Write([(byte)(addresses.Count >> 8), (byte)(addresses.Count & 0xff)]);
        stream.Write([0x00, 0x00, 0x00, 0x00]);
        stream.Write(query.AsSpan(12));

        foreach (var address in addresses)
        {
            var bytes = address.GetAddressBytes();
            stream.Write([0xc0, 0x0c]);
            stream.Write([0x00, 0x01]);
            stream.Write([0x00, 0x01]);
            stream.Write([0x00, 0x00, 0x00, 0x3c]);
            stream.Write([0x00, 0x04]);
            stream.Write(bytes);
        }

        return stream.ToArray();
    }

    private sealed class RecordingResolver(params IPAddress[] addresses) : ILeikwanDnsResolver
    {
        public List<string> Hosts { get; } = [];

        public Task<IReadOnlyList<IPAddress>> ResolveAAsync(string host, CancellationToken ct = default)
        {
            Hosts.Add(host);
            return Task.FromResult<IReadOnlyList<IPAddress>>(addresses);
        }
    }
}
