using System.Net;
using System.Net.Sockets;
using System.Text.Json;
using System.Text.Json.Nodes;
using LeikwanBridge.Core.Models;
using LeikwanBridge.Core.Services;
using LeikwanBridge.Core.Services.Ultra;
using Xunit;

namespace LeikwanBridge.Core.Tests.Runtime;

public sealed class WindowsProxyConnectionServiceTests
{
    [Fact]
    public async Task ExternalProfileCannotBecomeAnAccidentalSingBoxOutbound()
    {
        using var temp = new TempStateDirectory();
        var executable = Path.Combine(temp.Path, "sing-box.exe");
        await File.WriteAllTextAsync(executable, "test");
        var host = new FakeProcessHost();
        var store = new WindowsProxyProfileStore(Path.Combine(temp.Path, "profiles.json"));
        await using var service = new WindowsProxyConnectionService(store, executable, temp.Path,
            host, new FakeSystemProxy(), new FakeProcessAdapter());
        await service.InitializeAsync();
        await service.SaveProfileAsync(new WindowsProxyProfile
        {
            Protocol = "external", Server = "198.51.100.7", Port = 51820,
            Uuid = "b03f79e5-61df-4451-b75b-b52a5e19c61a",
        });
        var saved = Assert.Single(await store.LoadAsync());
        Assert.Equal("external", saved.Protocol);
        Assert.Equal(51820, saved.Port);
        var buildError = Assert.Throws<InvalidOperationException>(() =>
            WindowsProxyConfigBuilder.Build(saved, WindowsProxyMode.Http, []));
        Assert.Equal("proxy_external_requires_forward", buildError.Message);

        var error = await Assert.ThrowsAsync<InvalidOperationException>(() =>
            service.StartAsync(WindowsProxyMode.Http, saved.Id, forwardListenPort: 11059));

        Assert.Equal("proxy_external_requires_forward", error.Message);
        Assert.False(host.IsRunning);
        Assert.Empty(host.LastConfigJson);
    }

    [Theory]
    [InlineData(WindowsProxyMode.Http)]
    [InlineData(WindowsProxyMode.Process)]
    public async Task ForwardProxyDoesNotPublishConnectedUntilLocalListenerIsReady(WindowsProxyMode mode)
    {
        using var temp = new TempStateDirectory();
        var executable = Path.Combine(temp.Path, "sing-box.exe");
        await File.WriteAllTextAsync(executable, "test");
        var process = new FakeProcessHost { ListenerReady = false };
        var system = new FakeSystemProxy();
        var adapter = new FakeProcessAdapter();
        await using var service = new WindowsProxyConnectionService(
            new WindowsProxyProfileStore(Path.Combine(temp.Path, "profiles.json")), executable, temp.Path,
            process, system, adapter);
        await service.InitializeAsync();
        await service.SaveProfileAsync(new WindowsProxyProfile { Name = "saved", Server = "node.example.test", Password = "secret" });

        var error = await Assert.ThrowsAsync<InvalidOperationException>(() => service.StartAsync(
            mode, 1, processRules: [new WindowsProcessProxyRule("game.exe")], forwardListenPort: 11059));

        Assert.Equal("proxy_listener_unavailable", error.Message);
        Assert.False(process.IsRunning);
        Assert.False(system.IsEnabled);
        Assert.False(adapter.Started);
        Assert.Equal(WindowsProxyConnectionState.Error, service.Snapshot.State);
    }

    [Fact]
    public async Task UnexpectedProcessExitPublishesErrorAndClearsSystemProxy()
    {
        using var temp = new TempStateDirectory();
        var executable = Path.Combine(temp.Path, "sing-box.exe");
        await File.WriteAllTextAsync(executable, "test");
        var process = new FakeProcessHost();
        var system = new FakeSystemProxy();
        await using var service = new WindowsProxyConnectionService(
            new WindowsProxyProfileStore(Path.Combine(temp.Path, "profiles.json")), executable, temp.Path,
            process, system, new FakeProcessAdapter());
        await service.InitializeAsync();
        await service.SaveProfileAsync(new WindowsProxyProfile { Name = "saved", Server = "node.example.test", Password = "secret" });
        await service.StartAsync(WindowsProxyMode.Http, 1, forwardListenPort: 11059);
        Assert.True(system.IsEnabled);
        var lost = new TaskCompletionSource<WindowsProxySnapshot>(TaskCreationOptions.RunContinuationsAsynchronously);
        service.SnapshotChanged += snapshot =>
        {
            if (snapshot.State == WindowsProxyConnectionState.Error)
                lost.TrySetResult(snapshot);
        };

        process.Crash();

        var snapshot = await lost.Task.WaitAsync(TimeSpan.FromSeconds(5));
        Assert.Equal(WindowsProxyConnectionService.ProcessLostErrorCode, snapshot.ErrorCode);
        Assert.False(snapshot.SystemProxyEnabled);
        Assert.False(system.IsEnabled);
    }

    [Fact]
    public async Task ProcessExitAfterUserStopIsIgnored()
    {
        using var temp = new TempStateDirectory();
        var executable = Path.Combine(temp.Path, "sing-box.exe");
        await File.WriteAllTextAsync(executable, "test");
        var process = new FakeProcessHost();
        await using var service = new WindowsProxyConnectionService(
            new WindowsProxyProfileStore(Path.Combine(temp.Path, "profiles.json")), executable, temp.Path,
            process, new FakeSystemProxy(), new FakeProcessAdapter());
        await service.InitializeAsync();
        await service.SaveProfileAsync(new WindowsProxyProfile { Name = "saved", Server = "node.example.test", Password = "secret" });
        await service.StartAsync(WindowsProxyMode.Http, 1, forwardListenPort: 11059);
        await service.StopAsync();

        process.Crash();
        await Task.Delay(50);

        Assert.Equal(WindowsProxyConnectionState.Disconnected, service.Snapshot.State);
    }

    [Theory]
    [InlineData("ss", "aes-256-gcm")]
    [InlineData("ss2022", "2022-blake3-aes-256-gcm")]
    [InlineData("vless", "aes-256-gcm")]
    public async Task ForwardStartUsesLoopbackCloneAndPreservesSavedEndpointAndSecrets(string protocol, string method)
    {
        using var temp = new TempStateDirectory();
        var executable = Path.Combine(temp.Path, "sing-box.exe");
        await File.WriteAllTextAsync(executable, "test");
        var process = new FakeProcessHost();
        var store = new WindowsProxyProfileStore(Path.Combine(temp.Path, "profiles.json"));
        await using var service = new WindowsProxyConnectionService(
            store, executable, temp.Path, process, new FakeSystemProxy(), new FakeProcessAdapter());
        await service.InitializeAsync();
        await service.SaveProfileAsync(new WindowsProxyProfile
        {
            Name = "saved node", Protocol = protocol, Server = "node.example.test", Port = 30526,
            Method = method, Password = "saved-secret", Uuid = "b03f79e5-61df-4451-b75b-b52a5e19c61a",
            Tls = true, ServerName = "tls.example.test", Host = "ws.example.test", Transport = "ws",
        });

        await service.StartAsync(WindowsProxyMode.Http, 1, forwardListenPort: 11059);

        using var json = System.Text.Json.JsonDocument.Parse(process.LastConfigJson);
        var outbound = json.RootElement.GetProperty("outbounds")[0];
        Assert.Equal("127.0.0.1", outbound.GetProperty("server").GetString());
        Assert.Equal(11059, outbound.GetProperty("server_port").GetInt32());
        if (protocol == "vless")
        {
            Assert.Equal("tls.example.test", outbound.GetProperty("tls").GetProperty("server_name").GetString());
            Assert.Equal("b03f79e5-61df-4451-b75b-b52a5e19c61a", outbound.GetProperty("uuid").GetString());
            Assert.Equal("ws.example.test", outbound.GetProperty("transport").GetProperty("headers").GetProperty("Host").GetString());
        }
        else
        {
            Assert.Equal("saved-secret", outbound.GetProperty("password").GetString());
            Assert.Equal(method, outbound.GetProperty("method").GetString());
        }
        foreach (var saved in new[] { Assert.Single(service.Profiles), Assert.Single(await store.LoadAsync()) })
        {
            Assert.Equal("node.example.test", saved.Server);
            Assert.Equal(30526, saved.Port);
            Assert.Equal("saved-secret", saved.Password);
            Assert.Equal("tls.example.test", saved.ServerName);
        }
        Assert.Empty(Directory.GetFiles(temp.Path, "proxy-*.json"));
    }

