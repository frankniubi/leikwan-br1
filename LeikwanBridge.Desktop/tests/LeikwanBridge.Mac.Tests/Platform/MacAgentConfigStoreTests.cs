using LeikwanBridge.Core.Models;
using LeikwanBridge.Mac.Services;
using Xunit;

namespace LeikwanBridge.Mac.Tests.Platform;

public sealed class MacAgentConfigStoreTests
{
    [Fact]
    public async Task ConfigJsonNeverContainsSessionOrAgentTokens()
    {
        using var temp = new TemporaryDirectory();
        var paths = new MacPlatformPaths(temp.Path, Path.Combine(temp.Path, "LeiKwan Bridge.app"));
        var secrets = new InMemorySecretStore();
        var store = new MacAgentConfigStore(paths, secrets);
        var config = new AgentConfig
        {
            MasterUrl = "https://panel.example.test",
            ClientId = 42,
            ActiveClientId = 42,
            DesktopSessionToken = "desktop-session-canary",
            AgentToken = "agent-token-canary",
        };

        await store.SaveAsync(config, CancellationToken.None);

        var onDisk = File.ReadAllText(paths.ConfigPath);
        Assert.DoesNotContain("desktop-session-canary", onDisk);
        Assert.DoesNotContain("agent-token-canary", onDisk);
        var loaded = await store.LoadAsync(CancellationToken.None);
        Assert.Equal("desktop-session-canary", loaded.DesktopSessionToken);
        Assert.Equal("agent-token-canary", loaded.AgentToken);
        Assert.Equal(paths.StateDirectory, loaded.StateDir);
        Assert.Equal(paths.BinDirectory + "/ultra-client", loaded.UltraBin);
    }

    private sealed class InMemorySecretStore : LeikwanBridge.Core.Services.Platform.ISecretStore
    {
        private readonly Dictionary<string, string> _values = [];
        public Task<string?> GetAsync(string name, CancellationToken ct) => Task.FromResult(_values.GetValueOrDefault(name));
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
