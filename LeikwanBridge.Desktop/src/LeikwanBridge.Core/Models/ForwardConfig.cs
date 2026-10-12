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

    [JsonPropertyName("native_outbound")]
    public bool NativeOutboundEnabled { get; set; }

    [JsonPropertyName("native_direction")]
    public string NativeDirection { get; set; } = "";

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

    /// <summary>
    /// Pull-scoped copy of client.allow_inbound_proxy. False (the default, and what an
    /// older panel implies) keeps every forward listener on 127.0.0.1 so the forward
    /// is never an open proxy on the LAN; true binds all interfaces as before.
    /// Ultra listeners stay on loopback regardless.
    /// </summary>
    [JsonIgnore]
    public bool AllowInboundProxy { get; set; }

    /// <summary>IPv4 bind address for this forward's local listener.</summary>
    [JsonIgnore]
    public string ListenHost => AllowInboundProxy ? "0.0.0.0" : "127.0.0.1";

    /// <summary>Bind host in wstunnel -L syntax (all IPv4/IPv6 interfaces or IPv4 loopback).</summary>
    [JsonIgnore]
    public string WstunnelListenHost => AllowInboundProxy ? "[::]" : "127.0.0.1";

    /// <summary>
    /// True for a panel-declared native outbound rule: the panel ships only the
    /// rule, and the data plane belongs to a user-initiated native outbound
    /// relay connection. The agent never owns an ordinary Ultra runtime for it.
    /// Derived only; never serialized, so the JSON contract is unchanged.
    /// </summary>
    [JsonIgnore]
    public bool IsNativeOutboundRule =>
        NativeOutboundEnabled && string.Equals(BackendMode, "ultra", StringComparison.OrdinalIgnoreCase);
}
