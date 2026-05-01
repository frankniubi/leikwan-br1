using LeikwanBridge.App.Services;
using Xunit;

namespace LeikwanBridge.App.Tests.Services;

public sealed class TrayWindowLifecycleTests
{
    [Fact]
    public void RequestExit_BlocksRestore_AndSkipsHideOnClose()
    {
        var lifecycle = new TrayWindowLifecycle();

        lifecycle.RequestExit();

        Assert.False(lifecycle.CanRestore);
        Assert.False(lifecycle.ShouldHideOnClose(minimizeToTray: true, trayReady: true));
    }

    [Fact]
    public void MarkClosing_BlocksRestore()
    {
        var lifecycle = new TrayWindowLifecycle();

        lifecycle.MarkClosing();

        Assert.False(lifecycle.CanRestore);
    }
}
