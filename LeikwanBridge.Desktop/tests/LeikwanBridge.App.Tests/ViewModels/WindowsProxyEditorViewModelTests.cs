using LeikwanBridge.App.ViewModels;
using LeikwanBridge.Core.Models;
using Xunit;

namespace LeikwanBridge.App.Tests.ViewModels;

public sealed class WindowsProxyEditorViewModelTests
{
    [Fact]
    public void ExposesOnlyFirstReleaseProtocolAndSelectorOptions()
    {
        var editor = new WindowsProxyEditorViewModel();

        Assert.Equal(["ss", "ss2022", "vless"], editor.ProtocolOptions);
        Assert.Contains("none", editor.ShadowsocksMethodOptions);
        Assert.Contains("aes-128-ctr", editor.ShadowsocksMethodOptions);
        Assert.Contains("2022-blake3-aes-256-gcm", editor.ShadowsocksMethodOptions);
        Assert.Equal(["tcp", "ws", "grpc"], editor.VlessTransportOptions);
        Assert.Equal([WindowsProxyMode.Http, WindowsProxyMode.Tun, WindowsProxyMode.Process], editor.ModeOptions);
        Assert.Contains(2080, editor.LocalListenPortOptions);
        Assert.Contains(2081, editor.LocalListenPortOptions);
    }

    [Fact]
    public void ImportLoadsStructuredProfileAndRoundTripsSelectors()
    {
        var editor = new WindowsProxyEditorViewModel();

        Assert.True(editor.TryImport(
            "vless://00000000-0000-0000-0000-000000000001@edge.example.test:443?type=ws&security=tls&sni=cdn.example.test&path=%2Fedge#edge",
            out var error));

        var profile = editor.ToProfile();
        Assert.Equal("", error);
        Assert.Equal("edge", profile.Name);
        Assert.Equal(WindowsProxyProtocol.Vless, profile.Protocol);
        Assert.Equal("ws", profile.Transport);
        Assert.True(profile.Tls);
        Assert.Equal("/edge", profile.Path);
    }

    [Fact]
    public void InvalidPortFallsBackToSafeDefaultForEditorOnly()
    {
        var editor = new WindowsProxyEditorViewModel { PortText = "not-a-port" };
        editor.Protocol = WindowsProxyProtocol.Shadowsocks;
        editor.Server = "edge.example.test";
        editor.Password = "secret";

        Assert.Equal(443, editor.ToProfile().Port);
    }

    [Fact]
    public void ToProfileNormalizesCipherWhenProtocolSelectionOrderWasMixed()
    {
        var editor = new WindowsProxyEditorViewModel
        {
            Protocol = WindowsProxyProtocol.Shadowsocks2022,
            Method = "aes-256-gcm",
            Server = "edge.example.test",
            Password = "test-secret",
        };

        var profile = editor.ToProfile();

        Assert.Equal(WindowsProxyProtocol.Shadowsocks2022, profile.Protocol);
        Assert.Equal("2022-blake3-aes-256-gcm", profile.Method);
    }
}
