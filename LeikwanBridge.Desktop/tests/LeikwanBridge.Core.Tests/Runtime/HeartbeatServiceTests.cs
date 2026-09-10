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
    public async Task SendHeartbeatAsync_ReportsBoundedCandidateFeedbackWithOpaqueLeaseHash()
    {
        using var tempDir = new TempStateDirectory();
        var statusPath = Path.Combine(tempDir.Path, "runtime", "ultra-forward-42.status.json");
        Directory.CreateDirectory(Path.GetDirectoryName(statusPath)!);
        File.WriteAllText(statusPath, $$"""
        {
          "forward_id": 42,
          "policy_generation": "{{new string('a', 64)}}",
          "candidate_feedback": [
            {
              "candidate_key_hash": "{{new string('b', 64)}}",
              "lease_hash": "{{new string('c', 64)}}",
              "protocol": "tcp",
              "outcome": "failure",
              "reason": "relay_connect_timeout",
              "evidence_id": "desktop-feedback-1",
              "observed_at": "2030-01-01T00:00:01Z"
            },
            {
              "candidate_key_hash": "{{new string('d', 64)}}",
              "lease_hash": "{{new string('e', 64)}}",
              "protocol": "tcp",
              "outcome": "failure",
              "reason": "process_stderr",
              "evidence_id": "desktop-stderr"
            }
          ]
        }
        """);
        using var server = new HeartbeatCaptureServer();
        using var api = new MasterApiClient(server.BaseUrl, "agent-token");
        await new HeartbeatService(api, new StateManager(tempDir.Path)).SendHeartbeatAsync();

        using var document = JsonDocument.Parse(server.LastRequestBody);
        var feedback = Assert.Single(document.RootElement.GetProperty("ultra_candidate_feedback").EnumerateArray());
        Assert.Equal(new string('b', 64), feedback.GetProperty("candidate_key_hash").GetString());
        Assert.Equal(new string('c', 64), feedback.GetProperty("lease_hash").GetString());
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
    public async Task SendHeartbeatAsync_UsesOnlyNewestStatusPerForward()
    {
        using var tempDir = new TempStateDirectory();
        var runtimeRoot = Path.Combine(tempDir.Path, "runtime");
        var oldDirectory = Path.Combine(runtimeRoot, "ultra-42-old");
        var currentDirectory = Path.Combine(runtimeRoot, "ultra-42-current");
        Directory.CreateDirectory(oldDirectory);
        Directory.CreateDirectory(currentDirectory);
        File.WriteAllText(Path.Combine(oldDirectory, "status.json"), $$"""
        {
          "forward_id": 42,
          "policy_generation": "{{new string('a', 64)}}",
          "bytes_in": 100,
          "bytes_out": 200,
          "updated_at": "2030-01-01T00:00:01Z"
        }
        """);
        File.WriteAllText(Path.Combine(currentDirectory, "status.json"), $$"""
        {
          "forward_id": 42,
          "policy_generation": "{{new string('b', 64)}}",
          "bytes_in": 300,
          "bytes_out": 400,
          "updated_at": "2030-01-01T00:00:02Z"
        }
        """);

        using var server = new HeartbeatCaptureServer();
        using var api = new MasterApiClient(server.BaseUrl, "agent-token");
        await new HeartbeatService(api, new StateManager(tempDir.Path)).SendHeartbeatAsync();

        using var document = JsonDocument.Parse(server.LastRequestBody);
        var ultra = Assert.Single(document.RootElement.GetProperty("ultra_usage").EnumerateArray());
        Assert.Equal(new string('b', 64), ultra.GetProperty("policy_generation").GetString());
        Assert.Equal(300, ultra.GetProperty("bytes_in").GetInt64());
        Assert.Equal(400, ultra.GetProperty("bytes_out").GetInt64());
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

    [Fact]
    public async Task SendHeartbeatAsync_CollectsNativeRuntimeOutsideStateDirectory()
    {
        using var tempDir = new TempStateDirectory();
        var nativeDirectory = Path.Combine(tempDir.Path, "native-runtime", "native-usage", "native-180-active");
        Directory.CreateDirectory(nativeDirectory);
        File.WriteAllText(Path.Combine(nativeDirectory, "status.json"), $$"""
        {
          "forward_id": 180,
          "policy_generation": "{{new string('a', 64)}}",
          "bytes_in": 204435000,
          "bytes_out": 3700000,
          "updated_at": "2030-01-01T00:00:01Z"
        }
        """);
        var ordinaryDirectory = Path.Combine(tempDir.Path, "runtime");
        Directory.CreateDirectory(ordinaryDirectory);
        File.WriteAllText(Path.Combine(ordinaryDirectory, "ultra-forward-180.status.json"), $$"""
        {
          "forward_id": 180,
          "policy_generation": "{{new string('a', 64)}}",
          "bytes_in": 4,
          "bytes_out": 0,
          "updated_at": "2030-01-01T00:00:02Z"
        }
        """);

        using var server = new HeartbeatCaptureServer();
        using var api = new MasterApiClient(server.BaseUrl, "agent-token");
        var service = new HeartbeatService(api, new StateManager(tempDir.Path), additionalRuntimeDirectories: [Path.Combine(tempDir.Path, "native-runtime")]);
        await service.SendHeartbeatAsync();
        File.WriteAllText(Path.Combine(nativeDirectory, "status.json"), $$"""
        {
          "forward_id": 180,
          "policy_generation": "{{new string('a', 64)}}",
          "bytes_in": 204435687,
          "bytes_out": 3774933,
          "updated_at": "2030-01-01T00:00:03Z"
        }
        """);
        await service.SendHeartbeatAsync();

        using var document = JsonDocument.Parse(server.LastRequestBody);
        var usage = Assert.Single(document.RootElement.GetProperty("ultra_usage").EnumerateArray());
        Assert.Equal(687, usage.GetProperty("bytes_in").GetInt64());
        Assert.Equal(74933, usage.GetProperty("bytes_out").GetInt64());
    }

    [Fact]
    public async Task SendHeartbeatAsync_TracksNativeInstancesWithoutDuplicateDeltas()
    {
        using var tempDir = new TempStateDirectory();
        var nativeRoot = Path.Combine(tempDir.Path, "native-runtime");
        var firstPath = Path.Combine(nativeRoot, "native-usage", "native-180-first", "status.json");
        var secondPath = Path.Combine(nativeRoot, "native-usage", "native-180-second", "status.json");
        WriteStatus(firstPath, 100, 20, "2030-01-01T00:00:01Z");
        WriteStatus(secondPath, 300, 40, "2030-01-01T00:00:02Z");
        using var server = new HeartbeatCaptureServer();
        using var api = new MasterApiClient(server.BaseUrl, "agent-token");
        var service = new HeartbeatService(api, new StateManager(tempDir.Path), additionalRuntimeDirectories: [nativeRoot]);

        await service.SendHeartbeatAsync();
        AssertUsage(server.LastRequestBody, 400, 60, 1);

        WriteStatus(firstPath, 150, 30, "2030-01-01T00:00:03Z");
        WriteStatus(secondPath, 350, 50, "2030-01-01T00:00:04Z");
        await service.SendHeartbeatAsync();
        using var secondDocument = JsonDocument.Parse(server.LastRequestBody);
        var secondUsage = Assert.Single(secondDocument.RootElement.GetProperty("ultra_usage").EnumerateArray());
        Assert.Equal(100, secondUsage.GetProperty("bytes_in").GetInt64());
        Assert.Equal(20, secondUsage.GetProperty("bytes_out").GetInt64());
    }

    private static void WriteStatus(string path, long bytesIn, long bytesOut, string updatedAt, int forwardId = 180)
    {
        Directory.CreateDirectory(Path.GetDirectoryName(path)!);
        File.WriteAllText(path, $$"""
        {
          "forward_id": {{forwardId}},
          "policy_generation": "{{new string('a', 64)}}",
          "bytes_in": {{bytesIn}},
          "bytes_out": {{bytesOut}},
          "updated_at": "{{updatedAt}}"
        }
        """);
    }

    [Fact]
    public async Task SendHeartbeatAsync_NewNativeRuntimeIncludesFirstBytesAndRetainsStopDelta()
    {
        using var temp = new TempStateDirectory();
        var nativeRoot = Path.Combine(temp.Path, "proxy-runtime");
        var status = Path.Combine(nativeRoot, "native-usage", "native-180-new", "status.json");
        using var server = new HeartbeatCaptureServer();
        using var api = new MasterApiClient(server.BaseUrl, "agent-token");
        var state = new StateManager(temp.Path);
        var service = new HeartbeatService(api, state, additionalRuntimeDirectories: [nativeRoot]);
        WriteStatus(status, 100, 50, "2030-01-01T00:00:01Z");
        await service.SendHeartbeatAsync();
        AssertUsage(server.LastRequestBody, 100, 50, 1);
        WriteStatus(status, 107, 59, "2030-01-01T00:00:02Z");
        File.WriteAllText(Path.Combine(Path.GetDirectoryName(status)!, "complete"), "");
        await new HeartbeatService(api, state, additionalRuntimeDirectories: [nativeRoot]).SendHeartbeatAsync();
        AssertUsage(server.LastRequestBody, 7, 9, 2);
        await service.SendHeartbeatAsync();
        using var last = JsonDocument.Parse(server.LastRequestBody);
        Assert.Empty(last.RootElement.GetProperty("ultra_usage").EnumerateArray());
    }

    [Fact]
    public async Task SendHeartbeatAsync_RetryUsesPersistedExactSequenceAndBytes()
    {
        using var temp = new TempStateDirectory();
        var runtimeRoot = Path.Combine(temp.Path, "proxy-runtime");
        var status = Path.Combine(runtimeRoot, "native-usage", "native-180-retry", "status.json");
        var state = new StateManager(temp.Path);
        using (var failing = new HeartbeatCaptureServer(statusCode: 503))
        using (var api = new MasterApiClient(failing.BaseUrl, "agent-token"))
        {
            var failingService = new HeartbeatService(api, state, additionalRuntimeDirectories: [runtimeRoot]);
            await Assert.ThrowsAsync<HttpRequestException>(() => failingService.SendHeartbeatAsync());
            WriteStatus(status, 100, 20, "2030-01-01T00:00:01Z");
            await Assert.ThrowsAsync<HttpRequestException>(() => failingService.SendHeartbeatAsync());
            AssertUsage(failing.LastRequestBody, 100, 20, 1);
        }
        WriteStatus(status, 300, 40, "2030-01-01T00:00:02Z");
        using var server = new HeartbeatCaptureServer();
        using var success = new MasterApiClient(server.BaseUrl, "agent-token");
        var service = new HeartbeatService(success, state, additionalRuntimeDirectories: [runtimeRoot]);
        await service.SendHeartbeatAsync();
        AssertUsage(server.LastRequestBody, 100, 20, 1);
        await service.SendHeartbeatAsync();
        AssertUsage(server.LastRequestBody, 200, 20, 2);
    }

    [Fact]
    public async Task SendHeartbeatAsync_MissingUsageAcknowledgementDoesNotCommitCursors()
    {
        using var temp = new TempStateDirectory();
        var root = Path.Combine(temp.Path, "proxy-runtime");
        var status = Path.Combine(root, "native-usage", "native-180-ack", "status.json");
        using var server = new HeartbeatCaptureServer(responseBody: "{\"ok\":false}");
        using var api = new MasterApiClient(server.BaseUrl, "agent-token");
        var service = new HeartbeatService(api, new StateManager(temp.Path), additionalRuntimeDirectories: [root]);
        await service.SendHeartbeatAsync();
        WriteStatus(status, 100, 20, "2030-01-01T00:00:01Z");
        await Assert.ThrowsAsync<HttpRequestException>(() => service.SendHeartbeatAsync());
    }

    [Fact]
    public async Task SendHeartbeatAsync_RetriesUsageGroupsBeyondPanelBatchLimit()
    {
        using var temp = new TempStateDirectory();
        var root = Path.Combine(temp.Path, "proxy-runtime");
        using var server = new HeartbeatCaptureServer();
        using var api = new MasterApiClient(server.BaseUrl, "agent-token");
        var service = new HeartbeatService(api, new StateManager(temp.Path), additionalRuntimeDirectories: [root]);
        await service.SendHeartbeatAsync();
        for (var i = 1; i <= 33; i++)
        {
            var path = Path.Combine(root, "native-usage", $"native-{i}", "status.json");
            WriteStatus(path, i, 0, $"2030-01-01T00:00:{i:00}Z", forwardId: i);
        }

        await service.SendHeartbeatAsync();
        using (var first = JsonDocument.Parse(server.LastRequestBody))
            Assert.Equal(32, first.RootElement.GetProperty("ultra_usage").GetArrayLength());
        await service.SendHeartbeatAsync();
        using var second = JsonDocument.Parse(server.LastRequestBody);
        var usage = Assert.Single(second.RootElement.GetProperty("ultra_usage").EnumerateArray());
        Assert.Equal(33, usage.GetProperty("forward_id").GetInt32());
        Assert.Equal(33, usage.GetProperty("bytes_in").GetInt64());
    }

    [Fact]
    public async Task SendHeartbeatAsync_CountsNewLayoutFromFirstSampleAndIgnoresOldNativeHistory()
    {
        using var temp = new TempStateDirectory();
        var root = Path.Combine(temp.Path, "proxy-runtime");
        WriteStatus(Path.Combine(root, "native-180-old", "status.json"), 200000000, 4000000, "2030-01-01T00:00:01Z");
        WriteStatus(Path.Combine(root, "native-usage", "native-180-new", "status.json"), 100, 20, "2030-01-01T00:00:02Z");
        using var server = new HeartbeatCaptureServer(responseBody: "{\"ok\":true}");
        using var api = new MasterApiClient(server.BaseUrl, "agent-token");
        var service = new HeartbeatService(api, new StateManager(temp.Path), additionalRuntimeDirectories: [root]);
        await service.SendHeartbeatAsync();
        AssertUsage(server.LastRequestBody, 100, 20, 1);
        await service.SendHeartbeatAsync();
        using var again = JsonDocument.Parse(server.LastRequestBody);
        Assert.Empty(again.RootElement.GetProperty("ultra_usage").EnumerateArray());
    }

    [Fact]
    public async Task SendHeartbeatAsync_SeparatesMacRuntimeNativeUsageFromLegacyNativeHistory()
    {
        using var temp = new TempStateDirectory();
        var runtimeRoot = Path.Combine(temp.Path, "runtime");
        WriteStatus(Path.Combine(runtimeRoot, "native-180-old", "status.json"), 200000000, 4000000, "2030-01-01T00:00:00Z");
        WriteStatus(Path.Combine(runtimeRoot, "native-usage", "native-180-first", "status.json"), 100, 20, "2030-01-01T00:00:01Z");
        WriteStatus(Path.Combine(runtimeRoot, "native-usage", "native-180-second", "status.json"), 40, 6, "2030-01-01T00:00:02Z");
        using var server = new HeartbeatCaptureServer();
        using var api = new MasterApiClient(server.BaseUrl, "agent-token");
        var service = new HeartbeatService(api, new StateManager(temp.Path), additionalRuntimeDirectories: [runtimeRoot]);

        await service.SendHeartbeatAsync();

        AssertUsage(server.LastRequestBody, 140, 26, 1);
    }

    [Fact]
    public async Task SendHeartbeatAsync_ExplicitUsageRejectionRetainsOutboxDespiteOk()
    {
        using var temp = new TempStateDirectory();
        var root = Path.Combine(temp.Path, "runtime");
        WriteStatus(Path.Combine(root, "ultra-180", "status.json"), 100, 20, "2030-01-01T00:00:01Z");
        using var server = new HeartbeatCaptureServer(responseBody: "{\"ok\":true,\"ultra_usage_acknowledged\":false}");
        using var api = new MasterApiClient(server.BaseUrl, "agent-token");
        var state = new StateManager(temp.Path);
        var service = new HeartbeatService(api, state);
        await Assert.ThrowsAsync<HttpRequestException>(() => service.SendHeartbeatAsync());
        var pending = Assert.Single(state.LoadUsageState().PendingUltraUsage);
        Assert.Equal(100, pending.BytesIn);
        Assert.Equal(20, pending.BytesOut);
        Assert.Equal(1, pending.Sequence);
    }

    [Fact]
    public async Task SendHeartbeatAsync_SplitsLargeAggregateWithoutLosingRemainder()
    {
        const long cap = 107374182400;
        using var temp = new TempStateDirectory();
        var root = Path.Combine(temp.Path, "proxy-runtime");
        using var server = new HeartbeatCaptureServer();
        using var api = new MasterApiClient(server.BaseUrl, "agent-token");
        var service = new HeartbeatService(api, new StateManager(temp.Path), additionalRuntimeDirectories: [root]);
        await service.SendHeartbeatAsync();
        WriteStatus(Path.Combine(root, "native-usage", "native-180-a", "status.json"), cap, cap + 50, "2030-01-01T00:00:01Z");
        WriteStatus(Path.Combine(root, "native-usage", "native-180-b", "status.json"), 70, 10, "2030-01-01T00:00:02Z");
        await service.SendHeartbeatAsync();
        AssertUsage(server.LastRequestBody, cap, cap, 1);
        await service.SendHeartbeatAsync();
        AssertUsage(server.LastRequestBody, 70, 60, 2);
        await service.SendHeartbeatAsync();
        using var again = JsonDocument.Parse(server.LastRequestBody);
        Assert.Empty(again.RootElement.GetProperty("ultra_usage").EnumerateArray());
    }

    [Fact]
    public async Task SendHeartbeatAsync_CollectsAllRetainedInstancesBeyond128()
    {
        using var temp = new TempStateDirectory();
        var root = Path.Combine(temp.Path, "proxy-runtime");
        using var server = new HeartbeatCaptureServer();
        using var api = new MasterApiClient(server.BaseUrl, "agent-token");
        var service = new HeartbeatService(api, new StateManager(temp.Path), additionalRuntimeDirectories: [root]);
        await service.SendHeartbeatAsync();
        for (var i = 0; i < 140; i++)
            WriteStatus(Path.Combine(root, "native-usage", $"native-180-{i:000}", "status.json"), 1, 2, "2030-01-01T00:00:01Z");
        await service.SendHeartbeatAsync();
        AssertUsage(server.LastRequestBody, 140, 280, 1);
    }

    private static void AssertUsage(string body, long bytesIn, long bytesOut, long sequence)
    {
        using var document = JsonDocument.Parse(body);
        var usage = Assert.Single(document.RootElement.GetProperty("ultra_usage").EnumerateArray());
        Assert.Equal(bytesIn, usage.GetProperty("bytes_in").GetInt64());
        Assert.Equal(bytesOut, usage.GetProperty("bytes_out").GetInt64());
        Assert.Equal(sequence, usage.GetProperty("sequence").GetInt64());
    }

    private sealed class HeartbeatCaptureServer : IDisposable
    {
        private readonly HttpListener _listener = new();
        private readonly CancellationTokenSource _cts = new();
        private readonly Task _serveTask;
        private readonly int _statusCode;
        private readonly string _responseBody;

        public HeartbeatCaptureServer(int statusCode = 200, string responseBody = "{\"ok\":true,\"ultra_usage_acknowledged\":true}")
        {
            _statusCode = statusCode;
            _responseBody = responseBody;
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
                await context.Response.OutputStream.WriteAsync(Encoding.UTF8.GetBytes(_responseBody));
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
