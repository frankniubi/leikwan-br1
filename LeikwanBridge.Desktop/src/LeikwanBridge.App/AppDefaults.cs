using System.IO;
using LeikwanBridge.Core.Services;

namespace LeikwanBridge.App;

// The GUI shell keeps its own endpoint list, but every value that must match
// the windowless client and the installers comes from ClientDefaults.
public static class AppDefaults
{
    public const string DefaultMasterUrl = ClientDefaults.DefaultMasterUrl;
    public const string MainlandMasterUrl = ClientDefaults.MainlandMasterUrl;
    public const string LegacyMasterUrl = ClientDefaults.LegacyMasterUrl;

    public const string CloudflareEndpointKey = "cloudflare";
    public const string AliyunEndpointKey = "aliyun";

    public sealed record ConnectionEndpoint(string Key, string DisplayName, string Url);

    public static IReadOnlyList<ConnectionEndpoint> ConnectionEndpoints { get; } =
    [
        new(CloudflareEndpointKey, "會聚總站 (Cloudflare)", DefaultMasterUrl),
        new(AliyunEndpointKey, "會聚中國内地 (Aliyun CDN)", MainlandMasterUrl),
    ];

    public static string NormalizeMasterUrl(string? value) => ClientDefaults.NormalizeMasterUrl(value);

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

    public static string GetInstallRoot() => ClientDefaults.ResolveInstallRoot();

    public static string ResolveInstallRoot(string? installStatePathOverride = null)
        => ClientDefaults.ResolveInstallRoot(installStatePathOverride);
}
