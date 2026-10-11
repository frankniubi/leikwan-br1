using System.Net;
using System.Net.Sockets;
using LeikwanBridge.Core.Services;
using LeikwanBridge.Core.Services.PanelEgress;
using Xunit;

namespace LeikwanBridge.Core.Tests.PanelEgress;

/// <summary>
/// Adapters that TUN/VPN tools and hypervisors create often report
/// IF_TYPE_ETHERNET_CSMACD, so the name/description rules are what keeps them
/// out of Automatic selection and out of the sign-in/settings lists.
/// </summary>
public sealed class TunnelAdapterFilterTests
{
    private static readonly IPAddress Gateway = IPAddress.Parse("192.168.1.1");

    public static TheoryData<string, string> VirtualAdapters => new()
    {
        // OpenVPN / TAP-Windows / DCO.
        { "以太网 2", "TAP-Windows Adapter V9" },
        { "Ethernet 3", "TAP-Windows Adapter V9 for OpenVPN Connect" },
        { "Local Area Connection", "TAP-Win32 Adapter OAS" },
        { "OpenVPN TAP-Windows6", "TAP-Windows Adapter V9" },
        { "OpenVPN Data Channel Offload", "OpenVPN Data Channel Offload" },
        { "Ethernet 4", "ExpressVPN TAP Adapter" },
        // Wintun and the proxy clients built on it.
        { "Meta", "Meta Tunnel" },
        { "Clash", "Clash Tunnel" },
        { "Mihomo", "Wintun Userspace Tunnel" },
        { "sing-box", "sing-box Tunnel" },
        { "tun0", "Wintun Userspace Tunnel" },
        { "v2rayN", "v2rayN Tunnel" },
        { "xray", "Wintun Userspace Tunnel" },
        { "NekoBox", "Wintun Userspace Tunnel" },
        { "nekoray-tun", "Wintun Userspace Tunnel" },
        { "Netch", "Wintun Userspace Tunnel" },
        { "LeiKwan Bridge", "Wintun Userspace Tunnel" },
        { "Ethernet 5", "Hiddify Tunnel" },
        // WireGuard / overlay / consumer VPNs.
        { "wg0", "WireGuard Tunnel" },
        { "Home Office", "WireGuard Tunnel" },
        { "Tailscale", "Tailscale Tunnel" },
        { "ZeroTier One [8056c2e21c000001]", "ZeroTier Virtual Port" },
        { "Hamachi", "LogMeIn Hamachi Virtual Ethernet Adapter" },
        { "Radmin VPN", "Famatech Radmin VPN Ethernet Adapter" },
        { "VPN - VPN Client", "VPN Client Adapter - VPN" },
        { "Ethernet 6", "SoftEther VPN Client Adapter" },
        { "CloudflareWARP", "Cloudflare WARP Interface Tunnel" },
        { "NordLynx", "NordLynx Tunnel" },
        { "Ethernet 7", "Cisco AnyConnect Secure Mobility Client Virtual Miniport Adapter for Windows x64" },
        { "Ethernet 8", "PANGP Virtual Ethernet Adapter Secure" },
        { "Ethernet 9", "Fortinet Virtual Ethernet Adapter (NDIS 6.30)" },
        // Capture / tunnelling pseudo-interfaces.
        { "Npcap Loopback Adapter", "Npcap Loopback Adapter" },
        { "Ethernet 10", "Microsoft KM-TEST Loopback Adapter" },
        { "Teredo Tunneling Pseudo-Interface", "Microsoft Teredo Tunneling Adapter" },
        { "Local Area Connection* 12", "WAN Miniport (L2TP)" },
        // Hypervisors and containers.
        { "vEthernet (Default Switch)", "Hyper-V Virtual Ethernet Adapter" },
        { "vEthernet (WSL)", "Hyper-V Virtual Ethernet Adapter #2" },
        { "vEthernet (WSL (Hyper-V firewall))", "Hyper-V Virtual Ethernet Adapter #3" },
        { "Ethernet 11", "Hyper-V Virtual Ethernet Adapter" },
        { "VMware Network Adapter VMnet8", "VMware Virtual Ethernet Adapter for VMnet8" },
        { "VirtualBox Host-Only Network", "VirtualBox Host-Only Ethernet Adapter" },
        { "vEthernet (DockerNAT)", "Hyper-V Virtual Ethernet Adapter" },
        { "Local Area Connection* 1", "Microsoft Wi-Fi Direct Virtual Adapter" },
        // macOS kernel names (the managed description equals the name there).
        { "utun4", "utun4" },
        { "ipsec0", "ipsec0" },
        { "ppp0", "ppp0" },
        { "bridge0", "bridge0" },
        { "bridge100", "bridge100" },
        { "vmnet1", "vmnet1" },
        { "vmenet0", "vmenet0" },
        { "feth2031", "feth2031" },
        { "gif0", "gif0" },
        { "stf0", "stf0" },
        { "awdl0", "awdl0" },
        { "llw0", "llw0" },
        { "anpi0", "anpi0" },
        { "vnic0", "vnic0" },
    };

