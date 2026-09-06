using System.Security.Cryptography;
using System.Text;
using System.Text.Json;
using LeikwanBridge.Core.Models;
using LeikwanBridge.Mac.Services;
using LeikwanBridge.Mac.Tests.Platform;
using Xunit;

namespace LeikwanBridge.Mac.Tests.Services;

public sealed class MacNativeRuleSetStoreTests
{
    [Theory]
    [InlineData("cn_only")]
    [InlineData("intl_only")]
    [InlineData("global")]
    public void RestrictedDnsDoesNotRequestBlockedLowPortsFromNativeRelay(string scope)
    {
        const string direction = "CAN -> HKG";
        using var lease = new NativeOutboundLease(180, "test", new string('a', 64), DateTimeOffset.UtcNow.AddMinutes(5),
            "tcp+udp", direction, scope, NativeOutboundLease.ComputeScopeDigest(180, direction, "tcp+udp", scope),
            "203.0.113.10", 5438, Encoding.UTF8.GetBytes("user"), Encoding.UTF8.GetBytes("password"));
        using var config = JsonDocument.Parse(MacConnectionConfigBuilder.BuildNative(lease, MacConnectionMode.Vpn, []).Json);
        Assert.All(config.RootElement.GetProperty("dns").GetProperty("servers").EnumerateArray(),
            server => Assert.False(server.TryGetProperty("detour", out _)));
        Assert.Contains(config.RootElement.GetProperty("route").GetProperty("rules").EnumerateArray(),
            rule => rule.TryGetProperty("port_range", out var ports) && ports.GetString() == "1:999"
                && rule.GetProperty("outbound").GetString() == "direct");
    }

    [Fact]
    public async Task BundledRulesAreVerifiedReusedAndCorruptCacheIsRepaired()
    {
        using var temp = new TemporaryDirectory();
        var paths = await MacNativeRuleSetStore.EnsureAsync(temp.Path);
        var ipBytes = await File.ReadAllBytesAsync(paths.GeoIpPath);
        var siteBytes = await File.ReadAllBytesAsync(paths.GeoSitePath);
        Assert.Equal(MacNativeRuleSetStore.GeoIpSha256, Convert.ToHexString(SHA256.HashData(ipBytes)).ToLowerInvariant());
        Assert.Equal(MacNativeRuleSetStore.GeoSiteSha256, Convert.ToHexString(SHA256.HashData(siteBytes)).ToLowerInvariant());
        Assert.Equal("SRS", Encoding.ASCII.GetString(ipBytes, 0, 3));
        Assert.Equal("SRS", Encoding.ASCII.GetString(siteBytes, 0, 3));
        var timestamp = File.GetLastWriteTimeUtc(paths.GeoIpPath);
        Assert.Equal(paths, await MacNativeRuleSetStore.EnsureAsync(temp.Path));
        Assert.Equal(timestamp, File.GetLastWriteTimeUtc(paths.GeoIpPath));
        await File.WriteAllTextAsync(paths.GeoIpPath, "corrupt cache");
        await MacNativeRuleSetStore.EnsureAsync(temp.Path);
        Assert.Equal(ipBytes, await File.ReadAllBytesAsync(paths.GeoIpPath));
        Assert.Empty(Directory.GetFiles(temp.Path, "*.tmp"));
    }

    [Theory]
    [InlineData("cn_only", false, "restricted")]
    [InlineData("intl_only", false, "restricted")]
    [InlineData("intl_only", false, "unrestricted")]
    [InlineData("global", true, "unrestricted")]
    public async Task NativeLocalRulesNeverDependOnTheRelayForBootstrap(string scope, bool bypassChina, string policy)
    {
        using var temp = new TemporaryDirectory();
        var paths = await MacNativeRuleSetStore.EnsureAsync(temp.Path);
        const string direction = "CAN -> HKG";
        var digest = policy == "restricted"
            ? NativeOutboundLease.ComputeScopeDigest(180, direction, "tcp+udp", scope)
            : NativeOutboundLease.ComputeScopeDigestV2(180, direction, "tcp+udp", scope, policy);
        using var lease = new NativeOutboundLease(180, "test", new string('a', 64), DateTimeOffset.UtcNow.AddMinutes(5),
            "tcp+udp", direction, scope, digest, "203.0.113.10", 5438,
            Encoding.UTF8.GetBytes("test-user"), Encoding.UTF8.GetBytes("test-password"), routePolicy: policy);
        using var baseline = JsonDocument.Parse(MacConnectionConfigBuilder.BuildNative(lease, MacConnectionMode.Vpn, [], bypassChinaMainland: bypassChina).Json);
        using var actual = JsonDocument.Parse(MacConnectionConfigBuilder.BuildNative(lease, MacConnectionMode.Vpn, [],
            bypassChinaMainland: bypassChina, ruleSetPaths: paths).Json);
        var rules = actual.RootElement.GetProperty("route").GetProperty("rule_set").EnumerateArray().ToArray();
        Assert.Equal(2, rules.Length);
        Assert.All(rules, rule =>
        {
            Assert.Equal("local", rule.GetProperty("type").GetString());
            Assert.Equal("binary", rule.GetProperty("format").GetString());
            Assert.True(File.Exists(rule.GetProperty("path").GetString()));
            Assert.False(rule.TryGetProperty("url", out _));
            Assert.False(rule.TryGetProperty("download_detour", out _));
        });
        Assert.Equal(baseline.RootElement.GetProperty("route").GetProperty("rules").GetRawText(),
            actual.RootElement.GetProperty("route").GetProperty("rules").GetRawText());
        Assert.Equal(baseline.RootElement.GetProperty("dns").GetRawText(), actual.RootElement.GetProperty("dns").GetRawText());
        if (policy == "unrestricted")
            Assert.Equal("proxy", actual.RootElement.GetProperty("dns").GetProperty("servers")[0].GetProperty("detour").GetString());
    }
}
