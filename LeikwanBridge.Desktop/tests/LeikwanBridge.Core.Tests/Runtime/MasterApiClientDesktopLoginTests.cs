using LeikwanBridge.Core.Models;
using LeikwanBridge.Core.Services;
using System.Net;
using System.Net.Sockets;
using System.Text;
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

    [Fact]
    public async Task PullConfigsAsync_SendsStableDeviceIdentityHeaders()
    {
        using var server = new HeaderCaptureServer();
        using var api = new MasterApiClient(
            server.BaseUrl,
            "agent-token-27",
            "windows-device-123",
            "Leikwan Bridge on DEVBOX",
            "windows-desktop");

        await api.PullConfigsAsync();

        Assert.Equal("windows-device-123", server.LastRequestHeaders["X-Device-Id"]);
        Assert.Equal("Leikwan Bridge on DEVBOX", server.LastRequestHeaders["X-Device-Name"]);
        Assert.Equal("windows-desktop", server.LastRequestHeaders["X-Device-Platform"]);
    }

    [Fact]
    public void BuildWindowsDeviceId_IsStableForSameClientAndMachine()
    {
        var first = MasterApiClient.BuildWindowsDeviceId(27, "DEVBOX");
        var second = MasterApiClient.BuildWindowsDeviceId(27, "DEVBOX");

        Assert.Equal(first, second);
        Assert.StartsWith("windows-client-27-", first);
    }

    [Fact]
    public void BuildWindowsDeviceId_DiffersAcrossClientProfiles()
    {
        var clientA = MasterApiClient.BuildWindowsDeviceId(27, "DEVBOX");
        var clientB = MasterApiClient.BuildWindowsDeviceId(28, "DEVBOX");

        Assert.NotEqual(clientA, clientB);
    }

    [Fact]
    public async Task TestConnectionAsync_ExposesOnlineDeviceLimitError()
    {
        using var server = new HeaderCaptureServer(
            statusCode: 429,
            payload: """
            {
              "error": "client_online_limit_reached",
              "details": {
                "client_id": 27,
                "online_device_limit": 1,
                "active_online_devices": 1
              }
            }
            """);
        using var api = new MasterApiClient(server.BaseUrl, "agent-token-27");

        var ok = await api.TestConnectionAsync();

        Assert.False(ok);
        Assert.Contains("Online device limit reached", api.LastErrorMessage);
        Assert.Contains("1 / 1", api.LastErrorMessage);
    }

    private sealed class HeaderCaptureServer : IDisposable
    {
        private readonly HttpListener _listener = new();
        private readonly CancellationTokenSource _cts = new();
        private readonly Task _serveTask;
        private readonly int _statusCode;
        private readonly string _payload;

        public HeaderCaptureServer(int statusCode = 200, string? payload = null)
        {
            _statusCode = statusCode;
            _payload = payload ?? """
                {
                  "ok": true,
                  "client": { "id": 27, "name": "Client 27", "display_host": "devbox", "agent_config_json": {} },
                  "forwards": []
                }
                """;
            var port = GetFreePort();
            BaseUrl = $"http://127.0.0.1:{port}";
            _listener.Prefixes.Add($"{BaseUrl}/");
            _listener.Start();
            _serveTask = Task.Run(ServeLoopAsync);
        }

        public string BaseUrl { get; }
        public Dictionary<string, string> LastRequestHeaders { get; } = new(StringComparer.OrdinalIgnoreCase);

        private async Task ServeLoopAsync()
        {
            while (!_cts.IsCancellationRequested)
            {
                HttpListenerContext context;
                try
                {
                    context = await _listener.GetContextAsync();
                }
                catch (HttpListenerException)
                {
                    break;
                }
                catch (ObjectDisposedException)
                {
                    break;
                }

                foreach (var key in context.Request.Headers.AllKeys)
                {
                    if (!string.IsNullOrWhiteSpace(key))
                        LastRequestHeaders[key] = context.Request.Headers[key] ?? "";
                }

                var bytes = Encoding.UTF8.GetBytes(_payload);
                context.Response.StatusCode = _statusCode;
                context.Response.ContentType = "application/json";
                context.Response.OutputStream.Write(bytes, 0, bytes.Length);
                context.Response.OutputStream.Close();
            }
        }

        private static int GetFreePort()
        {
            using var listener = new TcpListener(IPAddress.Loopback, 0);
            listener.Start();
            return ((IPEndPoint)listener.LocalEndpoint).Port;
        }

        public void Dispose()
        {
            _cts.Cancel();
            _listener.Close();
            try
            {
                _serveTask.Wait(TimeSpan.FromSeconds(5));
            }
            catch
            {
                // ignore
            }
            _cts.Dispose();
        }
    }
}
