using System.Text;
using LeikwanBridge.Mac.Services;
using Xunit;

namespace LeikwanBridge.Mac.Tests.Platform;

public sealed class MacFileSecretStoreTests
{
    [Fact]
    public async Task FileStoreRoundTripsValuesAndKeepsTheFilePrivate()
    {
        using var temp = new TemporaryDirectory();
        var path = Path.Combine(temp.Path, "secrets.json");
        var store = new MacFileSecretStore(path);

        await store.SetAsync("desktop-session", "session-canary", CancellationToken.None);
        await store.SetAsync(MacConnectionProfileStore.SecretAccount, "[]", CancellationToken.None);

        Assert.Equal("session-canary", await store.GetAsync("desktop-session", CancellationToken.None));
        Assert.Equal("[]", await store.GetAsync(MacConnectionProfileStore.SecretAccount, CancellationToken.None));
        Assert.Contains("session-canary", File.ReadAllText(path));
        if (!OperatingSystem.IsWindows())
            Assert.Equal(UnixFileMode.UserRead | UnixFileMode.UserWrite, File.GetUnixFileMode(path));

        var reopened = new MacFileSecretStore(path);
        Assert.Equal("session-canary", await reopened.GetAsync("desktop-session", CancellationToken.None));

        await reopened.DeleteAsync("desktop-session", CancellationToken.None);
        Assert.Null(await reopened.GetAsync("desktop-session", CancellationToken.None));
        Assert.Equal("[]", await reopened.GetAsync(MacConnectionProfileStore.SecretAccount, CancellationToken.None));
    }

    [Fact]
    public async Task FileStoreRejectsUnknownAccounts()
    {
        using var temp = new TemporaryDirectory();
        var store = new MacFileSecretStore(Path.Combine(temp.Path, "secrets.json"));

        await Assert.ThrowsAsync<ArgumentException>(() => store.GetAsync("connection-unknown", CancellationToken.None));
        await Assert.ThrowsAsync<ArgumentException>(() => store.SetAsync("connection-unknown", "{}", CancellationToken.None));
        await Assert.ThrowsAsync<ArgumentException>(() => store.DeleteAsync("connection-unknown", CancellationToken.None));
    }

    [Fact]
    public async Task ConnectionServiceStartsWithTheDefaultFileSecretStore()
    {
        using var temp = new TemporaryDirectory();
        var paths = new MacPlatformPaths(temp.Path, Path.Combine(temp.Path, "LeiKwan Bridge.app"));
        var secrets = new MacFileSecretStore(Path.Combine(temp.Path, "secrets.json"));
        await using var service = new MacConnectionService(paths, new MacConnectionProfileStore(secrets));

        await service.InitializeAsync();

        Assert.Empty(service.Profiles);
        Assert.Equal(0, service.Selection.ForwardId);
        Assert.Empty(service.Selection.ForwardProfiles);
    }

    [Fact]
    public async Task RouteSelectionRoundTripsThroughTheFileSecretStore()
    {
        using var temp = new TemporaryDirectory();
        var secretsPath = Path.Combine(temp.Path, "secrets.json");
        var store = new MacConnectionProfileStore(new MacFileSecretStore(secretsPath));

        await store.SaveSelectionAsync(new MacConnectionSelection(59, 4, new Dictionary<int, int>
        {
            [59] = 4,
            [60] = 5,
        }), CancellationToken.None);
        var reloadedStore = new MacConnectionProfileStore(new MacFileSecretStore(secretsPath));
        var selection = await reloadedStore.LoadSelectionAsync(CancellationToken.None);

        Assert.Equal(59, selection.ForwardId);
        Assert.Equal(4, selection.ProfileId);
        Assert.Equal(4, selection.ForwardProfiles[59]);
        Assert.Equal(5, selection.ForwardProfiles[60]);
    }

