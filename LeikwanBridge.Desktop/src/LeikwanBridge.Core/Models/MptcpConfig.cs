using System.Text.Json.Serialization;

namespace LeikwanBridge.Core.Models;

public sealed class MptcpConfig
{
    [JsonPropertyName("sidecar_host")]
    public string SidecarHost { get; set; } = "";

    [JsonPropertyName("sidecar_port")]
    public int SidecarPort { get; set; }

    [JsonPropertyName("remote_host")]
    public string RemoteHost { get; set; } = "";

    [JsonPropertyName("remote_port")]
    public int RemotePort { get; set; }

    [JsonPropertyName("tcp_session_token")]
    public string TcpSessionToken { get; set; } = "";

    [JsonPropertyName("udp_session_token")]
    public string UdpSessionToken { get; set; } = "";

    [JsonPropertyName("session_token")]
    public string SessionToken { get; set; } = "";

    [JsonPropertyName("allow_tcp_fallback")]
    public bool AllowTcpFallback { get; set; }

    [JsonPropertyName("connect_timeout_ms")]
    public int ConnectTimeoutMs { get; set; } = 3000;

    [JsonPropertyName("subflow_limit")]
    public int SubflowLimit { get; set; } = 2;

    [JsonPropertyName("manage_mptcp_endpoints")]
    public bool ManageMptcpEndpoints { get; set; }

    [JsonPropertyName("underlay")]
    public UnderlayConfig? Underlay { get; set; }
}
