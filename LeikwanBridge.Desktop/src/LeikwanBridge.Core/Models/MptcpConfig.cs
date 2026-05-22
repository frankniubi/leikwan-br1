using System.Text.Json.Serialization;

namespace LeikwanBridge.Core.Models;

public sealed class MptcpConfig
{
    [JsonPropertyName("enabled")]
    public bool Enabled { get; set; }

    [JsonPropertyName("sidecar_host")]
    public string SidecarHost { get; set; } = "";

    [JsonPropertyName("sidecar_port")]
    public int SidecarPort { get; set; }

    [JsonPropertyName("remote_host")]
    public string RemoteHost { get; set; } = "";

    [JsonPropertyName("remote_port")]
    public int RemotePort { get; set; }

    [JsonPropertyName("relay_host")]
    public string RelayHost { get; set; } = "";

    [JsonPropertyName("relay_port")]
    public int RelayPort { get; set; }

    [JsonPropertyName("tcp_session_token")]
    public string TcpSessionToken { get; set; } = "";

    [JsonPropertyName("udp_session_token")]
    public string UdpSessionToken { get; set; } = "";

    [JsonPropertyName("session_token")]
    public string SessionToken { get; set; } = "";

    [JsonPropertyName("allow_tcp_fallback")]
    public bool AllowTcpFallback { get; set; } = true;

    [JsonPropertyName("connect_timeout_ms")]
    public int ConnectTimeoutMs { get; set; } = 3000;

    [JsonPropertyName("relay_idle_timeout_seconds")]
    public int RelayIdleTimeoutSeconds { get; set; } = 120;

    [JsonPropertyName("subflow_limit")]
    public int SubflowLimit { get; set; } = 2;

    [JsonPropertyName("manage_mptcp_endpoints")]
    public bool ManageMptcpEndpoints { get; set; }

    [JsonPropertyName("mptcp_endpoint_device")]
    public string MptcpEndpointDevice { get; set; } = "";

    [JsonPropertyName("mptcp_endpoint_id_base")]
    public int MptcpEndpointIdBase { get; set; }

    [JsonPropertyName("mptcp_endpoints")]
    public List<object> MptcpEndpoints { get; set; } = [];

    [JsonPropertyName("udp_mode")]
    public string UdpMode { get; set; } = "stream_compat";

    [JsonPropertyName("tcp_congestion_control")]
    public string TcpCongestionControl { get; set; } = "";

    [JsonPropertyName("socket_buffer_bytes")]
    public int SocketBufferBytes { get; set; } = 8388608;

    [JsonPropertyName("underlay")]
    public UnderlayConfig? Underlay { get; set; }

    [JsonPropertyName("underlays")]
    public List<UnderlayConfig> Underlays { get; set; } = [];

    /// <summary>
    /// Slave pre-connect relay host. When set, the client connects to the slave's
    /// local relay via plain TCP instead of opening MPTCP directly to sidecar.
    /// </summary>
    [JsonPropertyName("slave_relay_host")]
    public string SlaveRelayHost { get; set; } = "";

    /// <summary>
    /// Slave pre-connect relay port.
    /// </summary>
    [JsonPropertyName("slave_relay_port")]
    public int SlaveRelayPort { get; set; }

    [JsonPropertyName("slave_uot_relay_host")]
    public string SlaveUotRelayHost { get; set; } = "";

    [JsonPropertyName("slave_uot_relay_port")]
    public int SlaveUotRelayPort { get; set; }
}
