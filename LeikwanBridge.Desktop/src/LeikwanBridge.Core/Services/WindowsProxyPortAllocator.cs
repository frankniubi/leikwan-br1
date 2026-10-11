using System.Net;
using System.Net.Sockets;

namespace LeikwanBridge.Core.Services;

public static class WindowsProxyPortAllocator
{
    private const int FallbackPortCount = 64;

    public static int Resolve(int preferredPort, bool allowFallback)
    {
        ValidatePort(preferredPort);
        if (IsAvailable(preferredPort))
            return preferredPort;
        if (!allowFallback)
            throw new InvalidOperationException("proxy_port_in_use");

        var lastPort = Math.Min(65535, preferredPort + FallbackPortCount);
        for (var candidate = preferredPort + 1; candidate <= lastPort; candidate++)
        {
            if (IsAvailable(candidate))
                return candidate;
        }

        throw new InvalidOperationException("proxy_port_unavailable");
    }

    public static bool IsAvailable(int port)
    {
        ValidatePort(port);
        TcpListener? tcp = null;
        UdpClient? udp = null;
        try
        {
            // The mixed inbound opens both sockets. Probe both families so a
            // UDP-only owner cannot make the later sing-box bind fail.
            tcp = new TcpListener(IPAddress.Loopback, port);
            tcp.Start();
            udp = new UdpClient(new IPEndPoint(IPAddress.Loopback, port));
            return true;
        }
        catch (SocketException)
        {
            return false;
        }
        catch (UnauthorizedAccessException)
        {
            return false;
        }
        finally
        {
            try { udp?.Dispose(); } catch (SocketException) { }
            try { tcp?.Stop(); } catch (SocketException) { }
        }
    }

    private static void ValidatePort(int port)
    {
        if (port is < 1 or > 65535)
            throw new ArgumentOutOfRangeException(nameof(port));
    }
}
