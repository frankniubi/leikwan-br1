using LeikwanBridge.Core.Models;
using LeikwanBridge.Core.Services;
using Xunit;

namespace LeikwanBridge.Core.Tests.Services;

/// <summary>
/// The panel answers a heartbeat with "refresh" when a line refresh replaced
/// the credential inside an installed lease. It carries no policy generation.
/// </summary>
public class UltraRefreshActionTests
{
    private static readonly ForwardConfig[] Forwards =
    [
        new() { ForwardId = 42, BackendMode = "ultra", UltraAccess = new UltraPolicy { ForwardId = 42 } },
        new() { ForwardId = 43, BackendMode = "ultra" },
        new() { ForwardId = 44, BackendMode = "bond", UltraAccess = new UltraPolicy { ForwardId = 44 } },
    ];

    [Theory]
    [InlineData(42, "refresh", true)]
    [InlineData(42, "REFRESH", true)]
    [InlineData(42, "stop", false)]
    [InlineData(42, "", false)]
    [InlineData(43, "refresh", false)] // no grant to lease from
    [InlineData(44, "refresh", false)] // not an Ultra forward
    [InlineData(77, "refresh", false)] // not one of this client's forwards
    [InlineData(0, "refresh", false)]
    public void RefreshAppliesOnlyToUltraForwardsThisClientRuns(int forwardId, string action, bool expected)
    {
        var panelAction = new UltraPolicyAction { ForwardId = forwardId, Action = action, PolicyGeneration = "" };

        Assert.Equal(expected, DesktopRuntimeSession.IsUltraRefreshAction(panelAction, Forwards));
    }
}
