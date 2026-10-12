using System.Globalization;
using System.Text;
using System.Text.Json;
using LeikwanBridge.Core.Nodes;

namespace LeikwanBridge.Core.Services;

/// <summary>
/// Hosted user nodes. The panel accepts these calls from an official desktop client
/// with the same identity as its config pull (agent token, per-install X-Device-Id,
/// X-Device-Platform); responses carry the user's credentials, so they are never
/// logged here.
/// </summary>
public sealed partial class MasterApiClient : IHostedNodeApi
{
    private static readonly JsonSerializerOptions HostedNodeJson = new()
    {
        PropertyNameCaseInsensitive = true,
        DefaultIgnoreCondition = System.Text.Json.Serialization.JsonIgnoreCondition.WhenWritingNull,
    };

    public Task<HostedNodesResponse> ListHostedNodesAsync(CancellationToken ct = default)
        => SendHostedNodeRequestAsync(HttpMethod.Get, "/api/client/hosted-nodes", null, ct);

    public Task<HostedNodesResponse> CreateHostedNodeAsync(HostedNodeUploadRequest request, CancellationToken ct = default)
        => SendHostedNodeRequestAsync(HttpMethod.Post, "/api/client/hosted-nodes", request, ct);

    public Task<HostedNodesResponse> BatchCreateHostedNodesAsync(HostedNodeBatchRequest request, CancellationToken ct = default)
        => SendHostedNodeRequestAsync(HttpMethod.Post, "/api/client/hosted-nodes/batch", request, ct);

    public Task<HostedNodesResponse> UpdateHostedNodeAsync(long nodeId, HostedNodeUpdateRequest request, CancellationToken ct = default)
        => SendHostedNodeRequestAsync(HttpMethod.Put, "/api/client/hosted-nodes/" + nodeId.ToString(CultureInfo.InvariantCulture), request, ct);

    public Task<HostedNodesResponse> DeleteHostedNodeAsync(long nodeId, CancellationToken ct = default)
        => SendHostedNodeRequestAsync(HttpMethod.Delete, "/api/client/hosted-nodes/" + nodeId.ToString(CultureInfo.InvariantCulture), null, ct);

    public Task<HostedNodesResponse> PairHostedNodeAsync(int forwardId, long? nodeId, CancellationToken ct = default)
        => SendHostedNodeRequestAsync(
            HttpMethod.Put,
            "/api/client/hosted-node-pairings/" + forwardId.ToString(CultureInfo.InvariantCulture),
            // A missing node_id clears the explicit pairing on the panel.
            nodeId is > 0 ? new Dictionary<string, object> { ["node_id"] = nodeId.Value } : new Dictionary<string, object>(),
            ct);

    private async Task<HostedNodesResponse> SendHostedNodeRequestAsync(HttpMethod method, string path, object? payload, CancellationToken ct)
    {
        using var request = new HttpRequestMessage(method, _masterUrl + path);
        if (payload is not null)
            request.Content = new StringContent(JsonSerializer.Serialize(payload, payload.GetType(), HostedNodeJson), Encoding.UTF8, "application/json");
        using var response = await SendAsync(request, ct).ConfigureAwait(false);
        var body = await response.Content.ReadAsStringAsync(ct).ConfigureAwait(false);
        return ParseHostedNodeResponse((int) response.StatusCode, response.IsSuccessStatusCode, body);
    }

    internal static HostedNodesResponse ParseHostedNodeResponse(int statusCode, bool success, string body)
    {
        if (!success && TryParseDeviceLimit(statusCode, body) is { } limit)
            throw limit;
        HostedNodesResponse? parsed = null;
        try
        {
            parsed = string.IsNullOrWhiteSpace(body) ? null : JsonSerializer.Deserialize<HostedNodesResponse>(body, HostedNodeJson);
        }
        catch (JsonException)
        {
            parsed = null;
        }
        if (parsed is null)
        {
            if (statusCode == 404)
                throw new HostedNodeException("hosted_nodes_unsupported", statusCode: statusCode);
            throw new MasterApiException(statusCode, success ? "master_api_invalid_json_response" : $"HTTP {statusCode}");
        }
        if (!success || !parsed.Ok)
        {
            var code = string.IsNullOrWhiteSpace(parsed.Error) ? $"hosted_nodes_http_{statusCode}" : parsed.Error!;
            throw new HostedNodeException(code, parsed.Field, statusCode);
        }
        return parsed;
    }
}
