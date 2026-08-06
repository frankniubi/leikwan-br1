using System.Text.Json;
using LeikwanBridge.Core.Helpers;
using LeikwanBridge.Core.Models;
using Xunit;

namespace LeikwanBridge.Core.Tests.Runtime;

public sealed class WindowsForwardPolicyTests
{
    [Theory]
    [InlineData("single")]
    [InlineData("race")]
    public void IsSupportedOnWindows_AcceptsExplicitSidecarFrontend(string mode)
    {
        var forward = CreateForward(50, mode);
        forward.BackendMode = "current_mode";
        forward.ContractVersion = 1;
        forward.ClientMode = mode;
        forward.ActiveFamily = "sidecar_aggregation";
        forward.AggregationEnabled = false;
        forward.TargetContract = "sidecar_aggregation";
        forward.SidecarRequired = true;
        forward.BondSidecarPointId = 10;
        forward.Bonding = new BondingConfig
        {
            FrontendMode = mode,
            StrategyLocked = true,
            RemoteTarget = "10.240.10.1:18501",
        };

        Assert.True(WindowsForwardPolicy.IsSupportedOnWindows(forward));
    }

    [Fact]
    public void FilterSupportedForwards_KeepsSupportedRuntimeModesForLegacyConfigs()
    {
        var forwards = new[]
        {
            CreateForward(23, "bond"),
            CreateForward(24, "mptcp"),
            CreateForward(25, "race"),
            CreateForward(26, "single"),
        };

        var filtered = WindowsForwardPolicy.FilterSupportedForwards(forwards);

        Assert.Equal([23, 24, 26], filtered.Select(forward => forward.ForwardId).ToArray());
    }

    [Fact]
    public void IsSupportedOnWindows_AcceptsDedicatedLeiGodWithoutSidecarRuntime()
    {
        var forward = CreateForward(47, "single");
        forward.BackendMode = "leigod_mode";
        forward.ContractVersion = 1;
        forward.ClientMode = "single";
        forward.ActiveFamily = "leigod_only";
        forward.AggregationEnabled = false;
        forward.TargetContract = "dedicated_forward_node";
        forward.SidecarRequired = false;
        forward.BondSidecarPointId = null;
        forward.Bonding = null;
        forward.Mptcp = null;

        Assert.True(WindowsForwardPolicy.IsSupportedOnWindows(forward));
    }

    [Fact]
    public void IsSupportedOnWindows_RequiresSidecarTargetForAggregationContract()
    {
        var missingSidecar = CreateForward(48, "bond");
        missingSidecar.BackendMode = "leigod_mode";
        missingSidecar.ContractVersion = 1;
        missingSidecar.ClientMode = "bond";
        missingSidecar.ActiveFamily = "sidecar_aggregation";
        missingSidecar.AggregationEnabled = true;
        missingSidecar.TargetContract = "sidecar_aggregation";
        missingSidecar.SidecarRequired = true;
        missingSidecar.BondSidecarPointId = null;
        missingSidecar.Bonding = null;

        var withSidecar = CreateForward(49, "bond");
        withSidecar.BackendMode = "leigod_mode";
        withSidecar.ContractVersion = 1;
        withSidecar.ClientMode = "bond";
        withSidecar.ActiveFamily = "sidecar_aggregation";
        withSidecar.AggregationEnabled = true;
        withSidecar.TargetContract = "sidecar_aggregation";
        withSidecar.SidecarRequired = true;
        withSidecar.BondSidecarPointId = 4;
        withSidecar.Bonding = new BondingConfig
        {
            SidecarHost = "198.51.100.4",
            SidecarPort = 8899,
        };

        Assert.False(WindowsForwardPolicy.IsSupportedOnWindows(missingSidecar));
        Assert.True(WindowsForwardPolicy.IsSupportedOnWindows(withSidecar));
    }

    [Fact]
    public void IsSupportedOnWindows_AcceptsValidSlaveSidecarIngressWithoutSidecarRuntimeTarget()
    {
        var forward = CreateSlaveSidecarForward();

        Assert.True(WindowsForwardPolicy.IsSupportedOnWindows(forward));

        forward.Transport = "tcp+udp";
        Assert.False(WindowsForwardPolicy.IsSupportedOnWindows(forward));

        forward.Transport = "tcp";
        forward.Endpoints.Add(new EndpointConfig
        {
            EndpointId = 185,
            Host = "8.163.90.235",
            Port = 7002,
            IngressAuthToken = "token-b",
        });
        Assert.False(WindowsForwardPolicy.IsSupportedOnWindows(forward));

        var nullTransport = CreateSlaveSidecarForward();
        nullTransport.Transport = null!;
        Assert.False(WindowsForwardPolicy.IsSupportedOnWindows(nullTransport));

        var nullIngressMode = CreateSlaveSidecarForward();
        nullIngressMode.Bonding!.IngressMode = null!;
        Assert.False(WindowsForwardPolicy.IsSupportedOnWindows(nullIngressMode));

        var unknownPlacement = CreateSlaveSidecarForward();
        unknownPlacement.Bonding!.AggregationPlacement = "client_magic";
        Assert.False(WindowsForwardPolicy.IsSupportedOnWindows(unknownPlacement));
        Assert.False(WindowsForwardPolicy.UsesBondRuntime(unknownPlacement));

        var nullEndpoint = CreateSlaveSidecarForward();
        nullEndpoint.Endpoints.Insert(0, null!);
        Assert.False(WindowsForwardPolicy.IsSupportedOnWindows(nullEndpoint));

        var extraInvalidEndpoint = CreateSlaveSidecarForward();
        extraInvalidEndpoint.Endpoints.Add(new EndpointConfig
        {
            EndpointId = 185,
            Host = "",
            Port = 0,
            IngressAuthToken = "",
        });
        Assert.False(WindowsForwardPolicy.IsSupportedOnWindows(extraInvalidEndpoint));

        var outOfRangeEndpoint = CreateSlaveSidecarForward();
        outOfRangeEndpoint.Endpoints[0].Port = 65536;
        Assert.False(WindowsForwardPolicy.IsSupportedOnWindows(outOfRangeEndpoint));
    }

    private static ForwardConfig CreateForward(int forwardId, string mode)
    {
        return new ForwardConfig
        {
            ForwardId = forwardId,
            Mode = mode,
            ListenPort = 10000 + forwardId,
            Target = "193.227.109.82:5201",
            Transport = "tcp",
            Endpoints =
            [
                new EndpointConfig
                {
                    Host = "127.0.0.1",
                    Port = 443,
                },
            ],
        };
    }

    private static ForwardConfig CreateSlaveSidecarForward()
    {
        return JsonSerializer.Deserialize<ForwardConfig>(
            """
            {
              "forward_id": 61,
              "mode": "single",
              "client_mode": "bond",
              "contract_version": 1,
              "active_family": "sidecar_aggregation",
              "aggregation_enabled": true,
              "target_contract": "sidecar_aggregation",
              "sidecar_required": true,
              "bond_sidecar_point_id": 9,
              "listen_port": 11061,
              "path_prefix": "slave-sidecar-61",
              "transport": "tcp",
              "endpoints": [
                {
                  "endpoint_id": 184,
                  "host": "8.163.90.132",
                  "port": 7001,
                  "ingress_auth_token": "token-a"
                }
              ],
              "bonding": {
                "aggregation_placement": "slave_sidecar",
                "ingress_mode": "plain_tcp_mux",
                "route_key": "slave-sidecar-61"
              }
            }
            """)!;
    }
}
