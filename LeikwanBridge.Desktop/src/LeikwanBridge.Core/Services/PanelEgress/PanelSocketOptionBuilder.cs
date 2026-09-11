using System.Net.Sockets;

namespace LeikwanBridge.Core.Services.PanelEgress;

public enum PanelEgressPlatform
{
    Unknown,
    Windows,
    MacOS,
}

public sealed record RawSocketOptionRequest(int Level, int Name, byte[] Value);

/// <summary>
/// Builds the (level, option, value) tuples that force a socket's outgoing
/// interface, per platform. Pure function so both OS branches are unit
/// testable on any host.
///
/// Windows (winsock, verified against MSDN and wireguard-go):
///   IP_UNICAST_IF    = 31 at IPPROTO_IP   (0),  value: DWORD ifIndex in NETWORK byte order.
///   IPV6_UNICAST_IF  = 31 at IPPROTO_IPV6 (41), value: DWORD ifIndex in HOST byte order.
///
/// macOS (Darwin &lt;netinet/in.h&gt; / &lt;netinet6/in6.h&gt;):
///   IP_BOUND_IF      = 25 at IPPROTO_IP   (0),  value: native int ifIndex.
///   IPV6_BOUND_IF    = 125 at IPPROTO_IPV6 (41), value: native int ifIndex.
/// </summary>
public static class PanelSocketOptionBuilder
{
    public const int IpprotoIp = 0;
    public const int IpprotoIpv6 = 41;
    public const int IpUnicastIf = 31;
    public const int Ipv6UnicastIf = 31;
    public const int IpBoundIf = 25;
    public const int Ipv6BoundIf = 125;

    public static PanelEgressPlatform CurrentPlatform =>
        OperatingSystem.IsWindows() ? PanelEgressPlatform.Windows
        : OperatingSystem.IsMacOS() ? PanelEgressPlatform.MacOS
        : PanelEgressPlatform.Unknown;

    public static RawSocketOptionRequest? Build(
        PanelEgressPlatform platform,
        AddressFamily family,
        int interfaceIndex)
    {
        if (interfaceIndex <= 0)
            return null;

        return platform switch
        {
            PanelEgressPlatform.Windows when family == AddressFamily.InterNetwork =>
                new RawSocketOptionRequest(IpprotoIp, IpUnicastIf, NetworkOrderUInt32(interfaceIndex)),
            PanelEgressPlatform.Windows when family == AddressFamily.InterNetworkV6 =>
                new RawSocketOptionRequest(IpprotoIpv6, Ipv6UnicastIf, HostOrderUInt32(interfaceIndex)),
            PanelEgressPlatform.MacOS when family == AddressFamily.InterNetwork =>
                new RawSocketOptionRequest(IpprotoIp, IpBoundIf, HostOrderInt32(interfaceIndex)),
            PanelEgressPlatform.MacOS when family == AddressFamily.InterNetworkV6 =>
                new RawSocketOptionRequest(IpprotoIpv6, Ipv6BoundIf, HostOrderInt32(interfaceIndex)),
            _ => null,
        };
    }

    internal static byte[] NetworkOrderUInt32(int value)
    {
        var bytes = BitConverter.GetBytes((uint)value);
        if (BitConverter.IsLittleEndian)
            Array.Reverse(bytes);
        return bytes;
    }

    internal static byte[] HostOrderUInt32(int value) => BitConverter.GetBytes((uint)value);

    internal static byte[] HostOrderInt32(int value) => BitConverter.GetBytes(value);
}
