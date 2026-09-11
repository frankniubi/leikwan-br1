using System.Net;
using System.Net.Sockets;

namespace LeikwanBridge.Core.Services.PanelEgress;

public sealed record HostRouteEntry(
    IPAddress Address,
    IPAddress Gateway,
    int InterfaceIndex,
    string InterfaceName);

public sealed record RoutePlan(
    IReadOnlyList<HostRouteEntry> ToAdd,
    IReadOnlyList<HostRouteEntry> ToRemove);

/// <summary>
/// Pure host-route planning. Builds /32 (IPv4) and /128 (IPv6) routes through
/// the physical gateway for every routeable public panel IP, and diffs them
/// against the currently applied set. Longest-prefix match makes these host
/// routes win over any third-party 0.0.0.0/0 default route.
/// </summary>
public static class PanelHostRoutePlanner
{
    public static RoutePlan Plan(
        IReadOnlyList<IPAddress> addresses,
        PhysicalEgressInterface? v4,
        PhysicalEgressInterface? v6,
        IEnumerable<HostRouteEntry> applied)
    {
        var targets = new List<HostRouteEntry>();
        foreach (var address in addresses)
        {
            if (!IsRouteablePublic(address))
                continue;

            PhysicalEgressInterface? egress;
            if (address.AddressFamily == AddressFamily.InterNetwork)
            {
                egress = v4?.Gateway is null ? null : v4;
            }
            else if (address.AddressFamily == AddressFamily.InterNetworkV6)
            {
                egress = v6?.Gateway is null ? null : v6;
            }
            else
            {
                continue;
            }

            if (egress is null || egress.Index <= 0)
                continue;
            targets.Add(new HostRouteEntry(address, egress.Gateway!, egress.Index, egress.Name));
        }

        var appliedList = applied.ToList();
        var appliedByAddress = appliedList.ToDictionary(
            entry => entry.Address.ToString(), StringComparer.Ordinal);
        var targetByAddress = targets.ToDictionary(
            entry => entry.Address.ToString(), StringComparer.Ordinal);

        var toAdd = targets
            .Where(target => !appliedByAddress.TryGetValue(target.Address.ToString(), out var existing)
                || !existing.Equals(target))
            .ToList();
        var toRemove = appliedList
            .Where(existing => !targetByAddress.TryGetValue(existing.Address.ToString(), out var target)
                || !target.Equals(existing))
            .ToList();
        return new RoutePlan(toAdd, toRemove);
    }

    public static bool IsRouteablePublic(IPAddress address)
    {
        if (address is null)
            return false;

        if (address.AddressFamily == AddressFamily.InterNetwork)
            return !LeikwanHttpHostPolicy.IsBypassAddress(address);

        if (address.AddressFamily == AddressFamily.InterNetworkV6)
        {
            if (IPAddress.IsLoopback(address)
                || address.IsIPv6Multicast
                || address.IsIPv6LinkLocal
                || address.IsIPv6SiteLocal
                || address.IsIPv6Teredo)
                return false;
            var first = address.GetAddressBytes()[0];
            if (first is 0xfc or 0xfd)
                return false;
            return true;
        }

        return false;
    }
}
