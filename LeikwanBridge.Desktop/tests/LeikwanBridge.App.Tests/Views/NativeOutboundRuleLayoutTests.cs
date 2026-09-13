using System.Xml.Linq;
using Xunit;

namespace LeikwanBridge.App.Tests.Views;

/// <summary>
/// The Windows shell must render the native outbound rule label in both the
/// forward card and the details panel, and keep start/stop visibility tied to
/// the summary flags so the row can never offer an agent-side action.
/// </summary>
public sealed class NativeOutboundRuleLayoutTests
{
    private static readonly XNamespace X = "http://schemas.microsoft.com/winfx/2006/xaml";

    [Fact]
    public void ForwardCardAndDetailsPanelCarryTheNativeOutboundRuleLabel()
    {
        var document = LoadMainWindow();
        var labels = document.Descendants()
            .Where(element => (string?)element.Attribute("Text") == "{DynamicResource ForwardNativeOutboundRule}")
            .ToList();

        Assert.Equal(2, labels.Count);
        Assert.Contains(labels, label =>
            (string?)label.Attribute("Visibility") == "{Binding Summary.IsNativeOutboundRule, Converter={StaticResource BoolToVis}}");
        Assert.Contains(labels, label =>
            (string?)label.Attribute("Visibility") == "{Binding SelectedForward.Summary.IsNativeOutboundRule, Converter={StaticResource BoolToVis}}");
    }

    [Fact]
    public void StartAndStopButtonsStayBehindTheSummaryFlags()
    {
        var document = LoadMainWindow();
        var buttons = document.Descendants()
            .Where(element => element.Name.LocalName == "Button"
                && ((string?)element.Attribute("Command"))?.Contains("ForwardCommand", StringComparison.Ordinal) == true)
            .ToList();

        Assert.Equal(4, buttons.Count);
        foreach (var button in buttons)
        {
            var visibility = (string?)button.Attribute("Visibility");
            Assert.NotNull(visibility);
            Assert.Contains("Summary.Can", visibility, StringComparison.Ordinal);
        }
    }

    [Fact]
    public void BothLocalesDefineTheNativeOutboundRuleLabel()
    {
        Assert.Equal("(Native Outbound Rule)", ResourceValue("en-US.xaml", "ForwardNativeOutboundRule"));
        Assert.Equal("（原生出站规则）", ResourceValue("zh-CN.xaml", "ForwardNativeOutboundRule"));
    }

    private static string ResourceValue(string fileName, string key)
    {
        var path = Path.Combine(
            SourceRoot().FullName, "src", "LeikwanBridge.App", "Resources", "Strings", fileName);
        var document = XDocument.Load(path);
        var entry = document.Root!.Elements()
            .Single(element => (string?)element.Attribute(X + "Key") == key);
        return entry.Value;
    }

    private static XDocument LoadMainWindow()
        => XDocument.Load(Path.Combine(SourceRoot().FullName, "src", "LeikwanBridge.App", "Views", "MainWindow.xaml"));

    private static DirectoryInfo SourceRoot()
    {
        var root = new DirectoryInfo(AppContext.BaseDirectory);
        while (root is not null && !Directory.Exists(Path.Combine(root.FullName, "src", "LeikwanBridge.App")))
            root = root.Parent;
        Assert.NotNull(root);
        return root!;
    }
}
