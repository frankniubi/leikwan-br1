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

    private sealed class CapturingProcessHost : IMacConnectionProcessHost
    {
        public int StartCalls { get; private set; }
        public int StopCalls { get; private set; }
        public string StartedConfigJson { get; private set; } = string.Empty;

        public async Task StartAsync(string executablePath, string configPath, MacConnectionMode mode, CancellationToken ct)
        {
            StartCalls++;
            StartedConfigJson = await File.ReadAllTextAsync(configPath, ct);
        }

        public Task StopAsync(CancellationToken ct)
        {
            StopCalls++;
            return Task.CompletedTask;
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
}
