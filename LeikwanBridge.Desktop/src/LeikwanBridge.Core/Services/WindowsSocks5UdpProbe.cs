using System.Net;
using System.Net.Sockets;
using System.Security.Cryptography;

namespace LeikwanBridge.Core.Services;

/// <summary>
/// Small, dependency-free SOCKS5 UDP associate probe.  It is used by the
/// Windows UI's diagnostics and by the opt-in live SS2022 test; it never logs
/// the node or the payload.
/// </summary>
public static class WindowsSocks5UdpProbe
{
    private static readonly TimeSpan DefaultTimeout = TimeSpan.FromSeconds(10);

    public static async Task<WindowsSocks5UdpProbeResult> QueryDnsAsync(
        string proxyHost,
        int proxyPort,
        string queryHost = "1.1.1.1",
        int queryPort = 53,
        CancellationToken ct = default)
    {
        if (string.IsNullOrWhiteSpace(proxyHost) || proxyPort is < 1 or > 65535)
            return WindowsSocks5UdpProbeResult.Failed("proxy_endpoint_invalid");
        if (string.IsNullOrWhiteSpace(queryHost) || queryPort is < 1 or > 65535)
            return WindowsSocks5UdpProbeResult.Failed("probe_target_invalid");

        try
        {
            using var timeout = CancellationTokenSource.CreateLinkedTokenSource(ct);
            timeout.CancelAfter(DefaultTimeout);
            var operationToken = timeout.Token;

            using var tcp = new TcpClient();
            await tcp.ConnectAsync(proxyHost, proxyPort, operationToken);
            await using var stream = tcp.GetStream();
            await WriteAsync(stream, [5, 1, 0], operationToken);
            var greeting = await ReadExactAsync(stream, 2, operationToken);
            if (greeting[0] != 5 || greeting[1] != 0)
                return WindowsSocks5UdpProbeResult.Failed("socks5_auth_failed");

            await WriteAsync(stream, [5, 3, 0, 1, 0, 0, 0, 0, 0, 0], operationToken);
            var reply = await ReadSocksAddressAsync(stream, operationToken);
            if (reply.Reply != 0)
                return WindowsSocks5UdpProbeResult.Failed($"socks5_udp_associate_{reply.Reply}");
            if (reply.Port == 0)
                return WindowsSocks5UdpProbeResult.Failed("socks5_udp_relay_invalid");

            var relayAddress = (reply.Address.Equals(IPAddress.Any) || reply.Address.Equals(IPAddress.IPv6Any))
                ? (tcp.Client.LocalEndPoint as IPEndPoint)?.Address ?? IPAddress.Loopback
                : reply.Address;
            using var udp = new UdpClient(relayAddress.AddressFamily);
            var payload = BuildDnsQuery();
            var packet = BuildUdpRequest(queryHost, queryPort, payload);
            udp.Connect(new IPEndPoint(relayAddress, reply.Port));
            await udp.SendAsync(packet, operationToken);

            var received = await udp.ReceiveAsync(operationToken);
            if (received.Buffer.Length < 10)
                return WindowsSocks5UdpProbeResult.Failed("socks5_udp_empty_reply");
            var dnsPayload = ParseUdpReply(received.Buffer);
            if (dnsPayload.Length < 4 || dnsPayload[0] != payload[0] || dnsPayload[1] != payload[1])
                return WindowsSocks5UdpProbeResult.Failed("socks5_udp_dns_id_mismatch");
            return new WindowsSocks5UdpProbeResult(true, "", dnsPayload.Length);
        }
        catch (OperationCanceledException) when (ct.IsCancellationRequested)
        {
            return WindowsSocks5UdpProbeResult.Failed("probe_cancelled");
        }
        catch (OperationCanceledException)
        {
            return WindowsSocks5UdpProbeResult.Failed("probe_timeout");
        }
        catch (SocketException exception)
        {
            return WindowsSocks5UdpProbeResult.Failed($"socket_{(int)exception.SocketErrorCode}");
        }
        catch (IOException)
        {
            return WindowsSocks5UdpProbeResult.Failed("probe_io_failed");
        }
        catch (InvalidOperationException exception)
        {
            return WindowsSocks5UdpProbeResult.Failed(exception.Message);
        }
    }

