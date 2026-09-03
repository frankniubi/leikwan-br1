using System.Text.Json.Serialization;
using LeikwanBridge.Core.Models;

namespace LeikwanBridge.Mac.Services;

public static class MacConnectionProtocol
{
    public const string Shadowsocks = "shadowsocks";
    public const string WireGuard = "wireguard";

    // Legacy protocol values are intentionally absent from this allowlist.
    public static IReadOnlyList<string> Supported { get; } = [Shadowsocks, WireGuard];

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
        "SHADOWSOCKS" => "Shadowsocks",
        _ => "Unsupported",
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
    DateTimeOffset? ActiveNativeLeaseExpiresAt { get; }
    string? ActiveNativeLeasePolicyGeneration { get; }
    bool IsNativeOutboundActive { get; }

    Task InitializeAsync(CancellationToken ct = default);
    Task SaveProfileAsync(MacConnectionProfile profile, CancellationToken ct = default);
    Task DeleteProfileAsync(int profileId, CancellationToken ct = default);
    Task StartAsync(
        MacConnectionMode mode,
        int profileId,
        string panelUrl,
        IEnumerable<ForwardConfig> forwards,
        CancellationToken ct = default);
    Task StartNativeAsync(
        MacConnectionMode mode,
        NativeOutboundLease lease,
        string panelUrl,
        IEnumerable<ForwardConfig> forwards,
        bool bypassChinaMainland = false,
        CancellationToken ct = default);
    Task<bool> TryRefreshNativeAsync(
        NativeOutboundLease lease,
        CancellationToken ct = default);
    Task StopAsync(CancellationToken ct = default);
}
