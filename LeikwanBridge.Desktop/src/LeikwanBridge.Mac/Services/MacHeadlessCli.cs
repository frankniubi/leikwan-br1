using LeikwanBridge.Core.Models;
using LeikwanBridge.Core.Services;

namespace LeikwanBridge.Mac.Services;

/// <summary>Small helpers the CLI needs before the full bootstrap runs.</summary>
public static class MacHeadlessCli
{
    public static string PanelUrl(MacPlatformPaths paths)
    {
        try
        {
            var config = new MacAgentConfigStore(paths, new MacFileSecretStore(
                    MacSecretStoreSelection.SecretsFilePath(paths),
                    new MacKeychainNative()))
                .LoadAsync(CancellationToken.None)
                .GetAwaiter()
                .GetResult();
            var normalized = PanelEndpoints.NormalizeKnownUrl(config.MasterUrl);
            if (!string.IsNullOrWhiteSpace(normalized))
                return normalized.TrimEnd('/');
        }
        catch (Exception exception) when (exception is IOException or UnauthorizedAccessException or InvalidOperationException)
        {
        }

        return PanelEndpoints.Default.Url.TrimEnd('/');
    }
}
