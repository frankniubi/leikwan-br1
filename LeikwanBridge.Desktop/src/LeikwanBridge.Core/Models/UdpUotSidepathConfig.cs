using System.Text.Json.Serialization;

namespace LeikwanBridge.Core.Models;

public sealed class UdpUotSidepathConfig
{
    private const int MaxProcessQueueBytes = 64 * 1024 * 1024;
    private const int MaxProcessQueuePackets = 262_144;
    private const int MaxProcessSessions = 65_536;

    [JsonPropertyName("enabled_forward_ids")]
    public List<int> EnabledForwardIds { get; set; } = [];

    [JsonPropertyName("shadow_route_suffix")]
    public string ShadowRouteSuffix { get; set; } = ".uot";

    [JsonPropertyName("sidecar_port")]
    public int SidecarPort { get; set; } = 8999;

    [JsonPropertyName("reorder_window_packets")]
    public int ReorderWindowPackets { get; set; } = 4096;

    [JsonPropertyName("reorder_window_bytes")]
    public int ReorderWindowBytes { get; set; } = 16 * 1024 * 1024;

    [JsonPropertyName("reorder_timeout_ms")]
    public int ReorderTimeoutMs { get; set; } = 25;

    [JsonPropertyName("delivery_mode")]
    public string DeliveryMode { get; set; } = "ordered";

    [JsonPropertyName("wireguard_aware")]
    public bool WireguardAware { get; set; }

    [JsonPropertyName("scheduler_mode")]
    public string SchedulerMode { get; set; } = "legacy";

    [JsonPropertyName("aggregate_rate_bps")]
    public long AggregateRateBps { get; set; } = 300_000_000;

    [JsonPropertyName("queue_target_ms")]
    public int QueueTargetMs { get; set; } = 5;

    [JsonPropertyName("queue_deadline_ms")]
    public int QueueDeadlineMs { get; set; } = 10;

    [JsonPropertyName("global_queue_bytes")]
    public int GlobalQueueBytes { get; set; } = 1_048_576;

    [JsonPropertyName("global_queue_packets")]
    public int GlobalQueuePackets { get; set; } = 8_192;

    [JsonPropertyName("per_session_queue_bytes")]
    public int PerSessionQueueBytes { get; set; } = 262_144;

    [JsonPropertyName("per_session_queue_packets")]
    public int PerSessionQueuePackets { get; set; } = 512;

    [JsonPropertyName("drr_quantum_bytes")]
    public int DrrQuantumBytes { get; set; } = 16_384;

    [JsonPropertyName("chunk_min_bytes")]
    public int ChunkMinBytes { get; set; } = 16_384;

    [JsonPropertyName("chunk_target_bytes")]
    public int ChunkTargetBytes { get; set; } = 32_768;

    [JsonPropertyName("chunk_max_bytes")]
    public int ChunkMaxBytes { get; set; } = 65_536;

    [JsonPropertyName("min_leg_window_bytes")]
    public int MinLegWindowBytes { get; set; } = 262_144;

    [JsonPropertyName("max_leg_window_bytes")]
    public int MaxLegWindowBytes { get; set; } = 4_194_304;

    [JsonPropertyName("max_total_outstanding_bytes")]
    public int MaxTotalOutstandingBytes { get; set; } = 8_388_608;

    [JsonPropertyName("progress_interval_bytes")]
    public int ProgressIntervalBytes { get; set; } = 65_536;

    [JsonPropertyName("progress_interval_frames")]
    public int ProgressIntervalFrames { get; set; } = 32;

    [JsonPropertyName("progress_interval_ms")]
    public int ProgressIntervalMs { get; set; } = 5;

    [JsonPropertyName("finish_skew_budget_ms")]
    public int FinishSkewBudgetMs { get; set; } = 2;

    [JsonPropertyName("probe_interval_ms")]
    public int ProbeIntervalMs { get; set; } = 1_000;

    [JsonPropertyName("max_sessions")]
    public int MaxSessions { get; set; } = 4_096;

    [JsonPropertyName("control_queue_packets")]
    public int ControlQueuePackets { get; set; } = 256;

    public void Validate()
    {
        if (SidecarPort is < 7000 or > 9000)
            throw new InvalidOperationException("udp_uot_sidepath sidecar_port must be within 7000-9000");
        if (SchedulerMode is not ("legacy" or "feedback_drr"))
            throw new InvalidOperationException("unsupported udp_uot_sidepath scheduler_mode");
        if (DeliveryMode is not ("ordered" or "unordered"))
            throw new InvalidOperationException("unsupported udp_uot_sidepath delivery_mode");
        if (DeliveryMode == "unordered" && !WireguardAware)
            throw new InvalidOperationException("unordered delivery requires wireguard_aware=true");
        if (SchedulerMode == "feedback_drr" && DeliveryMode != "unordered")
            throw new InvalidOperationException("feedback_drr requires unordered delivery");
        if (AggregateRateBps <= 0)
            throw new InvalidOperationException("aggregate_rate_bps must be positive");
        if (QueueTargetMs < 0 || QueueTargetMs > QueueDeadlineMs || QueueDeadlineMs <= 0)
            throw new InvalidOperationException("queue target/deadline is invalid");
        if (GlobalQueueBytes <= 0 || GlobalQueueBytes > MaxProcessQueueBytes
            || GlobalQueuePackets <= 0 || GlobalQueuePackets > MaxProcessQueuePackets)
            throw new InvalidOperationException("global queue limits are outside process bounds");
        if (PerSessionQueueBytes <= 0 || PerSessionQueueBytes > GlobalQueueBytes
            || PerSessionQueuePackets <= 0 || PerSessionQueuePackets > GlobalQueuePackets)
            throw new InvalidOperationException("per-session queue limits must fit global queue limits");
        if (DrrQuantumBytes <= 0)
            throw new InvalidOperationException("drr_quantum_bytes must be positive");
        if (ChunkMinBytes <= 0 || ChunkMinBytes > ChunkTargetBytes
            || ChunkTargetBytes > ChunkMaxBytes || ChunkMaxBytes < 65_535)
            throw new InvalidOperationException("chunk sizes are invalid");
        if (MinLegWindowBytes <= 0 || MinLegWindowBytes > MaxLegWindowBytes
            || MaxLegWindowBytes > MaxTotalOutstandingBytes
            || MaxTotalOutstandingBytes > MaxProcessQueueBytes)
            throw new InvalidOperationException("leg windows are outside process bounds");
        if (ProgressIntervalBytes <= 0 || ProgressIntervalFrames <= 0
            || ProgressIntervalMs <= 0 || ProbeIntervalMs <= 0)
            throw new InvalidOperationException("progress and probe intervals must be positive");
        if (MaxSessions <= 0 || MaxSessions > MaxProcessSessions)
            throw new InvalidOperationException("max_sessions is outside process bounds");
        if (ControlQueuePackets <= 0 || ControlQueuePackets > MaxProcessQueuePackets)
            throw new InvalidOperationException("control_queue_packets is outside process bounds");
        if (ReorderWindowPackets <= 0 || ReorderWindowBytes <= 0 || ReorderTimeoutMs <= 0)
            throw new InvalidOperationException("reorder limits must be positive");
    }
}
