using System.Net.NetworkInformation;

namespace LeikwanBridge.Core.Services;

/// <summary>
/// Reads the MTU the operating system currently has on the live TUN adapter.
/// A TUN MTU is fixed when the interface is created, so this value is the
/// ground truth for "did the pending MTU actually reach the data plane".
/// </summary>
public interface IWindowsTunMtuReader
{
    /// <summary>Live adapter MTU, or 0 when it cannot be read.</summary>
    int ReadTunMtu();
}

/// <summary>
/// Default reader: the IPv4 (or IPv6) MTU of the adapter sing-box creates for
/// the TUN inbound.
/// </summary>
public sealed class WindowsTunMtuReader : IWindowsTunMtuReader
{
    public int ReadTunMtu()
    {
        try
        {
            foreach (var network in NetworkInterface.GetAllNetworkInterfaces())
            {
                if (!string.Equals(
                        network.Name,
                        WindowsProxyConfigBuilder.TunInterfaceName,
                        StringComparison.OrdinalIgnoreCase))
                    continue;
                var properties = network.GetIPProperties();
                var ipv4 = properties.GetIPv4Properties();
                if (ipv4 is not null && ipv4.Mtu > 0)
                    return ipv4.Mtu;
                var ipv6 = properties.GetIPv6Properties();
                if (ipv6 is not null && ipv6.Mtu > 0)
                    return ipv6.Mtu;
            }
        }
        catch (NetworkInformationException) { }
        catch (PlatformNotSupportedException) { }
        catch (UnauthorizedAccessException) { }
        return 0;
    }
}
