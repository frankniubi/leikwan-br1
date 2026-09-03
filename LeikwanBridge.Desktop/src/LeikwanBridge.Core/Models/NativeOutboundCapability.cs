using System.Text.Json.Serialization;

namespace LeikwanBridge.Core.Models;

public sealed class NativeOutboundCapability
{
    [JsonPropertyName("enabled")] public bool Enabled { get; init; }
    [JsonPropertyName("protocol")] public string Protocol { get; init; } = "";
    [JsonPropertyName("lease_protocol")] public string LeaseProtocol { get; init; } = "";
    [JsonPropertyName("transport")] public string Transport { get; init; } = "tcp+udp";
    [JsonPropertyName("forward_ids")] public List<int> ForwardIds { get; init; } = [];
    [JsonPropertyName("default_forward_id")] public int? DefaultForwardId { get; init; }
    [JsonPropertyName("route_scopes")] public List<string> RouteScopes { get; init; } = [];
    [JsonPropertyName("default_route_scope")] public string DefaultRouteScope { get; init; } = "global";
    [JsonPropertyName("unrestricted_allowed")] public bool UnrestrictedAllowed { get; init; }
    [JsonPropertyName("forwards")] public List<NativeOutboundForward> Forwards { get; init; } = [];

    public NativeOutboundForward? FindForward(int forwardId)
        => Forwards.FirstOrDefault(item => item.ForwardId == forwardId);
}

public sealed record NativeOutboundForward(
    [property: JsonPropertyName("forward_id")] int ForwardId,
    [property: JsonPropertyName("direction")] string Direction,
    [property: JsonPropertyName("scope_digest")] string ScopeDigest,
    [property: JsonPropertyName("transport")] string Transport,
    [property: JsonPropertyName("route_scopes")] IReadOnlyList<string> RouteScopes,
    [property: JsonPropertyName("default_route_scope")] string DefaultRouteScope,
    [property: JsonPropertyName("unrestricted_allowed")] bool UnrestrictedAllowed = false);