    public static TheoryData<string, string> PhysicalAdapters => new()
    {
        { "Ethernet", "Intel(R) Ethernet Connection (7) I219-V" },
        { "以太网", "Realtek PCIe GbE Family Controller" },
        { "Wi-Fi", "Intel(R) Wi-Fi 6E AX211 160MHz" },
        { "WLAN", "MediaTek Wi-Fi 6 MT7921 Wireless LAN Card" },
        { "WLAN 2", "Killer(R) Wi-Fi 6 AX1650w 160MHz Wireless Network Adapter (200D2W)" },
        { "Ethernet 2", "Realtek USB GbE Family Controller" },
        { "Ethernet 3", "ASIX AX88179 USB 3.0 to Gigabit Ethernet Adapter" },
        // USB tethering (Android / iPhone) and mobile broadband.
        { "Ethernet 4", "Remote NDIS based Internet Sharing Device" },
        { "Ethernet 5", "Apple Mobile Device Ethernet" },
        { "Cellular", "Qualcomm Snapdragon X55 5G" },
        // Uplink NIC inside a Hyper-V or VirtIO guest.
        { "Ethernet", "Microsoft Hyper-V Network Adapter" },
        { "Ethernet", "Red Hat VirtIO Ethernet Adapter" },
        { "Ethernet0", "vmxnet3 Ethernet Adapter" },
        // macOS.
        { "en0", "en0" },
        { "en7", "en7" },
    };

    [Theory]
    [MemberData(nameof(VirtualAdapters))]
    public void TunnelHeuristics_ExcludeVirtualAdaptersEvenWhenTheyReportEthernet(string name, string description)
    {
        Assert.True(TunnelInterfaceHeuristics.IsTunnelLikeName(name, description));
        var adapter = AdapterBuilder.Adapter(name, NetworkAdapterKind.Physical, 7, 7,
            gateways: [Gateway], addresses: [IPAddress.Parse("10.8.0.2")], description: description);
        Assert.False(adapter.IsEgressEligible);
    }

    [Theory]
    [MemberData(nameof(PhysicalAdapters))]
    public void TunnelHeuristics_KeepRealWiFiEthernetAndTetheringEligible(string name, string description)
    {
        Assert.False(TunnelInterfaceHeuristics.IsTunnelLikeName(name, description));
        var adapter = AdapterBuilder.Adapter(name, NetworkAdapterKind.Physical, 7, 7,
            gateways: [Gateway], addresses: [IPAddress.Parse("192.168.1.20")], description: description);
        Assert.True(adapter.IsEgressEligible);
    }

    [Theory]
    [InlineData("TAP-WINDOWS ADAPTER V9")]
    [InlineData("hyper-v virtual ethernet adapter")]
    [InlineData("WireGuard TUNNEL")]
    public void TunnelHeuristics_AreCaseInsensitive(string description)
        => Assert.True(TunnelInterfaceHeuristics.IsTunnelLikeName("Ethernet 2", description));

