using System.Text.Json;
using System.Text.Json.Serialization;

namespace LeikwanBridge.Core.Models;

public sealed class ClientMeta
{
    [JsonPropertyName("id")]
    public int Id { get; set; }

    [JsonPropertyName("name")]
    public string Name { get; set; } = "";

    [JsonPropertyName("display_host")]
    public string DisplayHost { get; set; } = "";

    [JsonPropertyName("group_id")]
    public int? GroupId { get; set; }

    [JsonPropertyName("diag_request_token")]
    public string DiagRequestToken { get; set; } = "";

    [JsonPropertyName("diag_status")]
    public string DiagStatus { get; set; } = "ok";

    [JsonPropertyName("debug_collection")]
    public DebugCollectionMeta? DebugCollection { get; set; }

    [JsonPropertyName("agent_config_json")]
    public JsonElement? AgentConfigJson { get; set; }

    [JsonPropertyName("agent_update_request_token")]
    public string AgentUpdateRequestToken { get; set; } = "";

    [JsonPropertyName("desired_agent_version")]
    public string DesiredAgentVersion { get; set; } = "";

    [JsonPropertyName("desired_agent_sha256")]
    public string DesiredAgentSha256 { get; set; } = "";

    [JsonPropertyName("desired_bond_version")]
    public string DesiredBondVersion { get; set; } = "";

    [JsonPropertyName("desired_bond_sha256")]
    public string DesiredBondSha256 { get; set; } = "";

    [JsonPropertyName("desired_mptcp_runtime_version")]
    public string DesiredMptcpRuntimeVersion { get; set; } = "";

    [JsonPropertyName("desired_mptcp_runtime_sha256")]
    public string DesiredMptcpRuntimeSha256 { get; set; } = "";

    /// <summary>
    /// Hosted user nodes change marker (no node content). Null when the panel
    /// predates hosted nodes; empty when the client hosts none.
    /// </summary>
    [JsonPropertyName("hosted_nodes_revision")]
    public string? HostedNodesRevision { get; set; }

    /// <summary>
    /// clients.allow_inbound_proxy: whether forward listeners may accept connections
    /// from other machines. Absent (older panel) means no: listeners stay on loopback.
    /// </summary>
    [JsonPropertyName("allow_inbound_proxy")]
    public bool AllowInboundProxy { get; set; }
}

public sealed class DebugCollectionMeta
{
    [JsonPropertyName("uuid")]
    public string Uuid { get; set; } = "";

    [JsonPropertyName("expires_at")]
    public string ExpiresAt { get; set; } = "";

    [JsonPropertyName("status")]
    public string Status { get; set; } = "";

    [JsonPropertyName("byte_limit")]
    public long ByteLimit { get; set; }

    [JsonPropertyName("bytes_received")]
    public long BytesReceived { get; set; }
}
