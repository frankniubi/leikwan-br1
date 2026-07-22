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

    [Fact]
    public void Build_PreservesCanonicalBackendContractFields()
    {
        var forward = new ForwardConfig
        {
            ForwardId = 47,
            Mode = "single",
            BackendMode = "leigod_mode",
            ContractVersion = 1,
            ClientMode = "single",
            RuntimePreference = "single",
            BondIngressMode = "plain_tcp_mux",
            ActiveFamily = "leigod_only",
            AggregationEnabled = false,
            TargetContract = "dedicated_forward_node",
            SidecarRequired = false,
            BondSidecarPointId = null,
            Target = "162.245.221.78:80",
            Endpoints =
            [
                new EndpointConfig { EndpointId = 316, Host = "203.0.113.12", Port = 443, HealthStatus = "active" },
            ],
        };

        var effective = EffectiveForwardConfigBuilder.Build(forward);

        Assert.Equal(1, effective.ContractVersion);
        Assert.Equal("single", effective.ClientMode);
        Assert.Equal("single", effective.RuntimePreference);
        Assert.Equal("plain_tcp_mux", effective.BondIngressMode);
        Assert.Equal("leigod_only", effective.ActiveFamily);
        Assert.False(effective.AggregationEnabled);
        Assert.Equal("dedicated_forward_node", effective.TargetContract);
        Assert.False(effective.SidecarRequired);
        Assert.Null(effective.BondSidecarPointId);
    }
}
