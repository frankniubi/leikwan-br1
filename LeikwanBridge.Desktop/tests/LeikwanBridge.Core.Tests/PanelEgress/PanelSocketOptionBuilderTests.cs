using System.Net.Sockets;
using LeikwanBridge.Core.Services.PanelEgress;
using Xunit;

namespace LeikwanBridge.Core.Tests.PanelEgress;

public sealed class PanelSocketOptionBuilderTests
{
    [Fact]
    public void WindowsIpv4_UsesIpUnicastIfWithNetworkByteOrderIndex()
    {
        var request = PanelSocketOptionBuilder.Build(PanelEgressPlatform.Windows, AddressFamily.InterNetwork, 0x0506);

        Assert.NotNull(request);
        Assert.Equal(PanelSocketOptionBuilder.IpprotoIp, request.Level);
        Assert.Equal(PanelSocketOptionBuilder.IpUnicastIf, request.Name);
        Assert.Equal(new byte[] { 0x00, 0x00, 0x05, 0x06 }, request.Value);
    }

    [Fact]
    public void WindowsIpv6_UsesIpv6UnicastIfWithHostByteOrderIndex()
    {
        var request = PanelSocketOptionBuilder.Build(PanelEgressPlatform.Windows, AddressFamily.InterNetworkV6, 0x0506);

        Assert.NotNull(request);
        Assert.Equal(PanelSocketOptionBuilder.IpprotoIpv6, request.Level);
        Assert.Equal(PanelSocketOptionBuilder.Ipv6UnicastIf, request.Name);
        Assert.Equal(new byte[] { 0x06, 0x05, 0x00, 0x00 }, request.Value);
    }

    [Fact]
    public void MacOsIpv4_UsesIpBoundIfWithNativeIndex()
    {
        var request = PanelSocketOptionBuilder.Build(PanelEgressPlatform.MacOS, AddressFamily.InterNetwork, 7);

        Assert.NotNull(request);
        Assert.Equal(PanelSocketOptionBuilder.IpprotoIp, request.Level);
        Assert.Equal(PanelSocketOptionBuilder.IpBoundIf, request.Name);
        Assert.Equal(new byte[] { 0x07, 0x00, 0x00, 0x00 }, request.Value);
    }

    [Fact]
    public void MacOsIpv6_UsesIpv6BoundIfWithNativeIndex()
    {
        var request = PanelSocketOptionBuilder.Build(PanelEgressPlatform.MacOS, AddressFamily.InterNetworkV6, 3);

        Assert.NotNull(request);
        Assert.Equal(PanelSocketOptionBuilder.IpprotoIpv6, request.Level);
        Assert.Equal(PanelSocketOptionBuilder.Ipv6BoundIf, request.Name);
        Assert.Equal(new byte[] { 0x03, 0x00, 0x00, 0x00 }, request.Value);
    }

    [Theory]
    [InlineData(0)]
    [InlineData(-1)]
    public void InvalidIndex_ReturnsNull(int index)
    {
        Assert.Null(PanelSocketOptionBuilder.Build(PanelEgressPlatform.Windows, AddressFamily.InterNetwork, index));
        Assert.Null(PanelSocketOptionBuilder.Build(PanelEgressPlatform.MacOS, AddressFamily.InterNetwork, index));
    }

    [Fact]
    public void UnknownPlatformOrFamily_ReturnsNull()
    {
        Assert.Null(PanelSocketOptionBuilder.Build(PanelEgressPlatform.Unknown, AddressFamily.InterNetwork, 1));
        Assert.Null(PanelSocketOptionBuilder.Build(PanelEgressPlatform.Windows, AddressFamily.Unix, 1));
        Assert.Null(PanelSocketOptionBuilder.Build(PanelEgressPlatform.MacOS, AddressFamily.Unix, 1));
    }

    [Fact]
    public void DocumentedConstants_MatchPlatformHeaders()
    {
        // Windows winsock: IP_UNICAST_IF = 31 (IPPROTO_IP), IPV6_UNICAST_IF = 31 (IPPROTO_IPV6).
        // Darwin netinet/in.h: IP_BOUND_IF = 25 (IPPROTO_IP), IPV6_BOUND_IF = 125 (IPPROTO_IPV6).
        Assert.Equal(0, PanelSocketOptionBuilder.IpprotoIp);
        Assert.Equal(41, PanelSocketOptionBuilder.IpprotoIpv6);
        Assert.Equal(31, PanelSocketOptionBuilder.IpUnicastIf);
        Assert.Equal(31, PanelSocketOptionBuilder.Ipv6UnicastIf);
        Assert.Equal(25, PanelSocketOptionBuilder.IpBoundIf);
        Assert.Equal(125, PanelSocketOptionBuilder.Ipv6BoundIf);
    }
}
