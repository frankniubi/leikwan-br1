namespace LeikwanBridge.Core.Models;

public sealed record PanelEndpointOption(string Key, string Label, string Url)
{
    public override string ToString() => Label;
}

public static class PanelEndpoints
{
    public const string CloudflareUrl = "https://v3.leikwanhost.com";
    public const string AliyunCdnUrl = "https://api-leikwanbridge.nbwly.com";

    public static IReadOnlyList<PanelEndpointOption> Options { get; } =
    [
        new("cloudflare", "會聚總站 (Cloudflare)", CloudflareUrl),
        new("aliyun-cdn", "會聚中國内地 (Aliyun CDN)", AliyunCdnUrl),
    ];

    public static PanelEndpointOption Default => Options[0];

    public static PanelEndpointOption ForUrl(string? url)
    {
        var normalized = (url ?? string.Empty).Trim().TrimEnd('/');
        return Options.FirstOrDefault(option =>
                   string.Equals(option.Url, normalized, StringComparison.OrdinalIgnoreCase))
            ?? Default;
    }

    public static string NormalizeKnownUrl(string? url)
    {
        var normalized = (url ?? string.Empty).Trim().TrimEnd('/');
        return Options.FirstOrDefault(option =>
                   string.Equals(option.Url, normalized, StringComparison.OrdinalIgnoreCase))?.Url
            ?? normalized;
    }
}
