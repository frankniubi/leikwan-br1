using LeikwanBridge.Mac.Services;
using Xunit;

namespace LeikwanBridge.Mac.Tests.Platform;

public sealed class MacSecretStoreSelectionTests
{
    [Fact]
    public void FileStorageIsTheDefaultSecretBackend()
    {
        using var temp = new TemporaryDirectory();
        var configPath = Path.Combine(temp.Path, "config.json");

        Assert.False(MacSecretStoreSelection.PreferKeychain(configPath));

        File.WriteAllText(configPath, "{\"master_url\":\"https://panel.example.test\"}");
        Assert.False(MacSecretStoreSelection.PreferKeychain(configPath));
    }

    [Fact]
    public void UseMacKeychainConfigSwitchesToTheKeychainBackend()
    {
        using var temp = new TemporaryDirectory();
        var configPath = Path.Combine(temp.Path, "config.json");

        File.WriteAllText(configPath, "{\"use_mac_keychain\":true}");
        Assert.True(MacSecretStoreSelection.PreferKeychain(configPath));

        File.WriteAllText(configPath, "{\"use_mac_keychain\":false}");
        Assert.False(MacSecretStoreSelection.PreferKeychain(configPath));
    }

    [Fact]
    public void MalformedConfigFallsBackToFileStorage()
    {
        using var temp = new TemporaryDirectory();
        var configPath = Path.Combine(temp.Path, "config.json");
        File.WriteAllText(configPath, "{not json");

        Assert.False(MacSecretStoreSelection.PreferKeychain(configPath));
    }

    [Fact]
    public async Task LegacyFallbackFileIsMergedWithoutOverwritingExistingValues()
    {
        using var temp = new TemporaryDirectory();
        var secretsPath = Path.Combine(temp.Path, MacSecretStoreSelection.SecretsFileName);
        File.WriteAllText(
            Path.Combine(temp.Path, MacSecretStoreSelection.LegacyFallbackFileName),
            "{\"desktop-session\":\"legacy-session\",\"client-agent-7\":\"legacy-agent\"}");
        File.WriteAllText(secretsPath, "{\"desktop-session\":\"current-session\"}");

        MacSecretStoreSelection.MergeLegacyFallbackFile(secretsPath);

        var store = new MacFileSecretStore(secretsPath);
        Assert.Equal("current-session", await store.GetAsync("desktop-session", CancellationToken.None));
        Assert.Equal("legacy-agent", await store.GetAsync("client-agent-7", CancellationToken.None));
        Assert.False(File.Exists(Path.Combine(temp.Path, MacSecretStoreSelection.LegacyFallbackFileName)));
    }

    [Fact]
    public void MissingLegacyFallbackFileIsANoOp()
    {
        using var temp = new TemporaryDirectory();
        var secretsPath = Path.Combine(temp.Path, MacSecretStoreSelection.SecretsFileName);

        Assert.False(MacSecretStoreSelection.MergeLegacyFallbackFile(secretsPath));
        Assert.False(File.Exists(secretsPath));
    }
}
