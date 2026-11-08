using System.Buffers.Binary;
using System.IO.Pipes;
using System.Net.Sockets;
using System.Text.Json;

namespace LeikwanBridge.Core.Services.Ultra;

/// <summary>Same absolute bound as ultra-client, leikwand and the Linux agent.</summary>
public static class UltraNetworkGrace
{
    public static readonly TimeSpan Limit = TimeSpan.FromSeconds(600);
    public static readonly TimeSpan Lead = TimeSpan.FromSeconds(60);
    public static DateTimeOffset? Deadline(DateTimeOffset issuedExpiry, DateTimeOffset now)
        => now < issuedExpiry - Lead || now >= issuedExpiry + Limit ? null : issuedExpiry + Limit;

    public static bool Send(string endpoint, bool namedPipe, int forwardId, object command)
    {
        try { return SendAsync(endpoint, namedPipe, forwardId, command).GetAwaiter().GetResult(); }
        catch (Exception ex) when (ex is IOException or SocketException or TimeoutException
                                      or OperationCanceledException or JsonException) { return false; }
    }

    private static async Task<bool> SendAsync(string endpoint, bool namedPipe, int forwardId, object command)
    {
        using var timeout = new CancellationTokenSource(TimeSpan.FromSeconds(3));
        Stream stream;
        if (namedPipe)
        {
            var pipe = new NamedPipeClientStream(".", endpoint, PipeDirection.InOut, PipeOptions.Asynchronous);
            try { await pipe.ConnectAsync(timeout.Token).ConfigureAwait(false); }
            catch { pipe.Dispose(); throw; }
            stream = pipe;
        }
        else
        {
            var socket = new Socket(AddressFamily.Unix, SocketType.Stream, ProtocolType.Unspecified);
            try { await socket.ConnectAsync(new UnixDomainSocketEndPoint(endpoint), timeout.Token).ConfigureAwait(false); }
            catch { socket.Dispose(); throw; }
            stream = new NetworkStream(socket, ownsSocket: true);
        }
        await using (stream)
        {
            var body = JsonSerializer.SerializeToUtf8Bytes(command);
            using var requestDoc = JsonDocument.Parse(body);
            var expectedGeneration = requestDoc.RootElement.TryGetProperty("policy_generation", out var requestGeneration)
                && requestGeneration.ValueKind == JsonValueKind.String
                ? requestGeneration.GetString()
                : null;
            var frame = new byte[4 + body.Length];
            BinaryPrimitives.WriteInt32BigEndian(frame, body.Length);
            body.CopyTo(frame, 4);
            await stream.WriteAsync(frame, timeout.Token).ConfigureAwait(false);
            var header = new byte[4];
            await stream.ReadExactlyAsync(header, timeout.Token).ConfigureAwait(false);
            var length = BinaryPrimitives.ReadInt32BigEndian(header);
            if (length < 1 || length > 65536) return false;
            var reply = new byte[length];
            await stream.ReadExactlyAsync(reply, timeout.Token).ConfigureAwait(false);
            using var doc = JsonDocument.Parse(reply);
            if (!doc.RootElement.TryGetProperty("ok", out var ok) || ok.ValueKind != JsonValueKind.True
                || !doc.RootElement.TryGetProperty("forward_id", out var id) || id.GetInt32() != forwardId)
                return false;
            if (expectedGeneration is not null
                && (!doc.RootElement.TryGetProperty("policy_generation", out var replyGeneration)
                    || replyGeneration.ValueKind != JsonValueKind.String
                    || !string.Equals(replyGeneration.GetString(), expectedGeneration, StringComparison.Ordinal)))
                return false;
            return true;
        }
    }
}
