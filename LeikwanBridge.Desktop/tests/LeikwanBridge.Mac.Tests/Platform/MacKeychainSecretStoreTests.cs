using LeikwanBridge.Mac.Services;
using Xunit;

namespace LeikwanBridge.Mac.Tests.Platform;

// Covers the opt-in legacy keychain backend. Since the file store became
// the default, these scenarios only exercise the explicit "use_mac_keychain"
// mode plus its file fallback.
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
    public async Task ConnectionProfilesUseAValidKeychainAccount()
    {
        using var temp = new TemporaryDirectory();
        var store = new MacKeychainSecretStore(
            new FakeKeychainNative(),
            Path.Combine(temp.Path, "fallback-secrets.json"));

        await store.SetAsync(MacConnectionProfileStore.SecretAccount, "[]", CancellationToken.None);

        Assert.Equal("[]", await store.GetAsync(MacConnectionProfileStore.SecretAccount, CancellationToken.None));
    }

    [Fact]
    public async Task UnknownConnectionAccountsRemainRejected()
    {
        using var temp = new TemporaryDirectory();
        var store = new MacKeychainSecretStore(
            new FakeKeychainNative(),
            Path.Combine(temp.Path, "fallback-secrets.json"));

        await Assert.ThrowsAsync<ArgumentException>(() => store.GetAsync("connection-unknown", CancellationToken.None));
        await Assert.ThrowsAsync<ArgumentException>(() => store.SetAsync("connection-unknown", "{}", CancellationToken.None));
        await Assert.ThrowsAsync<ArgumentException>(() => store.DeleteAsync("connection-unknown", CancellationToken.None));
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
    public async Task UnavailableKeychainIsNotRetriedForEachStartupSecret()
    {
        using var temp = new TemporaryDirectory();
        var native = new FakeKeychainNative { Available = false };
        var store = new MacKeychainSecretStore(
            native,
            Path.Combine(temp.Path, "fallback-secrets.json"));

        Assert.Null(await store.GetAsync("desktop-session", CancellationToken.None));
        Assert.Null(await store.GetAsync("client-agent-42", CancellationToken.None));

        Assert.Equal(1, native.ReadCount);
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

    [Fact]
    public async Task BlockingNativeReadTimesOutAndFallsBackWithoutBlockingCaller()
    {
        using var temp = new TemporaryDirectory();
        using var entered = new ManualResetEventSlim();
        using var release = new ManualResetEventSlim();
        var native = new BlockingKeychainNative(entered, release);
        var store = new MacKeychainSecretStore(
            native,
            Path.Combine(temp.Path, "fallback-secrets.json"),
            nativeOperationTimeout: TimeSpan.FromMilliseconds(100));

        var operation = Task.Run(() => store.GetAsync("desktop-session", CancellationToken.None));

        Assert.True(entered.Wait(TimeSpan.FromSeconds(1)));
        try
        {
            Assert.Same(operation, await Task.WhenAny(operation, Task.Delay(TimeSpan.FromSeconds(1))));
            Assert.Null(await operation);
            Assert.True(store.UsesFallback);
        }
        finally
        {
            release.Set();
            await Task.WhenAny(operation, Task.Delay(TimeSpan.FromSeconds(1)));
        }
    }
}
