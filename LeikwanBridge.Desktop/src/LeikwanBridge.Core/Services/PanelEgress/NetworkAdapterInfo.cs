using System.Net;

namespace LeikwanBridge.Core.Services.PanelEgress;

public enum NetworkAdapterKind
{
    Unknown,
    Physical,
    Virtual,
    Tunnel,
    Loopback,
}

/// <summary>
/// A platform-neutral snapshot of a network adapter, produced by
/// <see cref="ISystemInterfaceEnumerator"/>. V4Index/V6Index are the
/// per-family interface indexes used by raw socket options (IP_UNICAST_IF,
/// IP_BOUND_IF, ...). Metric uses -1 when the platform does not expose one.
/// </summary>
public sealed record NetworkAdapterInfo(
    string Name,
    string Description,
    int V4Index,
    int V6Index,
    bool IsUp,
    NetworkAdapterKind Kind,
    int V4Metric,
    int V6Metric,
    IReadOnlyList<IPAddress> Gateways,
    IReadOnlyList<IPAddress> Addresses)
{
    public bool IsLoopback => Kind == NetworkAdapterKind.Loopback;

    public bool IsTunnelLike => Kind == NetworkAdapterKind.Tunnel
        || TunnelInterfaceHeuristics.IsTunnelLikeName(Name, Description);
}

public interface ISystemInterfaceEnumerator
{
    IReadOnlyList<NetworkAdapterInfo> Enumerate();
}

public static class TunnelInterfaceHeuristics
{
    private static readonly string[] TunnelNamePrefixes =
    [
        "utun", "tun", "tap", "wintun", "ppp", "ipsec", "l2tp", "wg", "zt", "tailscale",
        "bridge", "awdl", "llw", "vmnet", "vboxnet", "docker", "veth", "br-",
    ];

    private static readonly string[] TunnelNameMarkers =
    [
        "netch", "wireguard", "openvpn", "nordlynx", "warp", "sstp", "tap-windows", "tap-win32",
        "docker", "virtualbox", "hyper-v", "vmware",
    ];

    private static readonly string[] TunnelDescriptionMarkers =
    [
        "tap-windows", "tap-win32", "wintun", "wireguard", "openvpn", "tun/tap",
        "tunnel adapter", "netch", "tailscale", "warp", "nordlynx", "virtual adapter",
    ];

    public static bool IsTunnelLikeName(string name, string description)
    {
        var normalizedName = (name ?? string.Empty).Trim().ToLowerInvariant();
        var normalizedDescription = (description ?? string.Empty).Trim().ToLowerInvariant();
        if (normalizedName.Length == 0 && normalizedDescription.Length == 0)
            return false;
        if (TunnelNamePrefixes.Any(prefix => normalizedName.StartsWith(prefix, StringComparison.Ordinal)))
            return true;
        if (TunnelNameMarkers.Any(marker => normalizedName.Contains(marker, StringComparison.Ordinal)))
            return true;
        return TunnelDescriptionMarkers.Any(marker => normalizedDescription.Contains(marker, StringComparison.Ordinal));
    }
}

internal sealed class EmptyInterfaceEnumerator : ISystemInterfaceEnumerator
{
    public IReadOnlyList<NetworkAdapterInfo> Enumerate() => [];
}
