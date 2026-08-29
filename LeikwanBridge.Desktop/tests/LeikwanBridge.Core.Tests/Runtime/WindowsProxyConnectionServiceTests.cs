using System.Net;
using System.Net.Sockets;
using LeikwanBridge.Core.Models;
using LeikwanBridge.Core.Services;
using Xunit;

namespace LeikwanBridge.Core.Tests.Runtime;

public sealed class WindowsProxyConnectionServiceTests
{
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

    private sealed class FakeProcessHost : IWindowsProxyProcessHost
    {
        public bool IsRunning { get; private set; }
        public Exception? StopException { get; set; }
        public Task StartAsync(string executablePath, string configPath, WindowsProxyMode mode, CancellationToken ct = default)
        {
            IsRunning = true;
            return Task.CompletedTask;
        }
        public Task StopAsync(CancellationToken ct = default)
        {
            IsRunning = false;
            return StopException is null ? Task.CompletedTask : Task.FromException(StopException);
        }
        public ValueTask DisposeAsync() { IsRunning = false; return ValueTask.CompletedTask; }
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
}
