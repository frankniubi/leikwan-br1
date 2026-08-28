using System.Buffers.Binary;
using System.Net.Sockets;
using System.Text;
using System.Text.Json;
using LeikwanBridge.Core.Models;
using LeikwanBridge.Core.Services.Ultra;
using Xunit;

namespace LeikwanBridge.Core.Tests.Runtime;

public sealed class UnixUltraLeaseClientTests
{
    [Fact]
    public async Task SendAsync_WritesBoundedFrameAndRequiresMatchingAcknowledgement()
    {
        var socketPath = TemporarySocketPath();
        using var listener = new Socket(AddressFamily.Unix, SocketType.Stream, ProtocolType.Unspecified);
        listener.Bind(new UnixDomainSocketEndPoint(socketPath));
        listener.Listen(1);
        var lease = CreateLease();
        byte[] captured = [];
        var server = Task.Run(async () =>
        {
            using var connection = await listener.AcceptAsync();
            var header = new byte[4];
            await ReadExactlyAsync(connection, header);
            var length = checked((int)BinaryPrimitives.ReadUInt32BigEndian(header));
            captured = new byte[length];
            await ReadExactlyAsync(connection, captured);
            var acknowledgement = JsonSerializer.SerializeToUtf8Bytes(new
            {
                ok = true,
                forward_id = lease.ForwardId,
                policy_generation = lease.PolicyGeneration,
            });
            BinaryPrimitives.WriteUInt32BigEndian(header, (uint)acknowledgement.Length);
            await connection.SendAsync(header);
            await connection.SendAsync(acknowledgement);
        });

        var sent = await new UnixUltraLeaseClient().SendAsync(socketPath, lease.ForwardId, lease);
        await server;

        Assert.True(sent);
        Assert.InRange(captured.Length, 1, UnixUltraLeaseClient.MaxFrameBytes);
        using var document = JsonDocument.Parse(captured);
        Assert.Equal(lease.ForwardId, document.RootElement.GetProperty("forward_id").GetInt32());
        Assert.Equal("203.0.113.20", document.RootElement.GetProperty("endpoint").GetProperty("ip").GetString());
        TryDelete(socketPath);
    }

    [Fact]
    public async Task SendAsync_RejectsMismatchedAckOversizeAndTimeout()
    {
        var socketPath = TemporarySocketPath();
        using var listener = new Socket(AddressFamily.Unix, SocketType.Stream, ProtocolType.Unspecified);
        listener.Bind(new UnixDomainSocketEndPoint(socketPath));
        listener.Listen(1);
        var lease = CreateLease();
        var server = Task.Run(async () =>
        {
            using var connection = await listener.AcceptAsync();
            var header = new byte[4];
            await ReadExactlyAsync(connection, header);
            var body = new byte[BinaryPrimitives.ReadUInt32BigEndian(header)];
            await ReadExactlyAsync(connection, body);
            var acknowledgement = JsonSerializer.SerializeToUtf8Bytes(new
            {
                ok = true,
                forward_id = lease.ForwardId + 1,
                policy_generation = lease.PolicyGeneration,
            });
            BinaryPrimitives.WriteUInt32BigEndian(header, (uint)acknowledgement.Length);
            await connection.SendAsync(header);
            await connection.SendAsync(acknowledgement);
        });

        Assert.False(await new UnixUltraLeaseClient().SendAsync(socketPath, lease.ForwardId, lease));
        await server;
        TryDelete(socketPath);

        lease.SessionTicket = new string('x', UnixUltraLeaseClient.MaxFrameBytes);
        Assert.False(await new UnixUltraLeaseClient().SendAsync("unused.sock", lease.ForwardId, lease));

        var timeoutPath = TemporarySocketPath();
        using var timeoutListener = new Socket(AddressFamily.Unix, SocketType.Stream, ProtocolType.Unspecified);
        timeoutListener.Bind(new UnixDomainSocketEndPoint(timeoutPath));
        timeoutListener.Listen(1);
        var timeoutServer = Task.Run(async () =>
        {
            using var connection = await timeoutListener.AcceptAsync();
            await Task.Delay(250);
        });
        lease.SessionTicket = "ticket";
        Assert.False(await new UnixUltraLeaseClient(TimeSpan.FromMilliseconds(50)).SendAsync(timeoutPath, lease.ForwardId, lease));
        await timeoutServer;
        TryDelete(timeoutPath);
    }

    private static UltraLease CreateLease()
    {
        return new UltraLease
        {
            LeaseId = "ul2_test",
            SessionTicket = "ticket",
            ForwardId = 42,
            PolicyGeneration = new string('a', 64),
            TargetDigest = new string('c', 64),
            ExpiresAt = DateTimeOffset.UtcNow.AddMinutes(3),
            Transport = "tcp",
            RateLimitMbps = 20,
            QuotaRemainingBytes = 1024,
            SelectionRef = "selection-reference-abcdefghijklmnop",
            NonceHash = new string('b', 64),
            ProviderIp = "203.0.113.20",
            ProviderPort = 5438,
            User = Encoding.UTF8.GetBytes("line-user"),
            Password = Encoding.UTF8.GetBytes("line-password"),
        };
    }

    private static string TemporarySocketPath()
    {
        return Path.Combine(Path.GetTempPath(), $"lw-{Guid.NewGuid():N}.sock");
    }

    private static async Task ReadExactlyAsync(Socket socket, byte[] buffer)
    {
        var offset = 0;
        while (offset < buffer.Length)
        {
            var read = await socket.ReceiveAsync(buffer.AsMemory(offset), SocketFlags.None);
            if (read <= 0)
                throw new EndOfStreamException();
            offset += read;
        }
    }

    private static void TryDelete(string path)
    {
        try
        {
            File.Delete(path);
        }
        catch (IOException)
        {
        }
    }
}
