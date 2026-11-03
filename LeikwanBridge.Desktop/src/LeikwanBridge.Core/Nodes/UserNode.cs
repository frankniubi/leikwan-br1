using System.Text.Json.Serialization;

namespace LeikwanBridge.Core.Nodes;

/// <summary>
/// Protocols the panel can host for a client (docs/plans/2026-10-12-hosted-user-node-pairing.md).
/// </summary>
public static class UserNodeProtocol
{
    public const string Shadowsocks = "ss";
    public const string Socks5 = "socks5";
    public const string Vless = "vless";

    public static IReadOnlyList<string> All { get; } = [Shadowsocks, Socks5, Vless];

    /// <summary>Maps share-link schemes and panel protocol names to the three canonical values; '' when unknown.</summary>
    public static string Normalize(string? value)
        => (value ?? string.Empty).Trim().ToLowerInvariant() switch
        {
            "ss" or "shadowsocks" or "ss2022" or "shadowsocks2022" => Shadowsocks,
            "socks" or "socks5" => Socks5,
            "vless" => Vless,
            _ => string.Empty,
        };

    /// <summary>AEAD and 2022 methods the panel accepts (HostedNodeSpec::SS_METHODS).</summary>
    public static IReadOnlyList<string> CloudShadowsocksMethods { get; } =
    [
        "2022-blake3-aes-128-gcm",
        "2022-blake3-aes-256-gcm",
        "2022-blake3-chacha20-poly1305",
        "aes-128-gcm",
        "aes-192-gcm",
        "aes-256-gcm",
        "chacha20-ietf-poly1305",
        "xchacha20-ietf-poly1305",
    ];

    /// <summary>Shadowsocks plugins sing-box runs; simple-obfs is the SIP003 alias of obfs-local.</summary>
    public static IReadOnlyList<string> ShadowsocksPlugins { get; } = ["obfs-local", "v2ray-plugin"];

    public static IReadOnlyList<string> VlessSecurities { get; } = ["none", "tls", "reality"];
    public static IReadOnlyList<string> VlessTransports { get; } = ["tcp", "ws", "grpc", "httpupgrade"];
}

/// <summary>
/// One of the user's own proxy nodes (Shadowsocks / SOCKS5 / VLESS), the
/// platform-neutral shape shared by the Windows and macOS clients.
///
/// A node never reaches a forward or relay. A client dials it through
/// <c>127.0.0.1:&lt;forward listen port&gt;</c>; the forward carries the bytes to
/// <see cref="Server"/>:<see cref="Port"/>.
///
/// <see cref="LocalId"/> is the device's own id (the Windows profile id);
/// <see cref="HostedId"/> is the panel's node id once the node is "上云". A node
/// with a hosted id is a cache of the panel copy (write-through, see
/// <see cref="HostedNodeSyncService"/>).
/// </summary>
public sealed class UserNode
{
    [JsonPropertyName("local_id")] public int LocalId { get; set; }
    [JsonPropertyName("hosted_id")] public long? HostedId { get; set; }
    [JsonPropertyName("name")] public string Name { get; set; } = "";
    [JsonPropertyName("protocol")] public string Protocol { get; set; } = UserNodeProtocol.Shadowsocks;
    [JsonPropertyName("server")] public string Server { get; set; } = "";
    [JsonPropertyName("port")] public int Port { get; set; }

    // Shadowsocks
    [JsonPropertyName("method")] public string Method { get; set; } = "";
    /// <summary>Shadowsocks password or SOCKS5 password. Passed through untrimmed.</summary>
    [JsonPropertyName("password")] public string Password { get; set; } = "";
    [JsonPropertyName("plugin")] public string Plugin { get; set; } = "";
    [JsonPropertyName("plugin_opts")] public string PluginOpts { get; set; } = "";
    [JsonPropertyName("udp_over_tcp")] public bool UdpOverTcp { get; set; }

    // SOCKS5 (RFC 1929; empty username = no auth)
    [JsonPropertyName("username")] public string Username { get; set; } = "";

    // VLESS
    [JsonPropertyName("uuid")] public string Uuid { get; set; } = "";
    [JsonPropertyName("flow")] public string Flow { get; set; } = "";
    [JsonPropertyName("security")] public string Security { get; set; } = "none";
    [JsonPropertyName("sni")] public string Sni { get; set; } = "";
    [JsonPropertyName("fingerprint")] public string Fingerprint { get; set; } = "";
    /// <summary>Comma separated (h2,http/1.1).</summary>
    [JsonPropertyName("alpn")] public string Alpn { get; set; } = "";
    [JsonPropertyName("allow_insecure")] public bool AllowInsecure { get; set; }
    [JsonPropertyName("public_key")] public string PublicKey { get; set; } = "";
    [JsonPropertyName("short_id")] public string ShortId { get; set; } = "";
    [JsonPropertyName("transport")] public string Transport { get; set; } = "tcp";
    [JsonPropertyName("path")] public string Path { get; set; } = "";
    [JsonPropertyName("host")] public string Host { get; set; } = "";
    [JsonPropertyName("service_name")] public string ServiceName { get; set; } = "";

    [JsonIgnore] public bool IsHosted => HostedId is > 0;

    [JsonIgnore]
    public string DisplayProtocol => UserNodeProtocol.Normalize(Protocol) switch
    {
        UserNodeProtocol.Shadowsocks => Method.StartsWith("2022-", StringComparison.OrdinalIgnoreCase) ? "SS2022" : "Shadowsocks",
        UserNodeProtocol.Socks5 => "SOCKS5",
        UserNodeProtocol.Vless => "VLESS",
        _ => Protocol,
    };

    [JsonIgnore]
    public string DisplayEndpoint => Server.Contains(':') ? $"[{Server}]:{Port}" : $"{Server}:{Port}";

    public UserNode Clone() => (UserNode)MemberwiseClone();

    /// <summary>Copy of this node with the connection (name, address, settings) of <paramref name="source"/>; ids kept.</summary>
    public UserNode WithConnectionOf(UserNode source)
    {
        var copy = source.Clone();
        copy.LocalId = LocalId;
        copy.HostedId = HostedId;
        return copy;
    }

    /// <summary>
    /// True when the panel would accept this node: one of the three protocols,
    /// and for Shadowsocks an AEAD / 2022 method with a supported plugin.
    /// Legacy stream ciphers stay local-only.
    /// </summary>
    [JsonIgnore]
    public bool IsCloudSupported
    {
        get
        {
            var protocol = UserNodeProtocol.Normalize(Protocol);
            if (protocol.Length == 0 || string.IsNullOrWhiteSpace(Server) || Port is < 1 or > 65535)
                return false;
            if (protocol != UserNodeProtocol.Shadowsocks)
                return true;
            var plugin = UserNodeShareLink.NormalizePlugin(Plugin);
            return UserNodeProtocol.CloudShadowsocksMethods.Contains(Method.Trim().ToLowerInvariant(), StringComparer.Ordinal)
                && Password.Length > 0
                && (plugin.Length == 0 || UserNodeProtocol.ShadowsocksPlugins.Contains(plugin, StringComparer.Ordinal));
        }
    }
}
