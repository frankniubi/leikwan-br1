using System.Net;
using System.Net.Sockets;

namespace LeikwanBridge.Core.Services.PanelEgress;

public sealed record PhysicalEgressInterface(
    string Name,
    int Index,
    AddressFamily Family,
    IPAddress? Gateway,
    bool IsDefaultRouteHolder);

/// <summary>
/// Pure, OS-independent selection logic over adapter snapshots so it can be
/// exercised on any test host. Real providers adapt platform enumeration
/// (iphlpapi GetAdaptersAddresses on Windows, getifaddrs-style managed APIs
/// plus <c>route -n get default</c> on macOS) into
/// <see cref="NetworkAdapterInfo"/> lists and delegate here.
/// </summary>
public static class PhysicalInterfaceSelector
{
    public static int MetricFor(NetworkAdapterInfo adapter, AddressFamily family)
        => family == AddressFamily.InterNetwork ? adapter.V4Metric : adapter.V6Metric;

    public static int IndexFor(NetworkAdapterInfo adapter, AddressFamily family)
        => family == AddressFamily.InterNetwork ? adapter.V4Index : adapter.V6Index;

    private static bool HasFamily(NetworkAdapterInfo adapter, AddressFamily family)
        => adapter.Gateways.Any(gateway => gateway.AddressFamily == family)
            || adapter.Addresses.Any(address => address.AddressFamily == family);

    public static PhysicalEgressInterface? SelectPreferred(
        IReadOnlyList<NetworkAdapterInfo> adapters,
        AddressFamily family,
        bool preferMacPhysicalNaming = false,
        string? preferredInterfaceName = null)
    {
        var holder = FindDefaultRouteHolder(adapters, family);
        var eligible = adapters
            .Where(adapter => adapter.IsUp
                && !adapter.IsLoopback
                && !adapter.IsTunnelLike
                && adapter.Kind is not NetworkAdapterKind.Virtual
                && IndexFor(adapter, family) > 0
                && HasFamily(adapter, family))
            .ToList();
        var hasExplicitPreference = !string.IsNullOrWhiteSpace(preferredInterfaceName);
        var preferred = eligible.FirstOrDefault(adapter =>
            hasExplicitPreference
            && string.Equals(adapter.Name, preferredInterfaceName!.Trim(), StringComparison.OrdinalIgnoreCase));
        if (hasExplicitPreference && preferred is null)
            return null;
        IEnumerable<NetworkAdapterInfo> candidates = preferred is null ? eligible : new[] { preferred };
        var best = candidates
            .Select(adapter => new
            {
                Adapter = adapter,
                Gateway = adapter.Gateways.FirstOrDefault(gateway => gateway.AddressFamily == family),
            })
            .OrderBy(item => NormalizeMetric(MetricFor(item.Adapter, family)))
            .ThenBy(item => NameScore(item.Adapter.Name, preferMacPhysicalNaming))
            .ThenBy(item => item.Adapter.Name, StringComparer.OrdinalIgnoreCase)
            .FirstOrDefault();
        if (best is null)
            return null;

        return new PhysicalEgressInterface(
            best.Adapter.Name,
            IndexFor(best.Adapter, family),
            family,
            best.Gateway,
            holder is not null
                && string.Equals(holder.Name, best.Adapter.Name, StringComparison.OrdinalIgnoreCase)
                && IndexFor(holder, family) == IndexFor(best.Adapter, family));
    }

    public static NetworkAdapterInfo? FindDefaultRouteHolder(
        IReadOnlyList<NetworkAdapterInfo> adapters,
        AddressFamily family)
    {
        return adapters
            .Where(adapter => adapter.IsUp
                && !adapter.IsLoopback
                && adapter.Gateways.Any(gateway => gateway.AddressFamily == family))
            .OrderBy(adapter => NormalizeMetric(MetricFor(adapter, family)))
            .ThenBy(adapter => NameScore(adapter.Name, preferMacPhysicalNaming: false))
            .ThenBy(adapter => adapter.Name, StringComparer.OrdinalIgnoreCase)
            .FirstOrDefault();
    }

    public static bool IsDefaultRoutePhysical(
        IReadOnlyList<NetworkAdapterInfo> adapters,
        AddressFamily family)
    {
        var holder = FindDefaultRouteHolder(adapters, family);
        return holder is null || !holder.IsTunnelLike;
    }

    private static int NormalizeMetric(int metric) => metric < 0 ? int.MaxValue : metric;

    private static int NameScore(string name, bool preferMacPhysicalNaming)
    {
        var normalized = (name ?? string.Empty).Trim().ToLowerInvariant();
        if (normalized.StartsWith("en", StringComparison.Ordinal))
            return 0;
        if (preferMacPhysicalNaming)
            return 1;
        if (normalized.StartsWith("ethernet", StringComparison.Ordinal)
            || normalized.StartsWith("wi-fi", StringComparison.Ordinal)
            || normalized.StartsWith("wlan", StringComparison.Ordinal)
            || normalized.Contains("以太网", StringComparison.Ordinal)
            || normalized.Contains("本地连接", StringComparison.Ordinal))
            return 0;
        return 1;
    }
}
