using System.Diagnostics;
using System.Net;
using System.Net.Sockets;
using System.Text.Json;
using LeikwanBridge.Core.Models;
using LeikwanBridge.Core.Services;
using Xunit;

namespace LeikwanBridge.Core.Tests.Runtime;

/// <summary>
/// geoip-cn / geosite-cn ship next to sing-box so a native start never waits
/// on raw.githubusercontent.com (sing-box 1.13 keeps its inbounds closed until
/// a remote rule-set's first download completes).
/// </summary>
public sealed class NativeChinaRuleSetTests
{
    private static readonly byte[] FakeRuleSet = [(byte)'S', (byte)'R', (byte)'S', 1, 0x78, 0xda];

    [Theory]
    [InlineData("global", true, WindowsProxyMode.Http)]
    [InlineData("global", true, WindowsProxyMode.Tun)]
    [InlineData("cn_only", false, WindowsProxyMode.Http)]
    [InlineData("intl_only", false, WindowsProxyMode.Tun)]
    public void BuildNativeUsesBundledLocalRuleSetsWhenPresent(
        string scope,
        bool bypassChina,
        WindowsProxyMode mode)
    {
        using var temp = new TempStateDirectory();
        WriteRuleSets(temp.Path);
        using var lease = CreateNativeLease(scope);

        using var document = JsonDocument.Parse(WindowsProxyConfigBuilder.BuildNative(
            lease,
            mode,
            [],
            bypassChinaMainland: bypassChina,
            chinaIpListPath: WriteChinaList(temp.Path),
            ruleSetDirectory: temp.Path).Json);

        var sets = document.RootElement.GetProperty("route").GetProperty("rule_set").EnumerateArray().ToList();
        Assert.Equal(["geoip-cn", "geosite-cn"], sets.Select(item => item.GetProperty("tag").GetString()));
        foreach (var set in sets)
        {
            Assert.Equal("local", set.GetProperty("type").GetString());
            Assert.Equal("binary", set.GetProperty("format").GetString());
            Assert.False(set.TryGetProperty("url", out _));
            Assert.False(set.TryGetProperty("download_detour", out _));
            var path = set.GetProperty("path").GetString()!;
            Assert.True(Path.IsPathFullyQualified(path), path);
            Assert.True(File.Exists(path), path);
        }
        Assert.Equal(Path.Combine(temp.Path, "geoip-cn.srs"), sets[0].GetProperty("path").GetString());
        Assert.Equal(Path.Combine(temp.Path, "geosite-cn.srs"), sets[1].GetProperty("path").GetString());
        var json = document.RootElement.GetRawText();
        Assert.DoesNotContain("raw.githubusercontent.com", json, StringComparison.Ordinal);
        Assert.DoesNotContain("\"remote\",\"format\"", json, StringComparison.Ordinal);
    }

    [Theory]
    [InlineData("global", true, "proxy")]
    [InlineData("cn_only", false, "direct")]
    [InlineData("intl_only", false, "direct")]
    public void BuildNativeFallsBackToRemoteRuleSetsWithoutBundledFiles(
        string scope,
        bool bypassChina,
        string detour)
    {
        using var temp = new TempStateDirectory();
        using var lease = CreateNativeLease(scope);

        using var document = JsonDocument.Parse(WindowsProxyConfigBuilder.BuildNative(
            lease,
            WindowsProxyMode.Http,
            [],
            bypassChinaMainland: bypassChina,
            chinaIpListPath: WriteChinaList(temp.Path),
            ruleSetDirectory: temp.Path).Json);

        var sets = document.RootElement.GetProperty("route").GetProperty("rule_set").EnumerateArray().ToList();
        Assert.Equal(2, sets.Count);
        Assert.All(sets, set =>
        {
            Assert.Equal("remote", set.GetProperty("type").GetString());
            Assert.StartsWith("https://raw.githubusercontent.com/SagerNet/", set.GetProperty("url").GetString());
            Assert.Equal(detour, set.GetProperty("download_detour").GetString());
            Assert.False(set.TryGetProperty("path", out _));
        });
    }

