using LeikwanBridge.Core.Models;
using LeikwanBridge.Core.Services;
using Xunit;

namespace LeikwanBridge.Core.Tests.Runtime;

public sealed class BondEndpointHealthServiceTests
{
    [Fact]
    public void SelectRuntimeEndpoints_CfBond_ExcludesQuarantinedEndpointWhenHealthyExists()
    {
        using var tempDir = new TempStateDirectory();
        var stateManager = new StateManager(tempDir.Path);
        var service = new BondEndpointHealthService(stateManager);
        service.ForceQuarantine(47, 316, "cf_bond_leg_probe_failed", 1200, nowUnixSeconds: 1_000);

        var selected = service.SelectRuntimeEndpoints(CreateCfBondForward(), nowUnixSeconds: 1_001);

        Assert.Equal([317], selected.Select(endpoint => endpoint.EndpointId).ToList());
        Assert.All(selected, endpoint => Assert.Equal("healthy", endpoint.HealthRole));
    }

    [Fact]
    public void SelectRuntimeEndpoints_CfBond_UsesSingleProbationEndpointWhenNoHealthyExists()
    {
        using var tempDir = new TempStateDirectory();
        var stateManager = new StateManager(tempDir.Path);
        var service = new BondEndpointHealthService(stateManager);
        service.ForceQuarantine(47, 316, "failed-a", null, nowUnixSeconds: 1_000);
        service.ForceQuarantine(47, 317, "failed-b", null, nowUnixSeconds: 900);

        var selected = service.SelectRuntimeEndpoints(CreateCfBondForward(), nowUnixSeconds: 1_001);

        Assert.Single(selected);
        Assert.Equal(317, selected[0].EndpointId);
        Assert.Equal("probation", selected[0].HealthRole);
        Assert.Equal(25, selected[0].DynamicPriority);
    }

    [Fact]
    public void RecordProbeResult_ThirdCfFailureQuarantinesEndpointForNinetySeconds()
    {
        using var tempDir = new TempStateDirectory();
        var stateManager = new StateManager(tempDir.Path);
        var service = new BondEndpointHealthService(stateManager);

        service.RecordProbeResult(47, 316, ok: false, latencyMs: null, error: "one", nowUnixSeconds: 2_000);
        service.RecordProbeResult(47, 316, ok: false, latencyMs: null, error: "two", nowUnixSeconds: 2_001);
        var entry = service.RecordProbeResult(47, 316, ok: false, latencyMs: null, error: "three", nowUnixSeconds: 2_002);

        Assert.Equal("quarantined", entry.Role);
        Assert.Equal(3, entry.ConsecutiveFailures);
        Assert.Equal(2_092, entry.QuarantineUntil);
        Assert.Equal("three", entry.LastError);
    }

    private static ForwardConfig CreateCfBondForward()
    {
        return new ForwardConfig
        {
            ForwardId = 47,
            BackendMode = "cf_mode",
            Mode = "bond",
            ListenPort = 11035,
            Transport = "tcp",
            Endpoints =
            [
                new EndpointConfig
                {
                    EndpointId = 316,
                    Host = "8.1.1.1",
                    Port = 7000,
                    RouteKey = "forward-47",
                    IngressAuthToken = "token-a",
                    DynamicPriority = 10,
                },
                new EndpointConfig
                {
                    EndpointId = 317,
                    Host = "8.1.1.2",
                    Port = 7000,
                    RouteKey = "forward-47",
                    IngressAuthToken = "token-b",
                    DynamicPriority = 10,
                },
            ],
            Bonding = new BondingConfig
            {
                IngressMode = "plain_tcp_mux",
                RouteKey = "forward-47",
                SessionToken = "tcp-session",
                RemoteTarget = "127.0.0.1:8899",
                WsPriority = 10,
            },
        };
    }
}
