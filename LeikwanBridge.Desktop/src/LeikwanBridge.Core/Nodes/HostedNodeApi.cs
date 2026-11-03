using System.Text.Json.Serialization;

namespace LeikwanBridge.Core.Nodes;

/// <summary>
/// Hosted user nodes ("上云") on the panel: /api/client/hosted-nodes(/batch|/{id}) and
/// /api/client/hosted-node-pairings/{forwardId}. Every call returns the full listing, so
/// one call is enough to resync. <see cref="Services.MasterApiClient"/> implements it with
/// the desktop identity headers (agent token, X-Device-Id, X-Device-Platform) and the
/// control-plane mirror failover.
///
/// Failures: a refusal the panel names throws <see cref="HostedNodeException"/>; the online
/// device limit throws <see cref="Services.DeviceLimitReachedException"/>; anything else a
/// <see cref="Services.MasterApiException"/> or a transport exception.
/// </summary>
public interface IHostedNodeApi
{
    Task<HostedNodesResponse> ListHostedNodesAsync(CancellationToken ct = default);
    Task<HostedNodesResponse> CreateHostedNodeAsync(HostedNodeUploadRequest request, CancellationToken ct = default);
    Task<HostedNodesResponse> BatchCreateHostedNodesAsync(HostedNodeBatchRequest request, CancellationToken ct = default);
    Task<HostedNodesResponse> UpdateHostedNodeAsync(long nodeId, HostedNodeUpdateRequest request, CancellationToken ct = default);
    Task<HostedNodesResponse> DeleteHostedNodeAsync(long nodeId, CancellationToken ct = default);
    Task<HostedNodesResponse> PairHostedNodeAsync(int forwardId, long? nodeId, CancellationToken ct = default);
}

/// <summary>A hosted-node call the panel refused: <see cref="Code"/> is the panel error (hosted_node_invalid ...), <see cref="Field"/> the bad field.</summary>
public sealed class HostedNodeException : Exception
{
    public HostedNodeException(string code, string? field = null, int statusCode = 0)
        : base(code)
    {
        Code = code;
        Field = field;
        StatusCode = statusCode;
    }

    public string Code { get; }
    public string? Field { get; }
    public int StatusCode { get; }
}

public sealed class HostedNodesResponse
{
    [JsonPropertyName("ok")] public bool Ok { get; set; }
    [JsonPropertyName("error")] public string? Error { get; set; }
    [JsonPropertyName("field")] public string? Field { get; set; }
    /// <summary>Empty when the client hosts no nodes; matches client.hosted_nodes_revision in the pull.</summary>
    [JsonPropertyName("revision")] public string? Revision { get; set; }
    [JsonPropertyName("nodes")] public List<HostedNodeDto>? Nodes { get; set; }
    [JsonPropertyName("pairings")] public List<HostedNodePairingDto>? Pairings { get; set; }
    [JsonPropertyName("node_id")] public long? NodeId { get; set; }
    [JsonPropertyName("created")] public bool? Created { get; set; }
    [JsonPropertyName("results")] public List<HostedNodeBatchResult>? Results { get; set; }
}

public sealed class HostedNodeDto
{
    [JsonPropertyName("id")] public long Id { get; set; }
    [JsonPropertyName("name")] public string? Name { get; set; }
    [JsonPropertyName("protocol")] public string? Protocol { get; set; }
    [JsonPropertyName("server")] public string? Server { get; set; }
    [JsonPropertyName("port")] public int? Port { get; set; }
    /// <summary>Canonical share link; parsed with <see cref="UserNodeShareLink"/> like a pasted link.</summary>
    [JsonPropertyName("uri")] public string? Uri { get; set; }
    [JsonPropertyName("version")] public long? Version { get; set; }
    [JsonPropertyName("source")] public string? Source { get; set; }
    [JsonPropertyName("updated_at")] public string? UpdatedAt { get; set; }
}

public sealed class HostedNodePairingDto
{
    [JsonPropertyName("forward_id")] public int ForwardId { get; set; }
    [JsonPropertyName("node_id")] public long NodeId { get; set; }
    /// <summary>"explicit" (console or a device) or "target_match" (the forward target equals the node).</summary>
    [JsonPropertyName("source")] public string? Source { get; set; }
}

public sealed class HostedNodeBatchResult
{
    [JsonPropertyName("ref")] public string? Ref { get; set; }
    [JsonPropertyName("ok")] public bool Ok { get; set; }
    [JsonPropertyName("node_id")] public long? NodeId { get; set; }
    [JsonPropertyName("created")] public bool? Created { get; set; }
    [JsonPropertyName("error")] public string? Error { get; set; }
    [JsonPropertyName("field")] public string? Field { get; set; }
}

public sealed record HostedNodeUploadRequest(
    [property: JsonPropertyName("uri")] string Uri,
    [property: JsonPropertyName("name")] string? Name = null,
    [property: JsonPropertyName("pair_forward_id")] int? PairForwardId = null);

public sealed record HostedNodeBatchEntry(
    [property: JsonPropertyName("ref")] string Ref,
    [property: JsonPropertyName("uri")] string Uri,
    [property: JsonPropertyName("name")] string? Name = null);

public sealed record HostedNodeBatchRequest(
    [property: JsonPropertyName("nodes")] IReadOnlyList<HostedNodeBatchEntry> Nodes);

public sealed record HostedNodeUpdateRequest(
    [property: JsonPropertyName("uri")] string Uri,
    [property: JsonPropertyName("name")] string? Name = null);
