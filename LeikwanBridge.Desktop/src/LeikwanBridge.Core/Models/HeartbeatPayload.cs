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

    [JsonPropertyName("ultra_usage")]
    public List<UltraUsageSnapshot> UltraUsage { get; set; } = [];
}

public sealed class UltraUsageSnapshot
{
    [JsonPropertyName("forward_id")] public int ForwardId { get; set; }
    [JsonPropertyName("policy_generation")] public string PolicyGeneration { get; set; } = "";
    [JsonPropertyName("sequence")] public long Sequence { get; set; }
    [JsonPropertyName("bytes_in")] public long BytesIn { get; set; }
    [JsonPropertyName("bytes_out")] public long BytesOut { get; set; }
}

public sealed class HeartbeatResponse
{
    [JsonPropertyName("ok")] public bool Ok { get; set; }
    [JsonPropertyName("ultra_usage_acknowledged")]
    public bool? UltraUsageAcknowledged { get; set; }
    [JsonPropertyName("ultra_actions")] public List<UltraPolicyAction> UltraActions { get; set; } = [];
}

public sealed class UltraPolicyAction
{
    [JsonPropertyName("forward_id")] public int ForwardId { get; set; }
    [JsonPropertyName("policy_generation")] public string PolicyGeneration { get; set; } = "";
    [JsonPropertyName("action")] public string Action { get; set; } = "";
    [JsonPropertyName("reason")] public string Reason { get; set; } = "";
}
