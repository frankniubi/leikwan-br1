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
            [MacConnectionProtocol.Shadowsocks, MacConnectionProtocol.Vless, MacConnectionProtocol.WireGuard],
            editor.ProtocolOptions);
        Assert.Contains("tcp", editor.VlessTransportOptions);
        Assert.Contains("ws", editor.VlessTransportOptions);
        Assert.Contains("grpc", editor.VlessTransportOptions);
        Assert.Contains("2022-blake3-aes-256-gcm", editor.ShadowsocksMethodOptions);
    }
}
