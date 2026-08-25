using System.Text.Json.Serialization;

namespace LeikwanBridge.Core.Models;

public sealed class AgentState
{
    public Dictionary<string, AppliedForwardState> Applied { get; set; } = [];
    public PullState PullState { get; set; } = new();
    public UsageState UsageState { get; set; } = new();
}

public sealed class AppliedForwardState
{
    [JsonPropertyName("forward_id")]
    public int ForwardId { get; set; }

    [JsonPropertyName("mode")]
    public string Mode { get; set; } = "";

    [JsonPropertyName("listen_port")]
    public int ListenPort { get; set; }

    [JsonPropertyName("ingress_mode")]
    public string IngressMode { get; set; } = "";

    [JsonPropertyName("endpoint_count")]
    public int EndpointCount { get; set; }

    [JsonPropertyName("leg_ports")]
    public List<int> LegPorts { get; set; } = [];

    [JsonPropertyName("process_roles")]
    public List<string> ProcessRoles { get; set; } = [];

    [JsonPropertyName("process_labels")]
    public List<string> ProcessLabels { get; set; } = [];

    [JsonPropertyName("process_ids")]
    public List<int> ProcessIds { get; set; } = [];

    [JsonPropertyName("contract_signature")]
    public string ContractSignature { get; set; } = "";

    [JsonPropertyName("last_apply_reason")]
    public string LastApplyReason { get; set; } = "";

    [JsonPropertyName("user_stopped")]
    public bool UserStopped { get; set; }

    [JsonPropertyName("updated_at")]
    public long UpdatedAt { get; set; }
}

public sealed class PullState
{
    [JsonPropertyName("status")]
    public string Status { get; set; } = "ok";

    [JsonPropertyName("last_attempt_at")]
    public double LastAttemptAt { get; set; }

    [JsonPropertyName("last_success_at")]
    public double LastSuccessAt { get; set; }

    [JsonPropertyName("last_error")]
    public string LastError { get; set; } = "";

    [JsonPropertyName("agent_version")]
    public string AgentVersion { get; set; } = "";

    [JsonPropertyName("forwards_total")]
    public int ForwardsTotal { get; set; }

    [JsonPropertyName("applied_forward_ids")]
    public List<int> AppliedForwardIds { get; set; } = [];
}

public sealed class UsageState
{
    [JsonPropertyName("total_bytes_in")]
    public long TotalBytesIn { get; set; }

    [JsonPropertyName("total_bytes_out")]
    public long TotalBytesOut { get; set; }

    [JsonPropertyName("last_reported_bytes_in")]
    public long LastReportedBytesIn { get; set; }

    [JsonPropertyName("last_reported_bytes_out")]
    public long LastReportedBytesOut { get; set; }

    [JsonPropertyName("ultra_last_bytes_in")]
    public Dictionary<int, long> UltraLastBytesIn { get; set; } = [];

    [JsonPropertyName("ultra_last_bytes_out")]
    public Dictionary<int, long> UltraLastBytesOut { get; set; } = [];

    [JsonPropertyName("ultra_sequences")]
    public Dictionary<int, long> UltraSequences { get; set; } = [];
}