    [Theory]
    [InlineData(WindowsAdapterKindClassifier.IfTypeTunnel, 0, NetworkAdapterKind.Tunnel)]
    // Wintun / WireGuardNT register as IF_TYPE_PROP_VIRTUAL.
    [InlineData(WindowsAdapterKindClassifier.IfTypePropVirtual, 0, NetworkAdapterKind.Tunnel)]
    [InlineData(WindowsAdapterKindClassifier.IfTypeSoftwareLoopback, 0, NetworkAdapterKind.Loopback)]
    [InlineData(WindowsAdapterKindClassifier.IfTypePpp, 0, NetworkAdapterKind.Virtual)]
    // An "Ethernet" adapter without a hardware address is a software tunnel.
    [InlineData(WindowsAdapterKindClassifier.IfTypeEthernetCsmacd, 0, NetworkAdapterKind.Tunnel)]
    [InlineData(WindowsAdapterKindClassifier.IfTypeEthernetCsmacd, 6, NetworkAdapterKind.Physical)]
    [InlineData(WindowsAdapterKindClassifier.IfTypeIeee80211, 6, NetworkAdapterKind.Physical)]
    [InlineData(WindowsAdapterKindClassifier.IfTypeWwanPp, 0, NetworkAdapterKind.Physical)]
    [InlineData(WindowsAdapterKindClassifier.IfTypeWwanPp2, 0, NetworkAdapterKind.Physical)]
    [InlineData(1, 6, NetworkAdapterKind.Unknown)]
    public void WindowsClassifier_UsesInterfaceTypeAndHardwareAddress(int ifType, int macLength, NetworkAdapterKind expected)
        => Assert.Equal(expected, WindowsAdapterKindClassifier.Classify(ifType, macLength));

    [Fact]
    public void Automatic_SkipsEthernetTypedTapAndVEthernetEvenWithLowerMetric()
    {
        var adapters = new[]
        {
            AdapterBuilder.Adapter("以太网 2", NetworkAdapterKind.Physical, 30, 30, v4Metric: 1,
                gateways: [IPAddress.Parse("10.8.0.1")], description: "TAP-Windows Adapter V9"),
            AdapterBuilder.Adapter("vEthernet (External)", NetworkAdapterKind.Physical, 31, 31, v4Metric: 2,
                gateways: [Gateway], description: "Hyper-V Virtual Ethernet Adapter"),
            AdapterBuilder.Adapter("Meta", NetworkAdapterKind.Unknown, 32, 32, v4Metric: 0,
                gateways: [IPAddress.Parse("198.18.0.2")], description: "Meta Tunnel"),
            AdapterBuilder.Adapter("Wi-Fi", NetworkAdapterKind.Physical, 12, 12, v4Metric: 50,
                gateways: [Gateway], description: "Intel(R) Wi-Fi 6E AX211 160MHz"),
        };

        var selected = PhysicalInterfaceSelector.SelectPreferred(adapters, AddressFamily.InterNetwork);

        Assert.NotNull(selected);
        Assert.Equal("Wi-Fi", selected.Name);
    }

    [Fact]
    public void ExplicitChoice_OfAnIneligibleAdapterIsRefused()
    {
        var adapters = new[]
        {
            AdapterBuilder.Adapter("vEthernet (Default Switch)", NetworkAdapterKind.Physical, 31, 31,
                gateways: [Gateway], description: "Hyper-V Virtual Ethernet Adapter"),
            AdapterBuilder.Adapter("Ethernet", NetworkAdapterKind.Physical, 12, 12,
                gateways: [Gateway], description: "Intel(R) Ethernet Connection (7) I219-V"),
        };

        Assert.Null(PhysicalInterfaceSelector.SelectPreferred(
            adapters, AddressFamily.InterNetwork, preferredInterfaceName: "vEthernet (Default Switch)"));
        Assert.Null(EgressInterfaceCatalog.Find(
            EgressInterfaceCatalog.Build(adapters), "vEthernet (Default Switch)"));
    }

