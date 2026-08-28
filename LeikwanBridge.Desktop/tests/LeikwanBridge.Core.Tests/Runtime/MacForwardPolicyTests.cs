using LeikwanBridge.Core.Helpers;
using LeikwanBridge.Core.Models;
using Xunit;

namespace LeikwanBridge.Core.Tests.Runtime;

public sealed class MacForwardPolicyTests
{
    [Theory]
    [InlineData("current_mode", "single", "", true, "single")]
    [InlineData("current_mode", "bond", "", true, "bond")]
    [InlineData("current_mode", "mptcp", "", false, "")]
    [InlineData("current_mode", "race", "", false, "")]
    [InlineData("current_mode", "single", "race", false, "")]
    [InlineData("cf_mode", "single", "", false, "")]
    [InlineData("leigod_mode", "single", "", false, "")]
    [InlineData("unknown", "single", "", false, "")]
    public void Evaluate_UsesRawModeBeforePermissiveNormalization(
        string backendMode,
        string mode,
        string clientMode,
        bool accepted,
        string effectiveMode)
    {
        var forward = CreateCurrentModeForward(mode);
        forward.BackendMode = backendMode;
        forward.ClientMode = clientMode;

        var result = MacForwardPolicy.Instance.Evaluate(forward);

        Assert.Equal(accepted, result.Accepted);
        Assert.Equal(effectiveMode, result.EffectiveMode);
        Assert.Equal(accepted ? string.Empty : MacForwardPolicy.UnsupportedModeNotice, result.Notice);
    }

    [Fact]
    public void Evaluate_UsesTheExactBilingualUnsupportedNotice()
    {
        var result = MacForwardPolicy.Instance.Evaluate(CreateCurrentModeForward("mptcp"));

        Assert.Equal(
            "The mode of this forward is not supported in MacOS binary. 此转发的模式尚未在MacOS客户端支援.",
            result.Notice);
    }

    [Fact]
    public void Evaluate_AcceptsOnlyTheExistingStrictUltraShape()
    {
        var valid = CreateUltraForward();
        var malformedMode = CreateUltraForward();
        malformedMode.ClientMode = "bond";
        var sidecar = CreateUltraForward();
        sidecar.SidecarRequired = true;
        var endpointInventory = CreateUltraForward();
        endpointInventory.Endpoints = [new EndpointConfig { Host = "192.0.2.8", Port = 443 }];

        Assert.True(MacForwardPolicy.Instance.Evaluate(valid).Accepted);
        Assert.False(MacForwardPolicy.Instance.Evaluate(malformedMode).Accepted);
        Assert.False(MacForwardPolicy.Instance.Evaluate(sidecar).Accepted);
        Assert.False(MacForwardPolicy.Instance.Evaluate(endpointInventory).Accepted);
    }

    private static ForwardConfig CreateCurrentModeForward(string mode) => new()
    {
        ForwardId = 40,
        BackendMode = "current_mode",
        Mode = mode,
        ServiceStatus = "active",
        ListenPort = 12040,
        Target = "198.51.100.40:443",
        Transport = "tcp",
        Endpoints = [new EndpointConfig { Host = "192.0.2.40", Port = 7443 }],
    };

    private static ForwardConfig CreateUltraForward() => new()
    {
        ForwardId = 41,
        BackendMode = "ultra",
        Mode = "single",
        ClientMode = "single",
        ServiceStatus = "active",
        ContractVersion = 2,
        ActiveFamily = "ultra",
        TargetContract = "ultra_direct",
        ListenPort = 12041,
        Target = "198.51.100.41:443",
        Transport = "tcp",
        Endpoints = [],
    };
}
