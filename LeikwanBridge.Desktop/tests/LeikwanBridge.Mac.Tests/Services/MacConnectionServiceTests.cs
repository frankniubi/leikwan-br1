using LeikwanBridge.Core.Models;
using LeikwanBridge.Core.Services.Platform;
using LeikwanBridge.Mac.Services;
using LeikwanBridge.Mac.Tests.Platform;
using Xunit;

namespace LeikwanBridge.Mac.Tests.Services;

public sealed class MacConnectionServiceTests
{
    [Fact]
    public async Task StartCompilesEphemeralConfigAndCleansItAfterHelperStarts()
    {
        using var temp = new TemporaryDirectory();
        var paths = new MacPlatformPaths(temp.Path, Path.Combine(temp.Path, "LeiKwan Bridge.app"));
        Directory.CreateDirectory(paths.BinDirectory);
        File.WriteAllText(paths.SingBoxPath, "helper");
        var secrets = new MemorySecretStore();
        var store = new MacConnectionProfileStore(secrets);
        var host = new CapturingProcessHost();
        var service = new MacConnectionService(paths, store, processHost: host);
        await service.InitializeAsync();
        await service.SaveProfileAsync(new MacConnectionProfile
        {
            Id = 4,
            Name = "Test SS",
            Protocol = MacConnectionProtocol.Shadowsocks,
            Server = "198.51.100.50",
            Port = 443,
            Method = "aes-256-gcm",
            Password = "memory-secret",
        });

        await service.StartAsync(
            MacConnectionMode.Proxy,
            4,
            "https://panel.example.test",
            [new ForwardConfig
            {
                BackendMode = "ultra",
                RuntimeLease = new UltraLease { ProviderIp = "203.0.113.50" },
            }]);

        Assert.Equal(MacConnectionState.Connected, service.Snapshot.State);
        Assert.Equal(2, service.Snapshot.BypassAddressCount);
        Assert.Contains("memory-secret", host.StartedConfigJson, StringComparison.Ordinal);
        Assert.DoesNotContain("memory-secret", string.Join("\n", Directory.GetFiles(paths.RuntimeDirectory)));
        Assert.True(host.StartCalls == 1);

        await service.StopAsync();
        Assert.Equal(2, host.StopCalls);
        await service.DisposeAsync();
    }

    [Fact]
    public async Task NativeVpnStartUsesLeaseAndWipesCredentialsOnStop()
        => await VerifyNativeLeaseCleanupAsync(false);

    [Fact]
    public async Task NativeProcessExitClearsConnectedStateAndStopsRelay()
        => await VerifyNativeLeaseCleanupAsync(true);

    private static async Task VerifyNativeLeaseCleanupAsync(bool unexpectedExit)
    {
        using var temp = new TemporaryDirectory();
        var paths = new MacPlatformPaths(temp.Path, Path.Combine(temp.Path, "LeiKwan Bridge.app"));
        Directory.CreateDirectory(paths.BinDirectory);
        File.WriteAllText(paths.SingBoxPath, "helper");
        File.WriteAllText(Path.Combine(paths.BinDirectory, "ultra-client"), "helper");
        var host = new CapturingProcessHost();
        var relay = new FakeNativeRelayHost();
        var service = new MacConnectionService(
            paths,
            new MacConnectionProfileStore(new MemorySecretStore()),
            processHost: host,
            nativeRelayHost: relay);
        await service.InitializeAsync();
        const int forwardId = 158;
        const string direction = "Ultra Hong Kong to Mainland China";
        var user = System.Text.Encoding.UTF8.GetBytes("native-user");
        var password = System.Text.Encoding.UTF8.GetBytes("native-password");
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
            user,
            password);

        await service.StartNativeAsync(
            MacConnectionMode.Vpn,
            lease,
            "https://panel.example.test",
            [new ForwardConfig { ForwardId = forwardId, BackendMode = "ultra" }]);

        Assert.Equal(MacConnectionState.Connected, service.Snapshot.State);
        Assert.Equal(MacConnectionService.NativeOutboundDisplayName, service.Snapshot.ActiveProfileName);
        Assert.True(service.IsNativeOutboundActive);
        Assert.Contains("127.0.0.1", host.StartedConfigJson, StringComparison.Ordinal);
        Assert.DoesNotContain("native-password", host.StartedConfigJson, StringComparison.Ordinal);
        Assert.Equal(1, relay.StartCalls);

        if (unexpectedExit)
        {
            host.ExitUnexpectedly();
            for (var attempt = 0; attempt < 50 && service.Snapshot.State != MacConnectionState.Error; attempt++)
                await Task.Delay(20);
            Assert.Equal(MacConnectionState.Error, service.Snapshot.State);
            Assert.Equal("vpn_process_exited", service.Snapshot.ErrorCode);
            Assert.False(relay.IsRunning);
        }
        await service.StopAsync();