    [Theory]
    [InlineData(0)]
    [InlineData(-1)]
    [InlineData(65536)]
    public async Task InvalidForwardListenerDoesNotFallBackToOriginalNode(int port)
    {
        using var temp = new TempStateDirectory();
        var executable = Path.Combine(temp.Path, "sing-box.exe");
        await File.WriteAllTextAsync(executable, "test");
        var process = new FakeProcessHost();
        await using var service = new WindowsProxyConnectionService(
            new WindowsProxyProfileStore(Path.Combine(temp.Path, "profiles.json")), executable, temp.Path,
            process, new FakeSystemProxy(), new FakeProcessAdapter());
        await service.InitializeAsync();
        await service.SaveProfileAsync(new WindowsProxyProfile { Name = "saved", Server = "node.example.test", Password = "secret" });

        var error = await Assert.ThrowsAsync<InvalidOperationException>(() =>
            service.StartAsync(WindowsProxyMode.Http, 1, forwardListenPort: port));

        Assert.Equal("proxy_forward_listener_invalid", error.Message);
        Assert.False(process.IsRunning);
        Assert.Empty(process.LastConfigJson);
        Assert.Equal(WindowsProxyConnectionState.Error, service.Snapshot.State);
    }

    [Fact]
    public async Task HttpStartEnablesSystemProxyAndStopRestoresIt()
    {
        using var temp = new TempStateDirectory();
        var executable = Path.Combine(temp.Path, "sing-box.exe");
        await File.WriteAllTextAsync(executable, "test");
        var process = new FakeProcessHost();
        var systemProxy = new FakeSystemProxy();
        var adapter = new FakeProcessAdapter();
        await using var service = new WindowsProxyConnectionService(
            new WindowsProxyProfileStore(Path.Combine(temp.Path, "profiles.json")),
            executable,
            temp.Path,
            process,
            systemProxy,
            adapter);
        await service.InitializeAsync();
        await service.SaveProfileAsync(new WindowsProxyProfile
        {
            Name = "test",
            Server = "198.51.100.7",
            Method = "aes-256-gcm",
            Password = "secret",
        });

        await service.StartAsync(WindowsProxyMode.Http, 1);

        Assert.Equal(WindowsProxyConnectionState.Connected, service.Snapshot.State);
        Assert.True(service.Snapshot.SystemProxyEnabled);
        Assert.Equal(2080, systemProxy.EnabledPort);
        Assert.True(process.IsRunning);
        Assert.False(adapter.Started);

        await service.StopAsync();

        Assert.Equal(WindowsProxyConnectionState.Disconnected, service.Snapshot.State);
        Assert.True(systemProxy.Disabled);
        Assert.False(process.IsRunning);
    }

    [Fact]
    public async Task NativeServiceReadsRuleSetsFromTheSingBoxDirectory()
    {
        using var temp = new TempStateDirectory();
        var executable = Path.Combine(temp.Path, "sing-box.exe");
        await File.WriteAllTextAsync(executable, "test");
        foreach (var name in new[] { NativeChinaRuleSets.GeoIpFileName, NativeChinaRuleSets.GeoSiteFileName })
            await File.WriteAllBytesAsync(Path.Combine(temp.Path, name), [(byte)'S', (byte)'R', (byte)'S', 1]);
        var process = new FakeProcessHost();
        await using var service = new WindowsProxyConnectionService(
            new WindowsProxyProfileStore(Path.Combine(temp.Path, "profiles.json")),
            executable,
            Path.Combine(temp.Path, "runtime"),
            process,
            new FakeSystemProxy(),
            new FakeProcessAdapter(),
            nativeRelayHost: new FakeNativeRelayHost());
        await service.InitializeAsync();
        using var lease = CreateMtuLease(158);

        await service.StartNativeAsync(WindowsProxyMode.Http, lease);

        using var config = JsonDocument.Parse(process.LastConfigJson);
        var sets = config.RootElement.GetProperty("route").GetProperty("rule_set").EnumerateArray().ToList();
        Assert.Equal(2, sets.Count);
        Assert.All(sets, set => Assert.Equal("local", set.GetProperty("type").GetString()));
        Assert.Equal(Path.Combine(temp.Path, "geoip-cn.srs"), sets[0].GetProperty("path").GetString());
    }

