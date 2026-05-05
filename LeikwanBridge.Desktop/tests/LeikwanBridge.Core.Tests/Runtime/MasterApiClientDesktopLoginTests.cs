using LeikwanBridge.Core.Models;
using Xunit;

namespace LeikwanBridge.Core.Tests.Runtime;

public sealed class MasterApiClientDesktopLoginTests
{
    [Fact]
    public void DesktopLoginResponse_DeserializesClientChoices()
    {
        var json = """
{
  "ok": true,
  "session_token": "desktop-session-token",
  "user_id": 8,
  "username": "Ops",
  "email": "ops@example.com",
  "clients": [
    { "id": 26, "name": "Client 26" },
    { "id": 27, "name": "Client 27" }
  ]
}
""";

        var result = System.Text.Json.JsonSerializer.Deserialize<DesktopLoginResponse>(json)!;

        Assert.True(result.Ok);
        Assert.Equal("desktop-session-token", result.SessionToken);
        Assert.Equal(2, result.Clients.Count);
        Assert.Equal(26, result.Clients[0].Id);
    }

    [Fact]
    public void ClientSelectionResponse_DeserializesAgentToken()
    {
        var json = """
{
  "ok": true,
  "client_id": 27,
  "client_name": "Client 27",
  "agent_token": "agent-token-27"
}
""";

        var result = System.Text.Json.JsonSerializer.Deserialize<ClientSelectionResponse>(json)!;

        Assert.True(result.Ok);
        Assert.Equal(27, result.ClientId);
        Assert.Equal("agent-token-27", result.AgentToken);
    }
}
