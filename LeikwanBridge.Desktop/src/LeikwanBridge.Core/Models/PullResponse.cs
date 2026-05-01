using System.Text.Json.Serialization;

namespace LeikwanBridge.Core.Models;

public sealed class PullResponse
{
    [JsonPropertyName("ok")]
    public bool Ok { get; set; }

    [JsonPropertyName("client")]
    public ClientMeta? Client { get; set; }

    [JsonPropertyName("forwards")]
    public List<ForwardConfig> Forwards { get; set; } = [];
}
