using System.Text.Json;
using System.Diagnostics;
using LeikwanBridge.Core.Models;
using LeikwanBridge.Core.Services;
using Xunit;

namespace LeikwanBridge.Core.Tests.Runtime;

public sealed class WindowsProxyConfigBuilderTests
{
    [Fact]
    public void AgentConfigDefaultsToRemoteDnsAndPrefillsCustomServer()
    {
        var config = new AgentConfig();

        Assert.Equal("Remote", config.ProxyDnsMode);
        Assert.Equal("8.8.8.8", config.ProxyDnsServers);
        Assert.Equal(WindowsProxyDnsMode.Remote, config.GetEffectiveProxyDnsMode());
        Assert.Equal(["8.8.8.8"], config.GetEffectiveProxyDnsServers());
    }

    [Fact]
    public void HttpModeUsesLocalMixedInboundAndStructuredSs2022Outbound()
    {
        var profile = new WindowsProxyProfile
        {
            Name = "HKG",
            Protocol = WindowsProxyProtocol.Shadowsocks2022,
            Server = "198.51.100.7",
            Port = 30526,
            Method = "2022-blake3-aes-256-gcm",
            Password = "server-key=:client-secret=",
        };

        var result = WindowsProxyConfigBuilder.Build(
            profile,
            WindowsProxyMode.Http,
            ["panel.example.test", "198.51.100.8"]);

        using var document = JsonDocument.Parse(result.Json);
        var root = document.RootElement;
        var inbound = root.GetProperty("inbounds")[0];
        Assert.Equal("mixed", inbound.GetProperty("type").GetString());
        Assert.Equal("127.0.0.1", inbound.GetProperty("listen").GetString());
        Assert.Equal(2080, inbound.GetProperty("listen_port").GetInt32());

        var outbound = root.GetProperty("outbounds")[0];
        Assert.Equal("shadowsocks", outbound.GetProperty("type").GetString());
        Assert.Equal("2022-blake3-aes-256-gcm", outbound.GetProperty("method").GetString());
        Assert.Equal("server-key=:client-secret=", outbound.GetProperty("password").GetString());
        Assert.DoesNotContain("server-key=:client-secret=", WindowsProxyProcessHost.BuildStartInfo(
            "sing-box.exe", "C:\\Temp\\proxy.json", WindowsProxyMode.Http).Arguments);
    }

    [Fact]
    public void ProcessModeStartRequestsElevationOnWindows()
    {
        if (!OperatingSystem.IsWindows() || WindowsProxyElevation.IsElevated())
            return;

        var startInfo = WindowsProxyProcessHost.BuildStartInfo(
            "sing-box.exe", "C:\\Temp\\proxy.json", WindowsProxyMode.Process);

        Assert.True(startInfo.UseShellExecute);
        Assert.Equal("runas", startInfo.Verb);
        Assert.False(startInfo.RedirectStandardOutput);
        Assert.False(startInfo.RedirectStandardError);
    }

