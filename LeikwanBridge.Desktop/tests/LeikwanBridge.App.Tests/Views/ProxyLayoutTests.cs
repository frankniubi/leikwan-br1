using System.Xml.Linq;
using Xunit;

namespace LeikwanBridge.App.Tests.Views;

public sealed class ProxyLayoutTests
{
    private static readonly XNamespace X = "http://schemas.microsoft.com/winfx/2006/xaml";

    [Fact]
    public void RoutesAreVisibleBeforeCollapsedEditors()
    {
        var document = Load();
        var routes = Named(document, "ProxyPairedForwardCombo");
        Assert.DoesNotContain(routes.Ancestors(), node => node.Name.LocalName == "Expander");
        var ordinary = Named(document, "ProxyNodeEditorExpander");
        var native = Named(document, "NativeOutboundExpander");
        Assert.Equal("False", (string?)ordinary.Attribute("IsExpanded"));
        var order = document.Descendants().ToList();
        Assert.True(order.IndexOf(routes) < order.IndexOf(ordinary));
        Assert.True(order.IndexOf(ordinary) < order.IndexOf(native));
        Assert.DoesNotContain(document.Descendants(), node => (string?)node.Attribute(X + "Name") == "ProxyStartButton");
    }

    [Fact]
    public void SavedNodesHaveTheirOwnSidebarAndEditorActions()
    {
        var document = Load();
        var sidebar = Named(document, "ForwardPairingNodeSection");
        var list = Named(document, "ForwardPairingProfileList");
        Assert.Equal("ListBox", list.Name.LocalName);
        Assert.Contains(sidebar, list.Ancestors());
        foreach (var handler in new[] { "OnPairingNew_Click", "OnPairingEdit_Click", "OnPairingDelete_Click" })
            Assert.Contains(sidebar.Descendants(), node => (string?)node.Attribute("Click") == handler);
        Assert.DoesNotContain(list.AncestorsAndSelf().TakeWhile(node => node != sidebar.Parent?.Parent),
            node => (string?)node.Attribute("Visibility") == "Collapsed");
    }

    [Fact]
    public void EachRouteExposesLabelledListenerAndTwoWayNodeSelection()
    {
        var document = Load();
        var routes = Named(document, "ProxyPairedForwardCombo");
        Assert.Equal("{Binding PairedProxyForwardRows}", (string?)routes.Attribute("ItemsSource"));
        Assert.Equal("ForwardId", (string?)routes.Attribute("SelectedValuePath"));
        Assert.Contains("SelectedProxyForwardId", (string?)routes.Attribute("SelectedValue") ?? "");
        Assert.Contains(routes.Descendants(), node => (string?)node.Attribute("Text") == "{Binding ListenerEndpoint}");
        Assert.Contains(document.Descendants(), node => (string?)node.Attribute("Text") == "{DynamicResource ProxyRouteListenPort}");
    }

    private static XElement Named(XDocument document, string name) => Assert.Single(document.Descendants(),
        node => (string?)node.Attribute(X + "Name") == name);

    [Fact]
    public void ImportFieldShowsShadowsocksPromptAndEmptyInputPlaceholder()
    {
        var document = Load();
        var editor = Named(document, "ForwardPairingNodeEditorExpander");
        Assert.Contains(editor.Descendants(), node => (string?)node.Attribute("Text") == "{DynamicResource ProxyImportPrompt}");
        Assert.Contains(editor.Descendants(), node => (string?)node.Attribute("Text") == "{DynamicResource ProxyImportSupported}");
        var placeholder = Named(document, "PairingImportPlaceholder");
        Assert.Equal("ss://", (string?)placeholder.Attribute("Text"));
        Assert.Equal("False", (string?)placeholder.Attribute("IsHitTestVisible"));
        var trigger = Assert.Single(placeholder.Descendants(), node => node.Name.LocalName == "DataTrigger");
        Assert.Equal("{Binding Text, ElementName=PairingImportUri}", (string?)trigger.Attribute("Binding"));
        Assert.Equal("", (string?)trigger.Attribute("Value"));
        Assert.Equal("{DynamicResource ProxyImportSupported}", (string?)Named(document, "PairingImportUri").Attribute("AutomationProperties.HelpText"));
    }

