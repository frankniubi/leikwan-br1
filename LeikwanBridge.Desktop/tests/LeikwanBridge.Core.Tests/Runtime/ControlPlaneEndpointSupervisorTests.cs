using System.Net;
using System.Net.Http;
using LeikwanBridge.Core.Services;
using Xunit;

namespace LeikwanBridge.Core.Tests.Runtime;

public sealed class ControlPlaneEndpointSupervisorTests
{
    [Fact]
    public async Task TransportFailureSwitchesToFallback()
    {
        var supervisor = new ControlPlaneEndpointSupervisor(
            "https://primary.example",
            ["https://fallback.example"],
            (request, _) => Task.FromResult(new HttpResponseMessage(
                request.RequestUri!.Host == "primary.example"
                    ? HttpStatusCode.ServiceUnavailable
                    : HttpStatusCode.OK)
            { RequestMessage = request }));

        var result = await supervisor.ExecuteAsync(
            HttpMethod.Get,
            "/api/client/configs/pull",
            endpoint => new HttpRequestMessage(HttpMethod.Get, new Uri(endpoint, "/api/client/configs/pull")),
            (response, _) => Task.FromResult(response.RequestMessage!.RequestUri!.Host),
            CancellationToken.None);

        Assert.Equal("fallback.example", result);
        Assert.Equal("https://fallback.example/", supervisor.CurrentEndpoint.AbsoluteUri);
    }

    [Theory]
    [InlineData(HttpStatusCode.BadGateway)]
    [InlineData(HttpStatusCode.ServiceUnavailable)]
    [InlineData(HttpStatusCode.GatewayTimeout)]
    [InlineData((HttpStatusCode)530)]
    public void CdnFailureStatusesAreNotBusinessResponses(HttpStatusCode status)
        => Assert.False(ControlPlaneEndpointSupervisor.IsBusinessResponse(status));

    [Theory]
    [InlineData(HttpStatusCode.Unauthorized)]
    [InlineData(HttpStatusCode.Forbidden)]
    [InlineData(HttpStatusCode.Conflict)]
    public void BusinessStatusesDoNotTriggerFailover(HttpStatusCode status)
        => Assert.True(ControlPlaneEndpointSupervisor.IsBusinessResponse(status));

    [Fact]
    public async Task PrimarySuccessRecoversFromFallback()
    {
        var supervisor = new ControlPlaneEndpointSupervisor(
            "https://primary.example",
            ["https://fallback.example"],
            (_, _) => Task.FromResult(new HttpResponseMessage(HttpStatusCode.OK)));

        supervisor.RecordTransportFailure(new Uri("https://primary.example"));
        Assert.Equal("fallback.example", supervisor.CurrentEndpoint.Host);
        supervisor.RecordSuccess(new Uri("https://primary.example"));

        Assert.Equal("primary.example", supervisor.CurrentEndpoint.Host);
        await Task.CompletedTask;
    }

    [Fact]
    public async Task PostWithReceivedTransientResponseIsNotRetriedOnFallback()
    {
        var calls = new List<string>();
        var supervisor = new ControlPlaneEndpointSupervisor(
            "https://primary.example",
            ["https://fallback.example"],
            (request, _) =>
            {
                calls.Add(request.RequestUri!.Host);
                return Task.FromResult(new HttpResponseMessage(HttpStatusCode.ServiceUnavailable)
                {
                    RequestMessage = request,
                });
            });

        var response = await supervisor.ExecuteAsync(
            HttpMethod.Post,
            "/api/client/heartbeat",
            endpoint => new HttpRequestMessage(HttpMethod.Post, new Uri(endpoint, "/api/client/heartbeat")),
            (result, _) => Task.FromResult(result.StatusCode),
            CancellationToken.None);

        Assert.Equal(HttpStatusCode.ServiceUnavailable, response);
        Assert.Equal(["primary.example"], calls);
    }
}
