using System.Xml.Linq;
using LeikwanBridge.Mac.Services;
using LeikwanBridge.Mac.ViewModels;
using Xunit;

namespace LeikwanBridge.Mac.Tests.ViewModels;

public sealed class MainWindowShellTests
{
    [Fact]
    public void SidebarSectionsAreMutuallyExclusive()
    {
        var vm = new MainWindowViewModel(new MacUiFixtureController("forwards"), dispatch: action => action());

        Assert.True(vm.IsDashboardSelected);
        vm.ShowNodesCommand.Execute(null);
        Assert.True(vm.IsNodesVisible);
        Assert.True(vm.IsNodesSelected);
        Assert.True(vm.IsNodesSectionVisible);
        Assert.False(vm.IsDashboardVisible);

        // 线路配对 is a tab of the Nodes section.
        vm.ShowPairingCommand.Execute(null);
        Assert.False(vm.IsNodesVisible);
        Assert.True(vm.IsPairingVisible);
        Assert.True(vm.IsNodesSelected);
        Assert.True(vm.IsNodesSectionVisible);

        vm.ShowConnectionsCommand.Execute(null);
        Assert.True(vm.IsServicesSelected);
        Assert.False(vm.IsNodesSectionVisible);

        vm.ShowSettingsCommand.Execute(null);
        Assert.True(vm.IsSettingsSelected);
        Assert.False(vm.IsNodesVisible);
    }

    [Fact]
    public void RunningTimerCountsFromTheFirstRunningTick()
    {
        // The fixture has two running forwards.
        var vm = new MainWindowViewModel(new MacUiFixtureController("forwards"), dispatch: action => action());
        Assert.True(vm.IsAnyRunning);
        var start = DateTimeOffset.UtcNow;

        vm.Tick(start);
        vm.Tick(start.AddHours(2).AddMinutes(13).AddSeconds(57));

        Assert.Equal("02:13:57", vm.RunningTimeText);
    }

    [Fact]
    public void TimerStaysAtZeroWhenNothingRuns()
    {
        var vm = new MainWindowViewModel(new MacUiFixtureController("dashboard"), dispatch: action => action());
        Assert.False(vm.IsAnyRunning);

        vm.Tick(DateTimeOffset.UtcNow);
        vm.Tick(DateTimeOffset.UtcNow.AddMinutes(5));

        Assert.Equal("00:00:00", vm.RunningTimeText);
    }

    [Fact]
    public void TickTurnsCountersIntoRatesAndSessionTotals()
    {
        long bytesIn = 1_000_000;
        var vm = new MainWindowViewModel(
            new MacUiFixtureController("forwards"),
            dispatch: action => action(),
            trafficCounters: () => new Dictionary<string, TrafficCounter> { ["a"] = new(bytesIn, 0) });
        var start = DateTimeOffset.UtcNow;

        vm.Tick(start);
        bytesIn += 2 * 1024 * 1024;
        vm.Tick(start.AddSeconds(2));

        Assert.Equal("1.0 MB/s", vm.DownloadRateText);
        Assert.Equal("2.0 MB", vm.SessionDownloadText);
        Assert.Equal(TrafficMonitor.HistoryLength, vm.DownloadHistory.Count);
    }

    [Fact]
    public void OutboundModeCardSelectsTheConnectionMode()
    {
        var vm = new MainWindowViewModel(new MacUiFixtureController("forwards"), dispatch: action => action());

        vm.SelectOutboundModeCommand.Execute(MacConnectionMode.Vpn);

        Assert.Equal(MacConnectionMode.Vpn, vm.SelectedConnectionMode);
        Assert.True(vm.IsVpnModeSelected);
        Assert.False(vm.IsProxyModeSelected);
    }

    [Fact]
    public void ElapsedFormatKeepsHoursPastADay()
        => Assert.Equal("26:00:05", MainWindowViewModel.FormatElapsed(TimeSpan.FromHours(26) + TimeSpan.FromSeconds(5)));

    [Fact]
    public void WindowHasFourPrimarySectionsAndARunToggle()
    {
        var root = new DirectoryInfo(AppContext.BaseDirectory);
        while (root is not null && !Directory.Exists(Path.Combine(root.FullName, "src", "LeikwanBridge.Mac")))
            root = root.Parent;
        Assert.NotNull(root);
        var view = XDocument.Load(Path.Combine(root.FullName, "src", "LeikwanBridge.Mac", "Views", "MainWindow.axaml"));
        var nav = view.Descendants()
            .Where(element => element.Name.LocalName == "Button" && ((string?)element.Attribute("Classes"))?.Split(' ').Contains("nav") == true)
            .Select(element => (string?)element.Attribute("Command"))
            .ToList();
        Assert.Equal(
            [
                "{Binding ShowDashboardCommand}",
                "{Binding ShowConnectionsCommand}",
                "{Binding ShowNodesCommand}",
                "{Binding ShowSettingsCommand}",
                "{Binding ShowLogsCommand}",
            ],
            nav);
        var fab = Assert.Single(view.Descendants(), element => (string?)element.Attribute("Command") == "{Binding ToggleRunningCommand}");
        Assert.Contains(fab.Descendants(), element => (string?)element.Attribute("Text") == "{Binding RunningTimeText}");
        Assert.Contains(view.Descendants(), element => (string?)element.Attribute("Command") == "{Binding CheckForUpdatesFromSidebarCommand}");
    }
}