    [Fact]
    public void OrdinaryProxyUsesSingleDrawerWithClearlySeparatedConnectionSections()
    {
        var document = Load();
        var ordinary = Named(document, "ProxyNodeEditorExpander");
        Assert.DoesNotContain(document.Descendants(), node => (string?)node.Attribute(X + "Name") == "ProxyAdvancedExpander");

        foreach (var control in new[] { "ProxyModeCombo", "ProxyBypassChinaCheckBox", "ProxyLocalListenPortCombo" })
            Assert.Contains(document.Descendants(), node => (string?)node.Attribute(X + "Name") == control);

        foreach (var section in new[] { "ProxyImportSection", "ProxyManualSection", "ProxyConnectionSection" })
        {
            var border = Named(document, section);
            Assert.Equal("Border", border.Name.LocalName);
            Assert.NotNull(border.Attribute("Background"));
            Assert.NotNull(border.Attribute("BorderBrush"));
        }

        var sectionOrder = new[] { "ProxyImportSection", "ProxyManualSection", "ProxyConnectionSection" }
            .Select(name => document.Descendants().ToList().IndexOf(Named(document, name))).ToArray();
        Assert.True(sectionOrder[0] < sectionOrder[1] && sectionOrder[1] < sectionOrder[2]);
    }

    [Fact]
    public void ForwardNavigationIsPairingOnlyAndHasAnExplicitSaveSurface()
    {
        var document = Load();
        var nav = Assert.Single(document.Descendants().Where(node => node.Name.LocalName == "Button"), node =>
            (string?)node.Attribute("CommandParameter") == "Forwards");
        Assert.Equal("{DynamicResource NavForwards}", (string?)nav.Attribute("Content"));

        var panel = Named(document, "ForwardPairingPanel");
        Assert.Contains(panel.Descendants(), node => (string?)node.Attribute("Text") == "{DynamicResource ForwardPairingHint}");
        Assert.Contains(panel.Descendants(), node => (string?)node.Attribute("Content") == "{DynamicResource ForwardPairingSave}");
        Assert.Contains(panel.Descendants(), node => (string?)node.Attribute("Click") == "OnSaveForwardPairings_Click");
        Assert.Contains(panel.Descendants(), node => (string?)node.Attribute("ItemsSource") == "{Binding ProxyForwardRows}");
        var pairingCombo = Assert.Single(panel.Descendants(), node =>
            node.Name.LocalName == "ComboBox" && ((string?)node.Attribute("SelectedValue") ?? "").Contains("SelectedProfileId", StringComparison.Ordinal));
        Assert.Contains(pairingCombo.Descendants(), node => node.Name.LocalName == "DataTemplate");
        Assert.Contains(pairingCombo.Descendants(), node => (string?)node.Attribute("Text") == "{Binding Name}");
        Assert.DoesNotContain(panel.Descendants(), node =>
            (string?)node.Attribute("Click") is "OnProxyRowConnect_Click" or "OnProxyRowDisconnect_Click");
        Assert.DoesNotContain(panel.Descendants(), node =>
            (string?)node.Attribute("Command") is "{Binding StartAllCommand}" or "{Binding StopAllForwardsCommand}");
        var nodeSection = Named(document, "ForwardPairingNodeSection");
        Assert.Contains(nodeSection.Descendants(), node => (string?)node.Attribute(X + "Name") == "ForwardPairingProfileList");
        Assert.Contains(nodeSection.Descendants(), node => (string?)node.Attribute(X + "Name") == "PairingImportUri");
        Assert.Contains(nodeSection.Descendants(), node => (string?)node.Attribute("Click") == "OnPairingSave_Click");
        Assert.Contains(panel.Descendants(), node => node.Name.LocalName == "ComboBox"
            && ((string?)node.Attribute("SelectedValue") ?? "").Contains("SelectedProfileId", StringComparison.Ordinal));
    }

