using System.Net;
using System.Net.Sockets;

namespace LeikwanBridge.Core.Services;

public static class LeikwanHttpHostPolicy
{
    private const string ProtectedDomainSuffix = ".leikwanhost.com";

    public static bool ShouldResolveWithDoh(Uri uri)
    {
        if (!uri.IsAbsoluteUri)
            return false;
        if (!string.Equals(uri.Scheme, Uri.UriSchemeHttps, StringComparison.OrdinalIgnoreCase))
            return false;

        var host = uri.IdnHost;
        if (string.IsNullOrWhiteSpace(host))
            return false;
        if (IsLocalhost(host))
            return false;
        if (IPAddress.TryParse(host, out var address))
            return false;
        if (host.EndsWith(ProtectedDomainSuffix, StringComparison.OrdinalIgnoreCase))
            return !string.Equals(host, "leikwanhost.com", StringComparison.OrdinalIgnoreCase);

        // Panel endpoints and DoH providers resolve through the anti-poison
        // DoH path as well so third-party TUN tools cannot poison them.
        return PanelDirectEgress.IsWellKnownHost(host);
    }

    public static bool IsBypassAddress(IPAddress address)
    {
        if (IPAddress.IsLoopback(address))
            return true;
        if (address.AddressFamily != AddressFamily.InterNetwork)
            return true;

        var bytes = address.GetAddressBytes();
        return bytes[0] == 10
            || bytes[0] == 127
            || (bytes[0] == 172 && bytes[1] >= 16 && bytes[1] <= 31)
            || (bytes[0] == 192 && bytes[1] == 168)
            || (bytes[0] == 169 && bytes[1] == 254)
            || (bytes[0] == 100 && bytes[1] >= 64 && bytes[1] <= 127)
            || bytes[0] == 0
            || bytes[0] >= 224;
    }

    private static bool IsLocalhost(string host)
    {
        return string.Equals(host, "localhost", StringComparison.OrdinalIgnoreCase)
            || host.EndsWith(".localhost", StringComparison.OrdinalIgnoreCase);
    }
}