    [Fact]
    public void RestrictedScopeBypassesGitHubOnlyForTheRemoteFallback()
    {
        using var lease = CreateNativeLease("intl_only");
        using var withoutFiles = JsonDocument.Parse(WindowsProxyConfigBuilder.BuildNative(
            lease, WindowsProxyMode.Tun, []).Json);
        Assert.Contains("raw.githubusercontent.com", withoutFiles.RootElement.GetRawText(), StringComparison.Ordinal);

        using var temp = new TempStateDirectory();
        WriteRuleSets(temp.Path);
        var compiled = WindowsProxyConfigBuilder.BuildNative(
            lease, WindowsProxyMode.Tun, [], ruleSetDirectory: temp.Path);
        Assert.DoesNotContain("raw.githubusercontent.com", compiled.Json, StringComparison.Ordinal);
        Assert.DoesNotContain("raw.githubusercontent.com", compiled.BypassDomains);
    }

    [Fact]
    public void IncompleteOrForeignBundleFallsBackToRemote()
    {
        using var temp = new TempStateDirectory();
        File.WriteAllBytes(Path.Combine(temp.Path, NativeChinaRuleSets.GeoIpFileName), FakeRuleSet);
        Assert.Null(NativeChinaRuleSets.TryResolve(temp.Path));

        File.WriteAllText(Path.Combine(temp.Path, NativeChinaRuleSets.GeoSiteFileName), "<html>404</html>");
        Assert.Null(NativeChinaRuleSets.TryResolve(temp.Path));

        File.WriteAllBytes(Path.Combine(temp.Path, NativeChinaRuleSets.GeoSiteFileName), [(byte)'S', (byte)'R']);
        Assert.Null(NativeChinaRuleSets.TryResolve(temp.Path));

        File.WriteAllBytes(Path.Combine(temp.Path, NativeChinaRuleSets.GeoSiteFileName), FakeRuleSet);
        Assert.NotNull(NativeChinaRuleSets.TryResolve(temp.Path));
        Assert.Null(NativeChinaRuleSets.TryResolve(null));
        Assert.Null(NativeChinaRuleSets.TryResolve(Path.Combine(temp.Path, "missing")));
    }

    [Fact]
    public void GlobalWithoutBypassDeclaresNoRuleSets()
    {
        using var temp = new TempStateDirectory();
        WriteRuleSets(temp.Path);
        using var lease = CreateNativeLease("global");
        using var document = JsonDocument.Parse(WindowsProxyConfigBuilder.BuildNative(
            lease, WindowsProxyMode.Http, [], ruleSetDirectory: temp.Path).Json);
        Assert.False(document.RootElement.GetProperty("route").TryGetProperty("rule_set", out _));
    }

    [Fact]
    public void NativeOutboundFragmentPrefersBundledRuleSets()
    {
        using var temp = new TempStateDirectory();
        using var lease = CreateNativeLease("cn_only");
        using var remote = JsonDocument.Parse(
            NativeOutboundConfigBuilder.Build(lease, bypassChina: true, localPort: 2080).Json);
        Assert.All(remote.RootElement.GetProperty("route").GetProperty("rule_set").EnumerateArray(), set =>
            Assert.Equal("remote", set.GetProperty("type").GetString()));

        WriteRuleSets(temp.Path);
        using var local = JsonDocument.Parse(NativeOutboundConfigBuilder.Build(
            lease, bypassChina: true, localPort: 2080, ruleSetDirectory: temp.Path).Json);
        Assert.All(local.RootElement.GetProperty("route").GetProperty("rule_set").EnumerateArray(), set =>
        {
            Assert.Equal("local", set.GetProperty("type").GetString());
            Assert.StartsWith(temp.Path, set.GetProperty("path").GetString());
        });
    }

    [Fact]
    public void AgentConfigLocatesRuleSetsInTheBinDirectoryNextToChinaList()
    {
        var config = new AgentConfig();
        Assert.Equal(
            Path.GetDirectoryName(config.GetEffectiveProxyChinaIpList()),
            config.GetEffectiveProxyRuleSetDirectory());
    }

