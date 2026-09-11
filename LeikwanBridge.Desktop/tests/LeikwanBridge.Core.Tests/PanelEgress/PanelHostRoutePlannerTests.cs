using System.Net;
using System.Net.Sockets;
using LeikwanBridge.Core.Services.PanelEgress;
using Xunit;

namespace LeikwanBridge.Core.Tests.PanelEgress;

public sealed class PanelHostRoutePlannerTests
{
    private static readonly PhysicalEgressInterface V4 =
        new("Ethernet", 12, AddressFamily.InterNetwork, IPAddress.Parse("192.168.1.1"), true);

    private static readonly PhysicalEgressInterface V6 =
        new("Ethernet", 12, AddressFamily.InterNetworkV6, IPAddress.Parse("fe80::1"), true);

    [Fact]
    public void Plan_BuildsHostRoutesForPublicAddressesOnly()
    {
        var addresses = new[]
        {
            IPAddress.Parse("203.0.113.7"),
            IPAddress.Parse("198.51.100.9"),
            IPAddress.Parse("10.0.0.1"),
            IPAddress.Parse("192.168.1.20"),
            IPAddress.Parse("127.0.0.1"),
            IPAddress.Parse("2001:db8::7"),
            IPAddress.Parse("fe80::1"),
            IPAddress.Parse("fc00::1"),
        };

        var plan = PanelHostRoutePlanner.Plan(addresses, V4, V6, []);

        Assert.Equal(3, plan.ToAdd.Count);
        Assert.Empty(plan.ToRemove);
        Assert.Contains(plan.ToAdd, entry => entry.Address.Equals(IPAddress.Parse("203.0.113.7"))
            && entry.Gateway.Equals(V4.Gateway) && entry.InterfaceIndex == 12);
        Assert.Contains(plan.ToAdd, entry => entry.Address.Equals(IPAddress.Parse("198.51.100.9")));
        Assert.Contains(plan.ToAdd, entry => entry.Address.Equals(IPAddress.Parse("2001:db8::7"))
            && entry.Gateway.Equals(V6.Gateway));
    }

    [Fact]
    public void Plan_MissingGatewaySkipsThatFamily()
    {
        var addresses = new[]
        {
            IPAddress.Parse("203.0.113.7"),
            IPAddress.Parse("2001:db8::7"),
        };

        var noV6 = PanelHostRoutePlanner.Plan(addresses, V4, null, []);
        Assert.Single(noV6.ToAdd);
        Assert.Equal(AddressFamily.InterNetwork, noV6.ToAdd[0].Address.AddressFamily);

        var noV4 = PanelHostRoutePlanner.Plan(addresses, null, V6, []);
        Assert.Single(noV4.ToAdd);
        Assert.Equal(AddressFamily.InterNetworkV6, noV4.ToAdd[0].Address.AddressFamily);

        Assert.Empty(PanelHostRoutePlanner.Plan(addresses, null, null, []).ToAdd);
    }

    [Fact]
    public void Plan_RemovesRoutesNoLongerNeeded()
    {
        var old = new HostRouteEntry(IPAddress.Parse("203.0.113.7"), V4.Gateway!, 12, "Ethernet");
        var kept = new HostRouteEntry(IPAddress.Parse("198.51.100.9"), V4.Gateway!, 12, "Ethernet");

        var plan = PanelHostRoutePlanner.Plan(
            [kept.Address], V4, null, [old, kept]);

        Assert.Contains(plan.ToRemove, entry => entry.Address.Equals(old.Address));
        Assert.DoesNotContain(plan.ToRemove, entry => entry.Address.Equals(kept.Address));
        Assert.Empty(plan.ToAdd);
    }

    [Fact]
    public void Plan_ReplacesRouteWhenGatewayChanged()
    {
        var old = new HostRouteEntry(IPAddress.Parse("203.0.113.7"), IPAddress.Parse("192.168.1.254"), 12, "Ethernet");

        var plan = PanelHostRoutePlanner.Plan([old.Address], V4, null, [old]);

        Assert.Single(plan.ToAdd);
        Assert.Single(plan.ToRemove);
        Assert.Equal(V4.Gateway, plan.ToAdd[0].Gateway);
        Assert.Equal(old.Gateway, plan.ToRemove[0].Gateway);
    }

    [Fact]
    public void IsRouteablePublic_ClassifiesAddresses()
    {
        Assert.True(PanelHostRoutePlanner.IsRouteablePublic(IPAddress.Parse("203.0.113.7")));
        Assert.True(PanelHostRoutePlanner.IsRouteablePublic(IPAddress.Parse("2001:db8::7")));
        Assert.False(PanelHostRoutePlanner.IsRouteablePublic(IPAddress.Parse("10.0.0.1")));
        Assert.False(PanelHostRoutePlanner.IsRouteablePublic(IPAddress.Parse("192.168.1.20")));
        Assert.False(PanelHostRoutePlanner.IsRouteablePublic(IPAddress.Parse("172.16.0.1")));
        Assert.False(PanelHostRoutePlanner.IsRouteablePublic(IPAddress.Parse("169.254.1.1")));
        Assert.False(PanelHostRoutePlanner.IsRouteablePublic(IPAddress.Parse("100.64.0.1")));
        Assert.False(PanelHostRoutePlanner.IsRouteablePublic(IPAddress.Parse("127.0.0.1")));
        Assert.False(PanelHostRoutePlanner.IsRouteablePublic(IPAddress.Loopback));
        Assert.False(PanelHostRoutePlanner.IsRouteablePublic(IPAddress.Parse("fe80::1")));
        Assert.False(PanelHostRoutePlanner.IsRouteablePublic(IPAddress.Parse("fc00::1")));
        Assert.False(PanelHostRoutePlanner.IsRouteablePublic(IPAddress.Parse("ff02::1")));
    }
}
