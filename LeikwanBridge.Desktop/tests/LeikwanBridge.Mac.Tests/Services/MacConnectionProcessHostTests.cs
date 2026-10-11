using System.Diagnostics;
using System.Net;
using System.Net.Sockets;
using LeikwanBridge.Mac.Services;
using LeikwanBridge.Mac.Tests.Platform;
using Xunit;

namespace LeikwanBridge.Mac.Tests.Services;

public sealed class MacConnectionProcessHostTests
{
    [Fact]
    public async Task ProxyCleanupOnlyDisablesTheExactOwnedLoopbackPort()
    {
        var writes = new List<string>();
        var runner = new FakeCommandRunner(info =>
        {
            var command = info.ArgumentList[0];
            if (command == "-listallnetworkservices") return new MacCommandResult(0, "header\nEthernet\nOther VPN\n", "");
            if (command.StartsWith("-set"))
            {
                writes.Add(string.Join(" ", info.ArgumentList));
                return new MacCommandResult(0, "", "");
            }
            var port = info.ArgumentList[1] == "Ethernet" && command == "-getwebproxy" ? 2080 : 7890;
            return new MacCommandResult(0, $"Enabled: Yes\nServer: 127.0.0.1\nPort: {port}\n", "");
        });
        await MacConnectionProcessHost.ClearOwnedSystemProxyAsync(2080, runner);
        Assert.Equal(new[] { "-setwebproxystate Ethernet off" }, writes);
    }

    [Fact]
    public async Task StartProxy_ClearsStaleOwnedSystemProxyBeforeStarting()
    {
        using var temp = new TemporaryDirectory();
        var config = Path.Combine(temp.Path, "connection.json");
        const int port = 25842;
        await File.WriteAllTextAsync(config, $"{{\"inbounds\":[{{\"listen_port\":{port},\"set_system_proxy\":true}}]}}");
        var writes = new List<string>();
        var runner = new FakeCommandRunner(info =>
        {
            var command = info.ArgumentList[0];
            if (command == "-listallnetworkservices") return new MacCommandResult(0, "header\nEthernet\n", "");
            if (command.StartsWith("-set"))
            {
                writes.Add(string.Join(" ", info.ArgumentList));
                return new MacCommandResult(0, "", "");
            }
            return new MacCommandResult(0, $"Enabled: Yes\nServer: 127.0.0.1\nPort: {port}\n", "");
        });
        using var listener = new TcpListener(IPAddress.Loopback, port);
        await using var host = new MacConnectionProcessHost(
            processStarter: startInfo =>
            {
                var process = StartSleeper();
                _ = startInfo;
                Task.Run(async () =>
                {
                    await Task.Delay(100);
                    listener.Start();
                });
                return process;
            },
            startupTimeout: TimeSpan.FromSeconds(5),
            commandRunnerFactory: () => runner,
            isMacOs: () => true);

        await host.StartAsync("helper", config, MacConnectionMode.Proxy, CancellationToken.None);
        Assert.Contains(writes, item => item.StartsWith("-setwebproxystate Ethernet off", StringComparison.Ordinal));
        listener.Stop();
        await host.StopAsync(CancellationToken.None);
    }

    [Fact]
    public async Task DefaultProxyProbeDoesNotTreatElapsedTimeAsReadiness()
    {
        using var temp = new TemporaryDirectory();
        var config = Path.Combine(temp.Path, "connection.json");
        var listener = new TcpListener(IPAddress.Loopback, 0);
        listener.Start();
        var port = ((IPEndPoint)listener.LocalEndpoint).Port;
        listener.Stop();
        listener = new TcpListener(IPAddress.Loopback, port);
        await File.WriteAllTextAsync(config, $"{{\"inbounds\":[{{\"listen_port\":{port}}}]}}");
        await using var host = new MacConnectionProcessHost(processStarter: _ => StartSleeper(),
            startupTimeout: TimeSpan.FromSeconds(5));
        var started = host.StartAsync("helper", config, MacConnectionMode.Proxy, CancellationToken.None);
        try
        {
            await Task.Delay(600);
            Assert.False(started.IsCompleted);
            listener.Start();
            await started;
            Assert.True(host.IsRunning);
        }
        finally { listener.Stop(); }
    }

