using System.Buffers.Binary;
using System.IO.Pipes;
using System.Text;
using System.Text.Json;
using System.ComponentModel;
using System.Net.Sockets;
using LeikwanBridge.Core.Models;
using LeikwanBridge.Core.Services;
using Xunit;

namespace LeikwanBridge.Core.Tests.Runtime;

public sealed class UltraLeaseClientTests
{
    [Fact]
    public void PolicyIsV2AndContainsNoProviderMaterial()
    {
        var policy = new UltraPolicy
        {
            ProtocolValue = UltraPolicy.Protocol,
            ForwardId = 42,
            PolicyGeneration = new string('a', 64),
            SessionTicket = "opaque-ticket",
            TargetDigest = UltraPolicy.ComputeTargetDigest("203.0.113.10:443"),
            ExpiresAt = DateTimeOffset.UtcNow.AddMinutes(1),
            Transport = "tcp",
            QuotaRemainingBytes = 1024,
        };
        var json = JsonSerializer.Serialize(policy);
        Assert.Contains("leikwan-ultra-policy-v2", json);
        Assert.DoesNotContain("credential", json, StringComparison.OrdinalIgnoreCase);
        Assert.DoesNotContain("line_ips", json, StringComparison.OrdinalIgnoreCase);
        Assert.DoesNotContain("password", json, StringComparison.OrdinalIgnoreCase);
    }

    [Fact]
    public void LeaseWireNameAndFrameAreBounded()
    {
        Assert.Equal("leikwan-ultra-forward-42", UltraLeaseClient.PipeName(42));
        Assert.Equal(64 * 1024, UltraLeaseClient.MaxFrameBytes);
    }

    [Fact]
    public void NativePipeRecoverableErrorsAreClassifiedWithoutSwallowingCancellation()
    {
        Exception[] recoverable =
        [
            new TimeoutException(),
            new SocketException((int)SocketError.ConnectionReset),
            new IOException(),
            new InvalidDataException(),
            new JsonException(),
            new Win32Exception(2),
        ];

        Assert.All(recoverable, exception =>
            Assert.True(UltraLeaseClient.IsRecoverableNativePipeException(exception), exception.GetType().Name));
        Assert.False(UltraLeaseClient.IsRecoverableNativePipeException(new OperationCanceledException()));
        Assert.False(UltraLeaseClient.IsRecoverableNativePipeException(new UnauthorizedAccessException()));
    }

    [Fact]
    public async Task NativeLeaseWireUsesRuntimeTargetDigestField()
    {
        var pipeName = "leikwan-native-test-" + Guid.NewGuid().ToString("N");
        var generation = new string('a', 64);
        var digest = new string('b', 64);
        using var lease = new NativeOutboundLease(
            180,
            "native-lease-test",
            generation,
            DateTimeOffset.UtcNow.AddMinutes(2),
            "tcp+udp",
            "CAN -> HKG",
            "global",
            digest,
            "203.0.113.20",
            5438,
            Encoding.UTF8.GetBytes("native-user"),
            Encoding.UTF8.GetBytes("native-password"),
            rateLimitMbps: 20,
            quotaRemainingBytes: 1024,
            selectionRef: "selection-reference-native-test");
        await using var server = new NamedPipeServerStream(
            pipeName,
            PipeDirection.InOut,
            1,
            PipeTransmissionMode.Byte,
            PipeOptions.Asynchronous);

        var sendTask = UltraLeaseClient.SendNativeAsync(pipeName, 180, lease);
        await server.WaitForConnectionAsync();
        var body = await ReadFrameAsync(server);
        using var request = JsonDocument.Parse(body);
        var root = request.RootElement;
        Assert.Equal(digest, root.GetProperty("target_digest").GetString());
        Assert.False(root.TryGetProperty("scope_digest", out _));

        await WriteFrameAsync(
            server,
            $$"""{"ok":true,"forward_id":180,"policy_generation":"{{generation}}"}""");
        Assert.True(await sendTask);
    }

    [Fact]
    public async Task NativeLeaseBatchWirePreservesBoundedCandidates()
    {
        var pipeName = "leikwan-native-batch-test-" + Guid.NewGuid().ToString("N");
        var generation = new string('c', 64);
        var digest = new string('d', 64);
        using var leaseA = CreateNativeLease("native-batch-a", "selection-batch-a", generation, digest, 5438);
        using var leaseB = CreateNativeLease("native-batch-b", "selection-batch-b", generation, digest, 5439);
        await using var server = new NamedPipeServerStream(
            pipeName,
            PipeDirection.InOut,
            1,
            PipeTransmissionMode.Byte,
            PipeOptions.Asynchronous);

        var sendTask = UltraLeaseClient.SendNativeAsync(pipeName, 180, new[] { leaseA, leaseB });
        await server.WaitForConnectionAsync();
        var body = await ReadFrameAsync(server);
        using var request = JsonDocument.Parse(body);
        var leases = request.RootElement.GetProperty("leases");
        Assert.Equal(JsonValueKind.Array, leases.ValueKind);
        Assert.Equal(2, leases.GetArrayLength());
        Assert.Equal("selection-batch-a", leases[0].GetProperty("selection_ref").GetString());
        Assert.Equal("selection-batch-b", leases[1].GetProperty("selection_ref").GetString());
        Assert.Equal(digest, leases[0].GetProperty("target_digest").GetString());
        Assert.DoesNotContain("scope_digest", Encoding.UTF8.GetString(body), StringComparison.Ordinal);

        await WriteFrameAsync(
            server,
            $$"""{"ok":true,"forward_id":180,"policy_generation":"{{generation}}"}""");
        Assert.True(await sendTask);
    }

    private static NativeOutboundLease CreateNativeLease(
        string leaseId,
        string selectionRef,
        string generation,
        string digest,
        int port)
        => new(
            180,
            leaseId,
            generation,
            DateTimeOffset.UtcNow.AddMinutes(2),
            "tcp+udp",
            "CAN -> HKG",
            "intl_only",
            digest,
            "203.0.113.20",
            port,
            Encoding.UTF8.GetBytes("native-user"),
            Encoding.UTF8.GetBytes("native-password"),
            rateLimitMbps: 20,
            quotaRemainingBytes: 1024,
            selectionRef: selectionRef);

    private static async Task<byte[]> ReadFrameAsync(Stream stream)
    {
        var header = new byte[4];
        await ReadExactlyAsync(stream, header);
        var length = BinaryPrimitives.ReadUInt32BigEndian(header);
        Assert.InRange(length, 1u, (uint)UltraLeaseClient.MaxFrameBytes);
        var body = new byte[length];
        await ReadExactlyAsync(stream, body);
        return body;
    }

    private static async Task WriteFrameAsync(Stream stream, string json)
    {
        var body = Encoding.UTF8.GetBytes(json);
        var frame = new byte[4 + body.Length];
        BinaryPrimitives.WriteUInt32BigEndian(frame.AsSpan(0, 4), (uint)body.Length);
        body.CopyTo(frame, 4);
        await stream.WriteAsync(frame);
        await stream.FlushAsync();
    }

    private static async Task ReadExactlyAsync(Stream stream, byte[] buffer)
    {
        var offset = 0;
        while (offset < buffer.Length)
        {
            var read = await stream.ReadAsync(buffer.AsMemory(offset));
            if (read <= 0)
                throw new EndOfStreamException();
            offset += read;
        }
    }
}