    [Fact]
    public void TunModeAddsConservativeMtuAndGatewayUdpRejectBeforePrivateRoute()
    {
        var profile = new WindowsProxyProfile
        {
            Protocol = WindowsProxyProtocol.Shadowsocks2022,
            Server = "198.51.100.7",
            Port = 30526,
            Method = "2022-blake3-aes-256-gcm",
            Password = "MDEyMzQ1Njc4OWFiY2RlZjAxMjM0NTY3ODlhYmNkZWY=",
        };

        var result = WindowsProxyConfigBuilder.Build(
            profile,
            WindowsProxyMode.Tun,
            [],
            tunMtu: 1280);

        using var document = JsonDocument.Parse(result.Json);
        var root = document.RootElement;
        var tun = root.GetProperty("inbounds")[0];
        Assert.Equal("tun", tun.GetProperty("type").GetString());
        Assert.Equal(1280, tun.GetProperty("mtu").GetInt32());
        Assert.True(tun.GetProperty("auto_route").GetBoolean());
        Assert.True(tun.GetProperty("strict_route").GetBoolean());
        Assert.Equal("gvisor", tun.GetProperty("stack").GetString());
        Assert.True(tun.GetProperty("endpoint_independent_nat").GetBoolean());

        var rules = root.GetProperty("route").GetProperty("rules").EnumerateArray().ToList();
        var rejectIndex = rules.FindIndex(rule =>
            rule.TryGetProperty("action", out var action) && action.GetString() == "reject");
        var privateIndex = rules.FindIndex(rule =>
            rule.TryGetProperty("ip_is_private", out var privateValue) && privateValue.GetBoolean());
        Assert.True(rejectIndex >= 0);
        Assert.True(privateIndex > rejectIndex);
        var reject = rules[rejectIndex];
        Assert.Contains(reject.GetProperty("network").EnumerateArray(), item => item.GetString() == "udp");
        Assert.Contains(reject.GetProperty("ip_cidr").EnumerateArray(), item => item.GetString() == "172.19.0.2/32");
        Assert.Equal("drop", reject.GetProperty("method").GetString());
        Assert.Equal("proxy", root.GetProperty("route").GetProperty("final").GetString());
        Assert.Contains(tun.GetProperty("route_exclude_address").EnumerateArray(),
            item => item.GetString() == "198.51.100.7/32");
        Assert.Contains(tun.GetProperty("route_exclude_address").EnumerateArray(),
            item => item.GetString() == "127.0.0.0/8");
    }

    [Fact]
    public void Ss2022PinsUdpOverTcpVersionAndNativeUdpForTun()
    {
        var profile = new WindowsProxyProfile
        {
            Protocol = WindowsProxyProtocol.Shadowsocks2022,
            Server = "198.51.100.7",
            Port = 30526,
            Method = "2022-blake3-aes-256-gcm",
            Password = "test-secret",
            UdpOverTcp = false,
        };

        using var document = JsonDocument.Parse(WindowsProxyConfigBuilder.Build(
            profile,
            WindowsProxyMode.Tun,
            []).Json);
        var udpOverTcp = document.RootElement
            .GetProperty("outbounds")[0]
            .GetProperty("udp_over_tcp");

        Assert.False(udpOverTcp.GetProperty("enabled").GetBoolean());
        Assert.Equal(2, udpOverTcp.GetProperty("version").GetInt32());
    }

    [Theory]
    [InlineData("none")]
    [InlineData("aes-128-ctr")]
    public void ClassicShadowsocksMethodsBuildNativeUdpProfiles(string method)
    {
        var profile = new WindowsProxyProfile
        {
            Protocol = WindowsProxyProtocol.Shadowsocks,
            Server = "198.51.100.7",
            Port = 443,
            Method = method,
            Password = "test-secret",
        };

        using var document = JsonDocument.Parse(WindowsProxyConfigBuilder.Build(
            profile,
            WindowsProxyMode.Tun,
            []).Json);
        var outbound = document.RootElement.GetProperty("outbounds")[0];

        Assert.Equal("shadowsocks", outbound.GetProperty("type").GetString());
        Assert.Equal(method, outbound.GetProperty("method").GetString());
        Assert.False(outbound.TryGetProperty("udp_over_tcp", out _));
    }

    [Fact]
    public void TunModeUsesRemoteDnsAndHijacksDnsPackets()
    {
        var profile = new WindowsProxyProfile
        {
            Protocol = WindowsProxyProtocol.Shadowsocks2022,
            Server = "198.51.100.7",
            Port = 30526,
            Method = "2022-blake3-aes-256-gcm",
            Password = "test-secret",
        };

        using var document = JsonDocument.Parse(WindowsProxyConfigBuilder.Build(
            profile,
            WindowsProxyMode.Tun,
            [],
            dnsMode: WindowsProxyDnsMode.Remote).Json);
        var root = document.RootElement;
        var dns = root.GetProperty("dns");
        Assert.Equal("remote", dns.GetProperty("final").GetString());
        var remote = dns.GetProperty("servers").EnumerateArray()
            .Single(server => server.GetProperty("tag").GetString() == "remote");
        Assert.Equal("https", remote.GetProperty("type").GetString());
        Assert.Equal("proxy", remote.GetProperty("detour").GetString());
        Assert.Equal(443, remote.GetProperty("server_port").GetInt32());
        Assert.True(remote.GetProperty("tls").GetProperty("enabled").GetBoolean());
        Assert.Equal("cloudflare-dns.com", remote.GetProperty("tls").GetProperty("server_name").GetString());

        var hijack = root.GetProperty("route").GetProperty("rules").EnumerateArray()
            .Single(rule => rule.TryGetProperty("action", out var action)
                && action.GetString() == "hijack-dns");
        Assert.Equal("dns", hijack.GetProperty("protocol").GetString());
    }