    [Fact]
    public async Task NativeTunStartUsesAnonymousLeaseAndWipesItOnStop()
    {
        using var temp = new TempStateDirectory();
        var executable = Path.Combine(temp.Path, "sing-box.exe");
        await File.WriteAllTextAsync(executable, "test");
        var process = new FakeProcessHost();
        var systemProxy = new FakeSystemProxy();
        var adapter = new FakeProcessAdapter();
        var relay = new FakeNativeRelayHost();
        await using var service = new WindowsProxyConnectionService(
            new WindowsProxyProfileStore(Path.Combine(temp.Path, "profiles.json")),
            executable,
            temp.Path,
            process,
            systemProxy,
            adapter,
            nativeRelayHost: relay);
        await service.InitializeAsync();
        var user = System.Text.Encoding.UTF8.GetBytes("native-user");
        var password = System.Text.Encoding.UTF8.GetBytes("native-password");
        using var lease = new NativeOutboundLease(
            158,
            "native-lease",
            new string('a', 64),
            DateTimeOffset.UtcNow.AddMinutes(2),
            "tcp+udp",
            "Ultra Hong Kong to Mainland China",
            "cn_only",
            NativeOutboundLease.ComputeScopeDigest(
                158,
                "Ultra Hong Kong to Mainland China",
                "tcp+udp",
                "cn_only"),
            "203.0.113.20",
            5438,
            user,
            password,
            rateLimitMbps: 1000,
            quotaRemainingBytes: 1024 * 1024,
            selectionRef: "selection-native-158",
            selectionTier: UltraLease.SelectionTierUnknown);

        await service.StartNativeAsync(WindowsProxyMode.Tun, lease);

        Assert.Equal(WindowsProxyConnectionState.Connected, service.Snapshot.State);
        Assert.Equal(WindowsProxyConnectionService.NativeOutboundDisplayName, service.Snapshot.ActiveProfileName);
        Assert.True(service.IsNativeOutboundActive);
        Assert.True(service.IsNativeDataPlaneRunning);
        Assert.True(process.IsRunning);
        Assert.False(systemProxy.IsEnabled);
        Assert.Equal(1, relay.StartCalls);
        Assert.Equal("127.0.0.1", relay.LastEndpoint?.Host);
        Assert.Contains("127.0.0.1", process.LastConfigJson, StringComparison.Ordinal);
        Assert.DoesNotContain("native-password", process.LastConfigJson, StringComparison.Ordinal);

        var replacementUser = System.Text.Encoding.UTF8.GetBytes("replacement-user");
        var replacementPassword = System.Text.Encoding.UTF8.GetBytes("replacement-password");
        var replacement = new NativeOutboundLease(
            158,
            "native-lease-replacement",
            new string('b', 64),
            DateTimeOffset.UtcNow.AddMinutes(2),
            "tcp+udp",
            "Ultra Hong Kong to Mainland China",
            "cn_only",
            NativeOutboundLease.ComputeScopeDigest(
                158,
                "Ultra Hong Kong to Mainland China",
                "tcp+udp",
                "cn_only"),
            "203.0.113.21",
            5438,
            replacementUser,
            replacementPassword,
            rateLimitMbps: 1000,
            quotaRemainingBytes: 1024 * 1024,
            selectionRef: "selection-native-159",
            selectionTier: UltraLease.SelectionTierUnknown);
        relay.RefreshResult = false;
        Assert.False(await service.TryRefreshNativeAsync(replacement));
        Assert.True(service.IsNativeOutboundActive);
        Assert.True(service.IsNativeDataPlaneRunning);
        Assert.True(process.IsRunning);
        Assert.Equal(1, relay.StartCalls);
        Assert.Equal(1, relay.RefreshCalls);

        relay.RefreshResult = true;
        Assert.True(await service.TryRefreshNativeAsync(replacement));
        Assert.Equal(1, relay.StartCalls);
        Assert.Equal(2, relay.RefreshCalls);

        relay.IsRunning = false;
        Assert.False(service.IsNativeDataPlaneRunning);
        relay.IsRunning = true;
        process.SetRunning(false);
        Assert.False(service.IsNativeDataPlaneRunning);
        process.SetRunning(true);
        Assert.True(service.IsNativeDataPlaneRunning);

        await service.StopAsync();

        Assert.False(service.IsNativeOutboundActive);
        Assert.Null(service.ActiveNativeLeaseExpiresAt);
        Assert.All(user, value => Assert.Equal(0, value));
        Assert.All(password, value => Assert.Equal(0, value));
        Assert.All(replacementUser, value => Assert.Equal(0, value));
        Assert.All(replacementPassword, value => Assert.Equal(0, value));
        Assert.Equal(WindowsProxyConnectionState.Disconnected, service.Snapshot.State);
    }

    [Fact]
    public async Task NativeTunStartRetriesBeforePublishingConnectedWhenTunIsInitiallyUnavailable()
    {
        using var temp = new TempStateDirectory();
        var executable = Path.Combine(temp.Path, "sing-box.exe");
        await File.WriteAllTextAsync(executable, "test");
        var process = new FakeProcessHost();
        process.TunReadinessResults.Enqueue(false);
        process.TunReadinessResults.Enqueue(true);
        var relay = new FakeNativeRelayHost();
        await using var service = new WindowsProxyConnectionService(
            new WindowsProxyProfileStore(Path.Combine(temp.Path, "profiles.json")),
            executable,
            temp.Path,
            process,
            new FakeSystemProxy(),
            new FakeProcessAdapter(),
            nativeRelayHost: relay);
        await service.InitializeAsync();
        var user = System.Text.Encoding.UTF8.GetBytes("native-user");
        var password = System.Text.Encoding.UTF8.GetBytes("native-password");
        using var lease = CreateNativeLease(user, password);

        await service.StartNativeAsync(WindowsProxyMode.Tun, lease);

        Assert.Equal(2, process.StartCalls);
        Assert.Equal(2, process.TunReadinessCalls);
        Assert.Equal(1, relay.StartCalls);
        Assert.Equal(WindowsProxyConnectionState.Connected, service.Snapshot.State);
        Assert.True(service.IsNativeDataPlaneRunning);
    }

    [Fact]
    public async Task NativeTunStartFailsClosedAndWipesLeaseWhenTunNeverBecomesReady()
    {
        using var temp = new TempStateDirectory();
        var executable = Path.Combine(temp.Path, "sing-box.exe");
        await File.WriteAllTextAsync(executable, "test");
        var process = new FakeProcessHost();
        process.TunReadinessResults.Enqueue(false);
        process.TunReadinessResults.Enqueue(false);
        var relay = new FakeNativeRelayHost();
        await using var service = new WindowsProxyConnectionService(
            new WindowsProxyProfileStore(Path.Combine(temp.Path, "profiles.json")),
            executable,
            temp.Path,
            process,
            new FakeSystemProxy(),
            new FakeProcessAdapter(),
            nativeRelayHost: relay);
        await service.InitializeAsync();
        var user = System.Text.Encoding.UTF8.GetBytes("native-user");
        var password = System.Text.Encoding.UTF8.GetBytes("native-password");
        using var lease = CreateNativeLease(user, password);

        var exception = await Assert.ThrowsAsync<InvalidOperationException>(() =>
            service.StartNativeAsync(WindowsProxyMode.Tun, lease));

        Assert.Equal("proxy_tun_unavailable", exception.Message);
        Assert.Equal(2, process.StartCalls);
        Assert.Equal(2, process.TunReadinessCalls);
        Assert.False(process.IsRunning);
        Assert.False(relay.IsRunning);
        Assert.Equal(WindowsProxyConnectionState.Error, service.Snapshot.State);
        Assert.All(user, value => Assert.Equal(0, value));
        Assert.All(password, value => Assert.Equal(0, value));
    }

    [Fact]
    public async Task RepeatedStopAfterSuccessfulCleanupDoesNotRepeatNativeShutdown()
    {
        using var temp = new TempStateDirectory();
        var executable = Path.Combine(temp.Path, "sing-box.exe");
        await File.WriteAllTextAsync(executable, "test");
        var adapter = new FakeProcessAdapter();
        await using var service = new WindowsProxyConnectionService(
            new WindowsProxyProfileStore(Path.Combine(temp.Path, "profiles.json")),
            executable,
            temp.Path,
            new FakeProcessHost(),
            new FakeSystemProxy(),
            adapter);
        await service.InitializeAsync();
        await service.SaveProfileAsync(new WindowsProxyProfile
        {
            Name = "test",
            Server = "198.51.100.7",
            Method = "aes-256-gcm",
            Password = "secret",
        });

        await service.StartAsync(WindowsProxyMode.Http, 1);
        await service.StopAsync();
        var stopCallsAfterCleanup = adapter.StopCalls;
        await service.StopAsync();

        Assert.Equal(stopCallsAfterCleanup, adapter.StopCalls);
    }

    [Fact]
    public async Task ChangingLocalListenPortUsesConfiguredPort()
    {
        using var temp = new TempStateDirectory();
        var executable = Path.Combine(temp.Path, "sing-box.exe");
        await File.WriteAllTextAsync(executable, "test");
        var process = new FakeProcessHost();
        var systemProxy = new FakeSystemProxy();
        await using var service = new WindowsProxyConnectionService(
            new WindowsProxyProfileStore(Path.Combine(temp.Path, "profiles.json")),
            executable,
            temp.Path,
            process,
            systemProxy,
            new FakeProcessAdapter());
        await service.InitializeAsync();
        await service.SaveProfileAsync(new WindowsProxyProfile
        {
            Name = "test",
            Server = "198.51.100.7",
            Method = "aes-256-gcm",
            Password = "secret",
        });

        service.SetProxyPort(32123);
        await service.StartAsync(WindowsProxyMode.Http, 1);

        Assert.Equal(32123, service.ProxyPort);
        Assert.Equal(32123, service.Snapshot.ProxyPort);
        Assert.Equal(32123, systemProxy.EnabledPort);
    }

