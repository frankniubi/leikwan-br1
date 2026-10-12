using System.Text.Json;
using LeikwanBridge.Core.Models;
using LeikwanBridge.Core.Nodes;
using LeikwanBridge.Core.Services;
using Xunit;

namespace LeikwanBridge.Core.Tests.Nodes;

/// <summary>Hosted node protocols run through the existing Windows sing-box builder.</summary>
public sealed class WindowsNodeOutboundTests
{
    private static JsonElement Outbound(WindowsProxyProfile profile)
    {
        var json = WindowsProxyConfigBuilder.Build(profile, WindowsProxyMode.Http, []).Json;
        return JsonDocument.Parse(json).RootElement.GetProperty("outbounds")[0].Clone();
    }

    [Fact]
    public void Socks5WithAndWithoutAuth()
    {
        var auth = Outbound(WindowsProxyProfileNodeRepository.ToProfile(UserNodeShareLink.Parse("socks5://alice:wonder@198.51.100.7:1080#S")));
        Assert.Equal("socks", auth.GetProperty("type").GetString());
        Assert.Equal("5", auth.GetProperty("version").GetString());
        Assert.Equal("alice", auth.GetProperty("username").GetString());
        Assert.Equal("wonder", auth.GetProperty("password").GetString());
        Assert.Equal("proxy", auth.GetProperty("tag").GetString());

        var open = Outbound(WindowsProxyProfileNodeRepository.ToProfile(UserNodeShareLink.Parse("socks5://198.51.100.7:1080")));
        Assert.False(open.TryGetProperty("username", out _));

        var halfAuth = new WindowsProxyProfile { Protocol = WindowsProxyProtocol.Socks5, Server = "a.example.com", Port = 1, Password = "x" };
        Assert.Throws<ArgumentException>(() => WindowsProxyConfigBuilder.Build(halfAuth, WindowsProxyMode.Http, []));
    }

    [Fact]
    public void ShadowsocksPluginIsPassedToSingBox()
    {
        var node = UserNodeShareLink.Parse("ss://YWVzLTI1Ni1nY206cHc@ss.example.com:8388?plugin=simple-obfs%3Bobfs%3Dhttp#SS");
        var outbound = Outbound(WindowsProxyProfileNodeRepository.ToProfile(node));
        Assert.Equal("obfs-local", outbound.GetProperty("plugin").GetString());
        Assert.Equal("obfs=http", outbound.GetProperty("plugin_opts").GetString());
    }

    [Fact]
    public void RealityDefaultsToChromeUtlsAndHttpUpgradeIsSupported()
    {
        var reality = Outbound(WindowsProxyProfileNodeRepository.ToProfile(UserNodeShareLink.Parse(
            $"vless://11111111-2222-3333-4444-555555555555@r.example.com:443?security=reality&sni=www.example.com&pbk={new string('A', 43)}&flow=xtls-rprx-vision")));
        var tls = reality.GetProperty("tls");
        Assert.Equal("chrome", tls.GetProperty("utls").GetProperty("fingerprint").GetString());
        Assert.Equal("www.example.com", tls.GetProperty("server_name").GetString());
        Assert.True(tls.GetProperty("reality").GetProperty("enabled").GetBoolean());

        var upgrade = Outbound(WindowsProxyProfileNodeRepository.ToProfile(UserNodeShareLink.Parse(
            "vless://11111111-2222-3333-4444-555555555555@u.example.com:443?type=httpupgrade&security=tls&path=%2Fup&host=cdn.example.com")));
        var transport = upgrade.GetProperty("transport");
        Assert.Equal("httpupgrade", transport.GetProperty("type").GetString());
        Assert.Equal("/up", transport.GetProperty("path").GetString());
        Assert.Equal("cdn.example.com", transport.GetProperty("host").GetString());
    }

    [Fact]
    public void ProfileMappingRoundTripsEveryHostedProtocol()
    {
        foreach (var uri in new[]
        {
            "socks5://alice:wonder@198.51.100.7:1080#S",
            "ss://YWVzLTI1Ni1nY206cHc@ss.example.com:8388?plugin=v2ray-plugin%3Btls&uot=1#SS",
            "vless://11111111-2222-3333-4444-555555555555@v.example.com:443?encryption=none&type=grpc&security=tls&sni=v.example.com&serviceName=svc#G",
        })
        {
            var node = UserNodeShareLink.Parse(uri);
            node.LocalId = 5;
            node.HostedId = 9;
            var back = WindowsProxyProfileNodeRepository.ToNode(WindowsProxyProfileNodeRepository.ToProfile(node))!;
            Assert.Equal(UserNodeShareLink.Format(node), UserNodeShareLink.Format(back));
            Assert.Equal((5, 9L), (back.LocalId, back.HostedId!.Value));
        }
    }

    [Fact]
    public void ForwardOutboundDialsLoopbackButKeepsTheRealHostForTlsAndHttp()
    {
        var node = UserNodeShareLink.Parse("vless://11111111-2222-3333-4444-555555555555@cdn.example.com:443?type=ws&security=tls&path=%2Fray#W");
        var outbound = UserNodeOutbound.ForForward(node, 21001);
        Assert.Equal("127.0.0.1", outbound["server"]!.GetValue<string>());
        Assert.Equal(21001, outbound["server_port"]!.GetValue<int>());
        Assert.Equal("cdn.example.com", outbound["tls"]!["server_name"]!.GetValue<string>());
        Assert.Equal("cdn.example.com", outbound["transport"]!["headers"]!["Host"]!.GetValue<string>());

        var socks = UserNodeOutbound.ForForward(UserNodeShareLink.Parse("socks5://198.51.100.7:1080"), 21002);
        Assert.Equal(("127.0.0.1", 21002), (socks["server"]!.GetValue<string>(), socks["server_port"]!.GetValue<int>()));
        Assert.Throws<InvalidOperationException>(() => UserNodeOutbound.ForForward(node, 0));
    }
}
