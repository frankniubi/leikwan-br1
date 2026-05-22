using System.Net;
using System.Net.Sockets;
using System.Text;
using System.Text.Json;
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

    private sealed class HeartbeatCaptureServer : IDisposable
    {
        private readonly HttpListener _listener = new();
        private readonly CancellationTokenSource _cts = new();
        private readonly Task _serveTask;

        public HeartbeatCaptureServer()
        {
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

                context.Response.StatusCode = 200;
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
