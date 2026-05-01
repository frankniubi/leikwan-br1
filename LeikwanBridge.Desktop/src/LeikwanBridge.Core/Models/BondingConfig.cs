using System.Text.Json.Serialization;

namespace LeikwanBridge.Core.Models;

public sealed class BondingConfig
{
    [JsonPropertyName("enabled")]
    public bool Enabled { get; set; }

    [JsonPropertyName("hybrid_mode")]
    public string HybridMode { get; set; } = "quic_ws";

    [JsonPropertyName("sidecar_host")]
    public string SidecarHost { get; set; } = "";

    [JsonPropertyName("sidecar_port")]
    public int SidecarPort { get; set; }

    [JsonPropertyName("aggregator_host")]
    public string AggregatorHost { get; set; } = "";

    [JsonPropertyName("aggregator_port")]
    public int AggregatorPort { get; set; }

    [JsonPropertyName("relay_host")]
    public string RelayHost { get; set; } = "";

    [JsonPropertyName("relay_port")]
    public int RelayPort { get; set; }

    [JsonPropertyName("remote_target")]
    public string RemoteTarget { get; set; } = "";

    [JsonPropertyName("ingress_mode")]
    public string IngressMode { get; set; } = "wss_path_mux";

    [JsonPropertyName("route_key")]
    public string RouteKey { get; set; } = "";

    [JsonPropertyName("session_token")]
    public string SessionToken { get; set; } = "";

    [JsonPropertyName("udp_session_token")]
    public string UdpSessionToken { get; set; } = "";

    [JsonPropertyName("chunk_size")]
    public int ChunkSize { get; set; } = 65536;

    [JsonPropertyName("max_window_bytes")]
    public int MaxWindowBytes { get; set; } = 8388608;

    [JsonPropertyName("retransmit_ms")]
    public int RetransmitMs { get; set; } = 700;

    [JsonPropertyName("connect_timeout_ms")]
    public int ConnectTimeoutMs { get; set; } = 3000;

    [JsonPropertyName("join_timeout_ms")]
    public int JoinTimeoutMs { get; set; } = 400;

    [JsonPropertyName("interactive_chunk_bytes")]
    public int InteractiveChunkBytes { get; set; } = 16384;

    [JsonPropertyName("latency_guard_bytes")]
    public int LatencyGuardBytes { get; set; } = 262144;

    [JsonPropertyName("interactive_rtt_slack_ms")]
    public int InteractiveRttSlackMs { get; set; } = 30;

    [JsonPropertyName("warmup_trigger_bytes")]
    public int WarmupTriggerBytes { get; set; } = 65536;

    [JsonPropertyName("warmup_slice_bytes")]
    public int WarmupSliceBytes { get; set; } = 65536;

    [JsonPropertyName("warmup_leg_delay_ms")]
    public int WarmupLegDelayMs { get; set; } = 20;

    [JsonPropertyName("bulk_promote_bytes")]
    public int BulkPromoteBytes { get; set; } = 262144;

    [JsonPropertyName("leg_cooldown_ms")]
    public int LegCooldownMs { get; set; } = 3000;

    [JsonPropertyName("quic_enabled")]
    public bool QuicEnabled { get; set; } = true;

    [JsonPropertyName("quic_via_slave")]
    public bool QuicViaSlave { get; set; } = true;

    [JsonPropertyName("quic_direct_enabled")]
    public bool QuicDirectEnabled { get; set; }

    [JsonPropertyName("active_leg_limit")]
    public int ActiveLegLimit { get; set; } = 2;

    [JsonPropertyName("quic_active_leg_limit")]
    public int QuicActiveLegLimit { get; set; } = 2;

    [JsonPropertyName("wss_standby_enabled")]
    public bool WssStandbyEnabled { get; set; } = true;

    [JsonPropertyName("wss_standby_mode")]
    public string WssStandbyMode { get; set; } = "warm-passive";

    [JsonPropertyName("disable_local_rate_limit")]
    public bool DisableLocalRateLimit { get; set; } = true;

    [JsonPropertyName("quic_server_name")]
    public string QuicServerName { get; set; } = "";

    [JsonPropertyName("quic_insecure")]
    public bool QuicInsecure { get; set; } = true;

    [JsonPropertyName("quic_priority")]
    public int QuicPriority { get; set; }

    [JsonPropertyName("ws_priority")]
    public int WsPriority { get; set; } = 10;

    [JsonPropertyName("adaptive_join_timeout")]
    public bool AdaptiveJoinTimeout { get; set; } = true;

    [JsonPropertyName("join_timeout_floor_ms")]
    public int JoinTimeoutFloorMs { get; set; } = 35;

    [JsonPropertyName("join_timeout_per_leg_ms")]
    public int JoinTimeoutPerLegMs { get; set; } = 20;

    [JsonPropertyName("join_timeout_ceiling_ms")]
    public int JoinTimeoutCeilingMs { get; set; } = 220;
}
