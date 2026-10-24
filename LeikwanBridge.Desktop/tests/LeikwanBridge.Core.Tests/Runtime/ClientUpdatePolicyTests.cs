using System.Net;
using System.Text;
using System.Text.Json;
using LeikwanBridge.Core.Models;
using LeikwanBridge.Core.Services;
using Xunit;

namespace LeikwanBridge.Core.Tests.Runtime;

public sealed class ClientUpdatePolicyTests
{
    [Theory]
    // Windows 4-part
    [InlineData("0.1.20.68", "0.1.20.69", true)]
    [InlineData("0.1.20.69", "0.1.20.69", false)]
    [InlineData("0.1.20.70", "0.1.20.69", false)]
    [InlineData("0.1.20.9", "0.1.20.10", true)]
    // macOS 3-part
    [InlineData("1.0.58", "1.0.59", true)]
    [InlineData("1.0.59", "1.0.59", false)]
    [InlineData("1.0.60", "1.0.59", false)]
    [InlineData("1.0.9", "1.0.10", true)]
    // missing components count as 0
    [InlineData("1.0.59", "1.0.59.0", false)]
    [InlineData("1.0.59.0", "1.0.59", false)]
    [InlineData("1.0.59", "1.0.59.1", true)]
    [InlineData("1", "1.0.1", true)]
    // +/- suffixes are ignored
    [InlineData("1.0.58+abc", "1.0.59", true)]
    [InlineData("1.0.59-beta", "1.0.59", false)]
    [InlineData("0.1.20.68", "0.1.20.69+build.7", true)]
    public void IsUpdateRequiredComparesNumericallyPerComponent(string current, string minimum, bool required)
    {
        Assert.Equal(required, ClientUpdatePolicy.IsUpdateRequired(current, minimum));
    }

    [Theory]
    [InlineData("1.0.58", null)]
    [InlineData("1.0.58", "")]
    [InlineData("1.0.58", "   ")]
    [InlineData("1.0.58", "latest")]
    [InlineData("1.0.58", "1..59")]
    [InlineData("1.0.58", "v1.0.59")]
    [InlineData("1.0.58", "1.0.x")]
    [InlineData("1.0.58", "-1.0.59")]
    [InlineData("", "1.0.59")]
    [InlineData("garbage", "1.0.59")]
    public void UnparseableVersionsFailOpen(string current, string? minimum)
    {
        Assert.False(ClientUpdatePolicy.IsUpdateRequired(current, minimum));
    }

    [Fact]
    public void PullReadsClientUpdateObject()
    {
        var pull = Parse("""{"ok":true,"client_update":{"minimum_version":"1.0.59"}}""");

        Assert.NotNull(pull.ClientUpdate);
        Assert.Equal("1.0.59", pull.ClientUpdate!.MinimumVersion);
        Assert.True(pull.ClientUpdate.IsRequiredFor("1.0.58"));
    }

    [Theory]
    [InlineData("""{"ok":true}""")]
    [InlineData("""{"ok":true,"client_update":null}""")]
    [InlineData("""{"ok":true,"client_update":[]}""")]
    [InlineData("""{"ok":true,"client_update":"1.0.59"}""")]
    [InlineData("""{"ok":true,"client_update":true}""")]
    public void AbsentOrNonObjectClientUpdateReadsAsAbsent(string json)
    {
        var pull = Parse(json);

        Assert.True(pull.Ok);
        Assert.Null(pull.ClientUpdate);
    }

    [Theory]
    [InlineData("""{"ok":true,"client_update":{}}""", "")]
    [InlineData("""{"ok":true,"client_update":{"minimum_version":null}}""", "")]
    [InlineData("""{"ok":true,"client_update":{"minimum_version":{"x":1}}}""", "")]
    [InlineData("""{"ok":true,"client_update":{"minimum_version":2}}""", "2")]
    [InlineData("""{"ok":true,"client_update":{"minimum_version":" 1.0.59 ","extra":[1,2]}}""", "1.0.59")]
    public void MalformedMinimumNeverFailsThePull(string json, string expectedMinimum)
    {
        var pull = Parse(json);

        Assert.True(pull.Ok);
        Assert.NotNull(pull.ClientUpdate);
        Assert.Equal(expectedMinimum, pull.ClientUpdate!.MinimumVersion);
    }

    [Fact]
    public async Task PullConfigsAsyncCarriesClientUpdateAndToleratesPhpEmptyArray()
    {
        using var withPolicy = new HttpClient(new OkHandler(
            """{"ok":true,"config_revision":"r1","forwards":[],"client_update":{"minimum_version":"0.1.20.69"}}"""));
        using var api = CreateApi(withPolicy);
        var pull = await api.PullConfigsAsync();
        Assert.Equal("0.1.20.69", pull.ClientUpdate?.MinimumVersion);

        using var emptyArray = new HttpClient(new OkHandler(
            """{"ok":true,"config_revision":"r1","forwards":[],"client_update":[]}"""));
        using var api2 = CreateApi(emptyArray);
        var pull2 = await api2.PullConfigsAsync();
        Assert.True(pull2.Ok);
        Assert.Null(pull2.ClientUpdate);
    }

    [Fact]
    public void RequirementRemembersLastPolicyAcrossAbsentPulls()
    {
        var requirement = new ClientUpdateRequirement("1.0.58");
        Assert.False(requirement.IsRequired);

        Assert.True(requirement.Observe(new ClientUpdatePolicy { MinimumVersion = "1.0.59" }));
        Assert.True(requirement.IsRequired);

        // Failed, 304 and policy-less pulls all surface as null: no change.
        Assert.False(requirement.Observe(null));
        Assert.True(requirement.IsRequired);
        Assert.Equal("1.0.59", requirement.MinimumVersion);

        // Same policy again: nothing changed.
        Assert.False(requirement.Observe(new ClientUpdatePolicy { MinimumVersion = "1.0.59" }));

        // An explicit lower (or unparseable) minimum lifts the gate.
        Assert.True(requirement.Observe(new ClientUpdatePolicy { MinimumVersion = "1.0.50" }));
        Assert.False(requirement.IsRequired);
        Assert.True(requirement.Observe(new ClientUpdatePolicy { MinimumVersion = "1.0.60" }));
        Assert.True(requirement.Observe(new ClientUpdatePolicy { MinimumVersion = "garbage" }));
        Assert.False(requirement.IsRequired);
    }

    private static PullResponse Parse(string json)
        => JsonSerializer.Deserialize<PullResponse>(
               json,
               new JsonSerializerOptions { PropertyNamingPolicy = JsonNamingPolicy.SnakeCaseLower })
           ?? throw new InvalidOperationException("null pull");

    private static MasterApiClient CreateApi(HttpClient http)
        => new("https://panel.example.test", "agent-token", "device", httpClientFactory: () => http, ownsHttpClient: false);

    private sealed class OkHandler(string body) : HttpMessageHandler
    {
        protected override Task<HttpResponseMessage> SendAsync(HttpRequestMessage request, CancellationToken cancellationToken)
            => Task.FromResult(new HttpResponseMessage(HttpStatusCode.OK)
            {
                Content = new StringContent(body, Encoding.UTF8, "application/json"),
            });
    }
}
