using System.Text.Json.Serialization;

namespace LeikwanBridge.Core.Models;

public sealed class PullResponse
{
    [JsonPropertyName("ok")]
    public bool Ok { get; set; }

    [JsonPropertyName("config_revision")]
    public string ConfigRevision { get; set; } = "";

    [JsonPropertyName("forward_revision")]
    public string ForwardRevision { get; set; } = "";

    [JsonPropertyName("force_apply")]
    public bool ForceApply { get; set; }

    [JsonPropertyName("next_poll_after_ms")]
    public int NextPollAfterMs { get; set; }

    [JsonIgnore]
    public bool NotModified { get; set; }

    [JsonPropertyName("client")]
    public ClientMeta? Client { get; set; }

    [JsonPropertyName("forwards")]
    public List<ForwardConfig> Forwards { get; set; } = [];
}