    [Fact]
    public void CustomDnsUsesConfiguredTcpServersThroughProxy()
    {
        var profile = new WindowsProxyProfile
        {
            Protocol = WindowsProxyProtocol.Shadowsocks,
            Server = "198.51.100.7",
            Port = 443,
            Method = "aes-256-gcm",
            Password = "test-secret",
        };

        using var document = JsonDocument.Parse(WindowsProxyConfigBuilder.Build(
            profile,
            WindowsProxyMode.Tun,
            [],
            dnsMode: WindowsProxyDnsMode.Custom,
            customDnsServers: ["8.8.8.8", "223.5.5.5"]).Json);
        var dns = document.RootElement.GetProperty("dns");
        Assert.Equal("custom-0", dns.GetProperty("final").GetString());
        var servers = dns.GetProperty("servers").EnumerateArray().ToList();
        var custom = servers.Where(server => server.GetProperty("tag").GetString()?.StartsWith("custom-", StringComparison.Ordinal) == true).ToList();
        Assert.Equal(2, custom.Count);
        Assert.All(custom, server =>
        {
            Assert.Equal("tcp", server.GetProperty("type").GetString());
            Assert.Equal(53, server.GetProperty("server_port").GetInt32());
            Assert.Equal("proxy", server.GetProperty("detour").GetString());
        });
        Assert.Contains(custom, server => server.GetProperty("server").GetString() == "8.8.8.8");
        Assert.Contains(custom, server => server.GetProperty("server").GetString() == "223.5.5.5");
    }

    [Fact]
    public void CustomDnsRejectsInvalidServerAddress()
    {
        var profile = new WindowsProxyProfile
        {
            Protocol = WindowsProxyProtocol.Shadowsocks,
            Server = "198.51.100.7",
            Port = 443,
            Method = "aes-256-gcm",
            Password = "test-secret",
        };

        var error = Assert.Throws<InvalidOperationException>(() => WindowsProxyConfigBuilder.Build(
            profile,
            WindowsProxyMode.Tun,
            [],
            dnsMode: WindowsProxyDnsMode.Custom,
            customDnsServers: ["not-a-dns-server"]));
        Assert.Equal("proxy_dns_server_invalid", error.Message);
    }

    [Fact]
    public void VlessBuilderUsesSelectorValuesAndRealityTls()
    {
        var profile = new WindowsProxyProfile
        {
            Protocol = WindowsProxyProtocol.Vless,
            Server = "vless.example.test",
            Port = 443,
            Uuid = "00000000-0000-0000-0000-000000000001",
            Transport = "grpc",
            ServiceName = "edge",
            Tls = true,
            ServerName = "cdn.example.test",
            RealityPublicKey = "public-key",
            RealityShortId = "abcd",
        };

        using var document = JsonDocument.Parse(WindowsProxyConfigBuilder.Build(
            profile, WindowsProxyMode.Process, []).Json);
        var outbound = document.RootElement.GetProperty("outbounds")[0];
        Assert.Equal("vless", outbound.GetProperty("type").GetString());
        Assert.Equal("grpc", outbound.GetProperty("transport").GetProperty("type").GetString());
        Assert.Equal("edge", outbound.GetProperty("transport").GetProperty("service_name").GetString());
        Assert.True(outbound.GetProperty("tls").GetProperty("reality").GetProperty("enabled").GetBoolean());
        Assert.Equal("public-key", outbound.GetProperty("tls").GetProperty("reality").GetProperty("public_key").GetString());
    }

