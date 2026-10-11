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

    public static string ResolveEndpointUrl(string? key)
    {
        var normalized = (key ?? string.Empty).Trim();
        return Options.FirstOrDefault(option =>
                   string.Equals(option.Key, normalized, StringComparison.OrdinalIgnoreCase))?.Url
            ?? Default.Url;
    }

    /// <summary>
    /// Returns true when <paramref name="uri"/> has the same scheme, host and
    /// port as one of the known panel endpoints (Cloudflare / Aliyun CDN).
    /// </summary>
    public static bool IsKnownEndpointOrigin(Uri? uri)
    {
        if (uri is null)
            return false;
        return Options.Any(option =>
            Uri.TryCreate(option.Url, UriKind.Absolute, out var origin)
            && SameOrigin(uri, origin));
    }

    /// <summary>
    /// Makes an artifact download follow the selected endpoint. Relative
    /// artifact URLs are resolved against <paramref name="endpointBaseUrl"/>.
    /// Absolute URLs whose origin is one of the known panel endpoints are
    /// rebound onto the selected endpoint origin (keeping path and query) so
    /// mirrors advertised by one site are fetched from the site the user
    /// actually chose. Absolute URLs on other origins (custom CDNs) are kept
    /// untouched.
    /// </summary>
    public static string RebaseArtifactUrl(string? endpointBaseUrl, string? artifactUrl)
    {
        var normalizedArtifact = (artifactUrl ?? string.Empty).Trim();
        if (normalizedArtifact.Length == 0)
            return string.Empty;

        if (Uri.TryCreate(normalizedArtifact, UriKind.Absolute, out var absolute))
        {
            if (!Uri.TryCreate((endpointBaseUrl ?? string.Empty).Trim().TrimEnd('/'), UriKind.Absolute, out var endpoint)
                || !IsKnownEndpointOrigin(endpoint)
                || !IsKnownEndpointOrigin(absolute)
                || SameOrigin(endpoint, absolute))
                return absolute.ToString();

            var rebased = new UriBuilder(absolute)
            {
                Scheme = endpoint.Scheme,
                Host = endpoint.Host,
                Port = endpoint.Port,
            };
            return rebased.Uri.ToString();
        }

        var baseUrl = (endpointBaseUrl ?? string.Empty).Trim().TrimEnd('/');
        return baseUrl.Length == 0
            ? normalizedArtifact
            : baseUrl + "/" + normalizedArtifact.TrimStart('/');
    }

    private static bool SameOrigin(Uri left, Uri right)
        => string.Equals(left.Scheme, right.Scheme, StringComparison.OrdinalIgnoreCase)
            && string.Equals(left.Host, right.Host, StringComparison.OrdinalIgnoreCase)
            && left.Port == right.Port;
}
