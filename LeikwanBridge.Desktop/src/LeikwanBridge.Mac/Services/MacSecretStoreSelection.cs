using System.Text.Json;
using LeikwanBridge.Core.Models;

namespace LeikwanBridge.Mac.Services;

// Chooses the secret backend at startup. The default is the private file
// store; setting "use_mac_keychain": true in config.json opts back into the
// legacy login-keychain store explicitly.
public static class MacSecretStoreSelection
{
    public const string SecretsFileName = "secrets.json";
    public const string LegacyFallbackFileName = "fallback-secrets.json";

    private static readonly JsonSerializerOptions CaseInsensitiveOptions = new()
    {
        PropertyNameCaseInsensitive = true,
    };

    public static string SecretsFilePath(MacPlatformPaths paths)
        => paths.Root.TrimEnd('/', '\\') + "/" + SecretsFileName;

    public static bool PreferKeychain(string configPath)
    {
        try
        {
            if (!File.Exists(configPath))
                return false;
            var config = JsonSerializer.Deserialize<AgentConfig>(File.ReadAllBytes(configPath), CaseInsensitiveOptions)
                ?? new AgentConfig();
            return config.UseMacKeychain;
        }
        catch (JsonException)
        {
            return false;
        }
        catch (IOException)
        {
            return false;
        }
    }

    // Older releases that fell back from the keychain stored secrets in
    // fallback-secrets.json. Merge those values into the shared secret file
    // without overwriting anything, then remove the legacy file. Returns true
    // when at least one value was merged.
    public static bool MergeLegacyFallbackFile(string secretsFilePath)
    {
        var directory = Path.GetDirectoryName(secretsFilePath);
        if (string.IsNullOrWhiteSpace(directory))
            return false;
        var legacyPath = Path.Combine(directory, LegacyFallbackFileName);
        if (string.Equals(
                Path.GetFullPath(legacyPath),
                Path.GetFullPath(secretsFilePath),
                StringComparison.OrdinalIgnoreCase)
            || !File.Exists(legacyPath))
            return false;

        var current = MacFileSecretStore.ReadFile(secretsFilePath);
        var legacy = MacFileSecretStore.ReadFile(legacyPath);
        var merged = false;
        foreach (var pair in legacy)
        {
            if (current.TryAdd(pair.Key, pair.Value))
                merged = true;
        }
        if (merged)
            MacFileSecretStore.WriteFile(secretsFilePath, current);
        try
        {
            File.Delete(legacyPath);
        }
        catch (IOException)
        {
        }
        return merged;
    }
}