    [Fact]
    public async Task MigrationImportsLegacyKeychainValuesAndDeletesThemExactlyOnce()
    {
        using var temp = new TemporaryDirectory();
        var path = Path.Combine(temp.Path, "secrets.json");
        var native = new FakeKeychainNative();
        native.Set(MacKeychainSecretStore.ServiceName, "desktop-session", Encoding.UTF8.GetBytes("session-canary"));
        native.Set(MacKeychainSecretStore.ServiceName, "client-agent-7", Encoding.UTF8.GetBytes("agent-canary"));
        native.Set(MacKeychainSecretStore.ServiceName, "connection-profiles", Encoding.UTF8.GetBytes("[]"));
        native.Set(MacKeychainSecretStore.ServiceName, "connection-route-selection", Encoding.UTF8.GetBytes("{}"));
        var store = new MacFileSecretStore(path, native);

        await store.MigrateLegacyAsync(CancellationToken.None);

        Assert.Equal("session-canary", await store.GetAsync("desktop-session", CancellationToken.None));
        Assert.Equal("agent-canary", await store.GetAsync("client-agent-7", CancellationToken.None));
        Assert.Equal("[]", await store.GetAsync("connection-profiles", CancellationToken.None));
        Assert.Equal("{}", await store.GetAsync("connection-route-selection", CancellationToken.None));
        Assert.True(File.Exists(path + ".migrated"));
        Assert.Equal(4, native.DeleteCount);
        Assert.Equal(["desktop-session", "connection-profiles", "connection-route-selection", "client-agent-7"], native.DeletedAccounts);

        var readsAfterFirstSweep = native.ReadCount;
        var reopened = new MacFileSecretStore(path, native);
        await reopened.MigrateLegacyAsync(CancellationToken.None);
        Assert.Equal(readsAfterFirstSweep, native.ReadCount);
    }

    [Fact]
    public async Task MigrationSkipsAccountsAlreadyStoredInTheSecretFile()
    {
        using var temp = new TemporaryDirectory();
        var path = Path.Combine(temp.Path, "secrets.json");
        var native = new FakeKeychainNative();
        native.Set(MacKeychainSecretStore.ServiceName, "desktop-session", Encoding.UTF8.GetBytes("stale-session"));
        var store = new MacFileSecretStore(path, native);
        await store.SetAsync("desktop-session", "fresh-session", CancellationToken.None);

        await store.MigrateLegacyAsync(CancellationToken.None);

        Assert.Equal("fresh-session", await store.GetAsync("desktop-session", CancellationToken.None));
        Assert.DoesNotContain("desktop-session", native.ReadAccounts);
        Assert.True(File.Exists(path + ".migrated"));
    }

    [Fact]
    public async Task UnavailableKeychainLeavesTheMigrationPendingForTheNextLaunch()
    {
        using var temp = new TemporaryDirectory();
        var path = Path.Combine(temp.Path, "secrets.json");
        var native = new FakeKeychainNative { Available = false };
        var store = new MacFileSecretStore(path, native);

        await store.MigrateLegacyAsync(CancellationToken.None);

        Assert.False(File.Exists(path + ".migrated"));
        var readsAfterFirstSweep = native.ReadCount;
        await store.MigrateLegacyAsync(CancellationToken.None);
        Assert.True(native.ReadCount > readsAfterFirstSweep);
    }

    [Fact]
    public async Task BlockingKeychainReadTimesOutWithoutWritingTheMarker()
    {
        using var temp = new TemporaryDirectory();
        using var entered = new ManualResetEventSlim();
        using var release = new ManualResetEventSlim();
        var path = Path.Combine(temp.Path, "secrets.json");
        var store = new MacFileSecretStore(
            path,
            new BlockingKeychainNative(entered, release),
            nativeOperationTimeout: TimeSpan.FromMilliseconds(100));

        var operation = Task.Run(() => store.MigrateLegacyAsync(CancellationToken.None));

        Assert.True(entered.Wait(TimeSpan.FromSeconds(1)));
        try
        {
            await operation.WaitAsync(TimeSpan.FromSeconds(2));
            Assert.False(File.Exists(path + ".migrated"));
        }
        finally
        {
            release.Set();
            await Task.WhenAny(operation, Task.Delay(TimeSpan.FromSeconds(1)));
        }
    }
}
