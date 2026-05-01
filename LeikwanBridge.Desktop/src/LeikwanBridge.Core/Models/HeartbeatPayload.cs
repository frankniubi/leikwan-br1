using System.Text.Json.Serialization;

namespace LeikwanBridge.Core.Models;

public sealed class HeartbeatPayload
{
    [JsonPropertyName("agent_version")]
    public string AgentVersion { get; set; } = "";

    [JsonPropertyName("usage_bytes_in")]
    public long UsageBytesIn { get; set; }

    [JsonPropertyName("usage_bytes_out")]
    public long UsageBytesOut { get; set; }

    [JsonPropertyName("supports_mptcp")]
    public bool SupportsMptcp { get; set; }

    [JsonPropertyName("wireguard_public_key")]
    public string WireguardPublicKey { get; set; } = "";

    [JsonPropertyName("wireguard_listen_port")]
    public int WireguardListenPort { get; set; }

    [JsonPropertyName("wireguard_fallback_tcp_port")]
    public int WireguardFallbackTcpPort { get; set; }
}
