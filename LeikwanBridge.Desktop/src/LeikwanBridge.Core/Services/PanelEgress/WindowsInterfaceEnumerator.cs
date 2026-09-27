using System.Net;
using System.Net.Sockets;
using System.Runtime.InteropServices;

namespace LeikwanBridge.Core.Services.PanelEgress;

/// <summary>
/// Enumerates adapters through iphlpapi GetAdaptersAddresses so gateway,
/// metric and tunnel-ness (IF_TYPE_TUNNEL) are available on Windows. Layout
/// offsets follow IP_ADAPTER_ADDRESSES; any failure degrades to an empty
/// list, which leaves raw socket binding and host routes disabled without
/// breaking connectivity.
/// </summary>
internal sealed class WindowsInterfaceEnumerator : ISystemInterfaceEnumerator
{
    private const uint AfUnspec = 0;
    private const uint GaaFlagIncludeGateways = 0x0080;
    private const uint GaaFlagSkipAnycast = 0x0002;
    private const uint GaaFlagSkipMulticast = 0x0004;
    private const uint GaaFlagSkipDnsServer = 0x0008;
    private const uint Flags =
        GaaFlagIncludeGateways | GaaFlagSkipAnycast | GaaFlagSkipMulticast | GaaFlagSkipDnsServer;
    private const int IfOperStatusUp = 1;
    private const uint ErrorBufferOverflow = 111;

    private static class Offsets
    {
        public static readonly int Length = 0;
        public static readonly int IfIndex = 4;
        public static readonly int Next = 8;
        public static readonly int AdapterName = Next + IntPtr.Size;
        public static readonly int FirstUnicast = AdapterName + IntPtr.Size;
        public static readonly int FirstAnycast = FirstUnicast + IntPtr.Size;
        public static readonly int FirstMulticast = FirstAnycast + IntPtr.Size;
        public static readonly int FirstDns = FirstMulticast + IntPtr.Size;
        public static readonly int DnsSuffix = FirstDns + IntPtr.Size;
        public static readonly int Description = DnsSuffix + IntPtr.Size;
        public static readonly int FriendlyName = Description + IntPtr.Size;
        public static readonly int PhysicalAddress = FriendlyName + IntPtr.Size;
        public static readonly int PhysicalAddressLength = PhysicalAddress + 8;
        public static readonly int Flags = PhysicalAddressLength + 4;
        public static readonly int Mtu = Flags + 4;
        public static readonly int IfType = Mtu + 4;
        public static readonly int OperStatus = IfType + 4;
        public static readonly int Ipv6IfIndex = OperStatus + 4;
        public static readonly int ZoneIndices = Ipv6IfIndex + 4;
        public static readonly int FirstPrefix = ZoneIndices + 16 * 4;
        public static readonly int TransmitLinkSpeed = FirstPrefix + IntPtr.Size;
        public static readonly int ReceiveLinkSpeed = TransmitLinkSpeed + 8;
        public static readonly int FirstWins = ReceiveLinkSpeed + 8;
        public static readonly int FirstGateway = FirstWins + IntPtr.Size;
        public static readonly int Ipv4Metric = FirstGateway + IntPtr.Size;
        public static readonly int Ipv6Metric = Ipv4Metric + 4;
        public static readonly int SocketAddressStride = IntPtr.Size + 8;
    }

    [DllImport("iphlpapi.dll", CharSet = CharSet.Unicode, SetLastError = true)]
    private static extern uint GetAdaptersAddresses(
        uint family,
        uint flags,
        IntPtr reserved,
        IntPtr adapterAddresses,
        ref uint sizePointer);

    public IReadOnlyList<NetworkAdapterInfo> Enumerate()
    {
        if (!OperatingSystem.IsWindows())
            return [];
        try
        {
            return EnumerateCore();
        }
        catch
        {
            return [];
        }
    }

    private static IReadOnlyList<NetworkAdapterInfo> EnumerateCore()
    {
        uint size = 0;
        var result = GetAdaptersAddresses(AfUnspec, Flags, IntPtr.Zero, IntPtr.Zero, ref size);
        if (result != ErrorBufferOverflow || size == 0)
            return [];

        var buffer = Marshal.AllocHGlobal((int)size);
        try
        {
            result = GetAdaptersAddresses(AfUnspec, Flags, IntPtr.Zero, buffer, ref size);
            if (result != 0)
                return [];

            var adapters = new List<NetworkAdapterInfo>();
            var current = buffer;
            while (current != IntPtr.Zero)
            {
                var adapter = ReadAdapter(current);
                if (adapter is not null)
                    adapters.Add(adapter);
                current = Marshal.ReadIntPtr(current, Offsets.Next);
            }
            return adapters;
        }
        finally
        {
            Marshal.FreeHGlobal(buffer);
        }
    }