        Assert.False(service.IsNativeOutboundActive);
        Assert.Null(service.ActiveNativeLeaseExpiresAt);
        Assert.All(user, value => Assert.Equal(0, value));
        Assert.All(password, value => Assert.Equal(0, value));
    }

    [Fact]
    public async Task UnrestrictedLeaseCannotUseTheLegacyRestrictedStartEntryPoint()
    {
        using var temp = new TemporaryDirectory();
        var paths = new MacPlatformPaths(temp.Path, Path.Combine(temp.Path, "LeiKwan Bridge.app"));
        Directory.CreateDirectory(paths.BinDirectory);
        File.WriteAllText(paths.SingBoxPath, "helper");
        File.WriteAllText(Path.Combine(paths.BinDirectory, "ultra-client"), "helper");
        var relay = new FakeNativeRelayHost();
        var service = new MacConnectionService(
            paths,
            new MacConnectionProfileStore(new MemorySecretStore()),
            processHost: new CapturingProcessHost(),
            nativeRelayHost: relay);
        await service.InitializeAsync();
        const int forwardId = 159;
        const string direction = "Mainland China -> International";
        var user = System.Text.Encoding.UTF8.GetBytes("unrestricted-user");
        var password = System.Text.Encoding.UTF8.GetBytes("unrestricted-password");
        using var lease = new NativeOutboundLease(
            forwardId,
            "native-unrestricted",
            new string('e', 64),
            DateTimeOffset.UtcNow.AddMinutes(2),
            "tcp+udp",
            direction,
            "global",
            NativeOutboundLease.ComputeScopeDigestV2(forwardId, direction, "tcp+udp", "global", "unrestricted"),
            "203.0.113.33",
            5438,
            user,
            password,
            routePolicy: "unrestricted");

        var error = await Assert.ThrowsAsync<InvalidOperationException>(() => service.StartNativeAsync(
            MacConnectionMode.Vpn,
            lease,
            "https://panel.example.test",
            []));

        Assert.Equal("native_route_policy_mismatch", error.Message);
        Assert.Equal(0, relay.StartCalls);
        Assert.All(user, value => Assert.Equal(0, value));
        Assert.All(password, value => Assert.Equal(0, value));
        await service.DisposeAsync();
    }

    [Fact]
    public async Task NativeStartFailureStopsRelayAndWipesLease()
    {
        using var temp = new TemporaryDirectory();
        var paths = new MacPlatformPaths(temp.Path, Path.Combine(temp.Path, "LeiKwan Bridge.app"));
        Directory.CreateDirectory(paths.BinDirectory);
        File.WriteAllText(paths.SingBoxPath, "helper");
        File.WriteAllText(Path.Combine(paths.BinDirectory, "ultra-client"), "helper");
        var host = new CapturingProcessHost { FailStart = true };
        var relay = new FakeNativeRelayHost();
        var service = new MacConnectionService(
            paths,
            new MacConnectionProfileStore(new MemorySecretStore()),
            processHost: host,
            nativeRelayHost: relay);
        await service.InitializeAsync();
        var user = System.Text.Encoding.UTF8.GetBytes("failure-user");
        var password = System.Text.Encoding.UTF8.GetBytes("failure-password");
        using var lease = new NativeOutboundLease(
            158,
            "native-lease",
            new string('d', 64),
            DateTimeOffset.UtcNow.AddMinutes(2),
            "tcp+udp",
            "Hong Kong -> China Mainland",
            "cn_only",
            NativeOutboundLease.ComputeScopeDigest(158, "Hong Kong -> China Mainland", "tcp+udp", "cn_only"),
            "203.0.113.20",
            5438,
            user,
            password,
            rateLimitMbps: 100,
            quotaRemainingBytes: 1000,
            selectionRef: "selection-failure");

        await Assert.ThrowsAsync<InvalidOperationException>(() => service.StartNativeAsync(
            MacConnectionMode.Vpn,
            lease,
            "https://panel.example.test",
            []));

        Assert.Equal(1, relay.StopCalls);
        Assert.All(user, value => Assert.Equal(0, value));
        Assert.All(password, value => Assert.Equal(0, value));
    }

    [Fact]
    public async Task NativeLeaseRefreshUsesTheExistingRelayAndWipesThePreviousLease()
    {
        using var temp = new TemporaryDirectory();
        var paths = new MacPlatformPaths(temp.Path, Path.Combine(temp.Path, "LeiKwan Bridge.app"));
        Directory.CreateDirectory(paths.BinDirectory);
        File.WriteAllText(paths.SingBoxPath, "helper");
        File.WriteAllText(Path.Combine(paths.BinDirectory, "ultra-client"), "helper");
        var relay = new FakeNativeRelayHost();
        var service = new MacConnectionService(
            paths,
            new MacConnectionProfileStore(new MemorySecretStore()),
            processHost: new CapturingProcessHost(),
            nativeRelayHost: relay);
        await service.InitializeAsync();

        const int forwardId = 158;
        const string direction = "Hong Kong -> China Mainland";
        const string routeScope = "cn_only";
        var digest = NativeOutboundLease.ComputeScopeDigest(forwardId, direction, "tcp+udp", routeScope);
        var previousUser = System.Text.Encoding.UTF8.GetBytes("previous-user");
        var previousPassword = System.Text.Encoding.UTF8.GetBytes("previous-password");
        var previous = new NativeOutboundLease(
            forwardId,
            "native-lease-previous",
            new string('a', 64),
            DateTimeOffset.UtcNow.AddMinutes(2),
            "tcp+udp",
            direction,
            routeScope,
            digest,
            "203.0.113.20",
            5438,
            previousUser,
            previousPassword,
            rateLimitMbps: 100,
            quotaRemainingBytes: 1000,
            selectionRef: "selection-previous-01");

        await service.StartNativeAsync(
            MacConnectionMode.Vpn,
            previous,
            "https://panel.example.test",
            []);

        var nextUser = System.Text.Encoding.UTF8.GetBytes("next-user");
        var nextPassword = System.Text.Encoding.UTF8.GetBytes("next-password");
        var next = new NativeOutboundLease(
            forwardId,
            "native-lease-next",
            new string('b', 64),
            DateTimeOffset.UtcNow.AddMinutes(3),
            "tcp+udp",
            direction,
            routeScope,
            digest,
            "203.0.113.20",
            5438,
            nextUser,
            nextPassword,
            rateLimitMbps: 100,
            quotaRemainingBytes: 1000,
            selectionRef: "selection-next-01");

        Assert.True(await service.TryRefreshNativeAsync(next));
        Assert.Equal(1, relay.StartCalls);
        Assert.Equal(1, relay.RefreshCalls);
        Assert.Equal(0, relay.StopCalls);
        Assert.All(previousUser, value => Assert.Equal(0, value));
        Assert.All(previousPassword, value => Assert.Equal(0, value));
        Assert.NotEmpty(nextUser);
        Assert.NotEmpty(nextPassword);
        Assert.Equal(next.ExpiresAt, service.ActiveNativeLeaseExpiresAt);

        await service.StopAsync();
        Assert.All(nextUser, value => Assert.Equal(0, value));
        Assert.All(nextPassword, value => Assert.Equal(0, value));
        await service.DisposeAsync();
    }

    private sealed class CapturingProcessHost : IMacConnectionProcessHost
    {
        public bool IsRunning { get; private set; }
        public event Action<MacConnectionProcessExit>? UnexpectedExit;
        public string StartedConfigPath { get; private set; } = string.Empty;
        public int StartCalls { get; private set; }
        public int StopCalls { get; private set; }
        public string StartedConfigJson { get; private set; } = string.Empty;
        public bool FailStart { get; init; }

        public async Task StartAsync(string executablePath, string configPath, MacConnectionMode mode, CancellationToken ct)
        {
            StartCalls++;
            if (FailStart)
                throw new InvalidOperationException("process_start_failed");
            StartedConfigJson = await File.ReadAllTextAsync(configPath, ct);
            StartedConfigPath = configPath;
            IsRunning = true;
        }

        public Task StopAsync(CancellationToken ct)
        {
            StopCalls++;
            IsRunning = false;
            return Task.CompletedTask;
        }

        public void ExitUnexpectedly()
        {
            IsRunning = false;
            UnexpectedExit?.Invoke(new MacConnectionProcessExit(StartedConfigPath, "vpn_process_exited"));
        }

        public ValueTask DisposeAsync() => ValueTask.CompletedTask;
    }

    private sealed class MemorySecretStore : ISecretStore
    {
        private readonly Dictionary<string, string> _values = new(StringComparer.Ordinal);

        public Task<string?> GetAsync(string name, CancellationToken ct)
            => Task.FromResult(_values.GetValueOrDefault(name));

        public Task SetAsync(string name, string value, CancellationToken ct)
        {
            _values[name] = value;
            return Task.CompletedTask;
        }

        public Task DeleteAsync(string name, CancellationToken ct)
        {
            _values.Remove(name);
            return Task.CompletedTask;
        }
    }

    private sealed class FakeNativeRelayHost : LeikwanBridge.Core.Services.Ultra.INativeOutboundRelayHost
    {
        public int StartCalls { get; private set; }
        public int RefreshCalls { get; private set; }
        public int StopCalls { get; private set; }
        private bool _started;
        public bool IsRunning => _started;

        public Task<LeikwanBridge.Core.Services.Ultra.NativeOutboundRelayEndpoint> StartAsync(
            int forwardId,
            string ultraBinaryPath,
            int listenPort,
            NativeOutboundLease lease,
            string runtimeDirectory,
            CancellationToken ct = default)
        {
            StartCalls++;
            _started = true;
            return Task.FromResult(new LeikwanBridge.Core.Services.Ultra.NativeOutboundRelayEndpoint("127.0.0.1", listenPort));
        }

        public Task<bool> RefreshAsync(int forwardId, NativeOutboundLease lease, CancellationToken ct = default)
        {
            RefreshCalls++;
            return Task.FromResult(true);
        }

        public Task StopAsync(CancellationToken ct = default)
        {
            if (_started)
            {
                StopCalls++;
                _started = false;
            }
            return Task.CompletedTask;
        }
        public ValueTask DisposeAsync() => ValueTask.CompletedTask;
    }
}
