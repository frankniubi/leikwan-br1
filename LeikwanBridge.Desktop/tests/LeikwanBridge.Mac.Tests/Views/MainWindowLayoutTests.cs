using System.Xml.Linq;
using Xunit;

namespace LeikwanBridge.Mac.Tests.Views;

public sealed class MainWindowLayoutTests
{
    [Fact]
    public void SidebarExposesForwardPairingGuide()
    {
        var root = new DirectoryInfo(AppContext.BaseDirectory);
        while (root is not null && !Directory.Exists(Path.Combine(root.FullName, "src", "LeikwanBridge.Mac")))
            root = root.Parent;
        Assert.NotNull(root);
        var view = XDocument.Load(Path.Combine(root.FullName, "src", "LeikwanBridge.Mac", "Views", "MainWindow.axaml"));
        var pairingButton = Assert.Single(view.Descendants(), element =>
            element.Name.LocalName == "Button"
            && (string?)element.Attribute("Command") == "{Binding ShowPairingCommand}");
        Assert.Contains(pairingButton.Descendants(), element =>
            element.Name.LocalName == "TextBlock" && (string?)element.Attribute("Text") == "线路配对");
    }

    [Fact]
    public void ConnectionsShowOnlyPairedRoutesInOneSelectorAndKeepNodeManagementInSecondaryOverlays()
    {
        var root = new DirectoryInfo(AppContext.BaseDirectory);
        while (root is not null && !Directory.Exists(Path.Combine(root.FullName, "src", "LeikwanBridge.Mac")))
            root = root.Parent;
        Assert.NotNull(root);
        var view = XDocument.Load(Path.Combine(root.FullName, "src", "LeikwanBridge.Mac", "Views", "MainWindow.axaml"));
        var connections = Assert.Single(view.Descendants(), element =>
            (string?)element.Attribute("IsVisible") == "{Binding IsConnectionsVisible}");
        var routeSelector = Assert.Single(connections.Descendants(), element =>
            element.Name.LocalName == "ComboBox" && (string?)element.Attribute("ItemsSource") == "{Binding PairedConnectionRoutes}");
        Assert.Equal("{Binding SelectedConnectionForward, Mode=TwoWay}", (string?)routeSelector.Attribute("SelectedItem"));
        var modeSelector = Assert.Single(connections.Descendants(), element =>
            element.Name.LocalName == "ComboBox"
            && (string?)element.Attribute("ItemsSource") == "{Binding ConnectionModes}"
            && !element.Ancestors().Any(ancestor => (string?)ancestor.Attribute("IsVisible") == "False"));
        Assert.Equal("{Binding SelectedConnectionMode, Mode=TwoWay}", (string?)modeSelector.Attribute("SelectedItem"));
        Assert.Equal("请选择连接模式", (string?)modeSelector.Attribute("PlaceholderText"));
        Assert.Contains(connections.Descendants(), element => (string?)element.Attribute("Command") == "{Binding StartConnectionCommand}");
        Assert.Contains(connections.Descendants(), element => (string?)element.Attribute("Command") == "{Binding StopConnectionCommand}");
        Assert.DoesNotContain(connections.Descendants(), element =>
            element.Name.LocalName == "ItemsControl"
            && (string?)element.Attribute("ItemsSource") == "{Binding ConnectionRoutes}"
            && (string?)element.Attribute("IsVisible") != "False");
        Assert.DoesNotContain(connections.Descendants(), element => element.Name.LocalName == "ComboBox"
            && (string?)element.Attribute("ItemsSource") == "{Binding Forwards}");
        var library = Assert.Single(connections.Descendants(), element =>
            (string?)element.Attribute("ItemsSource") == "{Binding ConnectionProfiles}");
        Assert.Equal("0", (string?)routeSelector.Ancestors().First(element => element.Name.LocalName == "ScrollViewer").Attribute("Grid.Column"));
        Assert.Equal("1", (string?)library.Ancestors().First(element => element.Attribute("Grid.Column") is not null).Attribute("Grid.Column"));
        Assert.Contains(library.Ancestors(), element => element.Name.LocalName == "Border");
    }

    [Fact]
    public void ConnectionErrorAndExternalHintRemainOutsideCollapsedEditor()
    {
        var root = new DirectoryInfo(AppContext.BaseDirectory);
        while (root is not null && !Directory.Exists(Path.Combine(root.FullName, "src", "LeikwanBridge.Mac")))
            root = root.Parent;
        Assert.NotNull(root);
        var view = XDocument.Load(Path.Combine(root.FullName, "src", "LeikwanBridge.Mac", "Views", "MainWindow.axaml"));
        var textBlocks = view.Descendants().Where(element => element.Name.LocalName == "TextBlock").ToList();
        var error = Assert.Single(textBlocks, element => (string?)element.Attribute("Text") == "{Binding LocalConnectionError}");
        var hint = Assert.Single(textBlocks, element => (string?)element.Attribute("Text") ==
            "您可使用外部连接工具发起连接, 但需注意, 如在本机发起连接, 需把IP地址改为 127.0.0.1 且端口一致；若从局域网或外部发起连接，则IP地址需改为此计算机之地址。");
        Assert.DoesNotContain("Expander", error.Ancestors().Select(element => element.Name.LocalName));
        Assert.DoesNotContain("Expander", hint.Ancestors().Select(element => element.Name.LocalName));
        Assert.Equal("Wrap", (string?)hint.Attribute("TextWrapping"));
        Assert.Equal("11", (string?)hint.Attribute("FontSize"));
        Assert.Equal("muted", (string?)hint.Attribute("Classes"));
        Assert.DoesNotContain(textBlocks, element => ((string?)element.Attribute("Text"))?.StartsWith("Use a local node", StringComparison.Ordinal) == true);
    }
}
