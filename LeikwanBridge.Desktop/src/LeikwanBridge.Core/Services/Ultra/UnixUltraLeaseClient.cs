using System.Buffers.Binary;
using System.Net.Sockets;
using System.Security.Cryptography;
using System.Text;
using System.Text.Json;
using LeikwanBridge.Core.Models;

namespace LeikwanBridge.Core.Services.Ultra;

public sealed class UnixUltraLeaseClient
{
    public const int MaxFrameBytes = 64 * 1024;
    private readonly TimeSpan _timeout;

    public UnixUltraLeaseClient(TimeSpan? timeout = null)
    {
        _timeout = timeout ?? TimeSpan.FromSeconds(2);
    }

    public async Task<bool> SendAsync(string socketPath, int forwardId, UltraLease lease, CancellationToken ct = default)
        => await SendAsync(socketPath, forwardId, new[] { lease }, ct);

    public async Task<bool> SendAsync(string socketPath, int forwardId, IReadOnlyList<UltraLease> leases, CancellationToken ct = default)
    {
        if (string.IsNullOrWhiteSpace(socketPath)
            || forwardId <= 0
            || leases is null
            || leases.Count is < 1 or > 4
            || leases.Any(lease => lease.ForwardId != forwardId || string.IsNullOrWhiteSpace(lease.PolicyGeneration)))
            return false;

        byte[] body = [];
        byte[] frame = [];
        byte[] header = [];
        byte[] response = [];
        try
        {
            static object Wire(UltraLease lease) => new
            {
                protocol = UltraPolicy.Protocol,
                forward_id = lease.ForwardId,
                session_ticket = lease.SessionTicket,
                policy_generation = lease.PolicyGeneration,
                lease_id = lease.LeaseId,
                expires_at = lease.ExpiresAt.UtcDateTime.ToString("yyyy-MM-dd'T'HH:mm:ss'Z'"),
                transport = lease.Transport,
                rate_limit_mbps = lease.RateLimitMbps,
                quota_remaining_bytes = lease.QuotaRemainingBytes,
                target_digest = lease.TargetDigest,
                selection_ref = lease.SelectionRef,
                nonce_hash = lease.NonceHash,
                endpoint = new { ip = lease.ProviderIp, port = lease.ProviderPort },
                credential = new
                {
                    user = Encoding.UTF8.GetString(lease.User),
                    password = Encoding.UTF8.GetString(lease.Password),
                },
            };
            object wire = leases.Count == 1
                ? Wire(leases[0])
                : new { leases = leases.Select(Wire).ToArray() };
            body = JsonSerializer.SerializeToUtf8Bytes(wire);
            if (body.Length is < 1 or > MaxFrameBytes)
                return false;
            frame = new byte[4 + body.Length];
            BinaryPrimitives.WriteUInt32BigEndian(frame.AsSpan(0, 4), (uint)body.Length);
            body.CopyTo(frame, 4);

            using var timeout = CancellationTokenSource.CreateLinkedTokenSource(ct);
            timeout.CancelAfter(leases.Count == 1 ? _timeout : TimeSpan.FromSeconds(20));
            using var socket = new Socket(AddressFamily.Unix, SocketType.Stream, ProtocolType.Unspecified);
            await socket.ConnectAsync(new UnixDomainSocketEndPoint(socketPath), timeout.Token);
            await using var stream = new NetworkStream(socket, ownsSocket: false);
            await stream.WriteAsync(frame, timeout.Token);
            await stream.FlushAsync(timeout.Token);

            header = new byte[4];
            await ReadExactlyAsync(stream, header, timeout.Token);
            var responseLength = BinaryPrimitives.ReadUInt32BigEndian(header);
            if (responseLength is < 1 or > MaxFrameBytes)
                return false;
            response = new byte[responseLength];
            await ReadExactlyAsync(stream, response, timeout.Token);
            using var document = JsonDocument.Parse(response);
            var root = document.RootElement;
            return root.TryGetProperty("ok", out var ok)
                && ok.ValueKind == JsonValueKind.True
                && root.TryGetProperty("forward_id", out var returnedForward)
                && returnedForward.GetInt32() == forwardId
                && root.TryGetProperty("policy_generation", out var returnedGeneration)
                && string.Equals(returnedGeneration.GetString(), leases[0].PolicyGeneration, StringComparison.Ordinal);
        }
        catch (OperationCanceledException) when (!ct.IsCancellationRequested)
        {
            return false;
        }
        catch (SocketException)
        {
            return false;
        }
        catch (IOException)
        {
            return false;
        }
        catch (JsonException)
        {
            return false;
        }
        finally
        {
            CryptographicOperations.ZeroMemory(body);
            CryptographicOperations.ZeroMemory(frame);
            CryptographicOperations.ZeroMemory(header);
            CryptographicOperations.ZeroMemory(response);
        }
    }

    private static async Task ReadExactlyAsync(Stream stream, byte[] buffer, CancellationToken ct)
    {
        var offset = 0;
        while (offset < buffer.Length)
        {
            var count = await stream.ReadAsync(buffer.AsMemory(offset), ct);
            if (count <= 0)
                throw new EndOfStreamException();
            offset += count;
        }
    }
}
