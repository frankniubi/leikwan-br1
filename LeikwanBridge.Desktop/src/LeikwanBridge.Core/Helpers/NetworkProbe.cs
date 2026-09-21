using System.Net.NetworkInformation;
using System.Net.Sockets;

namespace LeikwanBridge.Core.Helpers;

public static class NetworkProbe
{
    public static async Task<(bool ok, double latencyMs, string? error)> TcpProbeAsync(
        string host, int port, int timeoutMs = 3000, CancellationToken ct = default)
    {
        if (string.IsNullOrWhiteSpace(host) || port <= 0)
            return (false, 0, "invalid host/port");

        try
        {
            using var client = new TcpClient();
            var sw = System.Diagnostics.Stopwatch.StartNew();
            using var cts = CancellationTokenSource.CreateLinkedTokenSource(ct);
            cts.CancelAfter(timeoutMs);
            await client.ConnectAsync(host, port, cts.Token);
            sw.Stop();
            return (true, sw.Elapsed.TotalMilliseconds, null);
        }
        catch (OperationCanceledException)
        {
            return (false, 0, "timeout");
        }
        catch (Exception ex)
        {
            return (false, 0, ex.Message);
        }
    }

    /// <summary>
    /// True when <paramref name="port"/> has a local listener on a protocol the
    /// Ultra runtime serves for it — TCP or UDP.
    /// </summary>
    /// <remarks>
    /// This used to be a bare TCP connect, which cannot see a UDP-only forward:
    /// tools/ultra-client opens the TCP listener only when the transport includes
    /// "tcp", so a <c>transport=udp</c> forward never binds one. The start path
    /// then timed out waiting for a listener that was already healthy, killed the
    /// runtime with "ultra_listener_not_ready" and restarted it every ~40s —
    /// which is what made a macOS UDP forward lose ~96% of its datagrams while
    /// its lease was being delivered successfully the whole time. Checking both
    /// is safe because a port is only ever probed by the forward that owns it.
    /// </remarks>
    public static bool IsPortListening(int port)
        => TcpListenerPresent(port) || IsUdpPortListening(port);

    /// <summary>
    /// True when <paramref name="port"/> has a local UDP socket bound.
    /// </summary>
    /// <remarks>
    /// A UDP connect() proves nothing (it is connectionless and always succeeds),
    /// so read the kernel's bound-endpoint table instead.
    /// </remarks>
    public static bool IsUdpPortListening(int port)
    {
        if (port is < 1 or > 65535)
            return false;
        try
        {
            foreach (var endpoint in IPGlobalProperties.GetIPGlobalProperties().GetActiveUdpListeners())
            {
                if (endpoint.Port == port)
                    return true;
            }
        }
        catch
        {
            // An unreadable endpoint table must not claim a listener exists.
        }
        return false;
    }

    private static bool TcpListenerPresent(int port)
    {
        try
        {
            using var client = new TcpClient();
            client.Connect("127.0.0.1", port);
            return true;
        }
        catch
        {
            return false;
        }
    }
}