    [Fact]
    public async Task ChangingLocalListenPortWhileConnectedRequiresStop()
    {
        using var temp = new TempStateDirectory();
        var executable = Path.Combine(temp.Path, "sing-box.exe");
        await File.WriteAllTextAsync(executable, "test");
        await using var service = new WindowsProxyConnectionService(
            new WindowsProxyProfileStore(Path.Combine(temp.Path, "profiles.json")),
            executable,
            temp.Path,
            new FakeProcessHost(),
            new FakeSystemProxy(),
            new FakeProcessAdapter());
        await service.InitializeAsync();
        await service.SaveProfileAsync(new WindowsProxyProfile
        {
            Name = "test",
            Server = "198.51.100.7",
            Method = "aes-256-gcm",
            Password = "secret",
        });
        await service.StartAsync(WindowsProxyMode.Http, 1);

        var exception = Assert.Throws<InvalidOperationException>(() => service.SetProxyPort(32124));

        Assert.Equal("proxy_port_change_requires_stop", exception.Message);
        Assert.Equal(2080, service.ProxyPort);
    }

    [Fact]
    public async Task ProcessModeFailsClosedWhenNetchPayloadIsUnavailable()
    {
        using var temp = new TempStateDirectory();
        var executable = Path.Combine(temp.Path, "sing-box.exe");
        await File.WriteAllTextAsync(executable, "test");
        var process = new FakeProcessHost();
        var adapter = new FakeProcessAdapter { Available = false, AvailabilityReason = "process_redirector_missing" };
        await using var service = new WindowsProxyConnectionService(
            new WindowsProxyProfileStore(Path.Combine(temp.Path, "profiles.json")),
            executable,
            temp.Path,
            process,
            new FakeSystemProxy(),
            adapter);
        await service.InitializeAsync();
        await service.SaveProfileAsync(new WindowsProxyProfile
        {
            Name = "test",
            Server = "198.51.100.7",
            Method = "aes-256-gcm",
            Password = "secret",
        });

        var exception = await Assert.ThrowsAsync<InvalidOperationException>(() =>
            service.StartAsync(WindowsProxyMode.Process, 1, processRules: [new(@"^demo\.exe$")]));

        Assert.Equal("process_redirector_missing", exception.Message);
        Assert.Equal(WindowsProxyConnectionState.Error, service.Snapshot.State);
        Assert.False(process.IsRunning);
        Assert.False(adapter.Started);

        service.SetProxyPort(32124);
        Assert.Equal(32124, service.ProxyPort);
    }

    [Fact]
    public async Task FailedProcessPreflightKeepsExistingHttpConnection()
    {
        using var temp = new TempStateDirectory();
        var executable = Path.Combine(temp.Path, "sing-box.exe");
        await File.WriteAllTextAsync(executable, "test");
        var process = new FakeProcessHost();
        var systemProxy = new FakeSystemProxy();
        var adapter = new FakeProcessAdapter { Available = false, AvailabilityReason = "administrator_required" };
        await using var service = new WindowsProxyConnectionService(
            new WindowsProxyProfileStore(Path.Combine(temp.Path, "profiles.json")),
            executable,
            temp.Path,
            process,
            systemProxy,
            adapter);
        await service.InitializeAsync();
        await service.SaveProfileAsync(new WindowsProxyProfile
        {
            Name = "test",
            Server = "198.51.100.7",
            Method = "aes-256-gcm",
            Password = "secret",
        });

        await service.StartAsync(WindowsProxyMode.Http, 1);
        await Assert.ThrowsAsync<InvalidOperationException>(() =>
            service.StartAsync(WindowsProxyMode.Process, 1, processRules: [new(@"^demo\.exe$")]));

        Assert.True(process.IsRunning);
        Assert.True(systemProxy.IsEnabled);
        Assert.Equal(WindowsProxyConnectionState.Connected, service.Snapshot.State);
        Assert.Equal(WindowsProxyMode.Http, service.Snapshot.Mode);
    }

    [Fact]
    public async Task ProcessModeUsesFallbackPortAndCanReconnectWhenPreferredPortIsBusy()
    {
        using var listener = new TcpListener(IPAddress.Loopback, 0);
        listener.Start();
        var preferredPort = ((IPEndPoint)listener.LocalEndpoint).Port;
        using var temp = new TempStateDirectory();
        var executable = Path.Combine(temp.Path, "sing-box.exe");
        await File.WriteAllTextAsync(executable, "test");
        var process = new FakeProcessHost();
        var adapter = new FakeProcessAdapter();
        await using var service = new WindowsProxyConnectionService(
            new WindowsProxyProfileStore(Path.Combine(temp.Path, "profiles.json")),
            executable,
            temp.Path,
            process,
            new FakeSystemProxy(),
            adapter,
            proxyPort: preferredPort);
        await service.InitializeAsync();
        await service.SaveProfileAsync(new WindowsProxyProfile
        {
            Name = "test",
            Server = "198.51.100.7",
            Method = "aes-256-gcm",
            Password = "secret",
        });

        await service.StartAsync(
            WindowsProxyMode.Process,
            1,
            processRules: [new(@"^demo\.exe$")]);

        Assert.NotEqual(preferredPort, service.Snapshot.ProxyPort);
        Assert.Equal(service.Snapshot.ProxyPort, adapter.LastSocksPort);

        await service.StopAsync();
        await service.StartAsync(
            WindowsProxyMode.Process,
            1,
            processRules: [new(@"^demo\.exe$")]);

        Assert.NotEqual(preferredPort, service.Snapshot.ProxyPort);
        Assert.Equal(service.Snapshot.ProxyPort, adapter.LastSocksPort);
    }

    [Fact]
    public async Task DeletingActiveProfileStopsConnectionAndPublishesDisconnected()
    {
        using var temp = new TempStateDirectory();
        var executable = Path.Combine(temp.Path, "sing-box.exe");
        await File.WriteAllTextAsync(executable, "test");
        var process = new FakeProcessHost();
        var systemProxy = new FakeSystemProxy();
        await using var service = new WindowsProxyConnectionService(
            new WindowsProxyProfileStore(Path.Combine(temp.Path, "profiles.json")),
            executable,
            temp.Path,
            process,
            systemProxy,
            new FakeProcessAdapter());
        await service.InitializeAsync();
        await service.SaveProfileAsync(new WindowsProxyProfile
        {
            Name = "active",
            Server = "198.51.100.7",
            Method = "aes-256-gcm",
            Password = "secret",
        });

        await service.StartAsync(WindowsProxyMode.Http, 1);
        await service.DeleteProfileAsync(1);

        Assert.Equal(WindowsProxyConnectionState.Disconnected, service.Snapshot.State);
        Assert.False(process.IsRunning);
        Assert.False(systemProxy.IsEnabled);
        Assert.Empty(service.Profiles);
    }

