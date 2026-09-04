using LeikwanBridge.Mac.Services;
using LeikwanBridge.Mac.ViewModels;
using Xunit;

namespace LeikwanBridge.Mac.Tests.ViewModels;

public sealed class MacConnectionEditorViewModelTests
{
    [Fact]
    public void EditorExposesFiniteProtocolAndTransportSelectors()
    {
        var editor = new MacConnectionEditorViewModel();

        Assert.Equal(
            [MacConnectionProtocol.Shadowsocks, MacConnectionProtocol.WireGuard],
            editor.ProtocolOptions);
        Assert.False(MacConnectionProtocol.IsSupported("vless"));
        Assert.Contains("2022-blake3-aes-256-gcm", editor.ShadowsocksMethodOptions);
    }

    [Fact]
    public void VlessProfilesAreNotSupportedByTheMacEditor()
    {
        var editor = new MacConnectionEditorViewModel();

        editor.Load(new MacConnectionProfile
        {
            Protocol = "vless",
            Server = "legacy.example.test",
        });

        Assert.Equal(MacConnectionProtocol.Shadowsocks, editor.Protocol);
        Assert.Equal("Unsupported", new MacConnectionProfile { Protocol = "vless" }.DisplayProtocol);

        editor.Protocol = "vless";
        Assert.Equal(MacConnectionProtocol.Shadowsocks, editor.Protocol);
    }
}