    [Fact]
    public async Task ChinaMainlandBypassAddsCnConfCidrsBeforeFinalProxy()
    {
        var listPath = Path.Combine(Path.GetTempPath(), $"leikwan-cn-{Guid.NewGuid():N}.conf");
        await File.WriteAllTextAsync(listPath, "# CN cidr address-list\nroute 1.0.1.0/24 via \"lo\";\nroute 240.0.0.0/4 via \"lo\";\n");
        try
        {
            var profile = new WindowsProxyProfile
            {
                Protocol = WindowsProxyProtocol.Shadowsocks2022,
                Server = "198.51.100.7",
                Port = 30526,
                Method = "2022-blake3-aes-256-gcm",
                Password = "test-secret",
            };

            using var document = JsonDocument.Parse(WindowsProxyConfigBuilder.Build(
                profile,
                WindowsProxyMode.Tun,
                [],
                bypassChinaMainland: true,
                chinaIpListPath: listPath).Json);
            var route = document.RootElement.GetProperty("route");

            var rules = route.GetProperty("rules").EnumerateArray().ToList();
            var chinaIndex = rules.FindIndex(rule =>
                rule.TryGetProperty("ip_cidr", out var set)
                && set.EnumerateArray().Any(item => item.GetString() == "1.0.1.0/24"));
            var finalProxyIndex = rules.Count;
            Assert.True(chinaIndex >= 0 && chinaIndex < finalProxyIndex);
            Assert.Equal("direct", rules[chinaIndex].GetProperty("outbound").GetString());
            Assert.Contains(rules[chinaIndex].GetProperty("ip_cidr").EnumerateArray(), item => item.GetString() == "240.0.0.0/4");
        }
        finally
        {
            try { File.Delete(listPath); } catch (IOException) { }
        }
    }

    [Fact]
    public void ChinaMainlandBypassRequiresCnConf()
    {
        var profile = new WindowsProxyProfile
        {
            Protocol = WindowsProxyProtocol.Shadowsocks,
            Server = "198.51.100.7",
            Port = 443,
            Method = "aes-256-gcm",
            Password = "test-secret",
        };

        var error = Assert.Throws<InvalidOperationException>(() => WindowsProxyConfigBuilder.Build(
            profile,
            WindowsProxyMode.Http,
            [],
            bypassChinaMainland: true,
            chinaIpListPath: Path.Combine(Path.GetTempPath(), Guid.NewGuid() + ".conf")));
        Assert.Equal("proxy_china_ip_list_missing", error.Message);
    }

    [Fact]
    public async Task GeneratedFirstReleaseProfilesPassSingBoxCheckWhenRequested()
    {
        var singBox = Environment.GetEnvironmentVariable("LEIKWAN_SINGBOX_PATH");
        if (string.IsNullOrWhiteSpace(singBox) || !File.Exists(singBox))
            return;

        var profiles = new[]
        {
            new WindowsProxyProfile
            {
                Protocol = WindowsProxyProtocol.Shadowsocks,
                Server = "198.51.100.7",
                Port = 443,
                Method = "aes-256-gcm",
                Password = "check-only",
            },
            new WindowsProxyProfile
            {
                Protocol = WindowsProxyProtocol.Shadowsocks2022,
                Server = "198.51.100.8",
                Port = 443,
                Method = "2022-blake3-aes-256-gcm",
                Password = "MDEyMzQ1Njc4OWFiY2RlZjAxMjM0NTY3ODlhYmNkZWY=",
            },
            new WindowsProxyProfile
            {
                Protocol = WindowsProxyProtocol.Vless,
                Server = "198.51.100.9",
                Port = 443,
                Uuid = "00000000-0000-0000-0000-000000000001",
                Transport = "ws",
                Path = "/edge",
                Tls = true,
                ServerName = "edge.example.test",
            },
        };

        foreach (var profile in profiles)
        foreach (var mode in Enum.GetValues<WindowsProxyMode>())
        {
            var configPath = Path.Combine(Path.GetTempPath(), $"leikwan-windows-proxy-check-{Guid.NewGuid():N}.json");
            try
            {
                await File.WriteAllTextAsync(configPath, WindowsProxyConfigBuilder.Build(profile, mode, []).Json);
                var info = new ProcessStartInfo
                {
                    FileName = singBox,
                    UseShellExecute = false,
                    RedirectStandardOutput = true,
                    RedirectStandardError = true,
                };
                info.ArgumentList.Add("check");
                info.ArgumentList.Add("-c");
                info.ArgumentList.Add(configPath);
                using var process = Process.Start(info) ?? throw new InvalidOperationException("sing_box_check_start_failed");
                await process.WaitForExitAsync();
                var error = await process.StandardError.ReadToEndAsync();
                Assert.True(process.ExitCode == 0, $"sing-box rejected {profile.Protocol}/{mode}: {error}");
            }
            finally
            {
                try { File.Delete(configPath); } catch (IOException) { }
            }
        }
    }

