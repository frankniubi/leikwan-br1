using System.Text.Json;
using LeikwanBridge.Core.Models;
using LeikwanBridge.Core.Services;
using Xunit;

namespace LeikwanBridge.Core.Tests.Runtime;

/// <summary>
/// An unrestricted native route must resolve destinations with the config's
/// protected DNS before the SOCKS handoff. Handing the relay a bare hostname
/// let its upstream resolve it with polluted DNS, so Google, YouTube and
/// GitHub failed TLS on a global route (the macOS client already did this).
/// </summary>
public sealed class NativeDomainResolveTests
{
    [Theory]
    [InlineData(WindowsProxyMode.Http)]
    [InlineData(WindowsProxyMode.Tun)]
    public void UnrestrictedGlobalResolvesWithTheCustomServer(WindowsProxyMode mode)
    {
        using var lease = CreateNativeLease("global", "unrestricted");
        using var document = JsonDocument.Parse(WindowsProxyConfigBuilder.BuildNative(
            lease,
            mode,
            [],
            dnsMode: WindowsProxyDnsMode.Custom,
            customDnsServers: ["8.8.8.8"]).Json);
        var route = document.RootElement.GetProperty("route");

        Assert.Contains(route.GetProperty("rules").EnumerateArray(), rule =>
            rule.TryGetProperty("action", out var action) && action.GetString() == "resolve");
        Assert.Equal("custom-0", route.GetProperty("default_domain_resolver").GetString());
        Assert.Equal("custom-0", document.RootElement.GetProperty("dns").GetProperty("final").GetString());
    }

    // TCP DNS through the native relay intermittently closes before replying
    // (observed 2026-10-13: 28 of 30 queries to 8.8.8.8:53 hit EOF), and every
    // hostname resolves through this server first, so browsing died while
    // IP-only apps kept working. The native SOCKS5 relay carries real UDP.
    [Theory]
    [InlineData("global", "unrestricted")]
    [InlineData("global", "restricted")]
    [InlineData("intl_only", "restricted")]
    public void NativeCustomDnsUsesUdpThroughTheRelay(string routeScope, string routePolicy)
    {
        using var lease = CreateNativeLease(routeScope, routePolicy);
        using var document = JsonDocument.Parse(WindowsProxyConfigBuilder.BuildNative(
            lease,
            WindowsProxyMode.Http,
            [],
            dnsMode: WindowsProxyDnsMode.Custom,
            customDnsServers: ["8.8.8.8"]).Json);
        var custom = document.RootElement.GetProperty("dns").GetProperty("servers").EnumerateArray()
            .Single(server => server.GetProperty("tag").GetString() == "custom-0");

        Assert.Equal("udp", custom.GetProperty("type").GetString());
        Assert.Equal("proxy", custom.GetProperty("detour").GetString());
    }

    [Fact]
    public void UnrestrictedGlobalResolvesWithRemoteDohByDefault()
    {
        using var lease = CreateNativeLease("global", "unrestricted");
        using var document = JsonDocument.Parse(WindowsProxyConfigBuilder.BuildNative(lease, WindowsProxyMode.Http, []).Json);
        var route = document.RootElement.GetProperty("route");

        Assert.Contains(route.GetProperty("rules").EnumerateArray(), rule =>
            rule.TryGetProperty("action", out var action) && action.GetString() == "resolve");
        Assert.Equal("remote", route.GetProperty("default_domain_resolver").GetString());
    }

    [Fact]
    public void RestrictedGlobalStillHandsTheHostnameToTheRelay()
    {
        using var lease = CreateNativeLease("global", "restricted");
        using var document = JsonDocument.Parse(WindowsProxyConfigBuilder.BuildNative(lease, WindowsProxyMode.Http, []).Json);
        var route = document.RootElement.GetProperty("route");

        Assert.DoesNotContain(route.GetProperty("rules").EnumerateArray(), rule =>
            rule.TryGetProperty("action", out var action) && action.GetString() == "resolve");
        Assert.Equal("local", route.GetProperty("default_domain_resolver").GetString());
    }

    private static NativeOutboundLease CreateNativeLease(string routeScope, string routePolicy)
    {
        const int forwardId = 180;
        const string direction = "Guangzhou to Hong Kong";
        return new NativeOutboundLease(
            forwardId,
            "native-lease",
            new string('a', 64),
            DateTimeOffset.UtcNow.AddMinutes(2),
            "tcp+udp",
            direction,
            routeScope,
            NativeOutboundLease.ComputeScopeDigestV2(forwardId, direction, "tcp+udp", routeScope, routePolicy),
            "203.0.113.20",
            5438,
            System.Text.Encoding.UTF8.GetBytes("native-user"),
            System.Text.Encoding.UTF8.GetBytes("native-password"),
            routePolicy: routePolicy);
    }
}
