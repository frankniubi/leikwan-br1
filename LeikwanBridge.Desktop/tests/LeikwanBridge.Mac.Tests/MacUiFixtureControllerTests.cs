using LeikwanBridge.Mac.Services;
using Xunit;

namespace LeikwanBridge.Mac.Tests;

public sealed class MacUiFixtureControllerTests
{
    [Fact]
    public void LoginFixture_IsSignedOutAndHasNoForwards()
    {
        var controller = new MacUiFixtureController("login");

        Assert.False(controller.Snapshot.IsSignedIn);
        Assert.Empty(controller.Snapshot.Forwards);
    }

    [Theory]
    [InlineData("forwards")]
    [InlineData("settings")]
    public void SignedInFixtures_ContainOnlySupportedModes(string state)
    {
        var controller = new MacUiFixtureController(state);

        Assert.True(controller.Snapshot.IsSignedIn);
        Assert.True(controller.Snapshot.IsConnected);
        Assert.Equal(
            ["single", "bond", "ultra"],
            controller.Snapshot.Forwards.Select(forward => forward.Mode));
    }

    [Fact]
    public void DashboardFixture_IsSignedInWithoutSyntheticForwardRows()
    {
        var controller = new MacUiFixtureController("dashboard");

        Assert.True(controller.Snapshot.IsSignedIn);
        Assert.Empty(controller.Snapshot.Forwards);
    }
}
