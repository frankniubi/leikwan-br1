using System.Net;
using System.Net.Sockets;
using System.Text;
using System.Text.Json;
using LeikwanBridge.Core.Models;
using LeikwanBridge.Core.Services;
using Xunit;

namespace LeikwanBridge.Core.Tests.Runtime;

public sealed class HeartbeatServiceTests
{
    [Fact]
    public async Task SendHeartbeatAsync_ReportsPortableWindowsMptcpSupport()
    {
        using var tempDir = new TempStateDirectory();
        using var server = new HeartbeatCaptureServer();
        using var api = new MasterApiClient(server.BaseUrl, "agent-token");
        var service = new HeartbeatService(api, new StateManager(tempDir.Path));

        await service.SendHeartbeatAsync();

        using var document = JsonDocument.Parse(server.LastRequestBody);
        Assert.True(document.RootElement.GetProperty("supports_mptcp").GetBoolean());
    }

    [Fact]
    public async Task SendHeartbeatAsync_UsesMacIdentityAndFindsNestedUltraStatus()
    {
        using var tempDir = new TempStateDirectory();
        var statusDirectory = Path.Combine(tempDir.Path, "runtime", "ultra-42-random");
        Directory.CreateDirectory(statusDirectory);
        File.WriteAllText(Path.Combine(statusDirectory, "status.json"), $$"""
        {
          "forward_id": 42,
          "policy_generation": "{{new string('a', 64)}}",
          "bytes_in": 100,
          "bytes_out": 200
        }
        """);
        using var server = new HeartbeatCaptureServer();
        using var api = new MasterApiClient(server.BaseUrl, "agent-token");
        var identity = new ClientPlatformIdentity(
            "macos-desktop", "device", "Mac", "Leikwan-Mac-GUI", "9.8.7", SupportsMptcp: false);
        var service = new HeartbeatService(api, new StateManager(tempDir.Path), platformIdentity: identity);

        await service.SendHeartbeatAsync();

        using var document = JsonDocument.Parse(server.LastRequestBody);
        Assert.Equal("9.8.7", document.RootElement.GetProperty("agent_version").GetString());
        Assert.False(document.RootElement.GetProperty("supports_mptcp").GetBoolean());
        var ultra = Assert.Single(document.RootElement.GetProperty("ultra_usage").EnumerateArray());
        Assert.Equal(42, ultra.GetProperty("forward_id").GetInt32());
        Assert.Equal(100, ultra.GetProperty("bytes_in").GetInt64());
        Assert.Equal(200, ultra.GetProperty("bytes_out").GetInt64());
    }

    [Fact]
    public async Task SendHeartbeatAsync_DoesNotAdvanceUsageUntilThePanelAcceptsIt()
    {
        using var tempDir = new TempStateDirectory();
        var state = new StateManager(tempDir.Path);
        state.SaveUsageState(new UsageState { TotalBytesIn = 100, TotalBytesOut = 200 });
        using (var failingServer = new HeartbeatCaptureServer(statusCode: 503))
        using (var failingApi = new MasterApiClient(failingServer.BaseUrl, "agent-token"))
        {
            var service = new HeartbeatService(failingApi, state);
            await Assert.ThrowsAsync<HttpRequestException>(() => service.SendHeartbeatAsync());
        }
        Assert.Equal(0, state.LoadUsageState().LastReportedBytesIn);
        Assert.Equal(0, state.LoadUsageState().LastReportedBytesOut);

        using var successServer = new HeartbeatCaptureServer();
        using var successApi = new MasterApiClient(successServer.BaseUrl, "agent-token");
        await new HeartbeatService(successApi, state).SendHeartbeatAsync();

        Assert.Equal(100, state.LoadUsageState().LastReportedBytesIn);
        Assert.Equal(200, state.LoadUsageState().LastReportedBytesOut);
    }

    private sealed class HeartbeatCaptureServer : IDisposable
    {
        private readonly HttpListener _listener = new();
        private readonly CancellationTokenSource _cts = new();
        private readonly Task _serveTask;
        private readonly int _statusCode;

        public HeartbeatCaptureServer(int statusCode = 200)
        {
            _statusCode = statusCode;
            var port = GetFreePort();
            BaseUrl = $"http://127.0.0.1:{port}";
            _listener.Prefixes.Add($"{BaseUrl}/");
            _listener.Start();
            _serveTask = Task.Run(ServeLoopAsync);
        }

        public string BaseUrl { get; }
        public string LastRequestBody { get; private set; } = "";

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

                using (var reader = new StreamReader(context.Request.InputStream, context.Request.ContentEncoding ?? Encoding.UTF8))
                {
                    LastRequestBody = await reader.ReadToEndAsync();
                }

                context.Response.StatusCode = _statusCode;
                context.Response.ContentType = "application/json";
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