    [Fact]
    public async Task StopReportsDriverFailureAfterCompletingOtherCleanup()
    {
        using var temp = new TempStateDirectory();
        var executable = Path.Combine(temp.Path, "sing-box.exe");
        await File.WriteAllTextAsync(executable, "test");
        var process = new FakeProcessHost();
        var systemProxy = new FakeSystemProxy();
        var adapter = new FakeProcessAdapter();
        await using var service = new WindowsProxyConnectionService(
            new WindowsProxyProfileStore(Path.Combine(temp.Path, "profiles.json")),
            executable,
            temp.Path,
            process,
            systemProxy,
            adapter);
        await service.InitializeAsync();
        await service.SaveProfileAsync(new WindowsProxyProfile
        {
            Name = "test",
            Server = "198.51.100.7",
            Method = "aes-256-gcm",
            Password = "secret",
        });
        await service.StartAsync(WindowsProxyMode.Http, 1);
        adapter.StopException = new InvalidOperationException("process_redirector_stop_timeout");

        var exception = await Assert.ThrowsAsync<InvalidOperationException>(() => service.StopAsync());

        Assert.Equal("process_redirector_stop_timeout", exception.Message);
        Assert.False(process.IsRunning);
        Assert.False(systemProxy.IsEnabled);
        Assert.Equal(WindowsProxyConnectionState.Error, service.Snapshot.State);
        Assert.Equal("process_redirector_stop_timeout", service.Snapshot.ErrorCode);
        adapter.StopException = null;
    }

    [Theory]
    [InlineData(1500, 1456)]
    [InlineData(1472, 1428)]
    [InlineData(1440, 1396)]
    [InlineData(1300, 1280)]
    [InlineData(0, 1500)]
    public async Task NativeTunStartSizesMtuFromTheRuntimeStatusCeiling(int ceiling, int expectedMtu)
    {
        using var temp = new TempStateDirectory();
        var executable = Path.Combine(temp.Path, "sing-box.exe");
        await File.WriteAllTextAsync(executable, "test");
        var process = new FakeProcessHost();
        await using var service = new WindowsProxyConnectionService(
            new WindowsProxyProfileStore(Path.Combine(temp.Path, "profiles.json")),
            executable,
            temp.Path,
            process,
            new FakeSystemProxy(),
            new FakeProcessAdapter(),
            tunMtu: 1500,
            nativeRelayHost: new FakeNativeRelayHost());
        await service.InitializeAsync();
        WriteRuntimeCeiling(temp.Path, 158, ceiling);
        var lease = CreateMtuLease(158);

        await service.StartNativeAsync(WindowsProxyMode.Tun, lease);

        Assert.Equal(expectedMtu, TunMtuOf(process.LastConfigJson));
        Assert.Equal(expectedMtu, service.AppliedTunMtu);
        Assert.Equal(ceiling, service.MeasuredPayloadCeiling);
        Assert.False(service.MtuUpdatePending);
        Assert.Equal(WindowsProxyConnectionState.Connected, service.Snapshot.State);
    }

    [Fact]
    public async Task LiveCeilingChangeSurfacesPendingUntilTheNextStartAppliesIt()
    {
        using var temp = new TempStateDirectory();
        var executable = Path.Combine(temp.Path, "sing-box.exe");
        await File.WriteAllTextAsync(executable, "test");
        var process = new FakeProcessHost();
        await using var service = new WindowsProxyConnectionService(
            new WindowsProxyProfileStore(Path.Combine(temp.Path, "profiles.json")),
            executable,
            temp.Path,
            process,
            new FakeSystemProxy(),
            new FakeProcessAdapter(),
            tunMtu: 1500,
            nativeRelayHost: new FakeNativeRelayHost());
        await service.InitializeAsync();
        WriteRuntimeCeiling(temp.Path, 158, 1500);

        await service.StartNativeAsync(WindowsProxyMode.Tun, CreateMtuLease(158));
        Assert.Equal(1456, service.AppliedTunMtu);

        // The relay failed over and the runtime re-measured a narrower ceiling.
        WriteRuntimeCeiling(temp.Path, 158, 1360);
        Assert.False(service.ObserveMeasuredPayloadCeiling(158, 1360));
        Assert.True(service.MtuUpdatePending);
        Assert.Equal(1316, service.PendingTunMtu);
        // The live TUN keeps the MTU it was created with until it is recreated.
        Assert.Equal(1456, service.AppliedTunMtu);

        // The same change read straight from the status file the runtime rewrote.
        Assert.Equal(1360, UltraRuntimeStatus.ReadForwardPayloadCeiling(temp.Path, 158));
        Assert.False(service.RefreshMeasuredPayloadCeiling(158));
        Assert.True(service.MtuUpdatePending);
        Assert.Equal(1316, service.PendingTunMtu);

        // Another forward's ceiling never resizes this connection.
        Assert.False(service.ObserveMeasuredPayloadCeiling(999, 1300));
        Assert.Equal(1316, service.PendingTunMtu);

        // The existing start path (failover/reconnect) rebuilds the config and
        // recreates the TUN with the ceiling that is current now.
        await service.StartNativeAsync(WindowsProxyMode.Tun, CreateMtuLease(158));

        Assert.Equal(1316, service.AppliedTunMtu);
        Assert.Equal(1316, TunMtuOf(process.LastConfigJson));
        Assert.Equal(1360, service.MeasuredPayloadCeiling);
        Assert.False(service.MtuUpdatePending);
        Assert.True(service.RefreshMeasuredPayloadCeiling(158));
    }

    [Fact]
    public async Task SavedProfileTunStartUsesTheObservedCeiling()
    {
        using var temp = new TempStateDirectory();
        var executable = Path.Combine(temp.Path, "sing-box.exe");
        await File.WriteAllTextAsync(executable, "test");
        var process = new FakeProcessHost();
        await using var service = new WindowsProxyConnectionService(
            new WindowsProxyProfileStore(Path.Combine(temp.Path, "profiles.json")),
            executable,
            temp.Path,
            process,
            new FakeSystemProxy(),
            new FakeProcessAdapter(),
            tunMtu: 1500);
        await service.InitializeAsync();
        await service.SaveProfileAsync(new WindowsProxyProfile
        {
            Name = "saved",
            Server = "198.51.100.7",
            Method = "aes-256-gcm",
            Password = "secret",
        });

        Assert.True(service.ObserveMeasuredPayloadCeiling(158, 1440));
        await service.StartAsync(WindowsProxyMode.Tun, 1);

        Assert.Equal(1396, service.AppliedTunMtu);
        Assert.Equal(1396, TunMtuOf(process.LastConfigJson));
        Assert.False(service.MtuUpdatePending);
    }

    [Fact]
    public async Task PendingTunMtuIsAppliedByRestartingSingBoxWithTheSameConfig()
    {
        using var temp = new TempStateDirectory();
        var executable = Path.Combine(temp.Path, "sing-box.exe");
        await File.WriteAllTextAsync(executable, "test");
        var process = new FakeProcessHost();
        var reader = new FakeTunMtuReader(process);
        await using var service = new WindowsProxyConnectionService(
            new WindowsProxyProfileStore(Path.Combine(temp.Path, "profiles.json")),
            executable,
            temp.Path,
            process,
            new FakeSystemProxy(),
            new FakeProcessAdapter(),
            tunMtu: 1500,
            nativeRelayHost: new FakeNativeRelayHost(),
            tunMtuReader: reader);
        await service.InitializeAsync();
        WriteRuntimeCeiling(temp.Path, 158, 1500);

        await service.StartNativeAsync(WindowsProxyMode.Tun, CreateMtuLease(158));
        Assert.Equal(1456, service.AppliedTunMtu);
        Assert.Equal(1456, reader.ReadTunMtu());
        var startedConfig = process.LastConfigJson;

        // The relay failed over and the runtime re-measured a narrower ceiling.
        WriteRuntimeCeiling(temp.Path, 158, 1360);
        Assert.False(service.RefreshMeasuredPayloadCeiling(158));
        Assert.True(service.MtuUpdatePending);

        Assert.True(await service.ApplyPendingTunMtuAsync());

        Assert.Equal(2, process.StartCalls);
        Assert.Equal(1316, service.AppliedTunMtu);
        Assert.Equal(1316, service.PendingTunMtu);
        Assert.Equal(1316, TunMtuOf(process.LastConfigJson));
        Assert.Equal(1316, reader.ReadTunMtu());
        Assert.False(service.MtuUpdatePending);
        Assert.Equal(WindowsProxyConnectionState.Connected, service.Snapshot.State);

        // Nothing but the MTU may change: the native relay port allocated for the
        // running session is not re-allocated by the resize.
        var original = JsonNode.Parse(startedConfig)!;
        var updated = JsonNode.Parse(process.LastConfigJson)!;
        original["inbounds"]![0]!["mtu"] = updated["inbounds"]![0]!["mtu"]!.GetValue<int>();
        Assert.True(JsonNode.DeepEquals(original, updated));

        // A ceiling that resolves to the MTU already live is a no-op again.
        Assert.True(service.RefreshMeasuredPayloadCeiling(158));
        Assert.False(await service.ApplyPendingTunMtuAsync());
        Assert.Equal(2, process.StartCalls);
    }

