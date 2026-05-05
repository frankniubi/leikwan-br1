using System.Text.Json.Serialization;

namespace LeikwanBridge.Core.Models;

public sealed class DesktopLoginResponse
{
    [JsonPropertyName("ok")]
    public bool Ok { get; set; }

    [JsonPropertyName("session_token")]
    public string SessionToken { get; set; } = "";

    [JsonPropertyName("user_id")]
    public int UserId { get; set; }

    [JsonPropertyName("username")]
    public string Username { get; set; } = "";

    [JsonPropertyName("email")]
    public string Email { get; set; } = "";

    [JsonPropertyName("clients")]
    public List<DesktopClientChoice> Clients { get; set; } = [];
}