    private static byte[] BuildDnsQuery()
    {
        var id = RandomNumberGenerator.GetBytes(2);
        var packet = new List<byte>(32);
        packet.AddRange(id);
        packet.AddRange([1, 0, 0, 1, 0, 0, 0, 0, 0, 0]);
        foreach (var label in "example.com".Split('.'))
        {
            packet.Add((byte)label.Length);
            packet.AddRange(System.Text.Encoding.ASCII.GetBytes(label));
        }
        packet.AddRange([0, 0, 1, 0, 1]);
        return packet.ToArray();
    }

    private static byte[] BuildUdpRequest(string host, int port, byte[] payload)
    {
        var packet = new List<byte> { 0, 0, 0 };
        if (IPAddress.TryParse(host, out var address))
        {
            packet.Add(address.AddressFamily == AddressFamily.InterNetwork ? (byte)1 : (byte)4);
            packet.AddRange(address.GetAddressBytes());
        }
        else
        {
            var hostBytes = System.Text.Encoding.UTF8.GetBytes(host);
            if (hostBytes.Length is < 1 or > 255)
                throw new InvalidOperationException("probe_target_invalid");
            packet.Add(3);
            packet.Add((byte)hostBytes.Length);
            packet.AddRange(hostBytes);
        }
        packet.Add((byte)(port >> 8));
        packet.Add((byte)(port & 0xff));
        packet.AddRange(payload);
        return packet.ToArray();
    }

    private static byte[] ParseUdpReply(byte[] packet)
    {
        if (packet.Length < 4)
            throw new InvalidOperationException("socks5_udp_reply_invalid");
        if (packet[0] != 0 || packet[1] != 0 || packet[2] != 0)
            throw new InvalidOperationException("socks5_udp_reply_invalid");
        var offset = 3;
        var atyp = packet[offset++];
        offset += atyp switch
        {
            1 => 4,
            4 => 16,
            3 when offset < packet.Length => 1 + packet[offset],
            _ => throw new InvalidOperationException("socks5_udp_reply_invalid"),
        };
        offset += 2;
        if (offset < 0 || offset > packet.Length)
            throw new InvalidOperationException("socks5_udp_reply_invalid");
        return packet[offset..];
    }

    private static async Task<(byte Reply, IPAddress Address, int Port)> ReadSocksAddressAsync(
        NetworkStream stream,
        CancellationToken ct)
    {
        var header = await ReadExactAsync(stream, 4, ct);
        if (header[0] != 5 || header[2] != 0)
            throw new InvalidOperationException("socks5_reply_invalid");
        var address = await ReadAddressAsync(stream, header[3], ct);
        var portBytes = await ReadExactAsync(stream, 2, ct);
        return (header[1], address, (portBytes[0] << 8) | portBytes[1]);
    }

    private static async Task<IPAddress> ReadAddressAsync(NetworkStream stream, byte atyp, CancellationToken ct)
    {
        return atyp switch
        {
            1 => new IPAddress(await ReadExactAsync(stream, 4, ct)),
            4 => new IPAddress(await ReadExactAsync(stream, 16, ct)),
            3 => await ReadDomainAddressAsync(stream, ct),
            _ => throw new InvalidOperationException("socks5_address_invalid"),
        };
    }

    private static async Task<IPAddress> ReadDomainAddressAsync(NetworkStream stream, CancellationToken ct)
    {
        var length = (await ReadExactAsync(stream, 1, ct))[0];
        if (length == 0)
            throw new InvalidOperationException("socks5_address_invalid");
        var host = System.Text.Encoding.UTF8.GetString(await ReadExactAsync(stream, length, ct));
        var addresses = await Dns.GetHostAddressesAsync(host, ct);
        return addresses.FirstOrDefault() ?? IPAddress.Loopback;
    }

    private static async Task WriteAsync(NetworkStream stream, byte[] bytes, CancellationToken ct)
        => await stream.WriteAsync(bytes, ct);

    private static async Task<byte[]> ReadExactAsync(NetworkStream stream, int length, CancellationToken ct)
    {
        var buffer = new byte[length];
        var offset = 0;
        while (offset < length)
        {
            var read = await stream.ReadAsync(buffer.AsMemory(offset, length - offset), ct);
            if (read == 0)
                throw new IOException("socks5_connection_closed");
            offset += read;
        }
        return buffer;
    }
}

public sealed record WindowsSocks5UdpProbeResult(bool Succeeded, string ErrorCode, int ResponseBytes)
{
    public static WindowsSocks5UdpProbeResult Failed(string errorCode)
        => new(false, errorCode, 0);
}
