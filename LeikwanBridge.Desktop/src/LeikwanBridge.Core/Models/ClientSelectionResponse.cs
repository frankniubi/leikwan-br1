using System.Text.Json.Serialization;

namespace LeikwanBridge.Core.Models;

public sealed class ClientSelectionResponse
{
    [JsonPropertyName("ok")]
    public bool Ok { get; set; }

    [JsonPropertyName("client_id")]
    public int ClientId { get; set; }

    [JsonPropertyName("client_name")]
    public string ClientName { get; set; } = "";

    [JsonPropertyName("agent_token")]
    public string AgentToken { get; set; } = "";
}
