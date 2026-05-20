using System.Text.Json.Serialization;

namespace LeikwanBridge.Core.Models;

/// <summary>
/// Panel-provisioned self-leg WireGuard profile for a forward.
/// Matches the panel's <c>self_leg_wg</c> JSON payload.
/// </summary>
public sealed class SelfLegWgConfig
{
    [JsonPropertyName("enabled")]
    public bool Enabled { get; set; }

    [JsonPropertyName("interface_name")]
    public string InterfaceName { get; set; } = "";

    [JsonPropertyName("client_address")]
    public string ClientAddress { get; set; } = "";

    [JsonPropertyName("sidecar_address")]
    public string SidecarAddress { get; set; } = "";

    [JsonPropertyName("client_public_key")]
    public string ClientPublicKey { get; set; } = "";

    [JsonPropertyName("sidecar_public_key")]
    public string SidecarPublicKey { get; set; } = "";

    [JsonPropertyName("endpoint_host")]
    public string EndpointHost { get; set; } = "";

    [JsonPropertyName("endpoint_port")]
    public int EndpointPort { get; set; }

    [JsonPropertyName("fallback_tcp_port")]
    public int FallbackTcpPort { get; set; }

    [JsonPropertyName("persistent_keepalive")]
    public int PersistentKeepalive { get; set; } = 25;

    /// <summary>
    /// Outer transport type: "direct", "wstunnel_udp", or "cf_wg".
    /// For the initial Windows pass, only "direct" is supported.
    /// </summary>
    [JsonPropertyName("outer_transport")]
    public string OuterTransport { get; set; } = "direct";

    [JsonPropertyName("cf_dial_host")]
    public string CfDialHost { get; set; } = "";

    [JsonPropertyName("cf_dial_port")]
    public int CfDialPort { get; set; }

    [JsonPropertyName("cf_sni_host")]
    public string CfSniHost { get; set; } = "";

    [JsonPropertyName("cf_path_prefix")]
    public string CfPathPrefix { get; set; } = "";

    /// <summary>
    /// The port the bond backend listens on inside the sidecar, reachable
    /// via the WireGuard tunnel at <see cref="SidecarAddress"/>.
    /// </summary>
    [JsonPropertyName("sidecar_bond_port")]
    public int SidecarBondPort { get; set; }
}
