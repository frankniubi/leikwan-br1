using LeikwanBridge.Mac.Services;
using Xunit;

namespace LeikwanBridge.Mac.Tests.Services;

public sealed class MacWindowClosePolicyTests
{
    [Fact]
    public void RedButtonHidesTheWindowAndKeepsConnections()
        => Assert.Equal(MacWindowCloseAction.Hide, MacWindowClosePolicy.Decide(quitStarted: false, appIsQuitting: false));

    [Fact]
    public void CmdQDockQuitAndShutdownQuitForReal()
        // Before the fix every close was cancelled and only hid the window, so
        // Cmd+Q left the process and its runtimes behind.
        => Assert.Equal(MacWindowCloseAction.Quit, MacWindowClosePolicy.Decide(quitStarted: false, appIsQuitting: true));

    [Theory]
    [InlineData(false)]
    [InlineData(true)]
    public void TheQuitSequenceClosesTheWindow(bool appIsQuitting)
        => Assert.Equal(MacWindowCloseAction.Close, MacWindowClosePolicy.Decide(quitStarted: true, appIsQuitting));

    [Theory]
    [InlineData(true, true, false)]
    [InlineData(true, false, true)]
    [InlineData(false, true, true)]
    [InlineData(false, false, true)]
    public void AHiddenWindowKeepsTheDockIconUnlessTheAppLivesInTheMenuBar(bool keepInMenuBar, bool menuBarIcon, bool dock)
        => Assert.Equal(dock, MacWindowClosePolicy.ShowInDockWhileHidden(keepInMenuBar, menuBarIcon));

    [Theory]
    [InlineData(true, true, true)]
    [InlineData(true, false, false)]
    [InlineData(false, true, false)]
    [InlineData(false, false, false)]
    public void MinimizingHidesToTheMenuBarOnlyWhenTheAppLivesThere(bool keepInMenuBar, bool menuBarIcon, bool hides)
        // Without a menu-bar icon the Dock is the only way back, so the window
        // still minimizes into it.
        => Assert.Equal(hides, MacWindowClosePolicy.MinimizeHidesToMenuBar(keepInMenuBar, menuBarIcon));

    [Fact]
    public void TheDockIconIsLeftAloneOffMacOS()
    {
        if (OperatingSystem.IsMacOS())
            return;
        Assert.False(MacDockIcon.TrySetVisible(false));
        Assert.False(MacDockIcon.TrySetVisible(true));
    }
}
