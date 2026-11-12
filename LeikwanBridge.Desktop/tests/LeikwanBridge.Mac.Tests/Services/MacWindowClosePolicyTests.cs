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
}
