using LeikwanBridge.Mac.Services;
using Xunit;

namespace LeikwanBridge.Mac.Tests.Platform;

public sealed class MacKeychainSecretStoreTests
{
    [Fact]
    public async Task KeychainRoundTripClearsTheNativeInputBuffer()
    {
        using var temp = new TemporaryDirectory();
        var native = new FakeKeychainNative();
        var store = new MacKeychainSecretStore(native, Path.Combine(temp.Path, "fallback-secrets.json"));

        await store.SetAsync("desktop-session", "session-canary", CancellationToken.None);

        Assert.Equal("session-canary", await store.GetAsync("desktop-session", CancellationToken.None));
        Assert.NotNull(native.LastSetBuffer);
        Assert.All(native.LastSetBuffer!, value => Assert.Equal(0, value));
        Assert.False(store.UsesFallback);
    }

    [Fact]
    public async Task UnavailableKeychainUsesOnlyThePrivateFallbackFile()
    {
        using var temp = new TemporaryDirectory();
        var fallbackPath = Path.Combine(temp.Path, "fallback-secrets.json");
        var store = new MacKeychainSecretStore(new FakeKeychainNative { Available = false }, fallbackPath);
        var warnings = new List<string>();
        store.WarningRaised += warnings.Add;

        await store.SetAsync("client-agent-42", "agent-token-canary", CancellationToken.None);

        Assert.Equal("agent-token-canary", await store.GetAsync("client-agent-42", CancellationToken.None));
        Assert.True(store.UsesFallback);
        Assert.Equal(["keychain_unavailable"], warnings);
        Assert.Contains("agent-token-canary", File.ReadAllText(fallbackPath));
        if (!OperatingSystem.IsWindows())
            Assert.Equal(UnixFileMode.UserRead | UnixFileMode.UserWrite, File.GetUnixFileMode(fallbackPath));
    }

    [Fact]
    public async Task RecoveredKeychainMigratesAnExistingFallbackValue()
    {
        using var temp = new TemporaryDirectory();
        var fallbackPath = Path.Combine(temp.Path, "fallback-secrets.json");
        var native = new FakeKeychainNative { Available = false };
        var unavailableStore = new MacKeychainSecretStore(native, fallbackPath);
        await unavailableStore.SetAsync("desktop-session", "session-canary", CancellationToken.None);
        native.Available = true;
        var recoveredStore = new MacKeychainSecretStore(native, fallbackPath);

        Assert.Equal("session-canary", await recoveredStore.GetAsync("desktop-session", CancellationToken.None));
        Assert.False(File.Exists(fallbackPath));
        Assert.False(recoveredStore.UsesFallback);
    }

    private sealed class FakeKeychainNative : IMacKeychainNative
    {
        private readonly Dictionary<string, byte[]> _values = [];
        public bool Available { get; set; } = true;
        public byte[]? LastSetBuffer { get; private set; }

        public MacKeychainReadResult Read(string service, string account)
        {
            if (!Available)
                return new MacKeychainReadResult(MacKeychainStatus.Unavailable, []);
            return _values.TryGetValue(account, out var value)
                ? new MacKeychainReadResult(MacKeychainStatus.Success, value.ToArray())
                : new MacKeychainReadResult(MacKeychainStatus.NotFound, []);
        }

        public MacKeychainStatus Set(string service, string account, byte[] secret)
        {
            if (!Available)
                return MacKeychainStatus.Unavailable;
            LastSetBuffer = secret;
            _values[account] = secret.ToArray();
            return MacKeychainStatus.Success;
        }

        public MacKeychainStatus Delete(string service, string account)
        {
            if (!Available)
                return MacKeychainStatus.Unavailable;
            _values.Remove(account);
            return MacKeychainStatus.Success;
        }
    }
}
