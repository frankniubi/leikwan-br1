using LeikwanBridge.Core.Services.Platform;
using LeikwanBridge.Mac.Services;
using Xunit;

namespace LeikwanBridge.Mac.Tests.Services;

public sealed class MacConnectionProfileStoreTests
{
    [Fact]
    public async Task ProfilesAreStoredInSecretStoreAndRoundTripWithoutDiskPath()
    {
        var secrets = new MemorySecretStore();
        var store = new MacConnectionProfileStore(secrets);
        var profile = new MacConnectionProfile
        {
            Id = 2,
            Name = "Secure node",
            Protocol = MacConnectionProtocol.Vless,
            Server = "node.example.test",
            Port = 443,
            Uuid = "uuid-secret",
            ServerName = "node.example.test",
        };

        await store.SaveAsync([profile], CancellationToken.None);
        var loaded = await store.LoadAsync(CancellationToken.None);

        Assert.Single(loaded);
        Assert.Equal("uuid-secret", loaded[0].Uuid);
        Assert.Equal(MacConnectionProfileStore.SecretAccount, secrets.LastAccount);
        Assert.Contains("uuid-secret", secrets.LastValue, StringComparison.Ordinal);
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
