using System.Text.Json.Serialization;
using LeikwanBridge.Core.Models;

namespace LeikwanBridge.Mac.Services;

public static class MacConnectionProtocol
{
    public const string Shadowsocks = "shadowsocks";
    public const string Vless = "vless";
    public const string WireGuard = "wireguard";

    public static IReadOnlyList<string> Supported { get; } = [Shadowsocks, Vless, WireGuard];

    public static bool IsSupported(string? protocol)
        => Supported.Contains(protocol?.Trim().ToLowerInvariant() ?? string.Empty, StringComparer.Ordinal);
}

public enum MacConnectionMode
{
    Proxy,
    Vpn,
}

public enum MacConnectionState
{
    Disconnected,
    Starting,
    Connected,
    Error,
}

public sealed class MacConnectionProfile
{
    [JsonPropertyName("id")]
    public int Id { get; set; }

    [JsonPropertyName("name")]
    public string Name { get; set; } = "";

    [JsonPropertyName("protocol")]
    public string Protocol { get; set; } = MacConnectionProtocol.Shadowsocks;

    [JsonPropertyName("server")]
    public string Server { get; set; } = "";

    [JsonPropertyName("port")]
    public int Port { get; set; } = 443;

    [JsonPropertyName("method")]
    public string Method { get; set; } = "aes-256-gcm";

    [JsonPropertyName("password")]
    public string Password { get; set; } = "";

    [JsonPropertyName("uuid")]
    public string Uuid { get; set; } = "";

    [JsonPropertyName("flow")]
    public string Flow { get; set; } = "";

    [JsonPropertyName("transport")]
    public string Transport { get; set; } = "tcp";

    [JsonPropertyName("host")]
    public string Host { get; set; } = "";

    [JsonPropertyName("path")]
    public string Path { get; set; } = "";

    [JsonPropertyName("service_name")]
    public string ServiceName { get; set; } = "";

    [JsonPropertyName("tls")]
    public bool Tls { get; set; }

    [JsonPropertyName("server_name")]
    public string ServerName { get; set; } = "";

    [JsonPropertyName("reality_public_key")]
    public string RealityPublicKey { get; set; } = "";

    [JsonPropertyName("reality_short_id")]
    public string RealityShortId { get; set; } = "";

    [JsonPropertyName("private_key")]
    public string PrivateKey { get; set; } = "";

    [JsonPropertyName("local_address")]
    public string LocalAddress { get; set; } = "";

    [JsonPropertyName("peer_public_key")]
    public string PeerPublicKey { get; set; } = "";

    [JsonPropertyName("pre_shared_key")]
    public string PreSharedKey { get; set; } = "";

    [JsonPropertyName("allowed_ips")]
    public string AllowedIps { get; set; } = "0.0.0.0/0, ::/0";

    [JsonPropertyName("mtu")]
    public int Mtu { get; set; } = 1420;

    [JsonPropertyName("persistent_keepalive")]
    public int PersistentKeepalive { get; set; } = 25;

    [JsonPropertyName("enabled")]
    public bool Enabled { get; set; } = true;

    [JsonIgnore]
    public string DisplayProtocol => Protocol.Trim().ToUpperInvariant() switch
    {
        "WIREGUARD" => "WireGuard",
        "VLESS" => "VLESS",
        _ => "Shadowsocks",
    };

    public MacConnectionProfile Clone() => (MacConnectionProfile)MemberwiseClone();
}

public sealed record MacConnectionSnapshot(
    MacConnectionState State,
    MacConnectionMode Mode,
    string ActiveProfileName,
    int ProxyPort,
    int BypassAddressCount,
    string ErrorCode)
{
    public static MacConnectionSnapshot Disconnected { get; } = new(
        MacConnectionState.Disconnected,
        MacConnectionMode.Proxy,
        "",
        2080,
        0,
        "");
}

public sealed record MacConnectionConfigResult(
    string Json,
    IReadOnlyList<string> BypassIps,
    IReadOnlyList<string> BypassDomains,
    int ProxyPort);

public interface IMacConnectionService : IAsyncDisposable
{
    event Action<MacConnectionSnapshot>? SnapshotChanged;

    IReadOnlyList<MacConnectionProfile> Profiles { get; }
    MacConnectionSnapshot Snapshot { get; }

    Task InitializeAsync(CancellationToken ct = default);
    Task SaveProfileAsync(MacConnectionProfile profile, CancellationToken ct = default);
    Task DeleteProfileAsync(int profileId, CancellationToken ct = default);
    Task StartAsync(
        MacConnectionMode mode,
        int profileId,
        string panelUrl,
        IEnumerable<ForwardConfig> forwards,
        CancellationToken ct = default);
    Task StopAsync(CancellationToken ct = default);
}