    [Fact]
    public async Task UnexpectedExitIsReportedButIntentionalStopIsNot()
    {
        using var temp = new TemporaryDirectory();
        var path = Path.Combine(temp.Path, "connection.json");
        await File.WriteAllTextAsync(path, "{}");
        Process? process = null;
        await using var host = new MacConnectionProcessHost(processStarter: _ => process = StartSleeper(),
            readinessProbe: (_, _, _) => Task.FromResult(true));
        var exited = new TaskCompletionSource<MacConnectionProcessExit>(TaskCreationOptions.RunContinuationsAsynchronously);
        host.UnexpectedExit += value => exited.TrySetResult(value);
        await host.StartAsync("helper", path, MacConnectionMode.Proxy, CancellationToken.None);
        process!.Kill(entireProcessTree: true);
        var result = await exited.Task.WaitAsync(TimeSpan.FromSeconds(5));
        Assert.Equal(path, result.ConfigPath);
        Assert.Equal("connection_process_exited", result.ErrorCode);
        await host.StopAsync(CancellationToken.None);
        await host.StartAsync("helper", path, MacConnectionMode.Proxy, CancellationToken.None);
        var stopped = false;
        host.UnexpectedExit += _ => stopped = true;
        await host.StopAsync(CancellationToken.None);
        Assert.False(stopped);
    }

    [Fact]
    public async Task StartWaitsForReadinessBeyondOldDelayAndKeepsConfig()
    {
        using var temp = new TemporaryDirectory();
        var config = Path.Combine(temp.Path, "connection.json");
        await File.WriteAllTextAsync(config, "{\"inbounds\":[{\"listen_port\":25841}]}");
        var ready = new TaskCompletionSource<bool>(TaskCreationOptions.RunContinuationsAsynchronously);
        await using var host = new MacConnectionProcessHost(processStarter: _ => StartSleeper(),
            readinessProbe: (_, _, _) => Task.FromResult(ready.Task.IsCompleted),
            startupTimeout: TimeSpan.FromSeconds(5));
        var started = host.StartAsync("helper", config, MacConnectionMode.Proxy, CancellationToken.None);
        await Task.Delay(600);
        Assert.False(started.IsCompleted);
        Assert.True(File.Exists(config));
        ready.SetResult(true);
        await started;
        Assert.True(host.IsRunning);
    }

    [Fact]
    public async Task AliveButNeverReadyFailsClosedAndStopsProcess()
    {
        using var temp = new TemporaryDirectory();
        var config = Path.Combine(temp.Path, "connection.json");
        await File.WriteAllTextAsync(config, "{\"inbounds\":[{\"listen_port\":25841}]}");
        await using var host = new MacConnectionProcessHost(processStarter: _ => StartSleeper(),
            readinessProbe: (_, _, _) => Task.FromResult(false),
            startupTimeout: TimeSpan.FromMilliseconds(700));
        var error = await Assert.ThrowsAsync<InvalidOperationException>(() =>
            host.StartAsync("helper", config, MacConnectionMode.Proxy, CancellationToken.None));
        Assert.Equal("connection_readiness_timeout", error.Message);
        Assert.False(host.IsRunning);
    }

    [Fact]
    public async Task ReloadSignalsTheHelperAndOnlySucceedsOnceTheLiveTunMtuMatches()
    {
        using var temp = new TemporaryDirectory();
        var config = Path.Combine(temp.Path, "connection.json");
        await File.WriteAllTextAsync(config, "{\"inbounds\":[{\"type\":\"tun\",\"mtu\":1316}]}");
        var signals = 0;
        var liveMtu = 1396;
        await using var host = new MacConnectionProcessHost(
            processStarter: _ => StartSleeper(),
            readinessProbe: (_, _, _) => Task.FromResult(true),
            signalReload: _ => { signals++; return true; },
            tunMtuProbe: _ => Task.FromResult(liveMtu),
            reloadTimeout: TimeSpan.FromMilliseconds(500),
            // The direct-start helper reloads on a signal; on macOS a VPN helper runs
            // privileged and reloads through the marker file instead.
            isMacOs: () => false);

        await host.StartAsync("helper", config, MacConnectionMode.Vpn, CancellationToken.None);

        // The signal was delivered but the interface still reports the old MTU.
        Assert.False(await host.ReloadAsync(CancellationToken.None));
        Assert.Equal(1, signals);

        liveMtu = 1316;
        Assert.True(await host.ReloadAsync(CancellationToken.None));
        Assert.Equal(2, signals);
        await host.StopAsync(CancellationToken.None);
    }

