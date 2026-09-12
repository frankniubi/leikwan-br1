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

    [JsonPropertyName("ultra_candidate_feedback")]
    public List<UltraCandidateFeedback> UltraCandidateFeedback { get; set; } = [];
}

public sealed class UltraUsageSnapshot
{
    [JsonPropertyName("forward_id")] public int ForwardId { get; set; }
    [JsonPropertyName("policy_generation")] public string PolicyGeneration { get; set; } = "";
    [JsonPropertyName("sequence")] public long Sequence { get; set; }
    [JsonPropertyName("bytes_in")] public long BytesIn { get; set; }
    [JsonPropertyName("bytes_out")] public long BytesOut { get; set; }

    // Runtime status published by the same snapshot the counters above come
    // from. Absent stays absent: a ceiling that was never measured and an
    // attestation that was not reported are omitted, never defaulted.
    [JsonPropertyName("udp_payload_ceiling")]
    [JsonIgnore(Condition = JsonIgnoreCondition.WhenWritingDefault)]
    public int UdpPayloadCeiling { get; set; }

    [JsonPropertyName("udp_oversize_drops")]
    [JsonIgnore(Condition = JsonIgnoreCondition.WhenWritingDefault)]
    public long UdpOversizeDrops { get; set; }

    [JsonPropertyName("runtime_attestation")]
    [JsonIgnore(Condition = JsonIgnoreCondition.WhenWritingNull)]
    public RuntimeAttestationSnapshot? RuntimeAttestation { get; set; }
}

/// <summary>
/// Identity the Ultra runtime reports about its own helper binary. The panel
/// compares it against the release manifest; the host only relays what the
/// runtime published and drops a malformed object instead of repairing it.
/// </summary>
public sealed class RuntimeAttestationSnapshot
{
    [JsonPropertyName("fingerprint")] public string Fingerprint { get; set; } = "";

    [JsonPropertyName("self_sha256")]
    [JsonIgnore(Condition = JsonIgnoreCondition.WhenWritingNull)]
    public string? SelfSha256 { get; set; }

    [JsonPropertyName("debugger")]
    [JsonIgnore(Condition = JsonIgnoreCondition.WhenWritingNull)]
    public bool? Debugger { get; set; }

    [JsonPropertyName("instrumented")]
    [JsonIgnore(Condition = JsonIgnoreCondition.WhenWritingNull)]
    public bool? Instrumented { get; set; }
}

public sealed class UltraCandidateFeedback
{
    [JsonPropertyName("forward_id")] public int ForwardId { get; set; }
    [JsonPropertyName("candidate_key_hash")] public string CandidateKeyHash { get; set; } = "";
    [JsonPropertyName("lease_hash")] public string LeaseHash { get; set; } = "";
    [JsonPropertyName("protocol")] public string Protocol { get; set; } = "";
    [JsonPropertyName("outcome")] public string Outcome { get; set; } = "";
    [JsonPropertyName("reason")] public string Reason { get; set; } = "";
    [JsonPropertyName("evidence_id")] public string EvidenceId { get; set; } = "";
    [JsonPropertyName("observed_at")] public string ObservedAt { get; set; } = "";
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