    [Fact]
    public async Task EveryAdvertisedClassicShadowsocksMethodPassesSingBoxCheckWhenRequested()
    {
        var singBox = Environment.GetEnvironmentVariable("LEIKWAN_SINGBOX_PATH");
        if (string.IsNullOrWhiteSpace(singBox) || !File.Exists(singBox))
            return;

        foreach (var method in WindowsProxyProtocol.ShadowsocksMethodOptions.Where(
            method => !WindowsProxyProtocol.IsShadowsocks2022Method(method)))
        {
            var profile = new WindowsProxyProfile
            {
                Protocol = WindowsProxyProtocol.Shadowsocks,
                Server = "198.51.100.7",
                Port = 443,
                Method = method,
                Password = method.Equals("none", StringComparison.OrdinalIgnoreCase)
                    ? ""
                    : "check-only",
            };
            var configPath = Path.Combine(Path.GetTempPath(), $"leikwan-classic-check-{Guid.NewGuid():N}.json");
            try
            {
                await File.WriteAllTextAsync(configPath, WindowsProxyConfigBuilder.Build(
                    profile, WindowsProxyMode.Tun, []).Json);
                var info = new ProcessStartInfo
                {
                    FileName = singBox,
                    UseShellExecute = false,
                    RedirectStandardError = true,
                };
                info.ArgumentList.Add("check");
                info.ArgumentList.Add("-c");
                info.ArgumentList.Add(configPath);
                using var process = Process.Start(info) ?? throw new InvalidOperationException("sing_box_check_start_failed");
                await process.WaitForExitAsync();
                var error = await process.StandardError.ReadToEndAsync();
                Assert.True(process.ExitCode == 0, $"sing-box rejected classic method {method}: {error}");
            }
            finally
            {
                try { File.Delete(configPath); } catch (IOException) { }
            }
        }
    }

    [Fact]
    public async Task TunStackVariantsPassSingBoxCheckWhenRequested()
    {
        var singBox = Environment.GetEnvironmentVariable("LEIKWAN_SINGBOX_PATH");
        if (string.IsNullOrWhiteSpace(singBox) || !File.Exists(singBox))
            return;

        var profile = new WindowsProxyProfile
        {
            Protocol = WindowsProxyProtocol.Shadowsocks2022,
            Server = "198.51.100.7",
            Port = 30526,
            Method = "2022-blake3-aes-256-gcm",
            Password = "MDEyMzQ1Njc4OWFiY2RlZjAxMjM0NTY3ODlhYmNkZWY=",
        };
        foreach (var stack in new[] { "mixed", "gvisor", "system" })
        {
            var configPath = Path.Combine(Path.GetTempPath(), $"leikwan-tun-stack-{Guid.NewGuid():N}.json");
            try
            {
                await File.WriteAllTextAsync(configPath, WindowsProxyConfigBuilder.Build(
                    profile, WindowsProxyMode.Tun, [], tunStack: stack).Json);
                var info = new ProcessStartInfo
                {
                    FileName = singBox,
                    UseShellExecute = false,
                    RedirectStandardError = true,
                };
                info.ArgumentList.Add("check");
                info.ArgumentList.Add("-c");
                info.ArgumentList.Add(configPath);
                using var process = Process.Start(info) ?? throw new InvalidOperationException("sing_box_check_start_failed");
                await process.WaitForExitAsync();
                var error = await process.StandardError.ReadToEndAsync();
                Assert.True(process.ExitCode == 0, $"sing-box rejected tun stack {stack}: {error}");
            }
            finally
            {
                try { File.Delete(configPath); } catch (IOException) { }
            }
        }
    }

