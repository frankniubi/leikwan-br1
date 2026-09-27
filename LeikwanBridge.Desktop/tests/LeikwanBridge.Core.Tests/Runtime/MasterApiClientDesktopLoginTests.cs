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
        Assert.Contains("Device limit reached (1 of 1 devices online)", api.LastErrorMessage);
        Assert.NotNull(api.LastDeviceLimit);
        Assert.Equal(1, api.LastDeviceLimit!.OnlineDeviceLimit);
        Assert.Equal(1, api.LastDeviceLimit.ActiveOnlineDevices);
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
    public async Task ResolveNativeOutboundLeaseAsync_UsesV2DigestForExplicitUnrestrictedPolicy()
    {
        const int forwardId = 159;
        const string direction = "Mainland China to International";
        const string routeScope = "intl_only";
        const string routePolicy = "unrestricted";
        var generation = new string('b', 64);
        var target = "198.51.100.11:443";
        var now = DateTimeOffset.UtcNow;
        var policy = new UltraPolicy
        {
            ProtocolValue = UltraPolicy.Protocol,
            ForwardId = forwardId,
            PolicyGeneration = generation,
            SessionTicket = "ticket-native-unrestricted",
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
            RouteScopes = ["intl_only", "global"],
            DefaultRouteScope = "global",
            UnrestrictedAllowed = true,
            Forwards =
            [
                new NativeOutboundForward(
                    forwardId,
                    direction,
                    NativeOutboundLease.ComputeScopeDigestV2(
                        forwardId,
                        direction,
                        "tcp+udp",
                        routeScope,
                        routePolicy),
                    "tcp+udp",
                    ["intl_only", "global"],
                    "global",
                    UnrestrictedAllowed: true),
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
        using var http = new HttpClient(new NativeLeaseHandler(
            forwardId,
            direction,
            generation,
            routeScope,
            routePolicy))
        {
            Timeout = TimeSpan.FromSeconds(5),
        };
        using var api = new MasterApiClient(
            "https://v3.leikwanhost.com",
            "agent-token",
            "windows-client-159-device",
            httpClientFactory: () => http,
            ownsHttpClient: false);

        using var lease = await api.ResolveNativeOutboundLeaseAsync(
            forward,
            capability,
            routeScope,
            routePolicy);

        Assert.NotNull(lease);
        Assert.Equal(routePolicy, lease!.RoutePolicy);
        Assert.Equal(routeScope, lease.RouteScope);
        Assert.Equal(
            NativeOutboundLease.ComputeScopeDigestV2(
                forwardId,
                direction,
                "tcp+udp",
                routeScope,
                routePolicy),
            lease.ScopeDigest);
    }

    [Fact]
    public async Task ResolveFreshNativeOutboundLeasesAsync_PullsCurrentGrantBeforeBatchRequest()
    {
        const int forwardId = 180;
        const string direction = "CAN -> HKG";
        const string routeScope = "intl_only";
        const string routePolicy = "unrestricted";
        var handler = new FreshNativeLeaseHandler(forwardId, direction, routeScope, routePolicy);
        using var http = new HttpClient(handler) { Timeout = TimeSpan.FromSeconds(5) };
        using var api = new MasterApiClient(
            "https://v3.leikwanhost.com",
            "agent-token",
            "windows-client-180-device",
            httpClientFactory: () => http,
            ownsHttpClient: false);

        var leases = await api.ResolveFreshNativeOutboundLeasesAsync(
            forwardId,
            routeScope,
            routePolicy);

        try
        {
            Assert.Equal(2, leases.Count);
            Assert.Equal(["/api/client/configs/pull", "/api/client/ultra/lease"], handler.Paths);
            Assert.Equal("fresh-ticket", handler.RequestTicket);
            Assert.Equal("auto", handler.SelectionMode);
            Assert.Equal(4, handler.MaxCandidates);
            Assert.All(leases, lease =>
            {
                Assert.Equal(routeScope, lease.RouteScope);
                Assert.Equal(routePolicy, lease.RoutePolicy);
            });
        }
        finally
        {
            foreach (var lease in leases)
                lease.Dispose();
        }
    }

    [Theory]
    [InlineData(0)]
    [InlineData(1)]
    public async Task DesktopRuntimeNativeRestartPullsFreshPolicyWithoutRestartingForwards(int scopeFailures)
    {
        var path = Path.Combine(Path.GetTempPath(), "native-session-policy-" + Guid.NewGuid().ToString("N"));
        Directory.CreateDirectory(path);
        try
        {
            var handler = new FreshNativeLeaseHandler(180, "CAN -> HKG", "intl_only", "unrestricted") { ScopeFailuresRemaining = scopeFailures };
            using var http = new HttpClient(handler);
            using var api = new MasterApiClient("https://panel.example.test", "agent-token", "device",
                httpClientFactory: () => http, ownsHttpClient: false);
            using var supervisor = new ProcessSupervisor();
            var state = new StateManager(path);
            var config = new AgentConfig();
            using var runtime = new ForwardRuntimeManager(config, supervisor, state);
            var polling = new ConfigPollingService(api, runtime, state);
            using var health = new HealthCheckService(supervisor, runtime, state);
            await using var session = new DesktopRuntimeSession(api, polling, new HeartbeatService(api, state), health, runtime);
            using var lease = await session.ResolveNativeOutboundLeaseAsync(180, "intl_only", "unrestricted", CancellationToken.None);
            Assert.NotNull(lease);
            Assert.Equal(Enumerable.Range(0, scopeFailures + 1).SelectMany(_ =>
                new[] { "/api/client/configs/pull", "/api/client/ultra/lease" }), handler.Paths);
            Assert.Equal("fresh-ticket", handler.RequestTicket);
            Assert.Equal("single", handler.SelectionMode);
            Assert.Equal(1, handler.MaxCandidates);
            Assert.Empty(supervisor.RunningProcesses);
        }
        finally { Directory.Delete(path, recursive: true); }
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

    [Theory]
    [InlineData("""{"enabled":true,"sidecar_target":"203.0.113.7:7443","token":"sidecar-token-canary-0123456789","queue_packets":1024,"keepalive_seconds":12,"reconnect_grace_seconds":45,"frame_payload_max":1400}""", true)]
    [InlineData("""{"enabled":true,"sidecar_target":"203.0.113.7:7443"}""", false)]
    [InlineData("""{"enabled":false,"sidecar_target":"203.0.113.7:7443","token":"sidecar-token-canary-0123456789"}""", false)]
    public async Task ResolveUltraLeasesAsync_ParsesTheOptionalExternalUotGrant(string grantJson, bool expectsGrant)
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
        using var http = new HttpClient(new UltraLeaseExternalUotHandler(generation, target, grantJson)) { Timeout = TimeSpan.FromSeconds(5) };
        using var api = new MasterApiClient(
            "https://v3.leikwanhost.com",
            "agent-token",
            "windows-client-42-device",
            httpClientFactory: () => http,
            ownsHttpClient: false);

        var leases = await api.ResolveUltraLeasesAsync(forward);

        if (grantJson.Contains("\"enabled\":true", StringComparison.Ordinal) && !expectsGrant)
        {
            Assert.Empty(leases);
            return;
        }
        var lease = Assert.Single(leases);
        Assert.Equal(expectsGrant, lease.ExternalUot is not null);
        if (!expectsGrant)
            return;
        var grant = lease.ExternalUot!;
        Assert.Equal("203.0.113.7:7443", grant.SidecarTarget);
        Assert.Equal("sidecar-token-canary-0123456789", Encoding.UTF8.GetString(grant.Token));
        Assert.Equal(1024, grant.QueuePackets);
        Assert.Equal(12, grant.KeepaliveSeconds);
        Assert.Equal(45, grant.ReconnectGraceSeconds);
        Assert.Equal(1400, grant.FramePayloadMax);
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

    private sealed class UltraLeaseExternalUotHandler(string generation, string target, string grantJson) : HttpMessageHandler
    {
        protected override async Task<HttpResponseMessage> SendAsync(HttpRequestMessage request, CancellationToken cancellationToken)
        {
            await request.Content!.ReadAsStringAsync(cancellationToken);
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
                          "rate_limit_mbps": 20, "quota_remaining_bytes": 1048576, "target_digest": "{{digest}}",
                          "selection_ref": "rrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrr", "selection_tier": "paid",
                          "nonce_hash": "bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb",
                          "endpoint": { "ip": "203.0.113.20", "port": 5438 },
                          "credential": { "user": "line-user", "password": "line-password" },
                          "external_uot": {{grantJson}}
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
        string routeScope,
        string routePolicy = "restricted") : HttpMessageHandler
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
            Assert.Equal(routePolicy, item.GetProperty("route_policy").GetString());
            Assert.Equal("tcp+udp", item.GetProperty("transport").GetString());
            var digest = routePolicy == "unrestricted"
                ? NativeOutboundLease.ComputeScopeDigestV2(
                    forwardId,
                    direction,
                    "tcp+udp",
                    routeScope,
                    routePolicy)
                : NativeOutboundLease.ComputeScopeDigest(
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
                        "route_policy": "{{routePolicy}}",
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

    private sealed class FreshNativeLeaseHandler(
        int forwardId,
        string direction,
        string routeScope,
        string routePolicy) : HttpMessageHandler
    {
        private readonly string _generation = new('e', 64);
        private readonly string _target = "198.51.100.180:443";

        public List<string> Paths { get; } = [];
        public string RequestTicket { get; private set; } = "";
        public string SelectionMode { get; private set; } = "";
        public int MaxCandidates { get; private set; }
        public int ScopeFailuresRemaining { get; set; }

        protected override async Task<HttpResponseMessage> SendAsync(
            HttpRequestMessage request,
            CancellationToken cancellationToken)
        {
            var path = request.RequestUri?.AbsolutePath ?? "";
            Paths.Add(path);
            var now = DateTimeOffset.UtcNow;
            var expires = now.AddMinutes(4).ToString("yyyy-MM-dd'T'HH:mm:ss'Z'");
            var issued = now.ToString("yyyy-MM-dd'T'HH:mm:ss'Z'");
            var renew = now.AddMinutes(3).ToString("yyyy-MM-dd'T'HH:mm:ss'Z'");
            var scopeDigest = NativeOutboundLease.ComputeScopeDigestV2(
                forwardId,
                direction,
                "tcp+udp",
                routeScope,
                routePolicy);

            if (path == "/api/client/configs/pull")
            {
                var targetDigest = UltraPolicy.ComputeTargetDigest(_target);
                return new HttpResponseMessage(HttpStatusCode.OK)
                {
                    RequestMessage = request,
                    Content = new StringContent($$"""
                        {
                          "ok": true,
                          "config_revision": "fresh-revision",
                          "forwards": [{
                            "forward_id": {{forwardId}}, "service_status": "active", "backend_mode": "ultra",
                            "target": "{{_target}}", "transport": "tcp+udp",
                            "ultra_access": {
                              "protocol": "leikwan-ultra-policy-v2", "forward_id": {{forwardId}},
                              "policy_generation": "{{_generation}}", "session_ticket": "fresh-ticket",
                              "issued_at": "{{issued}}", "renew_after": "{{renew}}", "expires_at": "{{expires}}",
                              "service_state": "active", "transport": "tcp+udp", "rate_limit_mbps": 20,
                              "quota_remaining_bytes": 1048576, "target_digest": "{{targetDigest}}"
                            }
                          }],
                          "native_outbound": {
                            "enabled": true, "protocol": "socks5",
                            "lease_protocol": "leikwan-ultra-native-socks5-v1", "transport": "tcp+udp",
                            "forward_ids": [{{forwardId}}], "default_forward_id": {{forwardId}},
                            "route_scopes": ["intl_only", "global"], "default_route_scope": "global",
                            "unrestricted_allowed": true,
                            "forwards": [{
                              "forward_id": {{forwardId}}, "direction": "{{direction}}",
                              "scope_digest": "{{scopeDigest}}", "transport": "tcp+udp",
                              "route_scopes": ["intl_only", "global"], "default_route_scope": "global",
                              "unrestricted_allowed": true
                            }]
                          }
                        }
                        """, Encoding.UTF8, "application/json"),
                };
            }

            Assert.Equal("/api/client/ultra/lease", path);
            if (ScopeFailuresRemaining-- > 0)
                return new HttpResponseMessage(HttpStatusCode.Conflict)
                {
                    RequestMessage = request,
                    Content = new StringContent("{\"error\":\"lease_scope_invalid\"}", Encoding.UTF8, "application/json"),
                };
            var body = await request.Content!.ReadAsStringAsync(cancellationToken);
            using var document = System.Text.Json.JsonDocument.Parse(body);
            var item = document.RootElement.GetProperty("forwards")[0];
            RequestTicket = item.GetProperty("session_ticket").GetString() ?? "";
            SelectionMode = item.GetProperty("selection_mode").GetString() ?? "";
            MaxCandidates = item.GetProperty("max_candidates").GetInt32();
            Assert.Equal(_generation, item.GetProperty("policy_generation").GetString());
            Assert.Equal(scopeDigest, item.GetProperty("target_digest").GetString());

            return new HttpResponseMessage(HttpStatusCode.OK)
            {
                RequestMessage = request,
                Content = new StringContent($$"""
                    {
                      "ok": true,
                      "leases": [
                        {
                          "protocol": "leikwan-ultra-native-socks5-v1", "purpose": "native_outbound",
                          "forward_id": {{forwardId}}, "lease_id": "fresh-native-a",
                          "policy_generation": "{{_generation}}", "expires_at": "{{expires}}",
                          "transport": "tcp+udp", "rate_limit_mbps": 20, "quota_remaining_bytes": 1048576,
                          "selection_ref": "fresh-selection-reference-a", "selection_tier": "paid",
                          "direction": "{{direction}}", "route_scope": "{{routeScope}}", "route_policy": "{{routePolicy}}",
                          "scope_digest": "{{scopeDigest}}", "endpoint": { "ip": "203.0.113.20", "port": 5438 },
                          "credential": { "user": "native-user-a", "password": "native-password-a" }
                        },
                        {
                          "protocol": "leikwan-ultra-native-socks5-v1", "purpose": "native_outbound",
                          "forward_id": {{forwardId}}, "lease_id": "fresh-native-b",
                          "policy_generation": "{{_generation}}", "expires_at": "{{expires}}",
                          "transport": "tcp+udp", "rate_limit_mbps": 20, "quota_remaining_bytes": 1048576,
                          "selection_ref": "fresh-selection-reference-b", "selection_tier": "paid",
                          "direction": "{{direction}}", "route_scope": "{{routeScope}}", "route_policy": "{{routePolicy}}",
                          "scope_digest": "{{scopeDigest}}", "endpoint": { "ip": "203.0.113.21", "port": 5439 },
                          "credential": { "user": "native-user-b", "password": "native-password-b" }
                        }
                      ]
                    }
                    """, Encoding.UTF8, "application/json"),
            };
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
