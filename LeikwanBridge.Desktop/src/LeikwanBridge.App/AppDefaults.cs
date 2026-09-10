using System.IO;
using LeikwanBridge.Core.Services;

namespace LeikwanBridge.App;

public static class AppDefaults
{
    public const string DefaultMasterUrl = "https://v3.leikwanhost.com";
    public const string MainlandMasterUrl = "https://api-leikwanbridge.nbwly.com";
    public const string LegacyMasterUrl = "https://v3-mirror.leikwanhost.com";

    public const string CloudflareEndpointKey = "cloudflare";
    public const string AliyunEndpointKey = "aliyun";

    public sealed record ConnectionEndpoint(string Key, string DisplayName, string Url);

    public static IReadOnlyList<ConnectionEndpoint> ConnectionEndpoints { get; } =
    [
        new(CloudflareEndpointKey, "會聚總站 (Cloudflare)", DefaultMasterUrl),
        new(AliyunEndpointKey, "會聚中國内地 (Aliyun CDN)", MainlandMasterUrl),
    ];

    public static string NormalizeMasterUrl(string? value)
    {
        var normalized = (value ?? string.Empty).Trim().TrimEnd('/');
        return string.Equals(normalized, LegacyMasterUrl, StringComparison.OrdinalIgnoreCase)
            ? DefaultMasterUrl
            : normalized;
    }

    public static string ResolveConnectionEndpoint(string? key)
    {
        var normalized = key?.Trim() ?? string.Empty;
        return ConnectionEndpoints.FirstOrDefault(endpoint =>
                   string.Equals(endpoint.Key, normalized, StringComparison.OrdinalIgnoreCase))?.Url
               ?? DefaultMasterUrl;
    }

    public static string GetConnectionEndpointKey(string? url)
    {
        var normalized = NormalizeMasterUrl(url);
        return ConnectionEndpoints.FirstOrDefault(endpoint =>
                   string.Equals(endpoint.Url, normalized, StringComparison.OrdinalIgnoreCase))?.Key
               ?? CloudflareEndpointKey;
    }

    public static string GetInstallRoot()
    {
        return ResolveInstallRoot();
    }

    public static string ResolveInstallRoot(string? installStatePathOverride = null)
    {
        var overridePath = Environment.GetEnvironmentVariable("LEIKWAN_INSTALL_DIR");
        if (!string.IsNullOrWhiteSpace(overridePath))
            return overridePath.Trim();

        var installStatePath = installStatePathOverride
            ?? Path.Combine(AppContext.BaseDirectory, "install-state.json");
        var installRoot = Path.GetDirectoryName(installStatePath);
        if (!string.IsNullOrWhiteSpace(installRoot))
        {
            var state = WindowsAppUpdateService.LoadInstallState(installRoot);
            if (state is not null && !string.IsNullOrWhiteSpace(state.InstallDir))
                return state.InstallDir.Trim();
        }

        var programFiles = Environment.GetFolderPath(Environment.SpecialFolder.ProgramFiles);
        return Path.Combine(programFiles, "LeiKwan Bridge");
    }
}
