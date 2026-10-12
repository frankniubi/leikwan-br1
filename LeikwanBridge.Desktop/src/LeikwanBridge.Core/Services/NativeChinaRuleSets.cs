using System.Text.Json.Nodes;

namespace LeikwanBridge.Core.Services;

/// <summary>Absolute paths of the bundled geoip-cn / geosite-cn rule-sets.</summary>
public sealed record NativeChinaRuleSetFiles(string GeoIpPath, string GeoSitePath);

/// <summary>
/// Declares the geoip-cn / geosite-cn sing-box rule-sets used by native
/// outbound routing. The clients ship both files next to sing-box (bin\ on
/// Windows), so startup never touches GitHub: sing-box 1.13 keeps every
/// inbound closed until the initial remote rule-set download finishes, and
/// raw.githubusercontent.com is slow or unreachable from the mainland and
/// through some native exits. The remote definition is only a fallback for
/// layouts that predate the bundled files.
/// </summary>
public static class NativeChinaRuleSets
{
    public const string GeoIpTag = "geoip-cn";
    public const string GeoSiteTag = "geosite-cn";
    public const string GeoIpFileName = "geoip-cn.srs";
    public const string GeoSiteFileName = "geosite-cn.srs";
    public const string RemoteDownloadHost = "raw.githubusercontent.com";

    private static ReadOnlySpan<byte> BinaryMagic => "SRS"u8;

    /// <summary>
    /// Returns both bundled rule-sets when <paramref name="directory"/> holds
    /// two readable sing-box binary rule-sets, otherwise null so callers fall
    /// back to the remote definitions.
    /// </summary>
    public static NativeChinaRuleSetFiles? TryResolve(string? directory)
    {
        if (string.IsNullOrWhiteSpace(directory))
            return null;
        try
        {
            var root = Path.GetFullPath(directory);
            var geoIp = Path.Combine(root, GeoIpFileName);
            var geoSite = Path.Combine(root, GeoSiteFileName);
            return IsBinaryRuleSet(geoIp) && IsBinaryRuleSet(geoSite)
                ? new NativeChinaRuleSetFiles(geoIp, geoSite)
                : null;
        }
        catch (Exception ex) when (ex is ArgumentException or IOException
            or UnauthorizedAccessException or NotSupportedException)
        {
            return null;
        }
    }

    /// <summary>
    /// Builds the route.rule_set array: local binary rule-sets when
    /// <paramref name="localFiles"/> is available, else the remote fallback
    /// downloaded through <paramref name="downloadDetour"/>.
    /// </summary>
    public static JsonArray BuildDefinitions(NativeChinaRuleSetFiles? localFiles, string downloadDetour)
    {
        if (localFiles is not null)
        {
            return new JsonArray
            {
                Local(GeoIpTag, localFiles.GeoIpPath),
                Local(GeoSiteTag, localFiles.GeoSitePath),
            };
        }
        downloadDetour = string.Equals(downloadDetour, "direct", StringComparison.OrdinalIgnoreCase)
            ? "direct"
            : "proxy";
        return new JsonArray
        {
            Remote(GeoIpTag, $"https://{RemoteDownloadHost}/SagerNet/sing-geoip/rule-set/{GeoIpFileName}", downloadDetour),
            Remote(GeoSiteTag, $"https://{RemoteDownloadHost}/SagerNet/sing-geosite/rule-set/{GeoSiteFileName}", downloadDetour),
        };
    }

    private static JsonObject Local(string tag, string path) => new()
    {
        ["tag"] = tag,
        ["type"] = "local",
        ["format"] = "binary",
        ["path"] = path,
    };

    private static JsonObject Remote(string tag, string url, string downloadDetour) => new()
    {
        ["tag"] = tag,
        ["type"] = "remote",
        ["format"] = "binary",
        ["url"] = url,
        ["download_detour"] = downloadDetour,
    };

    private static bool IsBinaryRuleSet(string path)
    {
        if (!File.Exists(path))
            return false;
        // A truncated or foreign file would make sing-box refuse the whole
        // config; only accept files that carry the SRS binary header.
        Span<byte> header = stackalloc byte[4];
        using var stream = new FileStream(path, FileMode.Open, FileAccess.Read, FileShare.Read);
        return stream.ReadAtLeast(header, header.Length, throwOnEndOfStream: false) == header.Length
            && header[..3].SequenceEqual(BinaryMagic);
    }
}
