using System.Text.RegularExpressions;
using System.Xml.Linq;
using LeikwanBridge.App.Services;
using Xunit;

namespace LeikwanBridge.App.Tests.Views;

/// <summary>The FlClash-style shell: navigation, dashboard cards, nodes page, theme and localization.</summary>
public sealed class RedesignLayoutTests
{
    private static readonly XNamespace X = "http://schemas.microsoft.com/winfx/2006/xaml";

    [Fact]
    public void SidebarNavigatesToDashboardServicesConnectNativeAndSettings()
    {
        var document = XDocument.Load(AppFile("Views", "MainWindow.xaml"));
        var targets = document.Descendants().Where(node => node.Name.LocalName == "Button"
                && (string?)node.Attribute("Command") == "{Binding NavigateToCommand}"
                && node.Attribute("Tag") is not null)
            .Select(node => (string?)node.Attribute("CommandParameter"))
            .ToList();
        foreach (var view in new[] { "Dashboard", "Forwards", "Proxy", "Native", "Settings" })
            Assert.Contains(view, targets);
        // The nodes live on the services page now, so the old Nodes item is gone.
        Assert.DoesNotContain("Nodes", targets);
        // Native outbound is listed only while the plan assigns one.
        var native = Assert.Single(document.Descendants().Where(node => node.Name.LocalName == "Button"
            && (string?)node.Attribute("CommandParameter") == "Native"));
        Assert.Equal("{Binding IsNativeOutboundVisible, Converter={StaticResource BoolToVis}}", (string?)native.Attribute("Visibility"));
    }

    [Fact]
    public void DashboardHasFourAccountCardsAboveTheForwardControls()
    {
        var document = XDocument.Load(AppFile("Views", "MainWindow.xaml"));
        var dashboard = Named(document, "DashboardPanel");
        foreach (var key in new[] { "DashPlan", "DashUsage", "DashRateLimit", "DashProvince", "DashForwardControls" })
            Assert.Contains(dashboard.Descendants(), node => (string?)node.Attribute("Text") == "{DynamicResource " + key + "}");
        foreach (var binding in new[] { "PlanText", "SpeedLimitText", "ProvinceText" })
            Assert.Contains(dashboard.Descendants(), node => (string?)node.Attribute("Text") == "{Binding " + binding + ", Converter={StaticResource ResourceText}}");
        // Speed chart, mode radios and the current-service combo are not on the dashboard any more.
        Assert.DoesNotContain(document.Descendants(), node => (string?)node.Attribute(X + "Name") is "DashSpeedChart" or "DashModeHttp" or "DashServiceCombo");
        Assert.Equal("OnCoreToggle_Click", (string?)Named(document, "CoreToggleButton").Attribute("Click"));
        var code = File.ReadAllText(AppFile("Views", "MainWindow.Dashboard.cs"));
        // The button reuses the paired-forward connect flow and the ordinary stop path.
        Assert.Contains("OnProxySelectedConnect_Click(sender, e)", code, StringComparison.Ordinal);
        Assert.Contains("await _vm.StopProxyAsync()", code, StringComparison.Ordinal);
    }

    [Fact]
    public void ServicesPageOffersImportCloudSyncBadgesAndPairing()
    {
        var document = XDocument.Load(AppFile("Views", "MainWindow.xaml"));
        var page = Named(document, "ForwardPairingPanel");
        foreach (var handler in new[] { "OnNodesImport_Click", "OnNodesSyncAll_Click", "OnNodesRefreshCloud_Click", "OnNodeUpload_Click", "OnNodeCopyLink_Click", "OnSaveForwardPairings_Click" })
            Assert.Contains(page.Descendants(), node => (string?)node.Attribute("Click") == handler);
        Assert.Contains(page.Descendants(), node => (string?)node.Attribute("Visibility") == "{Binding IsHosted, Converter={StaticResource BoolToVis}}");
        Assert.Contains(page.Descendants(), node => (string?)node.Attribute("ItemsSource") == "{Binding ProxyForwardRows}");
        Assert.Contains(page.Descendants(), node => (string?)node.Attribute(X + "Name") == "ForwardPairingNodeSection");
        // One place per action: the separate nodes page and its duplicate pairing list are gone.
        Assert.DoesNotContain(document.Descendants(), node => (string?)node.Attribute(X + "Name") is "NodesPanel" or "NodesPairingRows");
    }

    [Fact]
    public void ThemeSettingOffersFollowSystemDayAndNightWithoutAXamlDefault()
    {
        var document = XDocument.Load(AppFile("Views", "MainWindow.xaml"));
        var theme = Named(document, "SettTheme");
        Assert.Null(theme.Attribute("SelectedIndex"));
        Assert.Equal(["System", "Light", "Dark"], theme.Elements().Select(item => (string)item.Attribute("Tag")!).ToArray());
        Assert.Equal(ThemePreference.Dark, ThemeManager.ParsePreference("dark"));
        Assert.Equal(ThemePreference.System, ThemeManager.ParsePreference("bogus"));
        Assert.Equal("Follow system", Strings("en-US.xaml")["ThemeSystem"]);
        Assert.Equal(["跟随系统", "日间", "夜间"], new[] { "ThemeSystem", "ThemeLight", "ThemeDark" }.Select(key => Strings("zh-CN.xaml")[key]).ToArray());
    }