    [Fact]
    public async Task ReloadReportsFailureWhenTheSignalCannotBeDelivered()
    {
        using var temp = new TemporaryDirectory();
        var config = Path.Combine(temp.Path, "connection.json");
        await File.WriteAllTextAsync(config, "{\"inbounds\":[{\"type\":\"tun\",\"mtu\":1316}]}");
        var probes = 0;
        await using var host = new MacConnectionProcessHost(
            processStarter: _ => StartSleeper(),
            readinessProbe: (_, _, _) => Task.FromResult(true),
            signalReload: _ => false,
            tunMtuProbe: _ => { probes++; return Task.FromResult(1396); },
            reloadTimeout: TimeSpan.FromMilliseconds(500));

        await host.StartAsync("helper", config, MacConnectionMode.Vpn, CancellationToken.None);

        Assert.False(await host.ReloadAsync(CancellationToken.None));
        Assert.Equal(0, probes);
        await host.StopAsync(CancellationToken.None);
    }

    [Fact]
    public async Task ReloadIsRefusedWhenTheHelperIsNotRunning()
    {
        using var temp = new TemporaryDirectory();
        var config = Path.Combine(temp.Path, "connection.json");
        await File.WriteAllTextAsync(config, "{\"inbounds\":[{\"listen_port\":25844}]}");
        var signals = 0;
        await using var host = new MacConnectionProcessHost(
            processStarter: _ => StartSleeper(),
            readinessProbe: (_, _, _) => Task.FromResult(true),
            signalReload: _ => { signals++; return true; },
            tunMtuProbe: _ => Task.FromResult(0));

        Assert.False(await host.ReloadAsync(CancellationToken.None));
        await host.StartAsync("helper", config, MacConnectionMode.Proxy, CancellationToken.None);
        await host.StopAsync(CancellationToken.None);

        Assert.False(await host.ReloadAsync(CancellationToken.None));
        Assert.Equal(0, signals);
    }

    [Fact]
    public async Task ProxyReloadDeliversTheSignalWithoutWaitingForATun()
    {
        using var temp = new TemporaryDirectory();
        var config = Path.Combine(temp.Path, "connection.json");
        await File.WriteAllTextAsync(config, "{\"inbounds\":[{\"listen_port\":25845}]}");
        var signals = 0;
        var probes = 0;
        await using var host = new MacConnectionProcessHost(
            processStarter: _ => StartSleeper(),
            readinessProbe: (_, _, _) => Task.FromResult(true),
            signalReload: _ => { signals++; return true; },
            tunMtuProbe: _ => { probes++; return Task.FromResult(0); });

        await host.StartAsync("helper", config, MacConnectionMode.Proxy, CancellationToken.None);

        Assert.True(await host.ReloadAsync(CancellationToken.None));
        Assert.Equal(1, signals);
        Assert.Equal(0, probes);
        await host.StopAsync(CancellationToken.None);
    }

    [Theory]
    [InlineData("FATAL configure tun interface: bad tun name: LeiKwan Bridge", "vpn_interface_name_invalid")]
    [InlineData("open /private/user/connection.json: no such file or directory", "connection_config_missing")]
    [InlineData("execution error: User canceled. (-128)", "vpn_permission_cancelled")]
    [InlineData("execution error: authorization failed (-60007)", "vpn_permission_required")]
    [InlineData("secret-user:secret-password at secret-host", "vpn_process_exited")]
    public void ExitErrorsAreClassifiedWithoutLeakingPayload(string raw, string expected)
        => Assert.Equal(expected, MacConnectionProcessHost.ClassifyError(raw));

    private static Process StartSleeper()
    {
        var info = new ProcessStartInfo
        {
            FileName = OperatingSystem.IsWindows() ? "powershell.exe" : "/bin/sleep",
            UseShellExecute = false, RedirectStandardOutput = true, RedirectStandardError = true,
            CreateNoWindow = true,
        };
        foreach (var arg in OperatingSystem.IsWindows()
            ? new[] { "-NoProfile", "-NonInteractive", "-Command", "Start-Sleep -Seconds 60" }
            : new[] { "60" }) info.ArgumentList.Add(arg);
        return Process.Start(info)!;
    }
}
