using System.Text.Json;
using LeikwanBridge.Mac.Services;
using Xunit;

namespace LeikwanBridge.Mac.Tests.Services;

public sealed class MacConnectionConfigBuilderTests
{
    [Fact]
    public void ShadowsocksProfileUsesStructuredOutboundAndDirectBypassRules()
    {
        var profile = new MacConnectionProfile
        {
            Id = 7,
            Name = "Tokyo SS",
            Protocol = MacConnectionProtocol.Shadowsocks,
            Server = "198.51.100.7",
            Port = 443,
            Method = "2022-blake3-aes-256-gcm",
            Password = "memory-only-secret",
        };

        var result = MacConnectionConfigBuilder.Build(
            profile,
            MacConnectionMode.Proxy,
            ["198.51.100.20/32", "panel.example.test"]);

        using var document = JsonDocument.Parse(result.Json);
        var root = document.RootElement;
        var outbound = root.GetProperty("outbounds")[0];
        Assert.Equal("shadowsocks", outbound.GetProperty("type").GetString());
        Assert.Equal("198.51.100.7", outbound.GetProperty("server").GetString());
        Assert.Equal("memory-only-secret", outbound.GetProperty("password").GetString());
        var rules = root.GetProperty("route").GetProperty("rules");
        Assert.Contains(rules.EnumerateArray(), rule =>
            rule.TryGetProperty("ip_cidr", out var cidrs)
            && cidrs.EnumerateArray().Any(item => item.GetString() == "198.51.100.20/32"));
        Assert.Contains(rules.EnumerateArray(), rule =>
            rule.TryGetProperty("domain", out var domains)
            && domains.EnumerateArray().Any(item => item.GetString() == "panel.example.test"));
        Assert.Equal("proxy", root.GetProperty("route").GetProperty("final").GetString());
    }

    [Fact]
    public void VlessAndWireGuardProfilesUseOnlySupportedProtocols()
    {
        var vless = new MacConnectionProfile
        {
            Protocol = MacConnectionProtocol.Vless,
            Server = "vless.example.test",
            Port = 443,
            Uuid = "00000000-0000-0000-0000-000000000001",
            Transport = "ws",
            Path = "/edge",
            Host = "cdn.example.test",
            Tls = true,
            ServerName = "cdn.example.test",
        };
        var wireGuard = new MacConnectionProfile
        {
            Protocol = MacConnectionProtocol.WireGuard,
            Server = "198.51.100.8",
            Port = 51820,
            PrivateKey = "private-key",
            LocalAddress = "10.8.0.2/32",
            PeerPublicKey = "peer-public-key",
            AllowedIps = "0.0.0.0/0, ::/0",
        };

        using var vlessJson = JsonDocument.Parse(MacConnectionConfigBuilder.Build(vless, MacConnectionMode.Vpn, []).Json);
        using var wgJson = JsonDocument.Parse(MacConnectionConfigBuilder.Build(wireGuard, MacConnectionMode.Proxy, []).Json);
        Assert.Equal("vless", vlessJson.RootElement.GetProperty("outbounds")[0].GetProperty("type").GetString());
        Assert.Equal("tun", vlessJson.RootElement.GetProperty("inbounds")[0].GetProperty("type").GetString());
        Assert.Equal("wireguard", wgJson.RootElement.GetProperty("endpoints")[0].GetProperty("type").GetString());
        Assert.Equal("proxy", wgJson.RootElement.GetProperty("endpoints")[0].GetProperty("tag").GetString());
    }

    [Fact]
    public void UnsupportedProtocolIsRejectedBeforeConfigIsBuilt()
    {
        var profile = new MacConnectionProfile { Protocol = "vmess", Server = "example.test", Port = 443 };

        var error = Assert.Throws<ArgumentException>(() =>
            MacConnectionConfigBuilder.Build(profile, MacConnectionMode.Proxy, []));

        Assert.Contains("protocol", error.Message, StringComparison.OrdinalIgnoreCase);
    }
}