    [Fact]
    public void DayAndNightPalettesDefineTheSameRoles()
    {
        var light = Keys(XDocument.Load(AppFile("Resources", "Themes", "Colors.Light.xaml")));
        var dark = Keys(XDocument.Load(AppFile("Resources", "Themes", "Colors.Dark.xaml")));
        Assert.Equal(light, dark);
        // Seed #2563EB fidelity scheme shared with Android.
        Assert.Contains("#004AC6", File.ReadAllText(AppFile("Resources", "Themes", "Colors.Light.xaml")), StringComparison.Ordinal);
        Assert.Contains("#B4C5FF", File.ReadAllText(AppFile("Resources", "Themes", "Colors.Dark.xaml")), StringComparison.Ordinal);
    }

    [Fact]
    public void EveryResourceTheWindowUsesExistsInBothLanguagesAndThePalette()
    {
        var en = Strings("en-US.xaml");
        var zh = Strings("zh-CN.xaml");
        var palette = Keys(XDocument.Load(AppFile("Resources", "Themes", "Colors.Light.xaml")));
        var theme = Keys(XDocument.Load(AppFile("Resources", "Styles", "Theme.xaml")));
        var xaml = File.ReadAllText(AppFile("Views", "MainWindow.xaml")) + File.ReadAllText(AppFile("Resources", "Styles", "Theme.xaml"));
        foreach (Match match in Regex.Matches(xaml, @"\{DynamicResource (\w+)\}"))
        {
            var key = match.Groups[1].Value;
            Assert.True((en.ContainsKey(key) && zh.ContainsKey(key)) || palette.Contains(key) || theme.Contains(key), "missing resource: " + key);
        }
        foreach (var file in new[] { "MainWindow.Dashboard.cs", "MainWindow.xaml.cs" })
        {
            foreach (Match match in Regex.Matches(File.ReadAllText(AppFile("Views", file)), "(?:FindResource|SetResourceReference\\([^,]+,)\\s*\\(?\"(\\w+)\""))
                Assert.True((en.ContainsKey(match.Groups[1].Value) && zh.ContainsKey(match.Groups[1].Value)) || palette.Contains(match.Groups[1].Value),
                    "missing resource: " + match.Groups[1].Value);
        }
    }

    [Fact]
    public void EnglishStringsContainNoChineseAndChineseNavigationIsLocalized()
    {
        var en = Strings("en-US.xaml");
        foreach (var (key, value) in en.Where(pair => pair.Key != "AppTitle"))
            Assert.False(Regex.IsMatch(value, "[一-鿿]"), $"{key} mixes Chinese into English: {value}");
        var zh = Strings("zh-CN.xaml");
        foreach (var key in new[] { "NavDashboard", "NavForwards", "NavNodes", "NavSettings", "StateConnected", "QualityGood" })
            Assert.Matches("[一-鿿]", zh[key]);
    }

    [Fact]
    public void LanConnectionHintIsAlwaysShownOnTheProxyPage()
    {
        var document = XDocument.Load(AppFile("Views", "MainWindow.xaml"));
        var hint = Assert.Single(document.Descendants(), node => (string?)node.Attribute("Text") == "{DynamicResource ProxyExternalConnectionHint}");
        Assert.Null(hint.Attribute("Visibility"));
    }

    [Fact]
    public void OwnNodesAreLabelledWithoutTheirProtocolOutsideTheAddForm()
    {
        var xaml = File.ReadAllText(AppFile("Views", "MainWindow.xaml"));
        Assert.DoesNotContain("{Binding DisplayProtocol}", xaml, StringComparison.Ordinal);
        Assert.DoesNotContain("{Binding Protocol}", xaml, StringComparison.Ordinal);
        Assert.Contains("{DynamicResource OwnNodeLabel}", xaml, StringComparison.Ordinal);
        Assert.Equal("Your own node", Strings("en-US.xaml")["OwnNodeLabel"]);
        Assert.Equal("自带节点", Strings("zh-CN.xaml")["OwnNodeLabel"]);
        // The protocol picker appears only while adding a node, never for an existing one.
        Assert.Equal(2, Regex.Matches(xaml, @"Visibility=""\{Binding ProxyEditor.IsNew, Converter=\{StaticResource BoolToVis\}\}""").Count);
        // The supported protocols are named only where nodes are added or imported.
        Assert.Equal("Supports Shadowsocks, SOCKS5, VLESS", Strings("en-US.xaml")["ProxyImportSupported"]);
        foreach (var key in new[] { "ProxyMyNodes", "ProxyRowNode", "NodesCloudBadge", "DashCloudNodes" })
            Assert.DoesNotMatch("(?i)shadowsocks|socks|vless|wireguard", Strings("en-US.xaml")[key]);
    }

    private static HashSet<string> Keys(XDocument document)
        => document.Root!.Elements().Select(element => (string?)element.Attribute(X + "Key")).Where(key => key is not null).Select(key => key!).ToHashSet();

    private static Dictionary<string, string> Strings(string file)
        => XDocument.Load(AppFile("Resources", "Strings", file)).Root!.Elements()
            .Where(element => element.Attribute(X + "Key") is not null)
            .ToDictionary(element => (string)element.Attribute(X + "Key")!, element => element.Value);

    private static XElement Named(XDocument document, string name) => Assert.Single(document.Descendants(),
        node => (string?)node.Attribute(X + "Name") == name);

    private static string AppFile(params string[] relative)
    {
        var directory = new DirectoryInfo(AppContext.BaseDirectory);
        while (directory is not null)
        {
            var path = Path.Combine([directory.FullName, "LeikwanBridge.Desktop", "src", "LeikwanBridge.App", .. relative]);
            if (File.Exists(path)) return path;
            directory = directory.Parent;
        }
        throw new DirectoryNotFoundException("repository root not found");
    }
}
