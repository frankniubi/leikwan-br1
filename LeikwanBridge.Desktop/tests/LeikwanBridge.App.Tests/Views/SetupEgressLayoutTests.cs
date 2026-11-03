using System.Xml.Linq;
using Xunit;

namespace LeikwanBridge.App.Tests.Views;

public sealed class SetupEgressLayoutTests
{
    private static readonly XNamespace X = "http://schemas.microsoft.com/winfx/2006/xaml";

    [Fact]
    public void SignInCardOffersEthernetBindingBeforeTheCredentials()
    {
        var document = XDocument.Load(AppFile("Views", "MainWindow.xaml"));
        var overlay = Named(document, "SetupOverlay");
        var selector = Named(document, "SetupEgressInterface");

        Assert.Contains(overlay, selector.Ancestors());
        Assert.Equal("ComboBox", selector.Name.LocalName);
        Assert.Equal("CompactLabel", (string?)selector.Attribute("DisplayMemberPath"));
        Assert.Equal("Id", (string?)selector.Attribute("SelectedValuePath"));
        Assert.Equal("OnSetupEgressInterfaceChanged", (string?)selector.Attribute("SelectionChanged"));
        Assert.Equal("OnSetupEgressRefresh_Click", (string?)Named(document, "SetupEgressRefreshButton").Attribute("Click"));
        Assert.Contains(overlay.Descendants(), node => (string?)node.Attribute("Text") == "{DynamicResource SetupEgressHint}");
        Assert.Contains(overlay, Named(document, "SetupEgressNotice").Ancestors());

        var order = document.Descendants().ToList();
        var connect = overlay.Descendants().Single(node => (string?)node.Attribute("Click") == "OnConnect_Click");
        Assert.True(order.IndexOf(selector) < order.IndexOf(Named(document, "TxtClientId")));
        Assert.True(order.IndexOf(selector) < order.IndexOf(connect));
    }

    [Fact]
    public void SignInAppliesTheAdapterBeforeTheFirstPanelRequest()
    {
        var code = File.ReadAllText(AppFile("Views", "MainWindow.xaml.cs"));
        var connect = code[code.IndexOf("private async void OnConnect_Click", StringComparison.Ordinal)..];
        var apply = connect.IndexOf("EnsureSetupEgressApplied();", StringComparison.Ordinal);
        var request = connect.IndexOf("new MasterApiClient(", StringComparison.Ordinal);

        Assert.True(apply >= 0 && request > apply, "the adapter must be applied before the sign-in client exists");
        // Settings and sign-in write the same field through the same helper.
        Assert.Contains("ApplyEgressInterface(egressInterface, persist: false);", code, StringComparison.Ordinal);
        Assert.Contains("PanelDirectEgress.SetPreferredInterface(App.Config.PreferredEgressInterface);", code, StringComparison.Ordinal);
    }

    [Theory]
    [InlineData("en-US.xaml", "Ethernet Binding", "Automatic (recommended)",
        "Sign-in traffic bypasses system proxies and leaves through the selected adapter.")]
    [InlineData("zh-CN.xaml", "网卡绑定", "自动（推荐）", "登录请求将绕过系统代理并直接从所选网卡发出")]
    public void BothLanguagesDefineTheEthernetBindingStrings(string file, string label, string automatic, string hint)
    {
        var strings = XDocument.Load(AppFile("Resources", "Strings", file)).Root!.Elements()
            .Where(element => element.Attribute(X + "Key") is not null)
            .ToDictionary(element => (string)element.Attribute(X + "Key")!, element => element.Value);

        Assert.Equal(label, strings["SetupEgressInterface"]);
        Assert.Equal(automatic, strings["SetupEgressAutomatic"]);
        Assert.Equal(hint, strings["SetupEgressHint"]);
        Assert.Contains("{0}", strings["SetupEgressMissing"], StringComparison.Ordinal);
        Assert.NotEmpty(strings["SetupEgressRefresh"]);
    }

    private static XElement Named(XDocument document, string name)
        => document.Descendants().Single(node => (string?)node.Attribute(X + "Name") == name);

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
