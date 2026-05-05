using System.Text.Json.Serialization;

namespace LeikwanBridge.Core.Models;

public sealed class DesktopClientChoice
{
    [JsonPropertyName("id")]
    public int Id { get; set; }

    [JsonPropertyName("name")]
    public string Name { get; set; } = "";
}
