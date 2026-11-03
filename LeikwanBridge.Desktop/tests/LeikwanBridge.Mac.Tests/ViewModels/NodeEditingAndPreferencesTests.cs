using LeikwanBridge.Core.Nodes;
using LeikwanBridge.Mac.Services;
using LeikwanBridge.Mac.ViewModels;
using Xunit;

namespace LeikwanBridge.Mac.Tests.ViewModels;

public sealed class NodeEditingAndPreferencesTests
{
    [Fact]
    public void BlankSecretKeepsTheStoredValueAndAFilledOneReplacesIt()
    {
        var editor = new NodeEditorViewModel();
        var node = UserNodeShareLink.Parse("ss://YWVzLTI1Ni1nY206b2xkLXNlY3JldA@203.0.113.5:8388#Tokyo");
        node.LocalId = 7;
        node.HostedId = 41;

        editor.Open("7", node, hosted: true);
        Assert.Equal(string.Empty, editor.Password);
        editor.Name = "Tokyo 2";
        editor.PortText = "8443";
        var kept = editor.Build();
        Assert.Equal("old-secret", kept.Password);
        Assert.Equal("Tokyo 2", kept.Name);
        Assert.Equal(8443, kept.Port);
        Assert.Equal(41, kept.HostedId);

        editor.Password = "new-secret";
        Assert.Equal("new-secret", editor.Build().Password);
    }

    [Fact]
    public void VlessUuidIsKeptUnlessReentered()
    {
        var editor = new NodeEditorViewModel();
        editor.Open("4", UserNodeShareLink.Parse(
            "vless://11111111-2222-3333-4444-555555555555@edge.example.net:443?encryption=none&security=tls&type=ws&path=%2Fray#SG"), hosted: false);

        Assert.True(editor.IsVless);
        editor.Path = "/v2";
        var edited = editor.Build();
        Assert.Equal("11111111-2222-3333-4444-555555555555", edited.Uuid);
        Assert.Equal("/v2", edited.Path);
    }

    [Theory]
    [InlineData("0")]
    [InlineData("70000")]
    [InlineData("port")]
    public void InvalidPortIsRefused(string port)
    {
        var editor = new NodeEditorViewModel();
        editor.Open("1", UserNodeShareLink.Parse("ss://YWVzLTI1Ni1nY206cGFzcw@203.0.113.5:8388#A"), hosted: false);
        editor.PortText = port;

        Assert.Equal("proxy_port_invalid", Assert.Throws<NodeLibraryException>(() => editor.Build()).Code);
    }

    [Fact]
    public async Task EditSaveUpdatesTheNodeAndClosesTheEditor()
    {
        var library = new MacUiFixtureNodeLibrary();
        string? classic = null;
        var page = new NodesViewModel(() => library, (_, _) => Task.CompletedTask, key => library.DeleteAsync(key), action => action(),
            key => classic = key);
        page.Refresh([]);

        page.EditCommand.Execute(page.Nodes.Single(node => node.Key == "1"));
        Assert.True(page.Editor.IsOpen);
        Assert.True(page.Editor.IsHosted);
        page.Editor.Name = "Tokyo edited";
        await page.SaveEditCommand.ExecuteAsync(null);

        Assert.False(page.Editor.IsOpen);
        Assert.Equal(string.Empty, page.ErrorMessage);
        Assert.Equal("Tokyo edited", page.Nodes.Single(node => node.Key == "1").Name);

        // WireGuard goes to the classic editor instead.
        page.EditCommand.Execute(page.Nodes.Single(node => node.Key == "3"));
        Assert.False(page.Editor.IsOpen);
        Assert.Equal("3", classic);
    }

    [Fact]
    public async Task ThemeChoiceIsAppliedAndSaved()
    {
        string? saved = null;
        var vm = new MainWindowViewModel(
            new MacUiFixtureController("forwards"),
            dispatch: action => action(),
            themeMode: "light",
            saveThemeMode: (mode, _) => { saved = mode; return Task.CompletedTask; });
        Assert.True(vm.IsThemeDay);

        await vm.SelectThemeCommand.ExecuteAsync("night");

        Assert.Equal(MacAppearance.Night, vm.ThemeMode);
        Assert.True(vm.IsThemeNight);
        Assert.False(vm.IsThemeSystem);
        Assert.Equal(MacAppearance.Night, saved);
        Assert.Equal(MacAppearance.System, MacAppearance.Normalize("bogus"));
    }

    [Fact]
    public void ThemePreferenceRoundTripsAndToleratesACorruptFile()
    {
        var path = Path.Combine(Path.GetTempPath(), "lk-ui-" + Guid.NewGuid().ToString("N"), "ui-preferences.json");
        try
        {
            var store = new MacUiPreferencesStore(path);
            Assert.Equal(MacAppearance.System, store.LoadThemeMode());
            store.SaveThemeModeAsync(MacAppearance.Day).GetAwaiter().GetResult();
            Assert.Equal(MacAppearance.Day, store.LoadThemeMode());
            File.WriteAllText(path, "{oops");
            Assert.Equal(MacAppearance.System, store.LoadThemeMode());
        }
        finally
        {
            Directory.Delete(Path.GetDirectoryName(path)!, recursive: true);
        }
    }

    [Fact]
    public void DashboardSeparatesThePanelLinkFromTheNodeRoute()
    {
        // Panel connected, local node route idle: the two cards must not read as a contradiction.
        var vm = new MainWindowViewModel(new MacUiFixtureController("forwards"), dispatch: action => action());

        Assert.Equal("Panel online", vm.ServiceStatusText);
        Assert.Equal("Not running", vm.NodeRouteStateText);
        Assert.Equal("Not running", vm.LocalConnectionStatusText);
        Assert.DoesNotContain("Disconnected", vm.NodeRouteStateText);
    }
}
