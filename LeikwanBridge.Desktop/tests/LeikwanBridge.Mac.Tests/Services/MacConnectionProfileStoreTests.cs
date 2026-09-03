using LeikwanBridge.Core.Services.Platform;
using LeikwanBridge.Mac.Services;
using Xunit;

namespace LeikwanBridge.Mac.Tests.Services;

public sealed class MacConnectionProfileStoreTests
{
    [Fact]
    public async Task UnsupportedVlessProfilesAreDroppedWhileSupportedProfilesRoundTrip()
    {
        var secrets = new MemorySecretStore();
        var store = new MacConnectionProfileStore(secrets);
        var vless = new MacConnectionProfile
        {
            Id = 2,
            Name = "Secure node",
            Protocol = "vless",
            Server = "node.example.test",
            Port = 443,
        };
        var shadowsocks = new MacConnectionProfile
        {
            Id = 3,
            Name = "Supported node",
            Protocol = MacConnectionProtocol.Shadowsocks,
            Server = "ss.example.test",
            Port = 443,
            Method = "aes-256-gcm",
            Password = "secret",
        };

        await store.SaveAsync([vless, shadowsocks], CancellationToken.None);
        var loaded = await store.LoadAsync(CancellationToken.None);

        Assert.Single(loaded);
        Assert.Equal("Supported node", loaded[0].Name);
        Assert.Equal(MacConnectionProtocol.Shadowsocks, loaded[0].Protocol);
        Assert.Equal(MacConnectionProfileStore.SecretAccount, secrets.LastAccount);
        Assert.DoesNotContain("Secure node", secrets.LastValue, StringComparison.Ordinal);
        Assert.DoesNotContain("\"uuid\"", secrets.LastValue, StringComparison.OrdinalIgnoreCase);
        Assert.DoesNotContain("\"transport\"", secrets.LastValue, StringComparison.OrdinalIgnoreCase);
    }

    [Fact]
    public async Task LoadingLegacyVlessJsonPurgesUnsupportedRecordsFromSecretStore()
    {
        var secrets = new MemorySecretStore();
        var store = new MacConnectionProfileStore(secrets);
        await secrets.SetAsync(
            MacConnectionProfileStore.SecretAccount,
            "[{\"id\":2,\"name\":\"Legacy\",\"protocol\":\"vless\",\"uuid\":\"legacy-secret\"},{\"id\":3,\"name\":\"SS\",\"protocol\":\"shadowsocks\",\"server\":\"ss.example.test\",\"method\":\"aes-256-gcm\",\"password\":\"secret\"}]",
            CancellationToken.None);

        var loaded = await store.LoadAsync(CancellationToken.None);

        Assert.Single(loaded);
        Assert.Equal("SS", loaded[0].Name);
        Assert.DoesNotContain("legacy-secret", secrets.LastValue, StringComparison.Ordinal);
        Assert.DoesNotContain("vless", secrets.LastValue, StringComparison.OrdinalIgnoreCase);
        Assert.DoesNotContain("\"uuid\"", secrets.LastValue, StringComparison.OrdinalIgnoreCase);
    }

    private sealed class MemorySecretStore : ISecretStore
    {
        public string LastAccount { get; private set; } = string.Empty;
        public string LastValue { get; private set; } = string.Empty;

        public Task<string?> GetAsync(string name, CancellationToken ct)
            => Task.FromResult<string?>(name == LastAccount ? LastValue : null);

        public Task SetAsync(string name, string value, CancellationToken ct)
        {
            LastAccount = name;
            LastValue = value;
            return Task.CompletedTask;
        }

        public Task DeleteAsync(string name, CancellationToken ct)
        {
            if (name == LastAccount)
                LastValue = string.Empty;
            return Task.CompletedTask;
        }
    }
}
