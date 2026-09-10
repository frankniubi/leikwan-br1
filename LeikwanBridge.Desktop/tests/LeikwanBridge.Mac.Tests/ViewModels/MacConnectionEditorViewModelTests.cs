using LeikwanBridge.Mac.Services;
using LeikwanBridge.Mac.ViewModels;
using Xunit;

namespace LeikwanBridge.Mac.Tests.ViewModels;

public sealed class MacConnectionEditorViewModelTests
{
    [Theory]
    [InlineData("0")]
    [InlineData("65536")]
    [InlineData("invalid")]
    public void InvalidPortCannotSilentlyBecome443(string port)
    {
        var editor = new MacConnectionEditorViewModel { PortText = port };
        Assert.Throws<ArgumentException>(() => editor.ToProfile());
    }

    [Fact]
    public void EditorExposesFiniteProtocolAndTransportSelectors()
    {
        var editor = new MacConnectionEditorViewModel();

        Assert.Equal(
            [MacConnectionProtocol.Shadowsocks, MacConnectionProtocol.WireGuard, MacConnectionProtocol.External],
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

    [Fact]
    public void ImportingShadowsocksUriFillsServerPortAndCredentials()
    {
        var editor = new MacConnectionEditorViewModel();

        var imported = editor.TryImport(
            "ss://aes-256-gcm:secret@example.test:8443#Tokyo",
            out var error);

        Assert.True(imported);
        Assert.Empty(error);
        Assert.Equal(MacConnectionProtocol.Shadowsocks, editor.Protocol);
        Assert.Equal("Tokyo", editor.Name);
        Assert.Equal("example.test", editor.Server);
        Assert.Equal("8443", editor.PortText);
        Assert.Equal("aes-256-gcm", editor.Method);
        Assert.Equal("secret", editor.Password);
    }
}