    private static NetworkAdapterInfo? ReadAdapter(IntPtr adapter)
    {
        var length = Marshal.ReadInt32(adapter, Offsets.Length);
        if (length < Offsets.Ipv6Metric + 4)
            return null;

        var friendlyName = ReadString(Marshal.ReadIntPtr(adapter, Offsets.FriendlyName));
        var description = ReadString(Marshal.ReadIntPtr(adapter, Offsets.Description));
        var ifType = Marshal.ReadInt32(adapter, Offsets.IfType);
        var physicalAddressLength = Marshal.ReadInt32(adapter, Offsets.PhysicalAddressLength);
        var operStatus = Marshal.ReadInt32(adapter, Offsets.OperStatus);
        var v4Index = Marshal.ReadInt32(adapter, Offsets.IfIndex);
        var v6Index = Marshal.ReadInt32(adapter, Offsets.Ipv6IfIndex);
        var v4Metric = Marshal.ReadInt32(adapter, Offsets.Ipv4Metric);
        var v6Metric = Marshal.ReadInt32(adapter, Offsets.Ipv6Metric);
        var gateways = ReadSocketAddressList(Marshal.ReadIntPtr(adapter, Offsets.FirstGateway));
        var addresses = ReadSocketAddressList(Marshal.ReadIntPtr(adapter, Offsets.FirstUnicast));

        return new NetworkAdapterInfo(
            friendlyName.Length == 0 ? description : friendlyName,
            description,
            v4Index,
            v6Index,
            operStatus == IfOperStatusUp,
            ClassifyKind(ifType, physicalAddressLength),
            v4Metric,
            v6Metric,
            gateways,
            addresses);
    }

    private static NetworkAdapterKind ClassifyKind(int ifType, int physicalAddressLength)
        => WindowsAdapterKindClassifier.Classify(ifType, physicalAddressLength);

    private static string ReadString(IntPtr pointer)
        => pointer == IntPtr.Zero ? string.Empty : Marshal.PtrToStringUni(pointer) ?? string.Empty;

    private static List<IPAddress> ReadSocketAddressList(IntPtr first)
    {
        var addresses = new List<IPAddress>();
        var current = first;
        while (current != IntPtr.Zero)
        {
            var sockaddr = Marshal.ReadIntPtr(current, 0);
            if (sockaddr != IntPtr.Zero)
            {
                var family = Marshal.ReadInt16(sockaddr, 0);
                if (family == (int)AddressFamily.InterNetwork)
                {
                    var bytes = new byte[4];
                    Marshal.Copy(sockaddr + 4, bytes, 0, bytes.Length);
                    addresses.Add(new IPAddress(bytes));
                }
                else if (family == (int)AddressFamily.InterNetworkV6)
                {
                    var bytes = new byte[16];
                    Marshal.Copy(sockaddr + 8, bytes, 0, bytes.Length);
                    addresses.Add(new IPAddress(bytes));
                }
            }
            current = Marshal.ReadIntPtr(current, Offsets.SocketAddressStride);
        }
        return addresses;
    }
}

/// <summary>
/// Maps IP_ADAPTER_ADDRESSES driver hints to <see cref="NetworkAdapterKind"/>.
/// Wintun, WireGuardNT and most TUN drivers register as
/// IF_TYPE_PROP_VIRTUAL; an "Ethernet" adapter without a hardware address is
/// a software tunnel as well. Name/description rules
/// (<see cref="TunnelInterfaceHeuristics"/>) still apply on top of this for
/// virtual adapters that carry a MAC, such as TAP-Windows or Hyper-V vEthernet.
/// </summary>
public static class WindowsAdapterKindClassifier
{
    public const int IfTypeEthernetCsmacd = 6;
    public const int IfTypePpp = 23;
    public const int IfTypeSoftwareLoopback = 24;
    public const int IfTypePropVirtual = 53;
    public const int IfTypeIeee80211 = 71;
    public const int IfTypeTunnel = 131;
    public const int IfTypeWwanPp = 243;
    public const int IfTypeWwanPp2 = 244;

    public static NetworkAdapterKind Classify(int ifType, int physicalAddressLength)
    {
        return ifType switch
        {
            IfTypeTunnel or IfTypePropVirtual => NetworkAdapterKind.Tunnel,
            IfTypeSoftwareLoopback => NetworkAdapterKind.Loopback,
            IfTypePpp => NetworkAdapterKind.Virtual,
            IfTypeEthernetCsmacd when physicalAddressLength <= 0 => NetworkAdapterKind.Tunnel,
            IfTypeEthernetCsmacd or IfTypeIeee80211 => NetworkAdapterKind.Physical,
            // Mobile broadband modems are real uplinks.
            IfTypeWwanPp or IfTypeWwanPp2 => NetworkAdapterKind.Physical,
            _ => NetworkAdapterKind.Unknown,
        };
    }
}