    /// <summary>
    /// Real-runtime check, enabled by LEIKWAN_SINGBOX_PATH: the bundled
    /// rule-sets from the repository root (next to CN.conf) produce a native
    /// HTTP / global / bypass-China config that sing-box accepts, and the mixed
    /// inbound starts listening without any network path to GitHub.
    /// </summary>
    [Fact]
    public async Task BundledRuleSetsPassSingBoxCheckAndOpenInboundImmediately()
    {
        var singBox = Environment.GetEnvironmentVariable("LEIKWAN_SINGBOX_PATH");
        if (string.IsNullOrWhiteSpace(singBox) || !File.Exists(singBox))
            return;
        var repoRoot = FindRepoRoot();
        Assert.NotNull(NativeChinaRuleSets.TryResolve(repoRoot));

        using var temp = new TempStateDirectory();
        using var lease = CreateNativeLease("global");
        var port = FreeLoopbackPort();
        var configPath = Path.Combine(temp.Path, "native-check.json");
        await File.WriteAllTextAsync(configPath, WindowsProxyConfigBuilder.BuildNative(
            lease,
            WindowsProxyMode.Http,
            [],
            proxyPort: port,
            bypassChinaMainland: true,
            chinaIpListPath: Path.Combine(repoRoot, "CN.conf"),
            nativeProxyHost: "127.0.0.1",
            nativeProxyPort: FreeLoopbackPort(),
            ruleSetDirectory: repoRoot).Json);

        var (exitCode, output) = await RunAsync(singBox, "check", "-c", configPath);
        Assert.True(exitCode == 0, output);

        var info = new ProcessStartInfo { FileName = singBox, UseShellExecute = false, RedirectStandardError = true, RedirectStandardOutput = true };
        foreach (var argument in new[] { "run", "-c", configPath, "-D", temp.Path })
            info.ArgumentList.Add(argument);
        using var running = Process.Start(info) ?? throw new InvalidOperationException("sing_box_start_failed");
        try
        {
            var listening = false;
            var deadline = DateTime.UtcNow.AddSeconds(2);
            while (!listening && DateTime.UtcNow < deadline && !running.HasExited)
            {
                try
                {
                    using var client = new TcpClient();
                    await client.ConnectAsync(IPAddress.Loopback, port);
                    listening = true;
                }
                catch (SocketException)
                {
                    await Task.Delay(50);
                }
            }
            Assert.True(listening, "mixed inbound did not listen within 2s");
        }
        finally
        {
            try { running.Kill(entireProcessTree: true); } catch (InvalidOperationException) { }
            await running.WaitForExitAsync();
        }
    }

    private static void WriteRuleSets(string directory)
    {
        File.WriteAllBytes(Path.Combine(directory, NativeChinaRuleSets.GeoIpFileName), FakeRuleSet);
        File.WriteAllBytes(Path.Combine(directory, NativeChinaRuleSets.GeoSiteFileName), FakeRuleSet);
    }

    private static string WriteChinaList(string directory)
    {
        var path = Path.Combine(directory, "CN.conf");
        File.WriteAllText(path, "route 1.0.1.0/24 via \"lo\";\n");
        return path;
    }

    private static string FindRepoRoot()
    {
        for (var dir = new DirectoryInfo(AppContext.BaseDirectory); dir is not null; dir = dir.Parent)
        {
            if (File.Exists(Path.Combine(dir.FullName, "CN.conf"))
                && File.Exists(Path.Combine(dir.FullName, NativeChinaRuleSets.GeoIpFileName)))
                return dir.FullName;
        }
        throw new InvalidOperationException("repo_root_not_found");
    }

    private static int FreeLoopbackPort()
    {
        using var listener = new TcpListener(IPAddress.Loopback, 0);
        listener.Start();
        return ((IPEndPoint)listener.LocalEndpoint).Port;
    }

    private static async Task<(int ExitCode, string Output)> RunAsync(string fileName, params string[] arguments)
    {
        var info = new ProcessStartInfo { FileName = fileName, UseShellExecute = false, RedirectStandardError = true, RedirectStandardOutput = true };
        foreach (var argument in arguments)
            info.ArgumentList.Add(argument);
        using var process = Process.Start(info) ?? throw new InvalidOperationException("process_start_failed");
        var stdout = process.StandardOutput.ReadToEndAsync();
        var stderr = process.StandardError.ReadToEndAsync();
        await process.WaitForExitAsync();
        return (process.ExitCode, await stdout + "\n" + await stderr);
    }

    private static NativeOutboundLease CreateNativeLease(string routeScope)
    {
        const int forwardId = 158;
        var direction = routeScope == "intl_only"
            ? "Mainland China -> International"
            : "Ultra Hong Kong to Mainland China";
        return new NativeOutboundLease(
            forwardId,
            "native-lease",
            new string('a', 64),
            DateTimeOffset.UtcNow.AddMinutes(2),
            "tcp+udp",
            direction,
            routeScope,
            NativeOutboundLease.ComputeScopeDigest(forwardId, direction, "tcp+udp", routeScope),
            "203.0.113.20",
            5438,
            System.Text.Encoding.UTF8.GetBytes("native-user"),
            System.Text.Encoding.UTF8.GetBytes("native-password"));
    }
}
