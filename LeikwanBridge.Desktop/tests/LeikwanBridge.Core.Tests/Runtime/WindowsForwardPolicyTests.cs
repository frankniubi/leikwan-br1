using LeikwanBridge.Core.Helpers;
using LeikwanBridge.Core.Models;
using Xunit;

namespace LeikwanBridge.Core.Tests.Runtime;

public sealed class WindowsForwardPolicyTests
{
    [Theory]
    [InlineData("single")]
    [InlineData("bond")]
    [InlineData("mptcp")]
    public void IsSupportedOnWindows_RejectsCfModeWithoutFallback(string mode)
    {
        var forward = CreateForward(47, mode);
        forward.BackendMode = "cf_mode";
        forward.ContractVersion = 1;
        forward.ClientMode = mode;
        forward.ActiveFamily = "sidecar_aggregation";
        forward.TargetContract = "sidecar_aggregation";
        forward.AggregationEnabled = true;
        forward.SidecarRequired = true;
        forward.BondSidecarPointId = 10;
        forward.Bonding = new BondingConfig
        {
            FrontendMode = mode == "single" ? "single" : "",
            RemoteTarget = "10.240.10.1:18501",
        };

        Assert.False(WindowsForwardPolicy.IsSupportedOnWindows(forward));
    }

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
    public void IsSupportedOnWindows_AcceptsUltraDirectForward()
    {
        var forward = CreateForward(51, "single");
        forward.BackendMode = "ultra";
        forward.ContractVersion = 2;
        forward.ClientMode = "single";
        forward.ActiveFamily = "ultra";
        forward.AggregationEnabled = false;
        forward.TargetContract = "ultra_direct";
        forward.SidecarRequired = false;
        forward.BondSidecarPointId = null;
        forward.Bonding = null;
        forward.Mptcp = null;
        forward.Endpoints = [];

        Assert.True(WindowsForwardPolicy.IsSupportedOnWindows(forward));
    }

    [Fact]
    public void IsSupportedOnWindows_RejectsUltraThatRequiresSidecarOrEndpoints()
    {
        var forward = CreateForward(52, "single");
        forward.BackendMode = "ultra";
        forward.ContractVersion = 2;
        forward.ClientMode = "single";
        forward.ActiveFamily = "ultra";
        forward.TargetContract = "ultra_direct";
        forward.SidecarRequired = true;

        Assert.False(WindowsForwardPolicy.IsSupportedOnWindows(forward));

        forward.SidecarRequired = false;
        Assert.False(WindowsForwardPolicy.IsSupportedOnWindows(forward));
    }

    [Fact]
    public void IsSupportedOnWindows_RejectsRetiredCfMode()
    {
        var forward = CreateForward(53, "single");
        forward.BackendMode = "cf_mode";

        Assert.False(WindowsForwardPolicy.IsSupportedOnWindows(forward));
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
}
