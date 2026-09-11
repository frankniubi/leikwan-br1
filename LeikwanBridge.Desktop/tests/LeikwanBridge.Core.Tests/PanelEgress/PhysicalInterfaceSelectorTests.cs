using System.Net;
using System.Net.Sockets;
using LeikwanBridge.Core.Services.PanelEgress;
using Xunit;

namespace LeikwanBridge.Core.Tests.PanelEgress;

public sealed class PhysicalInterfaceSelectorTests
{
    private static readonly IPAddress Gateway = IPAddress.Parse("192.168.1.1");

    [Fact]
    public void Preferred_SkipsTunNamedInterfaceAndPicksPhysical()
    {
        var adapters = new[]
        {
            AdapterBuilder.Adapter("utun3", NetworkAdapterKind.Tunnel, 5, 5, v4Metric: 5, gateways: [Gateway]),
            AdapterBuilder.Adapter("Ethernet", NetworkAdapterKind.Physical, 12, 12, v4Metric: 25, gateways: [Gateway]),
        };

        var selected = PhysicalInterfaceSelector.SelectPreferred(adapters, AddressFamily.InterNetwork);

        Assert.NotNull(selected);
        Assert.Equal("Ethernet", selected.Name);
        Assert.Equal(12, selected.Index);
        Assert.Equal(Gateway, selected.Gateway);
    }

    [Fact]
    public void Preferred_LowestMetricWinsAmongPhysicalCandidates()
    {
        var adapters = new[]
        {
            AdapterBuilder.Adapter("Wi-Fi", NetworkAdapterKind.Physical, 4, 4, v4Metric: 55, gateways: [Gateway]),
            AdapterBuilder.Adapter("Ethernet", NetworkAdapterKind.Physical, 12, 12, v4Metric: 25, gateways: [Gateway]),
        };

        var selected = PhysicalInterfaceSelector.SelectPreferred(adapters, AddressFamily.InterNetwork);

        Assert.NotNull(selected);
        Assert.Equal("Ethernet", selected.Name);
        Assert.True(selected.IsDefaultRouteHolder);
    }

    [Fact]
    public void DefaultRouteHolder_TunnelWithLowerMetricWins()
    {
        var adapters = new[]
        {
            AdapterBuilder.Adapter("Ethernet", NetworkAdapterKind.Physical, 12, 12, v4Metric: 25, gateways: [Gateway]),
            AdapterBuilder.Adapter("Netch TUN", NetworkAdapterKind.Tunnel, 6, 6, v4Metric: 5, gateways: [Gateway]),
        };

        Assert.False(PhysicalInterfaceSelector.IsDefaultRoutePhysical(adapters, AddressFamily.InterNetwork));
        Assert.NotNull(PhysicalInterfaceSelector.FindDefaultRouteHolder(adapters, AddressFamily.InterNetwork));
        Assert.Equal("Netch TUN", PhysicalInterfaceSelector.FindDefaultRouteHolder(adapters, AddressFamily.InterNetwork)!.Name);
    }

    [Fact]
    public void DefaultRoutePhysical_WhenPhysicalHoldsLowestMetricOrNoRouteExists()
    {
        var physical = new[]
        {
            AdapterBuilder.Adapter("Ethernet", NetworkAdapterKind.Physical, 12, 12, v4Metric: 5, gateways: [Gateway]),
            AdapterBuilder.Adapter("utun0", NetworkAdapterKind.Tunnel, 3, 3, v4Metric: 25, gateways: [Gateway]),
        };
        Assert.True(PhysicalInterfaceSelector.IsDefaultRoutePhysical(physical, AddressFamily.InterNetwork));

        // No adapter with a gateway: unknown, assume physical (zero side effects).
        var none = new[] { AdapterBuilder.Adapter("Ethernet") };
        Assert.True(PhysicalInterfaceSelector.IsDefaultRoutePhysical(none, AddressFamily.InterNetwork));
    }

    [Fact]
    public void Preferred_ExcludesLoopbackVirtualAndDownAdapters()
    {
        var adapters = new[]
        {
            AdapterBuilder.Adapter("Loopback", NetworkAdapterKind.Loopback, 1, 1, gateways: [Gateway]),
            AdapterBuilder.Adapter("PPP VPN", NetworkAdapterKind.Virtual, 2, 2, gateways: [Gateway]),
            AdapterBuilder.Adapter("DownEth", NetworkAdapterKind.Physical, 3, 3, isUp: false, gateways: [Gateway]),
        };

        Assert.Null(PhysicalInterfaceSelector.SelectPreferred(adapters, AddressFamily.InterNetwork));
    }

    [Fact]
    public void Preferred_AddressOnlyAdapterSelectedWhenNoGatewayKnown()
    {
        // macOS managed enumeration carries no gateways; the adapter with a
        // family address must still be selectable for socket binding.
        var adapters = new[]
        {
            AdapterBuilder.Adapter("en0", NetworkAdapterKind.Physical, 8, 8,
                addresses: [IPAddress.Parse("192.168.1.20")]),
        };

        var selected = PhysicalInterfaceSelector.SelectPreferred(adapters, AddressFamily.InterNetwork, preferMacPhysicalNaming: true);

        Assert.NotNull(selected);
        Assert.Equal("en0", selected.Name);
        Assert.Null(selected.Gateway);
    }

    [Fact]
    public void Preferred_PrefersEnNamedInterfacesOnMac()
    {
        var adapters = new[]
        {
            AdapterBuilder.Adapter("bridge100", NetworkAdapterKind.Unknown, 9, 9,
                addresses: [IPAddress.Parse("192.168.64.1")]),
            AdapterBuilder.Adapter("en1", NetworkAdapterKind.Physical, 8, 8,
                addresses: [IPAddress.Parse("192.168.1.20")]),
        };

        var selected = PhysicalInterfaceSelector.SelectPreferred(adapters, AddressFamily.InterNetwork, preferMacPhysicalNaming: true);

        Assert.NotNull(selected);
        Assert.Equal("en1", selected.Name);
    }

    [Fact]
    public void Preferred_ReturnsNullWhenNothingEligible()
    {
        Assert.Null(PhysicalInterfaceSelector.SelectPreferred([], AddressFamily.InterNetwork));
        Assert.Null(PhysicalInterfaceSelector.SelectPreferred(
            [AdapterBuilder.Adapter("utun0", NetworkAdapterKind.Tunnel, 3, 3)], AddressFamily.InterNetwork));
    }

    [Theory]
    [InlineData("utun3", "", true)]
    [InlineData("tun0", "", true)]
    [InlineData("tap0", "", true)]
    [InlineData("wintun userspace tunnel", "", true)]
    [InlineData("Netch TUN", "", true)]
    [InlineData("wg0", "", true)]
    [InlineData("zt12345", "", true)]
    [InlineData("en0", "", false)]
    [InlineData("Ethernet", "", false)]
    [InlineData("Wi-Fi", "", false)]
    [InlineData("以太网", "", false)]
    [InlineData("Ethernet 2", "TAP-Windows Adapter V9", true)]
    [InlineData("Local Area Connection", "WireGuard Tunnel", true)]
    [InlineData("", "", false)]
    public void TunnelHeuristics_ClassifyNames(string name, string description, bool expected)
    {
        Assert.Equal(expected, TunnelInterfaceHeuristics.IsTunnelLikeName(name, description));
    }
}
