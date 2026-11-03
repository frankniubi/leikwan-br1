using System.Net;
using System.Text;
using System.Text.Json;
using LeikwanBridge.Core.Models;
using LeikwanBridge.Core.Nodes;
using LeikwanBridge.Core.Services;
using Xunit;

namespace LeikwanBridge.Core.Tests.Nodes;

public sealed class HostedNodeApiClientTests
{
    [Fact]
    public async Task CallsCarryTheDesktopIdentityAndParseTheListing()
    {
        var seen = new List<(HttpMethod Method, string Path, string Body, string Platform, string DeviceId, string Auth)>();
        using var api = Create(async request =>
        {
            seen.Add((request.Method, request.RequestUri!.AbsolutePath,
                request.Content is null ? "" : await request.Content.ReadAsStringAsync(),
                string.Join(",", request.Headers.TryGetValues("X-Device-Platform", out var p) ? p : []),
                string.Join(",", request.Headers.TryGetValues("X-Device-Id", out var d) ? d : []),
                request.Headers.Authorization?.ToString() ?? ""));
            return Json(HttpStatusCode.OK, """{"ok":true,"revision":"r1","nodes":[{"id":11,"name":"Home","protocol":"socks5","uri":"socks5://198.51.100.7:1080#Home","version":2}],"pairings":[{"forward_id":101,"node_id":11,"source":"explicit"}],"node_id":11,"created":true}""");
        });

        var listing = await api.ListHostedNodesAsync();
        await api.CreateHostedNodeAsync(new HostedNodeUploadRequest("socks5://198.51.100.7:1080#Home", "Home", 101));
        await api.BatchCreateHostedNodesAsync(new HostedNodeBatchRequest([new HostedNodeBatchEntry("4", "socks5://198.51.100.7:1080")]));
        await api.UpdateHostedNodeAsync(11, new HostedNodeUpdateRequest("socks5://198.51.100.7:1080#X", "X"));
        await api.DeleteHostedNodeAsync(11);
        await api.PairHostedNodeAsync(101, 11);
        await api.PairHostedNodeAsync(101, null);

        Assert.Equal("r1", listing.Revision);
        Assert.Equal(11, Assert.Single(listing.Nodes!).Id);
        Assert.Equal(101, Assert.Single(listing.Pairings!).ForwardId);
        Assert.Equal(
            [
                "GET /api/client/hosted-nodes",
                "POST /api/client/hosted-nodes",
                "POST /api/client/hosted-nodes/batch",
                "PUT /api/client/hosted-nodes/11",
                "DELETE /api/client/hosted-nodes/11",
                "PUT /api/client/hosted-node-pairings/101",
                "PUT /api/client/hosted-node-pairings/101",
            ],
            seen.Select(call => call.Method.Method + " " + call.Path).ToArray());
        Assert.All(seen, call =>
        {
            Assert.Equal("windows-desktop", call.Platform);
            Assert.Equal("windows-client-7-0123456789abcdef", call.DeviceId);
            Assert.Equal("Bearer agent-token", call.Auth);
        });
        using (var create = JsonDocument.Parse(seen[1].Body))
        {
            Assert.Equal(101, create.RootElement.GetProperty("pair_forward_id").GetInt32());
            Assert.Equal("Home", create.RootElement.GetProperty("name").GetString());
        }
        Assert.Contains("\"ref\":\"4\"", seen[2].Body, StringComparison.Ordinal);
        Assert.Equal("{\"node_id\":11}", seen[5].Body);
        Assert.Equal("{}", seen[6].Body);
    }

    [Fact]
    public async Task PanelRefusalsBecomeHostedNodeExceptions()
    {
        using var invalid = Create(_ => Task.FromResult(Json(HttpStatusCode.UnprocessableEntity, """{"ok":false,"error":"hosted_node_invalid","field":"uuid"}""")));
        var error = await Assert.ThrowsAsync<HostedNodeException>(() => invalid.ListHostedNodesAsync());
        Assert.Equal(("hosted_node_invalid", "uuid", 422), (error.Code, error.Field, error.StatusCode));

        using var untrusted = Create(_ => Task.FromResult(Json(HttpStatusCode.Forbidden, """{"ok":false,"error":"device_untrusted"}""")));
        Assert.Equal("device_untrusted", (await Assert.ThrowsAsync<HostedNodeException>(() => untrusted.ListHostedNodesAsync())).Code);

        using var limit = Create(_ => Task.FromResult(Json((HttpStatusCode) 429, """{"ok":false,"error":"client_online_limit_reached","online_device_limit":1,"active_online_devices":1}""")));
        await Assert.ThrowsAsync<DeviceLimitReachedException>(() => limit.ListHostedNodesAsync());

        using var old = Create(_ => Task.FromResult(new HttpResponseMessage(HttpStatusCode.NotFound) { Content = new StringContent("<html>") }));
        Assert.Equal("hosted_nodes_unsupported", (await Assert.ThrowsAsync<HostedNodeException>(() => old.ListHostedNodesAsync())).Code);
    }

    [Fact]
    public void PullCarriesTheRevisionMarker()
    {
        var pull = JsonSerializer.Deserialize<PullResponse>("""{"ok":true,"client":{"id":7,"hosted_nodes_revision":"abc"}}""")!;
        Assert.Equal("abc", pull.Client!.HostedNodesRevision);
        var older = JsonSerializer.Deserialize<PullResponse>("""{"ok":true,"client":{"id":7}}""")!;
        Assert.Null(older.Client!.HostedNodesRevision);
    }

    private static MasterApiClient Create(Func<HttpRequestMessage, Task<HttpResponseMessage>> respond)
        => new(
            "https://panel.example.test",
            "agent-token",
            "windows-client-7-0123456789abcdef",
            "Test PC",
            "windows-desktop",
            httpClientFactory: () => new HttpClient(new Handler(respond)),
            fallbackEndpoints: []);

    private static HttpResponseMessage Json(HttpStatusCode status, string body)
        => new(status) { Content = new StringContent(body, Encoding.UTF8, "application/json") };

    private sealed class Handler(Func<HttpRequestMessage, Task<HttpResponseMessage>> respond) : HttpMessageHandler
    {
        protected override Task<HttpResponseMessage> SendAsync(HttpRequestMessage request, CancellationToken cancellationToken)
            => respond(request);
    }
}