    [Fact]
    public void ProxyPageOnlyShowsPairedForwardConnectionActions()
    {
        var document = Load();
        var proxyRows = Named(document, "ProxyPairedForwardCombo");
        Assert.Equal("{Binding PairedProxyForwardRows}", (string?)proxyRows.Attribute("ItemsSource"));
        Assert.Contains(proxyRows.Descendants(), node => (string?)node.Attribute("Text") == "{Binding ListenerEndpoint}");
        Assert.Contains(document.Descendants(), node => (string?)node.Attribute("Click") == "OnProxySelectedConnect_Click");
        Assert.Contains(document.Descendants(), node => (string?)node.Attribute("Click") == "OnProxySelectedDisconnect_Click");
        Assert.DoesNotContain(document.Descendants(), node => (string?)node.Attribute(X + "Name") == "ProxyForwardRowsList");
        Assert.Equal("Collapsed", (string?)Named(document, "ProxySavedNodesSidebar").Attribute("Visibility"));
        Assert.Equal("Visible", (string?)Named(document, "ProxyNodeEditorExpander").Attribute("Visibility"));
        Assert.Equal("Collapsed", (string?)Named(document, "ProxyImportSection").Attribute("Visibility"));
        Assert.Equal("Collapsed", (string?)Named(document, "ProxyManualSection").Attribute("Visibility"));
        Assert.Equal("Collapsed", (string?)Named(document, "ProxySaveNodeButton").Attribute("Visibility"));
    }

    [Fact]
    public void ProxyRequiresAnExplicitModeBeforeConnect()
    {
        var document = Load();
        var mode = Named(document, "ProxyModeCombo");
        Assert.Equal("0", (string?)mode.Attribute("SelectedIndex"));
        Assert.Contains(mode.Descendants(), node => (string?)node.Attribute("Tag") == "");
        Assert.Contains(mode.Descendants(), node => (string?)node.Attribute("Tag") == "Http");
        Assert.Contains(mode.Descendants(), node => (string?)node.Attribute("Tag") == "Tun");
        Assert.Contains(mode.Descendants(), node => (string?)node.Attribute("Tag") == "Process");
        Assert.Contains(document.Descendants(), node => (string?)node.Attribute(X + "Name") == "ProxySelectedConnectButton"
            && ((string?)node.Attribute("IsEnabled") ?? "").Contains("IsProxyReadyForSelectedPairedForward", StringComparison.Ordinal));
    }

    [Fact]
    public void DashboardHasForwardLoadingSurfaceBeforeList()
    {
        var document = Load();
        var loading = Named(document, "DashboardForwardLoading");
        var list = Assert.Single(document.Descendants().Where(node => node.Name.LocalName == "ItemsControl"), node =>
            (string?)node.Attribute("ItemsSource") == "{Binding Forwards}");
        Assert.Contains(loading.Descendants(), node => node.Name.LocalName == "ProgressBar");
        Assert.Contains("IsLoadingForwards", (string?)loading.Attribute("Visibility") ?? "");
        Assert.True(document.Descendants().ToList().IndexOf(loading) < document.Descendants().ToList().IndexOf(list));
    }

    [Fact]
    public void PairingPageKeepsGlobalStartStopActionsHiddenByName()
    {
        var document = Load();
        foreach (var name in new[] { "SidebarStartAllButton", "SidebarStopAllButton" })
        {
            var button = Named(document, name);
            Assert.NotNull(button.Attribute("Visibility"));
        }
    }

    private static XDocument Load()
    {
        var directory = new DirectoryInfo(AppContext.BaseDirectory);
        while (directory is not null)
        {
            var path = Path.Combine(directory.FullName, "LeikwanBridge.Desktop", "src", "LeikwanBridge.App", "Views", "MainWindow.xaml");
            if (File.Exists(path)) return XDocument.Load(path);
            directory = directory.Parent;
        }
        throw new DirectoryNotFoundException("repository root not found");
    }
}
