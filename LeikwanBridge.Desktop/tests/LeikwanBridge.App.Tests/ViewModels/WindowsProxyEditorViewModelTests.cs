using LeikwanBridge.App.ViewModels;
using LeikwanBridge.Core.Models;
using Xunit;

namespace LeikwanBridge.App.Tests.ViewModels;

public sealed class WindowsProxyEditorViewModelTests
{
    [Fact]
    public void ExposesSavedProtocolAndExternalNodeSelectorOptions()
    {
        var editor = new WindowsProxyEditorViewModel();

        Assert.Equal(["ss", "ss2022", "vless", "socks5", "external"], editor.ProtocolOptions);
        Assert.Contains("none", editor.ShadowsocksMethodOptions);
        Assert.Contains("aes-128-ctr", editor.ShadowsocksMethodOptions);
        Assert.Contains("2022-blake3-aes-256-gcm", editor.ShadowsocksMethodOptions);
        Assert.Equal(["tcp", "ws", "grpc", "httpupgrade"], editor.VlessTransportOptions);
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

    [Fact]
    public void ProtocolIsChosenOnlyWhileAddingANode()
    {
        var editor = new WindowsProxyEditorViewModel();
        editor.Load(null);
        Assert.True(editor.IsNew);
        editor.Load(new WindowsProxyProfile { Id = 4, Protocol = WindowsProxyProtocol.Socks5, Server = "198.51.100.7", Port = 1080, HostedId = 11, Username = "alice", Password = "pw" });
        Assert.False(editor.IsNew);
        var saved = editor.ToProfile();
        Assert.Equal((11L, "alice", WindowsProxyProtocol.Socks5), (saved.HostedId, saved.Username, saved.Protocol));
    }
}
