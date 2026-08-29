using System.Diagnostics;
using System.Net;
using System.Net.Sockets;
using LeikwanBridge.Core.Models;
using LeikwanBridge.Core.Services;
using Xunit;

namespace LeikwanBridge.Core.Tests.Runtime;

public sealed class WindowsProxyLiveUdpTests
{
    [Fact]
    public async Task OptInSs2022NodeCarriesDnsUdpThroughLocalMixedInbound()
    {
        var uri = Environment.GetEnvironmentVariable("LEIKWAN_TEST_SS_URI");
        var singBox = Environment.GetEnvironmentVariable("LEIKWAN_SINGBOX_PATH");
        if (string.IsNullOrWhiteSpace(uri) || string.IsNullOrWhiteSpace(singBox) || !File.Exists(singBox))
            return;

        var profile = WindowsProxyUriParser.Parse(uri);
        Assert.Equal(WindowsProxyProtocol.Shadowsocks2022, profile.Protocol);

        var port = GetFreePort();
        var configPath = Path.Combine(Path.GetTempPath(), $"leikwan-live-udp-{Guid.NewGuid():N}.json");
        Process? process = null;
        try
        {
            await File.WriteAllTextAsync(configPath, WindowsProxyConfigBuilder.Build(
                profile,
                WindowsProxyMode.Http,
                [],
                proxyPort: port).Json);
            var info = WindowsProxyProcessHost.BuildStartInfo(singBox, configPath, WindowsProxyMode.Http);
            info.RedirectStandardOutput = true;
            info.RedirectStandardError = true;
            process = Process.Start(info) ?? throw new InvalidOperationException("sing_box_start_failed");
            await WaitForPortAsync(port, process);

            using var timeout = new CancellationTokenSource(TimeSpan.FromSeconds(15));
            var result = await WindowsSocks5UdpProbe.QueryDnsAsync(
                "127.0.0.1",
                port,
                Environment.GetEnvironmentVariable("LEIKWAN_TEST_UDP_HOST") ?? "1.1.1.1",
                53,
                timeout.Token);
            Assert.True(result.Succeeded, result.ErrorCode);
            Assert.True(result.ResponseBytes >= 12);
        }
        finally
        {
            try
            {
                if (process is { HasExited: false })
                    process.Kill(entireProcessTree: true);
                if (process is not null)
                    await process.WaitForExitAsync().WaitAsync(TimeSpan.FromSeconds(3));
            }
            catch (Exception) { }
            process?.Dispose();
            try { File.Delete(configPath); } catch (IOException) { }
        }
    }

    private static int GetFreePort()
    {
        using var listener = new TcpListener(IPAddress.Loopback, 0);
        listener.Start();
        return ((IPEndPoint)listener.LocalEndpoint).Port;
    }

    private static async Task WaitForPortAsync(int port, Process process)
    {
        var deadline = DateTime.UtcNow.AddSeconds(5);
        while (DateTime.UtcNow < deadline)
        {
            if (process.HasExited)
            {
                var error = await process.StandardError.ReadToEndAsync();
                throw new InvalidOperationException($"sing_box_exited: {error}");
            }
            try
            {
                using var probe = new TcpClient();
                await probe.ConnectAsync("127.0.0.1", port);
                return;
            }
            catch (SocketException)
            {
                await Task.Delay(100);
            }
        }
        throw new TimeoutException("sing_box_listener_timeout");
    }
}