    [Fact]
    public async Task MtuLadderWobbleInsideTheLiveMtuNeverRestartsSingBox()
    {
        using var temp = new TempStateDirectory();
        var executable = Path.Combine(temp.Path, "sing-box.exe");
        await File.WriteAllTextAsync(executable, "test");
        var process = new FakeProcessHost();
        var reader = new FakeTunMtuReader(process);
        await using var service = new WindowsProxyConnectionService(
            new WindowsProxyProfileStore(Path.Combine(temp.Path, "profiles.json")),
            executable,
            temp.Path,
            process,
            new FakeSystemProxy(),
            new FakeProcessAdapter(),
            tunMtu: 1280,
            tunMtuReader: reader);
        await service.InitializeAsync();
        await service.SaveProfileAsync(new WindowsProxyProfile
        {
            Name = "saved",
            Server = "198.51.100.7",
            Method = "aes-256-gcm",
            Password = "secret",
        });

        await service.StartAsync(WindowsProxyMode.Tun, 1);
        Assert.Equal(1280, service.AppliedTunMtu);

        // Every raw ceiling above the configured MTU resolves to that same MTU.
        Assert.True(service.ObserveMeasuredPayloadCeiling(158, 1500));
        Assert.True(service.ObserveMeasuredPayloadCeiling(158, 1430));
        Assert.True(service.ObserveMeasuredPayloadCeiling(158, 1324));
        Assert.False(service.MtuUpdatePending);

        Assert.False(await service.ApplyPendingTunMtuAsync());
        Assert.Equal(1, process.StartCalls);
        Assert.Equal(1280, service.AppliedTunMtu);
        Assert.Equal(WindowsProxyConnectionState.Connected, service.Snapshot.State);
    }

    [Fact]
    public async Task MtuUpdatePendingStaysWhenTheInPlaceRestartFails()
    {
        using var temp = new TempStateDirectory();
        var executable = Path.Combine(temp.Path, "sing-box.exe");
        await File.WriteAllTextAsync(executable, "test");
        var process = new FakeProcessHost();
        var reader = new FakeTunMtuReader(process);
        await using var service = new WindowsProxyConnectionService(
            new WindowsProxyProfileStore(Path.Combine(temp.Path, "profiles.json")),
            executable,
            temp.Path,
            process,
            new FakeSystemProxy(),
            new FakeProcessAdapter(),
            tunMtu: 1500,
            nativeRelayHost: new FakeNativeRelayHost(),
            tunMtuReader: reader);
        await service.InitializeAsync();
        WriteRuntimeCeiling(temp.Path, 158, 1500);
        await service.StartNativeAsync(WindowsProxyMode.Tun, CreateMtuLease(158));
        Assert.Equal(1456, service.AppliedTunMtu);

        WriteRuntimeCeiling(temp.Path, 158, 1360);
        Assert.False(service.RefreshMeasuredPayloadCeiling(158));
        process.FailStartCalls.Add(2);

        Assert.False(await service.ApplyPendingTunMtuAsync());

        Assert.True(service.MtuUpdatePending);
        Assert.Equal(1456, service.AppliedTunMtu);
        // The failed resize rolled the data plane back to the previous config.
        Assert.Equal(3, process.StartCalls);
        Assert.True(process.IsRunning);
        Assert.Equal(1456, TunMtuOf(process.LastConfigJson));
        Assert.Equal(WindowsProxyConnectionState.Connected, service.Snapshot.State);
    }

    [Fact]
    public async Task FailedResizeThatCannotBeRolledBackReportsAnErrorSnapshot()
    {
        using var temp = new TempStateDirectory();
        var executable = Path.Combine(temp.Path, "sing-box.exe");
        await File.WriteAllTextAsync(executable, "test");
        var process = new FakeProcessHost();
        var reader = new FakeTunMtuReader(process);
        await using var service = new WindowsProxyConnectionService(
            new WindowsProxyProfileStore(Path.Combine(temp.Path, "profiles.json")),
            executable,
            temp.Path,
            process,
            new FakeSystemProxy(),
            new FakeProcessAdapter(),
            tunMtu: 1500,
            nativeRelayHost: new FakeNativeRelayHost(),
            tunMtuReader: reader);
        await service.InitializeAsync();
        WriteRuntimeCeiling(temp.Path, 158, 1500);
        await service.StartNativeAsync(WindowsProxyMode.Tun, CreateMtuLease(158));

        WriteRuntimeCeiling(temp.Path, 158, 1360);
        Assert.False(service.RefreshMeasuredPayloadCeiling(158));
        process.FailStartCalls.Add(2);
        process.FailStartCalls.Add(3);

        Assert.False(await service.ApplyPendingTunMtuAsync());

        Assert.True(service.MtuUpdatePending);
        Assert.Equal(WindowsProxyConnectionState.Error, service.Snapshot.State);
        Assert.Equal("proxy_mtu_apply_failed", service.Snapshot.ErrorCode);
    }

    [Fact]
    public async Task ThePendingFlagSurvivesAnAdapterThatKeptItsOldMtu()
    {
        using var temp = new TempStateDirectory();
        var executable = Path.Combine(temp.Path, "sing-box.exe");
        await File.WriteAllTextAsync(executable, "test");
        var process = new FakeProcessHost();
        var reader = new FakeTunMtuReader(process);
        await using var service = new WindowsProxyConnectionService(
            new WindowsProxyProfileStore(Path.Combine(temp.Path, "profiles.json")),
            executable,
            temp.Path,
            process,
            new FakeSystemProxy(),
            new FakeProcessAdapter(),
            tunMtu: 1500,
            nativeRelayHost: new FakeNativeRelayHost(),
            tunMtuReader: reader);
        await service.InitializeAsync();
        WriteRuntimeCeiling(temp.Path, 158, 1500);
        await service.StartNativeAsync(WindowsProxyMode.Tun, CreateMtuLease(158));

        // The driver re-opens the existing adapter and keeps its size.
        reader.FixedMtu = 1456;
        WriteRuntimeCeiling(temp.Path, 158, 1360);
        Assert.False(service.RefreshMeasuredPayloadCeiling(158));

        Assert.False(await service.ApplyPendingTunMtuAsync());

        Assert.True(service.MtuUpdatePending);
        Assert.Equal(1456, service.AppliedTunMtu);
        // The process is alive, so the failed resize must not tear it down.
        Assert.True(process.IsRunning);
        Assert.Equal(2, process.StartCalls);
        Assert.True(reader.Reads > 0);
    }