    [Fact]
    public async Task CustomDnsConfigPassesSingBoxCheckWhenRequested()
    {
        var singBox = Environment.GetEnvironmentVariable("LEIKWAN_SINGBOX_PATH");
        if (string.IsNullOrWhiteSpace(singBox) || !File.Exists(singBox))
            return;

        var profile = new WindowsProxyProfile
        {
            Protocol = WindowsProxyProtocol.Shadowsocks2022,
            Server = "198.51.100.7",
            Port = 30526,
            Method = "2022-blake3-aes-256-gcm",
            Password = "MDEyMzQ1Njc4OWFiY2RlZjAxMjM0NTY3ODlhYmNkZWY=",
        };
        var configPath = Path.Combine(Path.GetTempPath(), $"leikwan-custom-dns-check-{Guid.NewGuid():N}.json");
        try
        {
            await File.WriteAllTextAsync(configPath, WindowsProxyConfigBuilder.Build(
                profile,
                WindowsProxyMode.Tun,
                [],
                dnsMode: WindowsProxyDnsMode.Custom,
                customDnsServers: ["8.8.8.8", "223.5.5.5"]).Json);
            var info = new ProcessStartInfo
            {
                FileName = singBox,
                UseShellExecute = false,
                RedirectStandardError = true,
            };
            info.ArgumentList.Add("check");
            info.ArgumentList.Add("-c");
            info.ArgumentList.Add(configPath);
            using var process = Process.Start(info) ?? throw new InvalidOperationException("sing_box_check_start_failed");
            await process.WaitForExitAsync();
            var error = await process.StandardError.ReadToEndAsync();
            Assert.True(process.ExitCode == 0, error);
        }
        finally
        {
            try { File.Delete(configPath); } catch (IOException) { }
        }
    }

    [Fact]
    public async Task ChinaMainlandCnConfConfigPassesSingBoxCheckWhenRequested()
    {
        var singBox = Environment.GetEnvironmentVariable("LEIKWAN_SINGBOX_PATH");
        var listPath = Environment.GetEnvironmentVariable("LEIKWAN_CHINA_LIST_PATH");
        if (string.IsNullOrWhiteSpace(singBox) || !File.Exists(singBox)
            || string.IsNullOrWhiteSpace(listPath) || !File.Exists(listPath))
            return;

        var profile = new WindowsProxyProfile
        {
            Protocol = WindowsProxyProtocol.Shadowsocks2022,
            Server = "198.51.100.7",
            Port = 30526,
            Method = "2022-blake3-aes-256-gcm",
            Password = "MDEyMzQ1Njc4OWFiY2RlZjAxMjM0NTY3ODlhYmNkZWY=",
        };
        var configPath = Path.Combine(Path.GetTempPath(), $"leikwan-china-rule-set-check-{Guid.NewGuid():N}.json");
        try
        {
            await File.WriteAllTextAsync(configPath, WindowsProxyConfigBuilder.Build(
                profile,
                WindowsProxyMode.Tun,
                [],
                bypassChinaMainland: true,
                chinaIpListPath: listPath).Json);
            var info = new ProcessStartInfo
            {
                FileName = singBox,
                UseShellExecute = false,
                RedirectStandardError = true,
            };
            info.ArgumentList.Add("check");
            info.ArgumentList.Add("-c");
            info.ArgumentList.Add(configPath);
            using var process = Process.Start(info) ?? throw new InvalidOperationException("sing_box_check_start_failed");
            await process.WaitForExitAsync();
            var error = await process.StandardError.ReadToEndAsync();
            Assert.True(process.ExitCode == 0, error);
        }
        finally
        {
            try { File.Delete(configPath); } catch (IOException) { }
        }
    }
}
