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
    public async Task PullConfigsAsync_SendsMacIdentityWithoutWindowsUserAgent()
    {
        using var server = new HeaderCaptureServer();
        var identity = new ClientPlatformIdentity(
            "macos-desktop",
            "macos-client-0123456789abcdef0123456789abcdef",
            "Test Mac",
            "Leikwan-Mac-GUI",
            MasterApiClient.AgentVersion,
            SupportsMptcp: false);
        using var api = new MasterApiClient(server.BaseUrl, "agent-token-27", platformIdentity: identity);

        await api.PullConfigsAsync();

        Assert.Equal(identity.DeviceId, server.LastRequestHeaders["X-Device-Id"]);
        Assert.Equal(identity.DeviceName, server.LastRequestHeaders["X-Device-Name"]);
        Assert.Equal(identity.Platform, server.LastRequestHeaders["X-Device-Platform"]);
        Assert.Contains("Leikwan-Mac-GUI/", server.LastRequestHeaders["User-Agent"]);
        Assert.DoesNotContain("Windows", server.LastRequestHeaders["User-Agent"], StringComparison.OrdinalIgnoreCase);
    }

    [Fact]
    public async Task PullConfigsAsync_UsesInjectedHttpClientFactory_ForProtectedLeikwanMasterUrl()
    {
        using var http = new HttpClient(new StubPullResponseHandler()) { Timeout = TimeSpan.FromSeconds(5) };
        var requestedUrls = new List<Uri>();
        using var api = new MasterApiClient(
            "https://v3-mirror.leikwanhost.com",
            "agent-token-27",
            httpClientFactory: () => http,
            ownsHttpClient: false,
            onRequestForTest: request => requestedUrls.Add(request.RequestUri!));

        var response = await api.PullConfigsAsync();

        Assert.True(response.Ok);
        Assert.Equal("https://v3-mirror.leikwanhost.com/api/client/configs/pull", requestedUrls.Single().ToString());
    }

    [Fact]
    public async Task PullConfigsAsync_LocalhostMasterUrl_RemainsUsableWithDefaultClient()
    {
        using var server = new HeaderCaptureServer();
        using var api = new MasterApiClient(server.BaseUrl, "agent-token-27");

        var response = await api.PullConfigsAsync();

        Assert.True(response.Ok);
        Assert.Equal("127.0.0.1", new Uri(server.LastRequestUrl).Host);
    }

    [Fact]
    public async Task PullConfigsAsync_ReportsInvalidJsonForSuccessfulHtmlResponse()
    {
        using var http = new HttpClient(new InvalidJsonPullResponseHandler())
        {
            Timeout = TimeSpan.FromSeconds(5),
        };
        using var api = new MasterApiClient(
            "https://v3.leikwanhost.com",
            "agent-token-27",
            httpClientFactory: () => http,
            ownsHttpClient: false);

        var exception = await Assert.ThrowsAsync<MasterApiException>(() => api.PullConfigsAsync());

        Assert.Equal(200, exception.StatusCode);
        Assert.Contains("master_api_invalid_json_response", exception.Message, StringComparison.Ordinal);
        Assert.Contains("text/html", exception.Message, StringComparison.Ordinal);
    }

    [Fact]
    public async Task PullConfigsAsync_ReturnsNotModified_On304_AndThreadsRevision()
    {
        using var http = new HttpClient(new NotModifiedHandler()) { Timeout = TimeSpan.FromSeconds(5) };
        string? requestedUrl = null;
        using var api = new MasterApiClient(
            "https://v3.leikwanhost.com",
            "agent-token-27",
            httpClientFactory: () => http,
            ownsHttpClient: false,
            onRequestForTest: request => requestedUrl = request.RequestUri!.ToString());

        var response = await api.PullConfigsAsync("rev-abc", 10);

        Assert.True(response.Ok);
        Assert.True(response.NotModified);
        Assert.Equal("rev-abc", response.ConfigRevision);
        Assert.Contains("since_revision=rev-abc", requestedUrl);
        Assert.Contains("wait_seconds=10", requestedUrl);
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

    [Fact]
    public async Task ResolveUltraLeaseAsync_UsesPolicyTicketAndKeepsProviderMaterialOutOfRequest()
    {
        const string target = "198.51.100.10:443";
        var generation = new string('a', 64);
        var now = DateTimeOffset.UtcNow;
        var policy = new UltraPolicy
        {
            ProtocolValue = UltraPolicy.Protocol,
            ForwardId = 42,
            PolicyGeneration = generation,
            SessionTicket = "ticket-a",
            IssuedAt = now,
            RenewAfter = now.AddMinutes(1),
            ExpiresAt = now.AddMinutes(4),
            ServiceState = "active",
            Transport = "tcp",
            RateLimitMbps = 20,
            QuotaRemainingBytes = 1024 * 1024,
            TargetDigest = UltraPolicy.ComputeTargetDigest(target),
        };
        var forward = new ForwardConfig
        {
            ForwardId = 42,
            BackendMode = "ultra",
            Mode = "single",
            Target = target,
            Transport = "tcp",
            UltraAccess = policy,
        };
        using var http = new HttpClient(new UltraLeaseHandler(generation, target)) { Timeout = TimeSpan.FromSeconds(5) };
        string? requestBody = null;
        using var api = new MasterApiClient(
            "https://v3.leikwanhost.com",
            "agent-token",
            "windows-client-42-device",
            httpClientFactory: () => http,
            ownsHttpClient: false,
            onRequestForTest: request =>
            {
                if (request.Content is not null)
                    requestBody = request.Content.ReadAsStringAsync().GetAwaiter().GetResult();
            });

        var lease = await api.ResolveUltraLeaseAsync(forward);

        Assert.NotNull(lease);
        Assert.Equal("203.0.113.20", lease!.ProviderIp);
        Assert.Equal(5438, lease.ProviderPort);
        Assert.DoesNotContain("line-password", requestBody, StringComparison.Ordinal);
        Assert.Contains("ticket-a", requestBody, StringComparison.Ordinal);
        Assert.Contains("policy_generation", requestBody, StringComparison.Ordinal);
    }

    [Fact]
    public async Task ResolveNativeOutboundLeaseAsync_UsesCarrierScopeAndScrubsProviderMaterial()
    {
        const int forwardId = 158;
        const string direction = "Ultra Hong Kong to Mainland China";
        const string routeScope = "cn_only";
        var generation = new string('a', 64);
        var target = "198.51.100.10:443";
        var now = DateTimeOffset.UtcNow;
        var policy = new UltraPolicy
        {
            ProtocolValue = UltraPolicy.Protocol,
            ForwardId = forwardId,
            PolicyGeneration = generation,
            SessionTicket = "ticket-native",
            IssuedAt = now,
            RenewAfter = now.AddMinutes(1),
            ExpiresAt = now.AddMinutes(4),
            ServiceState = "active",
            Transport = "tcp+udp",
            QuotaRemainingBytes = 1024 * 1024,
            TargetDigest = UltraPolicy.ComputeTargetDigest(target),
        };
        var capability = new NativeOutboundCapability
        {
            Enabled = true,
            Protocol = "socks5",
            LeaseProtocol = MasterApiClient.NativeOutboundLeaseProtocol,
            Transport = "tcp+udp",
            ForwardIds = [forwardId],
            DefaultForwardId = forwardId,
            RouteScopes = ["cn_only", "global"],
            DefaultRouteScope = "global",
            Forwards =
            [
                new NativeOutboundForward(
                    forwardId,
                    direction,
                    NativeOutboundLease.ComputeScopeDigest(forwardId, direction, "tcp+udp", "global"),
                    "tcp+udp",
                    ["cn_only", "global"],
                    "global"),
            ],
        };
        var forward = new ForwardConfig
        {
            ForwardId = forwardId,
            BackendMode = "ultra",
            Target = target,
            Transport = "tcp+udp",
            UltraAccess = policy,
            NativeOutbound = capability,
        };
        using var http = new HttpClient(new NativeLeaseHandler(forwardId, direction, generation, routeScope))
        {
            Timeout = TimeSpan.FromSeconds(5),
        };
        string? requestBody = null;
        using var api = new MasterApiClient(
            "https://v3.leikwanhost.com",
            "agent-token",
            "windows-client-158-device",
            httpClientFactory: () => http,
            ownsHttpClient: false,
            onRequestForTest: request =>
            {
                if (request.Content is not null)
                    requestBody = request.Content.ReadAsStringAsync().GetAwaiter().GetResult();
            });

        using var lease = await api.ResolveNativeOutboundLeaseAsync(forward, routeScope);

        Assert.NotNull(lease);
        Assert.Equal(forwardId, lease!.ForwardId);
        Assert.Equal(routeScope, lease.RouteScope);
        Assert.Equal(direction, lease.Direction);
        Assert.Equal("203.0.113.20", lease.EndpointHost);
        Assert.Equal(5438, lease.EndpointPort);
        Assert.Contains("native_outbound", requestBody, StringComparison.Ordinal);
        Assert.Contains("cn_only", requestBody, StringComparison.Ordinal);
        Assert.Contains("ticket-native", requestBody, StringComparison.Ordinal);
        Assert.DoesNotContain("line-password", requestBody, StringComparison.Ordinal);
        Assert.DoesNotContain("leigod", requestBody, StringComparison.OrdinalIgnoreCase);
    }

    [Fact]
    public async Task ResolveUltraLeasesAsync_DefaultsToSingleLease()
    {
        const string target = "198.51.100.10:443";
        var generation = new string('a', 64);
        var now = DateTimeOffset.UtcNow;
        var policy = new UltraPolicy
        {
            ProtocolValue = UltraPolicy.Protocol,
            ForwardId = 42,
            PolicyGeneration = generation,
            SessionTicket = "ticket-a",
            IssuedAt = now,
            RenewAfter = now.AddMinutes(1),
            ExpiresAt = now.AddMinutes(4),
            ServiceState = "active",
            Transport = "tcp+udp",
            QuotaRemainingBytes = 1024 * 1024,
            TargetDigest = UltraPolicy.ComputeTargetDigest(target),
        };
        var forward = new ForwardConfig
        {
            ForwardId = 42,
            BackendMode = "ultra",
            Mode = "single",
            Target = target,
            Transport = "tcp+udp",
            UltraAccess = policy,
        };
        using var http = new HttpClient(new UltraLeaseBatchHandler(generation, target)) { Timeout = TimeSpan.FromSeconds(5) };
        using var api = new MasterApiClient(
            "https://v3.leikwanhost.com",
            "agent-token",
            "windows-client-42-device",
            httpClientFactory: () => http,
            ownsHttpClient: false);

        var leases = await api.ResolveUltraLeasesAsync(forward);

        Assert.Single(leases);
        Assert.Equal("203.0.113.20", leases[0].ProviderIp);
    }

    [Fact]
    public async Task ResolveUltraLeasesAutoAsync_RequestsBoundedCandidateBatch()
    {
        const string target = "198.51.100.10:443";
        var generation = new string('a', 64);
        var now = DateTimeOffset.UtcNow;
        var policy = new UltraPolicy
        {
            ProtocolValue = UltraPolicy.Protocol,
            ForwardId = 42,
            PolicyGeneration = generation,
            SessionTicket = "ticket-a",
            IssuedAt = now,
            RenewAfter = now.AddMinutes(1),
            ExpiresAt = now.AddMinutes(4),
            ServiceState = "active",
            Transport = "tcp+udp",
            QuotaRemainingBytes = 1024 * 1024,
            TargetDigest = UltraPolicy.ComputeTargetDigest(target),
        };
        var forward = new ForwardConfig
        {
            ForwardId = 42,
            BackendMode = "ultra",
            Mode = "single",
            Target = target,
            Transport = "tcp+udp",
            UltraAccess = policy,
        };
        using var http = new HttpClient(new UltraLeaseBatchHandler(generation, target)) { Timeout = TimeSpan.FromSeconds(5) };
        using var api = new MasterApiClient(
            "https://v3.leikwanhost.com",
            "agent-token",
            "windows-client-42-device",
            httpClientFactory: () => http,
            ownsHttpClient: false);

        var leases = await api.ResolveUltraLeasesAutoAsync(forward);

        Assert.Equal(2, leases.Count);
        Assert.Equal("203.0.113.20", leases[0].ProviderIp);
        Assert.Equal("203.0.113.21", leases[1].ProviderIp);
        Assert.Equal("paid", leases[0].SelectionTier);
    }

    private sealed class NotModifiedHandler : HttpMessageHandler
    {
        protected override Task<HttpResponseMessage> SendAsync(HttpRequestMessage request, CancellationToken cancellationToken)
        {
            var response = new HttpResponseMessage(HttpStatusCode.NotModified) { RequestMessage = request };
            return Task.FromResult(response);
        }
    }

    private sealed class StubPullResponseHandler : HttpMessageHandler
    {
        protected override Task<HttpResponseMessage> SendAsync(HttpRequestMessage request, CancellationToken cancellationToken)
        {
            var response = new HttpResponseMessage(HttpStatusCode.OK)
            {
                RequestMessage = request,
                Content = new StringContent("""
                    {
                      "ok": true,
                      "client": { "id": 27, "name": "Client 27", "display_host": "devbox", "agent_config_json": {} },
                      "forwards": []
                    }
                    """, Encoding.UTF8, "application/json"),
            };
            return Task.FromResult(response);
        }
    }

    private sealed class InvalidJsonPullResponseHandler : HttpMessageHandler
    {
        protected override Task<HttpResponseMessage> SendAsync(HttpRequestMessage request, CancellationToken cancellationToken)
        {
            var response = new HttpResponseMessage(HttpStatusCode.OK)
            {
                RequestMessage = request,
                Content = new StringContent(
                    "<br /><b>Warning</b>: Undefined array key \"wstunnel_binary\"",
                    Encoding.UTF8,
                    "text/html"),
            };
            return Task.FromResult(response);
        }
    }

    private sealed class UltraLeaseHandler(string generation, string target) : HttpMessageHandler
    {
        protected override async Task<HttpResponseMessage> SendAsync(HttpRequestMessage request, CancellationToken cancellationToken)
        {
            Assert.Equal("/api/client/ultra/lease", request.RequestUri?.AbsolutePath);
            var body = await request.Content!.ReadAsStringAsync(cancellationToken);
            Assert.Contains("ticket-a", body, StringComparison.Ordinal);
            Assert.DoesNotContain("line-password", body, StringComparison.Ordinal);
            var response = new HttpResponseMessage(HttpStatusCode.OK)
            {
                RequestMessage = request,
                Content = new StringContent($$"""
                    {
                      "ok": true,
                      "leases": [
                        {
                          "protocol": "leikwan-ultra-policy-v2",
                          "forward_id": 42,
                          "session_ticket": "ticket-a",
                          "lease_id": "lease-a",
                          "policy_generation": "{{generation}}",
                          "expires_at": "{{DateTimeOffset.UtcNow.AddMinutes(3):yyyy-MM-dd'T'HH:mm:ss'Z'}}",
                          "transport": "tcp",
                          "rate_limit_mbps": 20,
                          "quota_remaining_bytes": 1048576,
                          "target_digest": "{{UltraPolicy.ComputeTargetDigest(target)}}",
                          "selection_ref": "rrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrr",
                          "nonce_hash": "bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb",
                          "endpoint": { "ip": "203.0.113.20", "port": 5438 },
                          "credential": { "user": "line-user", "password": "line-password" }
                        }
                      ]
                    }
                    """, Encoding.UTF8, "application/json"),
            };
            return response;
        }
    }

    private sealed class UltraLeaseBatchHandler(string generation, string target) : HttpMessageHandler
    {
        protected override async Task<HttpResponseMessage> SendAsync(HttpRequestMessage request, CancellationToken cancellationToken)
        {
            var body = await request.Content!.ReadAsStringAsync(cancellationToken);
            Assert.Contains("selection_mode", body, StringComparison.Ordinal);
            Assert.Contains("max_candidates", body, StringComparison.Ordinal);
            var expires = DateTimeOffset.UtcNow.AddMinutes(3).ToString("yyyy-MM-dd'T'HH:mm:ss'Z'");
            var digest = UltraPolicy.ComputeTargetDigest(target);
            var response = new HttpResponseMessage(HttpStatusCode.OK)
            {
                RequestMessage = request,
                Content = new StringContent($$"""
                    {
                      "ok": true,
                      "leases": [
                        {
                          "protocol": "leikwan-ultra-policy-v2", "forward_id": 42, "session_ticket": "ticket-a",
                          "lease_id": "lease-a", "policy_generation": "{{generation}}", "expires_at": "{{expires}}", "transport": "tcp+udp",
                          "rate_limit_mbps": 0, "quota_remaining_bytes": 1048576, "target_digest": "{{digest}}",
                          "selection_ref": "rrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrr", "selection_tier": "paid", "nonce_hash": "bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb",
                          "endpoint": { "ip": "203.0.113.20", "port": 5438 }, "credential": { "user": "line-user", "password": "line-password" }
                        },
                        {
                          "protocol": "leikwan-ultra-policy-v2", "forward_id": 42, "session_ticket": "ticket-a",
                          "lease_id": "lease-b", "policy_generation": "{{generation}}", "expires_at": "{{expires}}", "transport": "tcp+udp",
                          "rate_limit_mbps": 0, "quota_remaining_bytes": 1048576, "target_digest": "{{digest}}",
                          "selection_ref": "sssssssssssssssssssssssssssssssssssssssssss", "nonce_hash": "cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc",
                          "endpoint": { "ip": "203.0.113.21", "port": 5439 }, "credential": { "user": "line-user", "password": "line-password" }
                        }
                      ]
                    }
                    """, Encoding.UTF8, "application/json"),
            };
            return response;
        }
    }

    private sealed class NativeLeaseHandler(
        int forwardId,
        string direction,
        string generation,
        string routeScope) : HttpMessageHandler
    {
        protected override async Task<HttpResponseMessage> SendAsync(
            HttpRequestMessage request,
            CancellationToken cancellationToken)
        {
            Assert.Equal("/api/client/ultra/lease", request.RequestUri?.AbsolutePath);
            var body = await request.Content!.ReadAsStringAsync(cancellationToken);
            using var requestDocument = System.Text.Json.JsonDocument.Parse(body);
            var item = requestDocument.RootElement
                .GetProperty("forwards")[0];
            Assert.Equal("native_outbound", item.GetProperty("purpose").GetString());
            Assert.Equal(routeScope, item.GetProperty("route_scope").GetString());
            Assert.Equal("tcp+udp", item.GetProperty("transport").GetString());
            var digest = NativeOutboundLease.ComputeScopeDigest(
                forwardId,
                direction,
                "tcp+udp",
                routeScope);
            Assert.Equal(digest, item.GetProperty("target_digest").GetString());
            var expires = DateTimeOffset.UtcNow.AddMinutes(3)
                .ToString("yyyy-MM-dd'T'HH:mm:ss'Z'");
            var response = new HttpResponseMessage(HttpStatusCode.OK)
            {
                RequestMessage = request,
                Content = new StringContent($$"""
                    {
                      "ok": true,
                      "leases": [{
                        "protocol": "leikwan-ultra-native-socks5-v1",
                        "purpose": "native_outbound",
                        "forward_id": {{forwardId}},
                        "lease_id": "native-lease-a",
                        "policy_generation": "{{generation}}",
                        "expires_at": "{{expires}}",
                        "transport": "tcp+udp",
                        "direction": "{{direction}}",
                        "route_scope": "{{routeScope}}",
                        "scope_digest": "{{digest}}",
                        "endpoint": { "ip": "203.0.113.20", "port": 5438 },
                        "credential": { "user": "native-user", "password": "native-password" }
                      }]
                    }
                    """, Encoding.UTF8, "application/json"),
            };
            return response;
        }
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
        public string LastRequestUrl { get; private set; } = "";
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

                LastRequestUrl = context.Request.Url?.ToString() ?? "";
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