    [Fact]
    public async Task ASecondResizeInsideTheDebounceWindowIsRefused()
    {
        using var temp = new TempStateDirectory();
        var executable = Path.Combine(temp.Path, "sing-box.exe");
        await File.WriteAllTextAsync(executable, "test");
        var process = new FakeProcessHost();
        var reader = new FakeTunMtuReader(process);
        await using var service = new WindowsProxyConnectionService(
            new WindowsProxyProfileStore(Path.Combine(temp.Path, "profiles.json")),
            executable,
            temp.Path,
            process,
            new FakeSystemProxy(),
            new FakeProcessAdapter(),
            tunMtu: 1500,
            nativeRelayHost: new FakeNativeRelayHost(),
            tunMtuReader: reader);
        await service.InitializeAsync();
        WriteRuntimeCeiling(temp.Path, 158, 1500);
        await service.StartNativeAsync(WindowsProxyMode.Tun, CreateMtuLease(158));

        WriteRuntimeCeiling(temp.Path, 158, 1360);
        Assert.False(service.RefreshMeasuredPayloadCeiling(158));
        Assert.True(await service.ApplyPendingTunMtuAsync());
        Assert.Equal(1316, service.AppliedTunMtu);
        Assert.NotNull(service.LastMtuApplyAttemptAt);

        // A second, narrower ceiling arrives inside the debounce window.
        WriteRuntimeCeiling(temp.Path, 158, 1300);
        Assert.False(service.RefreshMeasuredPayloadCeiling(158));
        Assert.True(service.MtuUpdatePending);
        Assert.False(await service.ApplyPendingTunMtuAsync());
        Assert.Equal(2, process.StartCalls);
        Assert.Equal(1316, service.AppliedTunMtu);
        Assert.True(service.MtuUpdatePending);
    }

    [Fact]
    public async Task ResizeIsAllowedAgainOnceTheDebounceWindowIsEmpty()
    {
        using var temp = new TempStateDirectory();
        var executable = Path.Combine(temp.Path, "sing-box.exe");
        await File.WriteAllTextAsync(executable, "test");
        var process = new FakeProcessHost();
        var reader = new FakeTunMtuReader(process);
        await using var service = new WindowsProxyConnectionService(
            new WindowsProxyProfileStore(Path.Combine(temp.Path, "profiles.json")),
            executable,
            temp.Path,
            process,
            new FakeSystemProxy(),
            new FakeProcessAdapter(),
            tunMtu: 1500,
            nativeRelayHost: new FakeNativeRelayHost(),
            tunMtuReader: reader,
            mtuApplyMinInterval: TimeSpan.Zero);
        await service.InitializeAsync();
        WriteRuntimeCeiling(temp.Path, 158, 1500);
        await service.StartNativeAsync(WindowsProxyMode.Tun, CreateMtuLease(158));

        WriteRuntimeCeiling(temp.Path, 158, 1360);
        Assert.False(service.RefreshMeasuredPayloadCeiling(158));
        Assert.True(await service.ApplyPendingTunMtuAsync());
        Assert.Equal(1316, service.AppliedTunMtu);

        // Same window, but the caller opted out of the debounce.
        WriteRuntimeCeiling(temp.Path, 158, 1300);
        Assert.False(service.RefreshMeasuredPayloadCeiling(158));
        Assert.True(await service.ApplyPendingTunMtuAsync());
        Assert.Equal(3, process.StartCalls);
        Assert.Equal(1280, service.AppliedTunMtu);
        Assert.Equal(1280, TunMtuOf(process.LastConfigJson));
        Assert.False(service.MtuUpdatePending);
    }

    [Fact]
    public async Task ApplyCannotRunWhileAStartTransitionIsInFlight()
    {
        using var temp = new TempStateDirectory();
        var executable = Path.Combine(temp.Path, "sing-box.exe");
        await File.WriteAllTextAsync(executable, "test");
        var process = new FakeProcessHost();
        var reader = new FakeTunMtuReader(process);
        await using var service = new WindowsProxyConnectionService(
            new WindowsProxyProfileStore(Path.Combine(temp.Path, "profiles.json")),
            executable,
            temp.Path,
            process,
            new FakeSystemProxy(),
            new FakeProcessAdapter(),
            tunMtu: 1500,
            nativeRelayHost: new FakeNativeRelayHost(),
            tunMtuReader: reader);
        await service.InitializeAsync();
        WriteRuntimeCeiling(temp.Path, 158, 1500);

        process.StartRelease = new TaskCompletionSource(TaskCreationOptions.RunContinuationsAsynchronously);
        var starting = service.StartNativeAsync(WindowsProxyMode.Tun, CreateMtuLease(158));
        await process.StartEntered.Task.WaitAsync(TimeSpan.FromSeconds(5));

        var apply = service.ApplyPendingTunMtuAsync();
        await Task.Delay(TimeSpan.FromMilliseconds(100));
        // The resize waits for the transition instead of yanking the process.
        Assert.False(apply.IsCompleted);
        Assert.Equal(1, process.StartCalls);

        process.StartRelease.SetResult();
        await starting;
        Assert.False(await apply);
        Assert.Equal(1, process.StartCalls);
        Assert.False(service.MtuUpdatePending);
        Assert.Equal(WindowsProxyConnectionState.Connected, service.Snapshot.State);
    }

    [Fact]
    public async Task ApplyIsRefusedWhileNoTunIsLive()
    {
        using var temp = new TempStateDirectory();
        var executable = Path.Combine(temp.Path, "sing-box.exe");
        await File.WriteAllTextAsync(executable, "test");
        var process = new FakeProcessHost();
        await using var service = new WindowsProxyConnectionService(
            new WindowsProxyProfileStore(Path.Combine(temp.Path, "profiles.json")),
            executable,
            temp.Path,
            process,
            new FakeSystemProxy(),
            new FakeProcessAdapter(),
            tunMtu: 1500,
            tunMtuReader: new FakeTunMtuReader(process));
        await service.InitializeAsync();

        Assert.False(await service.ApplyPendingTunMtuAsync());
        Assert.Equal(0, process.StartCalls);
        Assert.Equal(WindowsProxyConnectionState.Disconnected, service.Snapshot.State);
    }

    private static void WriteRuntimeCeiling(string runtimeDirectory, int forwardId, int ceiling)
    {
        var statusPath = Path.Combine(runtimeDirectory, "native-usage", $"native-{forwardId}-mtu", "status.json");
        Directory.CreateDirectory(Path.GetDirectoryName(statusPath)!);
        File.WriteAllText(statusPath, ceiling > 0
            ? $$"""{"forward_id": {{forwardId}}, "udp_payload_ceiling": {{ceiling}}}"""
            : $$"""{"forward_id": {{forwardId}}}""");
    }

    private static int TunMtuOf(string configJson)
    {
        using var document = JsonDocument.Parse(configJson);
        return document.RootElement.GetProperty("inbounds")[0].GetProperty("mtu").GetInt32();
    }

    private static int TryTunMtuOf(string configJson)
    {
        try
        {
            return string.IsNullOrWhiteSpace(configJson) ? 0 : TunMtuOf(configJson);
        }
        catch (Exception exception) when (exception is JsonException or KeyNotFoundException)
        {
            return 0;
        }
    }

