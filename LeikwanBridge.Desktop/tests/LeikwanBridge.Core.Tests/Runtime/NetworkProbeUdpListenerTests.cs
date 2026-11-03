using System.Net;
using System.Net.Sockets;
using LeikwanBridge.Core.Helpers;
using Xunit;

namespace LeikwanBridge.Core.Tests.Runtime;

/// <summary>
/// Regression guards for <see cref="NetworkProbe.IsPortListening"/>, which the
/// agent uses on its start path (wait_listener) and on every health cycle.
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

    [Fact]
    public void IsTcpPortListening_SeesTcpListener()
    {
        var listener = new Socket(AddressFamily.InterNetwork, SocketType.Stream, ProtocolType.Tcp);
        listener.Bind(new IPEndPoint(IPAddress.Loopback, 0));
        listener.Listen(8);
        var port = ((IPEndPoint)listener.LocalEndPoint!).Port;
        try
        {
            Assert.True(NetworkProbe.IsTcpPortListening(port));
            Assert.True(NetworkProbe.IsPortListening(port));
        }
        finally
        {
            listener.Dispose();
        }
    }

    [Theory]
    [InlineData(0)]
    [InlineData(-1)]
    [InlineData(70000)]
    public void IsTcpPortListening_RejectsOutOfRangePorts(int port)
        => Assert.False(NetworkProbe.IsTcpPortListening(port));

    /// <summary>
    /// The probe must observe the listener, never become a client of it.
    /// </summary>
    /// <remarks>
    /// The Ultra runtime treats an accepted TCP connection as a real client: it
    /// dials the forward's target through the relay and, when the target does not
    /// answer, records <c>tcp stream ended ... reason=io_error</c> and increments
    /// <c>ha_failures</c>. A probe that connects therefore measured itself. On the
    /// Windows test client a forward whose target is UDP-only accumulated one
    /// false HA failure per 20s health cycle. Nothing is ever accepted from this
    /// socket, so any connection attempt stays pending and is visible to
    /// <see cref="Socket.Poll(int, SelectMode)"/>.
    /// </remarks>
    [Fact]
    public void IsPortListening_DoesNotConnectToTheListener()
    {
        var listener = new Socket(AddressFamily.InterNetwork, SocketType.Stream, ProtocolType.Tcp);
        listener.Bind(new IPEndPoint(IPAddress.Loopback, 0));
        listener.Listen(8);
        var port = ((IPEndPoint)listener.LocalEndPoint!).Port;
        try
        {
            for (var i = 0; i < 32; i++)
            {
                var table = System.Net.NetworkInformation.IPGlobalProperties.GetIPGlobalProperties()
                    .GetActiveTcpListeners();
                Assert.True(
                    NetworkProbe.IsPortListening(port),
                    $"passive probe saw no listener on port {port} table_len={table.Length} " +
                    $"table_has_port={table.Any(e => e.Port == port)} iteration={i}");
            }

            // 200ms is far longer than a loopback connect takes when it succeeds.
            Assert.False(
                listener.Poll(200_000, SelectMode.SelectRead),
                "IsPortListening must not open a connection to the port it probes");
        }
        finally
        {
            listener.Dispose();
        }
    }
}
