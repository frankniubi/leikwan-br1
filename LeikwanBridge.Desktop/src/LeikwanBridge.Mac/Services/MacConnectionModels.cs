using System.Text.Json.Serialization;
using LeikwanBridge.Core.Models;

namespace LeikwanBridge.Mac.Services;

public static class MacConnectionProtocol
{
    public const string Shadowsocks = "shadowsocks";
    public const string WireGuard = "wireguard";
    public const string External = "external";

    /// <summary>SOCKS5 / VLESS nodes come from share links or the panel's hosted nodes (Core UserNode).</summary>
    public const string Socks5 = "socks5";
    public const string Vless = "vless";

    // Legacy protocol values are intentionally absent from this allowlist: a SOCKS5 / VLESS
    // profile is only valid with a share link (see IsStorable); legacy field-based VLESS
    // records stay unsupported and are purged.
    public static IReadOnlyList<string> Supported { get; } = [Shadowsocks, WireGuard, External];

    /// <summary>Protocols the field editor can create; SOCKS5 / VLESS are imported as share links.</summary>
    public static IReadOnlyList<string> Editable { get; } = [Shadowsocks, WireGuard, External];

    public static bool IsSupported(string? protocol)
        => Supported.Contains(protocol?.Trim().ToLowerInvariant() ?? string.Empty, StringComparer.Ordinal);

    /// <summary>Whether a saved profile can be kept: a supported protocol, or a share-link node.</summary>
    public static bool IsStorable(MacConnectionProfile? profile)
        => profile is not null
            && (IsSupported(profile.Protocol)
                || (profile.HasShareLink
                    && profile.Protocol?.Trim().ToLowerInvariant() is Socks5 or Vless));
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

    /// <summary>Panel node id once the node is hosted ("上云"); null = only on this Mac.</summary>
    [JsonPropertyName("hosted_id")]
    public long? HostedId { get; set; }

    /// <summary>
    /// Canonical share link (Core UserNodeShareLink) for nodes the field editor cannot express:
    /// SOCKS5, VLESS, and Shadowsocks with a SIP003 plugin. The sing-box outbound is built from it.
    /// </summary>
    [JsonPropertyName("share_link")]
    public string ShareLink { get; set; } = "";

    [JsonIgnore]
    public bool IsHosted => HostedId is > 0;

    [JsonIgnore]
    public bool HasShareLink => !string.IsNullOrWhiteSpace(ShareLink);

    [JsonIgnore]
    public string DisplayProtocol => Protocol.Trim().ToUpperInvariant() switch
    {
        "WIREGUARD" => "WireGuard",
        "SHADOWSOCKS" => "Shadowsocks",
        "EXTERNAL" => Localization.L.T("ProtocolExternal", "External IP + port"),
        "SOCKS5" when HasShareLink => "SOCKS5",
        "VLESS" when HasShareLink => "VLESS",
        _ => Localization.L.T("ProtocolUnsupported", "Unsupported"),
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

public sealed record MacConnectionSelection(
    int ForwardId,
    int ProfileId,
    IReadOnlyDictionary<int, int> ForwardProfiles)
{
    public static MacConnectionSelection Empty { get; } = new(0, 0, new Dictionary<int, int>());
}

public interface IMacConnectionService : IAsyncDisposable
{
    event Action<MacConnectionSnapshot>? SnapshotChanged;

    IReadOnlyList<MacConnectionProfile> Profiles { get; }
    MacConnectionSnapshot Snapshot { get; }
    DateTimeOffset? ActiveNativeLeaseExpiresAt { get; }
    string? ActiveNativeLeasePolicyGeneration { get; }
    bool IsNativeOutboundActive { get; }

    void SetPreferredEgressInterface(string? interfaceName) { }

    /// <summary>Payload ceiling the native relay published for the live association (0 = unknown).</summary>
    int ActiveNativePayloadCeiling => 0;

    /// <summary>Set when the relay's re-measured ceiling changes the live TUN MTU.</summary>
    bool IsNativeTunMtuPending => false;
    MacConnectionSelection Selection => MacConnectionSelection.Empty;

    Task InitializeAsync(CancellationToken ct = default);
    Task SaveProfileAsync(MacConnectionProfile profile, CancellationToken ct = default);
    Task DeleteProfileAsync(int profileId, CancellationToken ct = default);
    Task SaveSelectionAsync(int forwardId, int profileId, CancellationToken ct = default)
        => Task.CompletedTask;
    Task StartRoutedAsync(
        MacConnectionMode mode,
        int profileId,
        int forwardListenPort,
        string panelUrl,
        IEnumerable<ForwardConfig> forwards,
        CancellationToken ct = default)
        => Task.FromException(new NotSupportedException("routed_connection_unavailable"));
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
    Task StartNativeAsync(
        MacConnectionMode mode,
        NativeOutboundLease lease,
        string panelUrl,
        IEnumerable<ForwardConfig> forwards,
        bool bypassChinaMainland,
        string nativeRoutePolicy,
        CancellationToken ct = default)
        => StartNativeAsync(mode, lease, panelUrl, forwards, bypassChinaMainland, ct);
    Task<bool> TryRefreshNativeAsync(
        NativeOutboundLease lease,
        CancellationToken ct = default);

    /// <summary>
    /// Re-apply a re-measured TUN MTU to the live native tunnel without restarting it.
    /// True means the live config now matches the ceiling (or already did); false leaves
    /// <see cref="IsNativeTunMtuPending"/> set so the caller can retry or restart.
    /// </summary>
    Task<bool> ApplyPendingNativeTunMtuAsync(CancellationToken ct = default)
        => Task.FromResult(false);

    Task StopAsync(CancellationToken ct = default);
}
