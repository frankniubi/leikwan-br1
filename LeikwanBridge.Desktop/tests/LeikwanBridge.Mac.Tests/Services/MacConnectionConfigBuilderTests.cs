using System.Text.Json;
using System.Diagnostics;
using LeikwanBridge.Core.Models;
using LeikwanBridge.Mac.Services;
using Xunit;

namespace LeikwanBridge.Mac.Tests.Services;

public sealed class MacConnectionConfigBuilderTests
{
    [Fact]
    public void VpnLetsRuntimeAllocateDarwinUtunInsteadOfDisplayName()
    {
        var profile = new MacConnectionProfile
        {
            Protocol = MacConnectionProtocol.Shadowsocks,
            Server = "198.51.100.7", Port = 443,
            Method = "aes-256-gcm", Password = "test-secret",
        };
        using var document = JsonDocument.Parse(MacConnectionConfigBuilder.Build(profile, MacConnectionMode.Vpn, []).Json);
        Assert.False(document.RootElement.GetProperty("inbounds")[0].TryGetProperty("interface_name", out _));
    }

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
        Assert.Contains(rules.EnumerateArray(), rule =>
            rule.TryGetProperty("action", out var action)
            && action.GetString() == "sniff");
        Assert.DoesNotContain(root.GetProperty("inbounds")[0].EnumerateObject(), property => property.Name == "sniff");
        var dnsServers = root.GetProperty("dns").GetProperty("servers");
        Assert.Equal("https", dnsServers[0].GetProperty("type").GetString());
        Assert.Equal("local", dnsServers[1].GetProperty("type").GetString());
        Assert.Equal("proxy", root.GetProperty("route").GetProperty("final").GetString());
    }

    [Fact]
    public void VlessIsRejectedWhileWireGuardRemainsSupported()
    {
        var vless = new MacConnectionProfile
        {
            Protocol = "vless",
            Server = "vless.example.test",
            Port = 443,
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

        using var wgJson = JsonDocument.Parse(MacConnectionConfigBuilder.Build(wireGuard, MacConnectionMode.Proxy, []).Json);
        Assert.Throws<ArgumentException>(() => MacConnectionConfigBuilder.Build(vless, MacConnectionMode.Vpn, []));
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

    [Fact]
    public void NativeCnOnlyUsesSocks5AndDirectFallbackWithoutAStoredNode()
    {
        const int forwardId = 158;
        const string direction = "Ultra Hong Kong to Mainland China";
        using var lease = new NativeOutboundLease(
            forwardId,
            "native-lease",
            new string('a', 64),
            DateTimeOffset.UtcNow.AddMinutes(2),
            "tcp+udp",
            direction,
            "cn_only",
            NativeOutboundLease.ComputeScopeDigest(forwardId, direction, "tcp+udp", "cn_only"),
            "203.0.113.20",
            5438,
            System.Text.Encoding.UTF8.GetBytes("user"),
            System.Text.Encoding.UTF8.GetBytes("password"));

        using var document = JsonDocument.Parse(MacConnectionConfigBuilder.BuildNative(
            lease,
            MacConnectionMode.Vpn,
            []).Json);
        var root = document.RootElement;
        var outbound = root.GetProperty("outbounds")[0];
        Assert.Equal("socks", outbound.GetProperty("type").GetString());
        Assert.Equal("5", outbound.GetProperty("version").GetString());
        Assert.False(outbound.TryGetProperty("network", out _));
        var tun = root.GetProperty("inbounds")[0];
        Assert.Equal("tun", tun.GetProperty("type").GetString());
        Assert.True(tun.GetProperty("endpoint_independent_nat").GetBoolean());
        Assert.Contains(tun.GetProperty("route_exclude_address").EnumerateArray(), item => item.GetString() == "127.0.0.0/8");
        Assert.Contains(tun.GetProperty("route_exclude_address").EnumerateArray(), item => item.GetString() == "203.0.113.20/32");
        Assert.Equal("direct", root.GetProperty("route").GetProperty("final").GetString());
        Assert.Equal("remote", root.GetProperty("route").GetProperty("default_domain_resolver").GetString());
        Assert.Contains(root.GetProperty("route").GetProperty("rules").EnumerateArray(), rule =>
            rule.TryGetProperty("action", out var action)
            && action.GetString() == "hijack-dns");
        Assert.Contains(root.GetProperty("route").GetProperty("rules").EnumerateArray(), rule =>
            rule.TryGetProperty("action", out var action)
            && action.GetString() == "reject"
            && rule.GetProperty("ip_cidr").EnumerateArray().Any(item => item.GetString() == "172.19.0.2/32"));
        Assert.Contains(root.GetProperty("route").GetProperty("rules").EnumerateArray(), rule =>
            rule.TryGetProperty("rule_set", out var sets)
            && sets.EnumerateArray().Any(item => item.GetString() == "geoip-cn")
            && rule.GetProperty("outbound").GetString() == "proxy");
        var dnsServers = root.GetProperty("dns").GetProperty("servers");
        Assert.Contains(dnsServers.EnumerateArray(), server =>
            server.GetProperty("tag").GetString() == "china"
            && server.GetProperty("server").GetString() == "223.5.5.5"
            && server.GetProperty("tls").GetProperty("server_name").GetString() == "dns.alidns.com"
            && server.GetProperty("detour").GetString() == "proxy");
        Assert.Contains(root.GetProperty("dns").GetProperty("rules").EnumerateArray(), rule =>
            rule.GetProperty("server").GetString() == "china"
            && rule.GetProperty("rule_set").EnumerateArray().Any(item => item.GetString() == "geosite-cn"));
        var routeRules = root.GetProperty("route").GetProperty("rules").EnumerateArray().ToList();
        Assert.Contains(routeRules, rule =>
            rule.TryGetProperty("action", out var action)
            && action.GetString() == "resolve"
            && rule.GetProperty("strategy").GetString() == "ipv4_only");
        var resolveIndex = routeRules.FindIndex(rule =>
            rule.TryGetProperty("action", out var action)
            && action.GetString() == "resolve");
        var chinaRouteIndex = routeRules.FindIndex(rule =>
            rule.TryGetProperty("rule_set", out var sets)
            && sets.EnumerateArray().Any(item => item.GetString() == "geoip-cn")
            && rule.GetProperty("outbound").GetString() == "proxy");
        Assert.True(resolveIndex >= 0 && resolveIndex < chinaRouteIndex);
        Assert.Equal("direct", root.GetProperty("route").GetProperty("rule_set")[0].GetProperty("download_detour").GetString());

        using var localRelayDocument = JsonDocument.Parse(MacConnectionConfigBuilder.BuildNative(
            lease,
            MacConnectionMode.Vpn,
            [],
            nativeProxyHost: "127.0.0.1",
            nativeProxyPort: 2099).Json);
        Assert.Contains(
            localRelayDocument.RootElement.GetProperty("inbounds")[0]
                .GetProperty("route_exclude_address").EnumerateArray(),
            item => item.GetString() == "203.0.113.20/32");
    }

    [Fact]
    public void NativeIntlOnlyKeepsChinaResolutionLocalAndResolvesBeforeProxy()
    {
        using var lease = new NativeOutboundLease(
            159,
            "native-lease",
            new string('b', 64),
            DateTimeOffset.UtcNow.AddMinutes(2),
            "tcp+udp",
            "Mainland China -> Hong Kong",
            "intl_only",
            NativeOutboundLease.ComputeScopeDigest(159, "Mainland China -> Hong Kong", "tcp+udp", "intl_only"),
            "203.0.113.21",
            5438,
            System.Text.Encoding.UTF8.GetBytes("user"),
            System.Text.Encoding.UTF8.GetBytes("password"));

        using var document = JsonDocument.Parse(MacConnectionConfigBuilder.BuildNative(lease, MacConnectionMode.Proxy, []).Json);
        var root = document.RootElement;
        Assert.Contains(root.GetProperty("dns").GetProperty("rules").EnumerateArray(), rule =>
            rule.GetProperty("server").GetString() == "local"
            && rule.GetProperty("rule_set").EnumerateArray().Any(item => item.GetString() == "geosite-cn"));
        Assert.Equal("proxy", root.GetProperty("dns").GetProperty("servers")[0].GetProperty("detour").GetString());
        Assert.All(
            root.GetProperty("route").GetProperty("rule_set").EnumerateArray(),
            item => Assert.Equal("proxy", item.GetProperty("download_detour").GetString()));
        var routeRules = root.GetProperty("route").GetProperty("rules").EnumerateArray().ToList();
        Assert.Contains(routeRules, rule =>
            rule.TryGetProperty("action", out var action)
            && action.GetString() == "resolve");
        var resolveIndex = routeRules.FindIndex(rule =>
            rule.TryGetProperty("action", out var action)
            && action.GetString() == "resolve");
        var chinaDirectIndex = routeRules.FindIndex(rule =>
            rule.TryGetProperty("rule_set", out var sets)
            && sets.EnumerateArray().Any(item => item.GetString() == "geoip-cn")
            && rule.GetProperty("outbound").GetString() == "direct");
        Assert.True(resolveIndex >= 0 && resolveIndex < chinaDirectIndex);
        Assert.Equal("proxy", root.GetProperty("route").GetProperty("final").GetString());
    }

    [Fact]
    public void NativeRestrictedKeepsLowPortsDirectAndScopesNativeTrafficToHighPorts()
    {
        const int forwardId = 158;
        const string direction = "Ultra Hong Kong to Mainland China";
        using var lease = new NativeOutboundLease(
            forwardId,
            "native-restricted",
            new string('d', 64),
            DateTimeOffset.UtcNow.AddMinutes(2),
            "tcp+udp",
            direction,
            "cn_only",
            NativeOutboundLease.ComputeScopeDigest(forwardId, direction, "tcp+udp", "cn_only"),
            "203.0.113.30",
            5438,
            System.Text.Encoding.UTF8.GetBytes("user"),
            System.Text.Encoding.UTF8.GetBytes("password"));

        using var document = JsonDocument.Parse(MacConnectionConfigBuilder.BuildNative(
            lease,
            MacConnectionMode.Vpn,
            []).Json);
        var rules = document.RootElement.GetProperty("route").GetProperty("rules").EnumerateArray().ToList();
        var lowPortIndex = rules.FindIndex(rule =>
            rule.TryGetProperty("port_range", out var range)
            && range.GetString() == "1:999"
            && rule.GetProperty("outbound").GetString() == "direct");
        var nativeIndex = rules.FindIndex(rule =>
            rule.TryGetProperty("rule_set", out var sets)
            && sets.EnumerateArray().Any(item => item.GetString() == "geoip-cn")
            && rule.GetProperty("outbound").GetString() == "proxy");

        Assert.True(lowPortIndex >= 0);
        Assert.True(nativeIndex > lowPortIndex);
        Assert.Equal(["tcp", "udp"], rules[lowPortIndex].GetProperty("network").EnumerateArray().Select(item => item.GetString() ?? "").ToArray());
        Assert.Equal("1000:65535", rules[nativeIndex].GetProperty("port_range").GetString());
        Assert.Equal(["tcp", "udp"], rules[nativeIndex].GetProperty("network").EnumerateArray().Select(item => item.GetString() ?? "").ToArray());
    }

    [Fact]
    public void NativeGlobalMainlandToInternationalKeepsDirectionBoundLeaseAndRestrictedPortSplit()
    {
        const int forwardId = 180;
        const string direction = "CAN -> HKG";
        const string endpoint = "203.0.113.180";
        const string scope = "global";
        const string transport = "tcp+udp";
        using var lease = new NativeOutboundLease(
            forwardId,
            "native-global",
            new string('g', 64),
            DateTimeOffset.UtcNow.AddMinutes(2),
            transport,
            direction,
            scope,
            NativeOutboundLease.ComputeScopeDigest(forwardId, direction, transport, scope),
            endpoint,
            5438,
            System.Text.Encoding.UTF8.GetBytes("user"),
            System.Text.Encoding.UTF8.GetBytes("password"));

        Assert.Equal(
            NativeOutboundLease.ComputeScopeDigest(forwardId, direction, transport, scope),
            lease.ScopeDigest);

        using var document = JsonDocument.Parse(MacConnectionConfigBuilder.BuildNative(
            lease,
            MacConnectionMode.Vpn,
            []).Json);
        var root = document.RootElement;
        var rules = root.GetProperty("route").GetProperty("rules").EnumerateArray().ToList();
        var lowPortIndex = rules.FindIndex(rule =>
            rule.TryGetProperty("port_range", out var range)
            && range.GetString() == "1:999"
            && rule.GetProperty("outbound").GetString() == "direct");

        Assert.True(lowPortIndex >= 0);
        Assert.Equal("proxy", root.GetProperty("route").GetProperty("final").GetString());
        Assert.Equal("local", root.GetProperty("route").GetProperty("default_domain_resolver").GetString());
        Assert.Contains(rules, rule =>
            rule.TryGetProperty("ip_cidr", out var cidrs)
            && cidrs.EnumerateArray().Any(item => item.GetString() == endpoint + "/32")
            && rule.GetProperty("outbound").GetString() == "direct");
        Assert.Equal("tcp+udp", lease.Transport);
        Assert.Equal("CAN -> HKG", lease.Direction);
    }

    [Fact]
    public void NativeUnrestrictedUsesAllPortsButKeepsProviderEndpointDirect()
    {
        const int forwardId = 159;
        const string direction = "Mainland China to International";
        const string endpoint = "203.0.113.31";
        using var lease = new NativeOutboundLease(
            forwardId,
            "native-unrestricted",
            new string('e', 64),
            DateTimeOffset.UtcNow.AddMinutes(2),
            "tcp+udp",
            direction,
            "intl_only",
            NativeOutboundLease.ComputeScopeDigestV2(forwardId, direction, "tcp+udp", "intl_only", "unrestricted"),
            endpoint,
            5438,
            System.Text.Encoding.UTF8.GetBytes("user"),
            System.Text.Encoding.UTF8.GetBytes("password"),
            routePolicy: "unrestricted");

        using var document = JsonDocument.Parse(MacConnectionConfigBuilder.BuildNative(
            lease,
            MacConnectionMode.Proxy,
            []).Json);
        var root = document.RootElement;
        var rules = root.GetProperty("route").GetProperty("rules").EnumerateArray().ToList();
        Assert.DoesNotContain(rules, rule =>
            rule.TryGetProperty("port_range", out var range) && range.GetString() == "1:999");
        var nativeIndex = rules.FindIndex(rule =>
            rule.TryGetProperty("rule_set", out var sets)
            && sets.EnumerateArray().Any(item => item.GetString() == "geosite-cn")
            && rule.GetProperty("outbound").GetString() == "direct");
        Assert.True(nativeIndex >= 0);
        Assert.Equal("1:65535", rules[nativeIndex].GetProperty("port_range").GetString());
        Assert.Contains(rules, rule =>
            rule.TryGetProperty("ip_cidr", out var cidrs)
            && cidrs.EnumerateArray().Any(item => item.GetString() == endpoint + "/32")
            && rule.GetProperty("outbound").GetString() == "direct");
    }

    [Fact]
    public async Task GeneratedProfilesPassTheBundledSingBoxCheckWhenRequested()
    {
        var singBox = Environment.GetEnvironmentVariable("LEIKWAN_SINGBOX_PATH");
        if (string.IsNullOrWhiteSpace(singBox) || !File.Exists(singBox))
            return;

        var profiles = new[]
        {
            new MacConnectionProfile
            {
                Protocol = MacConnectionProtocol.Shadowsocks,
                Server = "198.51.100.7",
                Port = 443,
                Method = "aes-256-gcm",
                Password = "check-only",
            },
            new MacConnectionProfile
            {
                Protocol = MacConnectionProtocol.WireGuard,
                Server = "198.51.100.9",
                Port = 51820,
                PrivateKey = "YNXtAzepDqRv9H52osJVDQnznT5AM11eCK3ESpwSt04=",
                LocalAddress = "10.8.0.2/32",
                PeerPublicKey = "Z1XXLsKYkYxuiYjJIkRvtIKFepCYHTgON+GwPq7SOV4=",
            },
        };

        foreach (var profile in profiles)
        {
            foreach (var mode in new[] { MacConnectionMode.Proxy, MacConnectionMode.Vpn })
            {
                var configPath = Path.Combine(Path.GetTempPath(), $"leikwan-check-{Guid.NewGuid():N}.json");
                try
                {
                    await File.WriteAllTextAsync(configPath, MacConnectionConfigBuilder.Build(profile, mode, []).Json);
                    var startInfo = new ProcessStartInfo
                    {
                        FileName = singBox,
                        UseShellExecute = false,
                        RedirectStandardOutput = true,
                        RedirectStandardError = true,
                    };
                    startInfo.ArgumentList.Add("check");
                    startInfo.ArgumentList.Add("-c");
                    startInfo.ArgumentList.Add(configPath);
                    using var process = Process.Start(startInfo) ?? throw new InvalidOperationException("sing_box_check_start_failed");
                    await process.WaitForExitAsync();
                    Assert.True(process.ExitCode == 0, $"sing-box rejected {profile.Protocol}/{mode}: {await process.StandardError.ReadToEndAsync()}");
                }
                finally
                {
                    try { File.Delete(configPath); } catch (IOException) { }
                }
            }
        }
    }

    [Fact]
    public async Task GeneratedNativeConfigsPassTheBundledSingBoxCheckWhenRequested()
    {
        var singBox = Environment.GetEnvironmentVariable("LEIKWAN_SINGBOX_PATH");
        if (string.IsNullOrWhiteSpace(singBox) || !File.Exists(singBox))
            return;

        foreach (var scope in new[] { "cn_only", "intl_only" })
        {
            using var lease = new NativeOutboundLease(
                scope == "cn_only" ? 158 : 159,
                "native-lease",
                new string('c', 64),
                DateTimeOffset.UtcNow.AddMinutes(2),
                "tcp+udp",
                scope == "cn_only" ? "Hong Kong -> China Mainland" : "Mainland China -> Hong Kong",
                scope,
                NativeOutboundLease.ComputeScopeDigest(
                    scope == "cn_only" ? 158 : 159,
                    scope == "cn_only" ? "Hong Kong -> China Mainland" : "Mainland China -> Hong Kong",
                    "tcp+udp",
                    scope),
                "203.0.113.22",
                5438,
                System.Text.Encoding.UTF8.GetBytes("user"),
                System.Text.Encoding.UTF8.GetBytes("password"));
            var configPath = Path.Combine(Path.GetTempPath(), $"leikwan-native-check-{Guid.NewGuid():N}.json");
            try
            {
                await File.WriteAllTextAsync(
                    configPath,
                    MacConnectionConfigBuilder.BuildNative(lease, MacConnectionMode.Vpn, []).Json);
                var startInfo = new ProcessStartInfo
                {
                    FileName = singBox,
                    UseShellExecute = false,
                    RedirectStandardOutput = true,
                    RedirectStandardError = true,
                };
                startInfo.ArgumentList.Add("check");
                startInfo.ArgumentList.Add("-c");
                startInfo.ArgumentList.Add(configPath);
                using var process = Process.Start(startInfo) ?? throw new InvalidOperationException("sing_box_check_start_failed");
                await process.WaitForExitAsync();
                Assert.True(process.ExitCode == 0, $"sing-box rejected native {scope}: {await process.StandardError.ReadToEndAsync()}");
            }
            finally
            {
                try { File.Delete(configPath); } catch (IOException) { }
            }
        }
    }
}
