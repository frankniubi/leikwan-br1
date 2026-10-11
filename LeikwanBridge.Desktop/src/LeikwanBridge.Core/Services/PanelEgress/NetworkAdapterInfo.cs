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

    /// <summary>
    /// True for an adapter that may carry panel/update traffic: up, not a
    /// loopback, not a TUN/VPN tool and not a hypervisor/container switch.
    /// Automatic selection and the user-facing adapter lists share this rule,
    /// so an explicit choice can never name an adapter Automatic would skip.
    /// </summary>
    public bool IsEgressEligible => IsUp
        && !IsLoopback
        && !IsTunnelLike
        && Kind is not NetworkAdapterKind.Virtual;
}

public interface ISystemInterfaceEnumerator
{
    IReadOnlyList<NetworkAdapterInfo> Enumerate();
}

/// <summary>
/// Name/description rules for adapters that must never carry panel traffic,
/// including the ones whose driver reports plain Ethernet (TAP-Windows,
/// Hyper-V vEthernet, VMware VMnet, ...). Every comparison is
/// case-insensitive. Real Wi-Fi, Ethernet and USB tethering adapters
/// ("Remote NDIS based Internet Sharing Device", "Apple Mobile Device
/// Ethernet", macOS en*) match none of these rules.
/// </summary>
public static class TunnelInterfaceHeuristics
{
    // Interface-name prefixes: BSD/macOS/Linux style kernel names.
    private static readonly string[] TunnelNamePrefixes =
    [
        "utun", "tun", "tap", "wintun", "ppp", "ipsec", "l2tp", "wg", "zt", "tailscale",
        "bridge", "awdl", "llw", "vmnet", "vmenet", "vboxnet", "vnic", "docker", "veth", "br-",
        "feth", "gif", "stf", "anpi", "pktap",
    ];

    // Short adapter names TUN tools create on Windows that are too generic to
    // match as substrings (Mihomo/Clash Meta names its Wintun adapter "Meta").
    private static readonly HashSet<string> TunnelExactNames = new(StringComparer.Ordinal)
    {
        "meta", "mihomo", "clash", "xray", "v2ray",
    };

    // Only meaningful in a friendly name: in a description "Hyper-V" also
    // names the synthetic NIC of a Hyper-V guest, which is that guest's uplink.
    private static readonly string[] TunnelNameMarkers =
    [
        "hyper-v",
    ];

    // Substrings checked against both the name and the description.
    private static readonly string[] TunnelMarkers =
    [
        // OpenVPN / TAP / Wintun / WireGuard.
        "tap-windows", "tap-win32", "tap0901", "tap adapter", "tun/tap", "openvpn", "ovpn-dco",
        "wintun", "wireguard", "nordlynx", "tunnel",
        // Proxy clients that install a TUN adapter (ours included).
        "clash", "mihomo", "sing-box", "singbox", "sing-tun", "v2rayn", "xray", "nekobox", "nekoray",
        "netch", "hiddify", "sstap", "leikwan bridge",
        // Overlay and consumer/enterprise VPNs.
        "tailscale", "zerotier", "hamachi", "radmin", "softether", "vpn", "warp", "sstp", "l2tp",
        "pptp", "anyconnect", "globalprotect", "pangp", "fortinet", "forticlient", "juniper",
        "pulse secure", "wan miniport", "teredo", "ip-https", "6to4", "isatap",
        // Capture and test loopbacks.
        "npcap", "loopback",
        // Host side of hypervisor and container switches.
        "vethernet", "default switch", "hyper-v virtual", "vmware network adapter",
        "vmware virtual", "vmnet", "virtualbox", "host-only", "shared networking", "docker", "wsl",
        "virtual adapter", "virtual miniport", "virtual port",
    ];

    public static bool IsTunnelLikeName(string name, string description)
    {
        var normalizedName = (name ?? string.Empty).Trim().ToLowerInvariant();
        var normalizedDescription = (description ?? string.Empty).Trim().ToLowerInvariant();
        if (normalizedName.Length == 0 && normalizedDescription.Length == 0)
            return false;
        if (TunnelNamePrefixes.Any(prefix => normalizedName.StartsWith(prefix, StringComparison.Ordinal)))
            return true;
        if (TunnelExactNames.Contains(normalizedName))
            return true;
        if (TunnelNameMarkers.Any(marker => normalizedName.Contains(marker, StringComparison.Ordinal)))
            return true;
        return TunnelMarkers.Any(marker =>
            normalizedName.Contains(marker, StringComparison.Ordinal)
            || normalizedDescription.Contains(marker, StringComparison.Ordinal));
    }
}

internal sealed class EmptyInterfaceEnumerator : ISystemInterfaceEnumerator
{
    public IReadOnlyList<NetworkAdapterInfo> Enumerate() => [];
}

/// <summary>Platform enumerator used by the selector and the adapter catalog.</summary>
internal static class SystemInterfaceEnumerator
{
    public static ISystemInterfaceEnumerator ForCurrentPlatform()
    {
        if (OperatingSystem.IsWindows())
            return new WindowsInterfaceEnumerator();
        if (OperatingSystem.IsMacOS())
            return new MacInterfaceEnumerator();
        return new EmptyInterfaceEnumerator();
    }
}