    private static NativeOutboundLease CreateMtuLease(int forwardId)
    {
        const string direction = "Ultra Hong Kong to Mainland China";
        const string routeScope = "cn_only";
        return new NativeOutboundLease(
            forwardId,
            "native-lease-mtu",
            new string('c', 64),
            DateTimeOffset.UtcNow.AddMinutes(2),
            "tcp+udp",
            direction,
            routeScope,
            NativeOutboundLease.ComputeScopeDigest(forwardId, direction, "tcp+udp", routeScope),
            "203.0.113.20",
            5438,
            System.Text.Encoding.UTF8.GetBytes("native-user"),
            System.Text.Encoding.UTF8.GetBytes("native-password"),
            rateLimitMbps: 1000,
            quotaRemainingBytes: 1024 * 1024,
            selectionRef: "selection-native-mtu",
            selectionTier: UltraLease.SelectionTierUnknown);
    }

    private sealed class FakeProcessHost : IWindowsProxyProcessHost
    {
        public bool IsRunning { get; private set; }
        public bool IsTunReady { get; private set; } = true;
        public string LastConfigJson { get; private set; } = string.Empty;
        public Exception? StopException { get; set; }
        public int StartCalls { get; private set; }
        public int TunReadinessCalls { get; private set; }
        public bool ListenerReady { get; set; } = true;
        public Queue<bool> TunReadinessResults { get; } = new();

        /// <summary>1-based start call numbers that must fail, like a refused TUN.</summary>
        public HashSet<int> FailStartCalls { get; } = [];

        /// <summary>Holds a start open so a transition can be observed mid-flight.</summary>
        public TaskCompletionSource? StartRelease { get; set; }
        public TaskCompletionSource StartEntered { get; } = new(TaskCreationOptions.RunContinuationsAsynchronously);

        /// <summary>
        /// MTU the adapter would carry: a TUN interface only changes size when
        /// the process that created it is (re)started.
        /// </summary>
        public int CurrentTunMtu { get; private set; }

        public async Task StartAsync(string executablePath, string configPath, WindowsProxyMode mode, CancellationToken ct = default)
        {
            StartCalls++;
            StartEntered.TrySetResult();
            if (StartRelease is not null)
                await StartRelease.Task.WaitAsync(ct);
            if (FailStartCalls.Contains(StartCalls))
            {
                IsRunning = false;
                throw new InvalidOperationException("proxy_process_start_failed");
            }
            LastConfigJson = File.ReadAllText(configPath);
            CurrentTunMtu = TryTunMtuOf(LastConfigJson);
            IsRunning = true;
            IsTunReady = true;
        }
        public Task<bool> WaitForTunReadyAsync(CancellationToken ct = default)
        {
            TunReadinessCalls++;
            IsTunReady = TunReadinessResults.TryDequeue(out var result) ? result : true;
            return Task.FromResult(IsTunReady);
        }
        public Task<bool> WaitForListenerAsync(int port, CancellationToken ct = default) => Task.FromResult(ListenerReady);
        public Task StopAsync(CancellationToken ct = default)
        {
            IsRunning = false;
            return StopException is null ? Task.CompletedTask : Task.FromException(StopException);
        }
        public void SetRunning(bool value) => IsRunning = value;
        public event Action<int>? UnexpectedExit;
        public void Crash(int exitCode = 1) { IsRunning = false; UnexpectedExit?.Invoke(exitCode); }
        public ValueTask DisposeAsync() { IsRunning = false; return ValueTask.CompletedTask; }
    }

    /// <summary>
    /// Stands in for the OS adapter: reports the MTU of the config the process
    /// host last started, optionally pinned to simulate a driver that kept the
    /// old interface size.
    /// </summary>
    private sealed class FakeTunMtuReader(FakeProcessHost host) : IWindowsTunMtuReader
    {
        public int? FixedMtu { get; set; }
        public int Reads { get; private set; }
        public int ReadTunMtu()
        {
            Reads++;
            return FixedMtu ?? host.CurrentTunMtu;
        }
    }

    private static NativeOutboundLease CreateNativeLease(byte[] user, byte[] password)
    {
        const int forwardId = 180;
        const string direction = "CAN -> HKG";
        const string routeScope = "intl_only";
        return new NativeOutboundLease(
            forwardId,
            "native-lease-readiness",
            new string('a', 64),
            DateTimeOffset.UtcNow.AddMinutes(2),
            "tcp+udp",
            direction,
            routeScope,
            NativeOutboundLease.ComputeScopeDigest(forwardId, direction, "tcp+udp", routeScope),
            "203.0.113.20",
            5438,
            user,
            password);
    }

    private sealed class FakeSystemProxy : IWindowsSystemProxyManager
    {
        public bool IsEnabled { get; private set; }
        public int EnabledPort { get; private set; }
        public bool Disabled { get; private set; }
        public Exception? DisableException { get; set; }
        public Task EnableAsync(int port, CancellationToken ct = default)
        {
            EnabledPort = port;
            IsEnabled = true;
            Disabled = false;
            return Task.CompletedTask;
        }
        public Task DisableAsync(CancellationToken ct = default)
        {
            IsEnabled = false;
            Disabled = true;
            return DisableException is null ? Task.CompletedTask : Task.FromException(DisableException);
        }
    }

    private sealed class FakeProcessAdapter : IWindowsProcessProxyAdapter
    {
        public bool Available { get; set; } = true;
        public string AvailabilityReason { get; set; } = "";
        public bool Started { get; private set; }
        public int StopCalls { get; private set; }
        public int LastSocksPort { get; private set; }
        public Exception? StopException { get; set; }
        public bool IsAvailable => Available;
        public Task StartAsync(int socksPort, IEnumerable<WindowsProcessProxyRule> rules, CancellationToken ct = default)
        {
            LastSocksPort = socksPort;
            Started = true;
            return Task.CompletedTask;
        }
        public Task StopAsync(CancellationToken ct = default)
        {
            StopCalls++;
            Started = false;
            var exception = StopException;
            StopException = null;
            return exception is null ? Task.CompletedTask : Task.FromException(exception);
        }
        public ValueTask DisposeAsync() { Started = false; return ValueTask.CompletedTask; }
    }

    private sealed class FakeNativeRelayHost : LeikwanBridge.Core.Services.Ultra.INativeOutboundRelayHost
    {
        public int StartCalls { get; private set; }
        public int RefreshCalls { get; private set; }
        public bool RefreshResult { get; set; } = true;
        public bool IsRunning { get; set; }
        public LeikwanBridge.Core.Services.Ultra.NativeOutboundRelayEndpoint? LastEndpoint { get; private set; }

        public Task<LeikwanBridge.Core.Services.Ultra.NativeOutboundRelayEndpoint> StartAsync(
            int forwardId,
            string ultraBinaryPath,
            int listenPort,
            NativeOutboundLease lease,
            string runtimeDirectory,
            CancellationToken ct = default)
        {
            StartCalls++;
            IsRunning = true;
            LastEndpoint = new LeikwanBridge.Core.Services.Ultra.NativeOutboundRelayEndpoint("127.0.0.1", listenPort);
            return Task.FromResult(LastEndpoint);
        }

        public Task<bool> RefreshAsync(int forwardId, NativeOutboundLease lease, CancellationToken ct = default)
        {
            RefreshCalls++;
            return Task.FromResult(RefreshResult);
        }

        public Task StopAsync(CancellationToken ct = default)
        {
            IsRunning = false;
            return Task.CompletedTask;
        }
        public ValueTask DisposeAsync() => ValueTask.CompletedTask;
    }
}
