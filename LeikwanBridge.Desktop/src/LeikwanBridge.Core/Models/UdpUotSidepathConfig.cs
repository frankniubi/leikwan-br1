using System.Text.Json.Serialization;

namespace LeikwanBridge.Core.Models;

public sealed class UdpUotSidepathConfig
{
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
}
