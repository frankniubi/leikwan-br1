using System.Text.Json.Serialization;
using System.Net;

namespace LeikwanBridge.Core.Models;

/// <summary>
/// Protocol identifiers exposed by the Windows editor.  SS2022 is kept as a
/// separate editor option even though sing-box uses the same shadowsocks
/// outbound type for both variants.
/// </summary>
public static class WindowsProxyProtocol
{
    public const string Shadowsocks = "ss";
    public const string Shadowsocks2022 = "ss2022";
    public const string Vless = "vless";
    public const string External = "external";

    public static IReadOnlyList<string> Supported { get; } =
        [Shadowsocks, Shadowsocks2022, Vless, External];

    public static IReadOnlyList<string> ShadowsocksMethodOptions { get; } =
    [
        // Legacy Shadowsocks/ss-libev ciphers.  These are intentionally kept
        // in the same selector as AEAD and SS2022 so imported classic links do
        // not get rejected before sing-box sees them.
        "none",
        "aes-128-ctr",
        "aes-192-ctr",
        "aes-256-ctr",
        "aes-128-cfb",
        "aes-192-cfb",
        "aes-256-cfb",
        "chacha20-ietf",
        "rc4-md5",
        "aes-128-gcm",
        "aes-192-gcm",
        "aes-256-gcm",
        "chacha20-ietf-poly1305",
        "xchacha20-ietf-poly1305",
        "2022-blake3-aes-128-gcm",
        "2022-blake3-aes-256-gcm",
        "2022-blake3-chacha20-poly1305",
    ];

    public static IReadOnlyList<string> VlessTransportOptions { get; } =
        ["tcp", "ws", "grpc"];

    public static string Normalize(string? value)
    {
        var normalized = value?.Trim().ToLowerInvariant() ?? string.Empty;
        return normalized switch
        {
            "shadowsocks" or "socks" => Shadowsocks,
            "shadowsocks2022" or "shadowsocks-2022" => Shadowsocks2022,
            _ => normalized,
        };
    }

    public static bool IsSupported(string? value)
        => Supported.Contains(Normalize(value), StringComparer.Ordinal);

    public static bool IsShadowsocks(string? value)
    {
        var normalized = Normalize(value);
        return normalized is Shadowsocks or Shadowsocks2022;
    }

    public static bool IsShadowsocks2022Method(string? method)
        => method?.Trim().StartsWith("2022-", StringComparison.OrdinalIgnoreCase) == true;

    public static bool IsSupportedShadowsocksMethod(string? method)
        => !string.IsNullOrWhiteSpace(method)
            && ShadowsocksMethodOptions.Contains(method.Trim(), StringComparer.OrdinalIgnoreCase);
}

public enum WindowsProxyMode
{
    Http,
    Tun,
    Process,
}

public enum WindowsProxyDnsMode
{
    Remote,
    Custom,
}

public static class WindowsProxyDns
{
    public const string DefaultCustomServer = "8.8.8.8";

    public static WindowsProxyDnsMode NormalizeMode(string? value)
        => Enum.TryParse<WindowsProxyDnsMode>(value?.Trim(), true, out var mode)
            ? mode
            : WindowsProxyDnsMode.Remote;

    public static IReadOnlyList<string> ParseServers(string? value)
        => NormalizeServers((value ?? string.Empty).Split(
            [',', ';', '\r', '\n', '\t', ' '],
            StringSplitOptions.RemoveEmptyEntries | StringSplitOptions.TrimEntries));

    public static IReadOnlyList<string> NormalizeServers(IEnumerable<string>? values)
    {
        var result = new List<string>();
        foreach (var value in values ?? [])
        {
            var candidate = value?.Trim() ?? string.Empty;
            if (candidate.Length == 0)
                continue;
            if (!IPAddress.TryParse(candidate, out var address)
                || address.AddressFamily is not (System.Net.Sockets.AddressFamily.InterNetwork
                    or System.Net.Sockets.AddressFamily.InterNetworkV6))
                throw new InvalidOperationException("proxy_dns_server_invalid");
            var normalized = address.ToString();
            if (!result.Contains(normalized, StringComparer.OrdinalIgnoreCase))
                result.Add(normalized);
            if (result.Count > 8)
                throw new InvalidOperationException("proxy_dns_server_limit");
        }
        return result;
    }
}

public enum WindowsProxyConnectionState
{
    Disconnected,
    Starting,
    Connected,
    Error,
}

public sealed class WindowsProxyProfile
{
    [JsonPropertyName("id")]
    public int Id { get; set; }

    [JsonPropertyName("name")]
    public string Name { get; set; } = "";

    [JsonPropertyName("protocol")]
    public string Protocol { get; set; } = WindowsProxyProtocol.Shadowsocks;

    [JsonPropertyName("server")]
    public string Server { get; set; } = "";

    [JsonPropertyName("port")]
    public int Port { get; set; } = 443;

    [JsonPropertyName("method")]
    public string Method { get; set; } = "aes-256-gcm";

    [JsonPropertyName("password")]
    public string Password { get; set; } = "";

