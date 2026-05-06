using System.Text.Json.Serialization;

namespace LeikwanBridge.Core.Models;

public sealed class EndpointConfig
{
    [JsonPropertyName("endpoint_id")]
    public int EndpointId { get; set; }

    [JsonPropertyName("host")]
    public string Host { get; set; } = "";

    [JsonPropertyName("port")]
    public int Port { get; set; }

    [JsonPropertyName("cdn_host")]
    public string CdnHost { get; set; } = "";

    [JsonPropertyName("route_key")]
    public string RouteKey { get; set; } = "";

    [JsonPropertyName("ingress_auth_token")]
    public string IngressAuthToken { get; set; } = "";

    [JsonPropertyName("ingress_protocol")]
    public string IngressProtocol { get; set; } = "";

    [JsonPropertyName("backend_protocol")]
    public string BackendProtocol { get; set; } = "";

    [JsonPropertyName("dynamic_priority")]
    public int DynamicPriority { get; set; }

    [JsonPropertyName("health_role")]
    public string HealthRole { get; set; } = "healthy";

    [JsonPropertyName("quality_score")]
    public double QualityScore { get; set; } = 1.0;

    [JsonPropertyName("supports_quic_leg")]
    public bool SupportsQuicLeg { get; set; } = true;

    public EndpointConfig Clone()
    {
        return new EndpointConfig
        {
            EndpointId = EndpointId,
            Host = Host,
            Port = Port,
            CdnHost = CdnHost,
            RouteKey = RouteKey,
            IngressAuthToken = IngressAuthToken,
            IngressProtocol = IngressProtocol,
            BackendProtocol = BackendProtocol,
            DynamicPriority = DynamicPriority,
            HealthRole = HealthRole,
            QualityScore = QualityScore,
            SupportsQuicLeg = SupportsQuicLeg,
        };
    }
}
