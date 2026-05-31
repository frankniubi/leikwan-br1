using LeikwanBridge.Core.Helpers;
using LeikwanBridge.Core.Models;
using Xunit;

namespace LeikwanBridge.Core.Tests.Runtime;

public sealed class EffectiveForwardConfigBuilderTests
{
    [Fact]
    public void Build_MarksPanelInactiveEndpointAsUnavailableBeforeLocalRanking()
    {
        var forward = new ForwardConfig
        {
            ForwardId = 40,
            Mode = "bond",
            BackendMode = "cf_mode",
            Endpoints =
            [
                new EndpointConfig { EndpointId = 254, Host = "a", Port = 1, HealthStatus = "active" },
                new EndpointConfig { EndpointId = 255, Host = "b", Port = 1, HealthStatus = "inactive" },
            ],
            Bonding = new BondingConfig { PolicyMode = "latency_first", WsPriority = 10 },
        };

        var effective = EffectiveForwardConfigBuilder.Build(forward);

        Assert.DoesNotContain(effective.Endpoints, endpoint => endpoint.EndpointId == 255);
    }
}
