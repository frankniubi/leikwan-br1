using System.Text.Json.Serialization;

namespace LeikwanBridge.Core.Models;

public sealed class UnderlayConfig
{
    [JsonPropertyName("type")]
    public string Type { get; set; } = "wireguard";

    [JsonPropertyName("mode")]
    public string Mode { get; set; } = "slave_transit";

    [JsonPropertyName("interface_name")]
    public string InterfaceName { get; set; } = "";

    [JsonPropertyName("config_path")]
    public string ConfigPath { get; set; } = "";

    [JsonPropertyName("listen_port")]
    public int ListenPort { get; set; }

    [JsonPropertyName("local_private_key_path")]
    public string LocalPrivateKeyPath { get; set; } = "";

    [JsonPropertyName("mtu")]
    public int Mtu { get; set; } = 1382;

    [JsonPropertyName("peer_keepalive_seconds")]
    public int PeerKeepaliveSeconds { get; set; } = 25;

    [JsonPropertyName("legs")]
    public List<UnderlayLeg> Legs { get; set; } = [];
}

public sealed class UnderlayLeg
{
    [JsonPropertyName("endpoint_id")]
    public int EndpointId { get; set; }

    [JsonPropertyName("local_address")]
    public string LocalAddress { get; set; } = "";

    [JsonPropertyName("peer_address")]
    public string PeerAddress { get; set; } = "";

    [JsonPropertyName("mptcp_remote_address")]
    public string MptcpRemoteAddress { get; set; } = "";

    [JsonPropertyName("peer_public_key")]
    public string PeerPublicKey { get; set; } = "";

    [JsonPropertyName("peer_public_host")]
    public string PeerPublicHost { get; set; } = "";

    [JsonPropertyName("peer_udp_port")]
    public int PeerUdpPort { get; set; }

    [JsonPropertyName("outer_transport")]
    public string OuterTransport { get; set; } = "udp";

    [JsonPropertyName("peer_tcp_fallback_port")]
    public int PeerTcpFallbackPort { get; set; }

    [JsonPropertyName("local_uot_udp_port")]
    public int LocalUotUdpPort { get; set; }

    [JsonPropertyName("slave_preconnect")]
    public bool SlavePreconnect { get; set; }

    [JsonPropertyName("preconnect_relay_port")]
    public int PreconnectRelayPort { get; set; }

    [JsonPropertyName("health_status")]
    public string HealthStatus { get; set; } = "active";
}
