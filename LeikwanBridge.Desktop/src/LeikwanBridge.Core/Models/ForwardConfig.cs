using System.Text.Json.Serialization;

namespace LeikwanBridge.Core.Models;

public sealed class ForwardConfig
{
    [JsonPropertyName("forward_id")]
    public int ForwardId { get; set; }

    [JsonPropertyName("service_status")]
    public string ServiceStatus { get; set; } = "active";

    [JsonPropertyName("mode")]
    public string Mode { get; set; } = "single";

    [JsonPropertyName("listen_port")]
    public int ListenPort { get; set; }

    [JsonPropertyName("target")]
    public string Target { get; set; } = "";

    [JsonPropertyName("sni")]
    public string Sni { get; set; } = "";

    [JsonPropertyName("path_prefix")]
    public string PathPrefix { get; set; } = "";

    [JsonPropertyName("transport")]
    public string Transport { get; set; } = "tcp+udp";

    [JsonPropertyName("backend_mode")]
    public string BackendMode { get; set; } = "current_mode";

    [JsonPropertyName("speed_limit_mbps")]
    public double? SpeedLimitMbps { get; set; }

    [JsonPropertyName("endpoints")]
    public List<EndpointConfig> Endpoints { get; set; } = [];

    [JsonPropertyName("bonding")]
    public BondingConfig? Bonding { get; set; }

    [JsonPropertyName("mptcp")]
    public MptcpConfig? Mptcp { get; set; }
}
