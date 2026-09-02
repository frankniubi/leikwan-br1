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

    [JsonPropertyName("contract_version")]
    public int ContractVersion { get; set; }

    [JsonPropertyName("client_mode")]
    public string ClientMode { get; set; } = "";

    [JsonPropertyName("runtime_preference")]
    public string RuntimePreference { get; set; } = "single";

    [JsonPropertyName("forward_combination_direction")]
    public string ForwardCombinationDirection { get; set; } = "";

    [JsonPropertyName("bond_ingress_mode")]
    public string BondIngressMode { get; set; } = "";

    [JsonPropertyName("active_family")]
    public string ActiveFamily { get; set; } = "";

    [JsonPropertyName("aggregation_enabled")]
    public bool AggregationEnabled { get; set; }

    [JsonPropertyName("target_contract")]
    public string TargetContract { get; set; } = "";

    [JsonPropertyName("sidecar_required")]
    public bool SidecarRequired { get; set; }

    [JsonPropertyName("bond_sidecar_point_id")]
    public int? BondSidecarPointId { get; set; }

    [JsonPropertyName("speed_limit_mbps")]
    public double? SpeedLimitMbps { get; set; }

    [JsonPropertyName("endpoints")]
    public List<EndpointConfig> Endpoints { get; set; } = [];

    [JsonPropertyName("bonding")]
    public BondingConfig? Bonding { get; set; }

    [JsonPropertyName("mptcp")]
    public MptcpConfig? Mptcp { get; set; }

    [JsonPropertyName("ultra_access")]
    public UltraPolicy? UltraAccess { get; set; }

    [JsonIgnore]
    public UltraLease? RuntimeLease { get; set; }

    [JsonIgnore]
    public List<UltraLease> RuntimeLeases { get; set; } = [];

    [JsonIgnore]
    public NativeOutboundCapability? NativeOutbound { get; set; }

    [JsonIgnore]
    public string NativeRouteScope { get; set; } = "";
}
