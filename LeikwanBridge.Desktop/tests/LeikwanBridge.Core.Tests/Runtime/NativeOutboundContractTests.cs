using System.Security.Cryptography;
using System.Text.Json;
using LeikwanBridge.Core.Models;
using LeikwanBridge.Core.Services;
using Xunit;

namespace LeikwanBridge.Core.Tests.Runtime;

public sealed class NativeOutboundContractTests
{
    [Fact]
    public void NativePipeNameIsIsolatedFromRegularUltraPipe()
    {
        var native = UltraLeaseClient.NativePipeName(174, "0123456789abcdef");

        Assert.Equal("leikwan-ultra-native-174-0123456789abcdef", native);
        Assert.NotEqual(UltraLeaseClient.PipeName(174), native);
        Assert.Throws<ArgumentException>(() => UltraLeaseClient.NativePipeName(174, "bad\\pipe"));
    }

    [Fact]
    public void PullResponseKeepsNativeCapabilityOptionalAndScrubbed()
    {
        var hidden = JsonSerializer.Deserialize<PullResponse>("{\"ok\":true,\"forwards\":[]}")!;
        Assert.Null(hidden.NativeOutbound);

        var visible = JsonSerializer.Deserialize<PullResponse>("""
        {
          "ok": true,
          "native_outbound": {
            "enabled": true,
            "protocol": "socks5",
            "lease_protocol": "leikwan-ultra-native-socks5-v1",
            "transport": "tcp+udp",
            "forward_ids": [158],
            "default_forward_id": 158,
            "route_scopes": ["cn_only", "global"],
            "default_route_scope": "global",
            "forwards": [{
              "forward_id": 158,
              "direction": "Ultra Hong Kong to Mainland China",
              "scope_digest": "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa",
              "transport": "tcp+udp",
              "route_scopes": ["cn_only", "global"],
              "default_route_scope": "global"
            }]
          }
        }
        """)!;
        Assert.NotNull(visible.NativeOutbound);
        Assert.Equal(["cn_only", "global"], visible.NativeOutbound!.Forwards[0].RouteScopes);
        Assert.Equal("global", visible.NativeOutbound.Forwards[0].DefaultRouteScope);
        Assert.DoesNotContain("leigod", JsonSerializer.Serialize(visible), StringComparison.OrdinalIgnoreCase);
        Assert.DoesNotContain("source_id", JsonSerializer.Serialize(visible), StringComparison.OrdinalIgnoreCase);
    }

    [Fact]
    public void NativeConfigEmitsSocks5UdpAndScopeRulesThenWipesCredentials()
    {
        var user = System.Text.Encoding.UTF8.GetBytes("temporary-user");
        var password = System.Text.Encoding.UTF8.GetBytes("temporary-password");
        var lease = new NativeOutboundLease(
            158,
            "lease-158",
            new string('a', 64),
            DateTimeOffset.UtcNow.AddMinutes(2),
            "tcp+udp",
            "Ultra Hong Kong to Mainland China",
            "cn_only",
            new string('b', 64),
            "203.0.113.20",
            5438,
            user,
            password);

        var fragment = NativeOutboundConfigBuilder.Build(lease, bypassChina: true, localPort: 2080);
        using var document = JsonDocument.Parse(fragment.Json);
        var root = document.RootElement;
        var outbound = root.GetProperty("outbound");
        Assert.Equal("socks", outbound.GetProperty("type").GetString());
        Assert.Equal("5", outbound.GetProperty("version").GetString());
        Assert.False(outbound.TryGetProperty("network", out _));
        Assert.True(outbound.GetProperty("udp_over_tcp").GetProperty("enabled").GetBoolean() == false);
        Assert.Equal("temporary-user", outbound.GetProperty("username").GetString());
        Assert.Equal("temporary-password", outbound.GetProperty("password").GetString());
        Assert.Contains(root.GetProperty("route").GetProperty("rules").EnumerateArray(), rule =>
            rule.TryGetProperty("rule_set", out var sets)
            && sets.EnumerateArray().Any(item => item.GetString() == "geoip-cn"));
        Assert.Equal("direct", root.GetProperty("route").GetProperty("final").GetString());
        Assert.All(
            root.GetProperty("route").GetProperty("rule_set").EnumerateArray(),
            item => Assert.Equal("direct", item.GetProperty("download_detour").GetString()));

        lease.Dispose();
        Assert.All(user, value => Assert.Equal(0, value));
        Assert.All(password, value => Assert.Equal(0, value));
        Assert.Empty(lease.User);
        Assert.Empty(lease.Password);
    }

    [Fact]
    public void NativeLeaseAcceptsIntlOnlyScope()
    {
        const int forwardId = 201;
        const string direction = "Mainland China -> International";
        using var lease = new NativeOutboundLease(
            forwardId,
            "lease-201",
            new string('a', 64),
            DateTimeOffset.UtcNow.AddMinutes(2),
            "tcp+udp",
            direction,
            "intl_only",
            NativeOutboundLease.ComputeScopeDigest(forwardId, direction, "tcp+udp", "intl_only"),
            "203.0.113.21",
            5438,
            System.Text.Encoding.UTF8.GetBytes("temporary-user"),
            System.Text.Encoding.UTF8.GetBytes("temporary-password"));

        Assert.True(lease.IsUsable(DateTimeOffset.UtcNow));
    }
}