    [Fact]
    public void Catalog_ListsOnlyEligibleAdaptersWithTheirIpv4()
    {
        var adapters = new[]
        {
            AdapterBuilder.Adapter("Wi-Fi", NetworkAdapterKind.Physical, 12, 12,
                addresses: [IPAddress.Parse("fe80::1"), IPAddress.Parse("192.168.1.20")],
                description: "Intel(R) Wi-Fi 6E AX211 160MHz"),
            AdapterBuilder.Adapter("Ethernet 4", NetworkAdapterKind.Physical, 18, 18,
                addresses: [IPAddress.Parse("192.168.42.129")],
                description: "Remote NDIS based Internet Sharing Device"),
            AdapterBuilder.Adapter("以太网 2", NetworkAdapterKind.Physical, 30, 30,
                addresses: [IPAddress.Parse("10.8.0.2")], description: "TAP-Windows Adapter V9"),
            AdapterBuilder.Adapter("Meta", NetworkAdapterKind.Tunnel, 32, 32,
                addresses: [IPAddress.Parse("198.18.0.1")], description: "Meta Tunnel"),
            AdapterBuilder.Adapter("vEthernet (WSL)", NetworkAdapterKind.Physical, 40, 40,
                addresses: [IPAddress.Parse("172.20.0.1")], description: "Hyper-V Virtual Ethernet Adapter"),
            AdapterBuilder.Adapter("Ethernet", NetworkAdapterKind.Physical, 5, 5, isUp: false,
                addresses: [IPAddress.Parse("192.168.2.5")], description: "Realtek PCIe GbE Family Controller"),
            AdapterBuilder.Adapter("Loopback Pseudo-Interface 1", NetworkAdapterKind.Loopback, 1, 1,
                addresses: [IPAddress.Loopback]),
        };

        var options = EgressInterfaceCatalog.Build(adapters, "自动（推荐）");

        Assert.Equal(["", "Wi-Fi", "Ethernet 4"], options.Select(option => option.Id));
        Assert.True(options[0].IsAutomatic);
        Assert.Equal("自动（推荐）", options[0].CompactLabel);
        Assert.Equal("Wi-Fi · 192.168.1.20", options[1].CompactLabel);
        Assert.Equal(12, options[1].Index);
        Assert.Equal("Ethernet 4 · 192.168.42.129", options[2].CompactLabel);
    }

    [Fact]
    public void Catalog_DefaultsTheAutomaticLabel()
        => Assert.Equal(EgressInterfaceCatalog.DefaultAutomaticLabel, EgressInterfaceCatalog.Build([])[0].Name);

    [Fact]
    public void Reconcile_KeepsAPresentAdapterCaseInsensitively()
    {
        var options = EgressInterfaceCatalog.Build([
            AdapterBuilder.Adapter("Wi-Fi", NetworkAdapterKind.Physical, 12, 12, addresses: [IPAddress.Parse("192.168.1.20")]),
        ]);

        var choice = EgressInterfaceSelection.Reconcile(options, " wi-fi ");

        Assert.Equal("Wi-Fi", choice.SelectedId);
        Assert.False(choice.FellBackToAutomatic);
    }

    [Fact]
    public void Reconcile_FallsBackToAutomaticAndNamesTheMissingAdapter()
    {
        var options = EgressInterfaceCatalog.Build([
            AdapterBuilder.Adapter("Wi-Fi", NetworkAdapterKind.Physical, 12, 12, addresses: [IPAddress.Parse("192.168.1.20")]),
        ]);

        var choice = EgressInterfaceSelection.Reconcile(options, "USB Ethernet");

        Assert.Equal(EgressInterfaceCatalog.AutomaticId, choice.SelectedId);
        Assert.True(choice.FellBackToAutomatic);
        Assert.Equal("USB Ethernet", choice.MissingInterface);
    }

    [Theory]
    [InlineData(null)]
    [InlineData("")]
    [InlineData("   ")]
    public void Reconcile_EmptyPreferenceIsAutomaticWithoutNotice(string? saved)
    {
        var choice = EgressInterfaceSelection.Reconcile(EgressInterfaceCatalog.Build([]), saved);

        Assert.Equal(EgressInterfaceCatalog.AutomaticId, choice.SelectedId);
        Assert.False(choice.FellBackToAutomatic);
    }
}
