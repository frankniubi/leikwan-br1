using LeikwanBridge.Core.Models;
using LeikwanBridge.Core.Services;
using LeikwanBridge.Core.Services.Platform;

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

    public static Func<CancellationToken, Task<DebugManualUploadResult>>? LogUploader(
        MacPlatformPaths paths,
        IClientServiceController controller)
    {
        try
        {
            var secrets = MacSecretStoreSelection.PreferKeychain(paths.ConfigPath)
                ? (ISecretStore)new MacKeychainSecretStore(new MacKeychainNative(), MacSecretStoreSelection.SecretsFilePath(paths))
                : new MacFileSecretStore(MacSecretStoreSelection.SecretsFilePath(paths), new MacKeychainNative());
            var config = new MacAgentConfigStore(paths, secrets)
                .LoadAsync(CancellationToken.None)
                .GetAwaiter()
                .GetResult();
            var masterUrl = PanelEndpoints.NormalizeKnownUrl(config.MasterUrl);
            if (string.IsNullOrWhiteSpace(masterUrl) || string.IsNullOrWhiteSpace(config.AgentToken))
                return null;
            var identity = new MacPlatformIdentityProvider(paths).GetOrCreate();
            return async ct =>
            {
                using var api = new MasterApiClient(masterUrl, config.AgentToken, platformIdentity: identity);
                var uploader = new DebugLogUploader(controller.LogDirectory, config.GetEffectiveStateDir());
                return await uploader.UploadNowAsync(api, ct: ct).ConfigureAwait(false);
            };
        }
        catch (Exception exception) when (exception is IOException or UnauthorizedAccessException or InvalidOperationException)
        {
            return null;
        }
    }
}