    [JsonPropertyName("udp_over_tcp")]
    public bool UdpOverTcp { get; set; }

    [JsonPropertyName("uuid")]
    public string Uuid { get; set; } = "";

    [JsonPropertyName("flow")]
    public string Flow { get; set; } = "";

    [JsonPropertyName("transport")]
    public string Transport { get; set; } = "tcp";

    [JsonPropertyName("host")]
    public string Host { get; set; } = "";

    [JsonPropertyName("path")]
    public string Path { get; set; } = "/";

    [JsonPropertyName("service_name")]
    public string ServiceName { get; set; } = "";

    [JsonPropertyName("tls")]
    public bool Tls { get; set; }

    [JsonPropertyName("server_name")]
    public string ServerName { get; set; } = "";

    [JsonPropertyName("fingerprint")]
    public string Fingerprint { get; set; } = "";

    [JsonPropertyName("reality_public_key")]
    public string RealityPublicKey { get; set; } = "";

    [JsonPropertyName("reality_short_id")]
    public string RealityShortId { get; set; } = "";

    [JsonPropertyName("alpn")]
    public string Alpn { get; set; } = "";

    [JsonPropertyName("allow_insecure")]
    public bool AllowInsecure { get; set; }

    [JsonPropertyName("enabled")]
    public bool Enabled { get; set; } = true;

    [JsonIgnore]
    public bool IsShadowsocks => WindowsProxyProtocol.IsShadowsocks(Protocol);

    [JsonIgnore]
    public bool IsExternal => WindowsProxyProtocol.Normalize(Protocol) == WindowsProxyProtocol.External;

    [JsonIgnore]
    public bool IsShadowsocks2022 => string.Equals(
        WindowsProxyProtocol.Normalize(Protocol),
        WindowsProxyProtocol.Shadowsocks2022,
        StringComparison.Ordinal);

    [JsonIgnore]
    public bool IsVless => string.Equals(
        WindowsProxyProtocol.Normalize(Protocol),
        WindowsProxyProtocol.Vless,
        StringComparison.Ordinal);

    [JsonIgnore]
    public string DisplayProtocol => WindowsProxyProtocol.Normalize(Protocol) switch
    {
        WindowsProxyProtocol.Shadowsocks2022 => "SS2022",
        WindowsProxyProtocol.Vless => "VLESS",
        WindowsProxyProtocol.External => "External",
        _ => "Shadowsocks",
    };

    [JsonIgnore]
    public string DisplayEndpoint => string.IsNullOrWhiteSpace(Server)
        ? DisplayProtocol
        : $"{DisplayProtocol} | {Server}:{Port}";

    public WindowsProxyProfile Clone() => (WindowsProxyProfile)MemberwiseClone();
}

public sealed class WindowsProxyGameProfile
{
    [JsonPropertyName("id")]
    public string Id { get; set; } = "";

    [JsonPropertyName("display_name")]
    public string DisplayName { get; set; } = "";

    [JsonPropertyName("process_patterns")]
    public List<string> ProcessPatterns { get; set; } = [];

    [JsonPropertyName("patterns_are_regex")]
    public bool PatternsAreRegex { get; set; }

    [JsonPropertyName("source")]
    public string Source { get; set; } = "custom";

    public WindowsProxyGameProfile Clone() => new()
    {
        Id = Id,
        DisplayName = DisplayName,
        ProcessPatterns = [.. ProcessPatterns],
        PatternsAreRegex = PatternsAreRegex,
        Source = Source,
    };
}

public sealed record WindowsProxyGameDefinition(
    string Id,
    string DisplayName,
    IReadOnlyList<string> ProcessNames,
    bool PatternsAreRegex = false,
    bool IsCustom = false,
    string Source = "builtin");

public sealed record WindowsProxyGameOption(
    string Id,
    string DisplayName,
    IReadOnlyList<string> ProcessNames,
    bool IsRunning,
    bool PatternsAreRegex = false,
    bool IsCustom = false,
    string Source = "builtin")
{
    public string StatusText => IsRunning ? "运行中" : "未运行";
    public string DisplayLabel => IsRunning ? $"{DisplayName}  ·  {StatusText}" : DisplayName;
}

public sealed record WindowsProxySnapshot(
    WindowsProxyConnectionState State,
    WindowsProxyMode Mode,
    string ActiveProfileName,
    int ProxyPort,
    int BypassAddressCount,
    bool SystemProxyEnabled,
    bool ProcessProxyEnabled,
    string ErrorCode)
{
    public static WindowsProxySnapshot Disconnected { get; } = new(
        WindowsProxyConnectionState.Disconnected,
        WindowsProxyMode.Http,
        "",
        2080,
        0,
        false,
        false,
        "");
}

public sealed record WindowsProxyConfigResult(
    string Json,
    IReadOnlyList<string> BypassIps,
    IReadOnlyList<string> BypassDomains,
    int ProxyPort,
    string TunGateway);

public sealed record WindowsProcessProxyRule(string Pattern, bool Bypass = false);
