using System.Net;
using System.Net.Sockets;
using LeikwanBridge.Core.Helpers;
using Xunit;

namespace LeikwanBridge.Core.Tests.Runtime;

/// <summary>
/// Regression guard for the macOS UDP forward that lost ~96% of its datagrams.
/// <see cref="NetworkProbe.IsPortListening"/> was a bare TCP connect, but a
/// forward with transport=udp only ever binds a UDP socket, so the agent's
/// wait_listener phase could never succeed: it killed a healthy runtime with
/// "ultra_listener_not_ready" and restarted it every ~40s.
/// </summary>
public sealed class NetworkProbeUdpListenerTests
{
    [Fact]
    public void IsPortListening_SeesUdpOnlyListener()
    {
        using var udp = new UdpClient(new IPEndPoint(IPAddress.Loopback, 0));
        var port = ((IPEndPoint)udp.Client.LocalEndPoint!).Port;

        // A UDP-only bind must read as listening. Before the fix this was false,
        // because a TCP connect to a UDP-only port cannot succeed.
        Assert.True(NetworkProbe.IsPortListening(port));
        Assert.True(NetworkProbe.IsUdpPortListening(port));
    }

    [Fact]
    public void IsPortListening_IsFalseForAnUnboundPort()
    {
        // Bind then release, so the port is known to have had no listener.
        int port;
        using (var udp = new UdpClient(new IPEndPoint(IPAddress.Loopback, 0)))
            port = ((IPEndPoint)udp.Client.LocalEndPoint!).Port;

        Assert.False(NetworkProbe.IsUdpPortListening(port));
    }

    [Theory]
    [InlineData(0)]
    [InlineData(-1)]
    [InlineData(70000)]
    public void IsUdpPortListening_RejectsOutOfRangePorts(int port)
        => Assert.False(NetworkProbe.IsUdpPortListening(port));
}
