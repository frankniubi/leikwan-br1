using System.Buffers.Binary;
using System.IO.Pipes;
using System.Security.Cryptography;
using System.Text;
using System.Text.Json;
using LeikwanBridge.Core.Models;

namespace LeikwanBridge.Core.Services;

public sealed class UltraLeaseClient
{
    public const int MaxFrameBytes = 64 * 1024;

    public static string PipeName(int forwardId) => $"leikwan-ultra-forward-{forwardId}";

    public static async Task<bool> SendAsync(int forwardId, UltraLease lease, CancellationToken ct = default)
        => await SendAsync(forwardId, new[] { lease }, ct);

    public static async Task<bool> SendAsync(int forwardId, IReadOnlyList<UltraLease> leases, CancellationToken ct = default)
    {
        if (forwardId <= 0 || leases is null || leases.Count is < 1 or > 4 || leases.Any(lease => lease.ForwardId != forwardId))
            return false;

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
            credential = new { user = Encoding.UTF8.GetString(lease.User), password = Encoding.UTF8.GetString(lease.Password) },
        };
        object wire = leases.Count == 1
            ? Wire(leases[0])
            : new { leases = leases.Select(Wire).ToArray() };
        byte[] body = [];
        try
        {
            body = JsonSerializer.SerializeToUtf8Bytes(wire);
            if (body.Length is < 1 or > MaxFrameBytes)
                return false;
            var frame = new byte[4 + body.Length];
            BinaryPrimitives.WriteUInt32BigEndian(frame.AsSpan(0, 4), (uint)body.Length);
            body.CopyTo(frame, 4);
            using var timeout = CancellationTokenSource.CreateLinkedTokenSource(ct);
            // The runtime probes immediately, including a single candidate;
            // keep the pipe open for the full bounded probe window.
            timeout.CancelAfter(TimeSpan.FromSeconds(20));
            await using var pipe = new NamedPipeClientStream(
                ".", PipeName(forwardId), PipeDirection.InOut, PipeOptions.Asynchronous);
            await pipe.ConnectAsync(timeout.Token);
            await pipe.WriteAsync(frame, timeout.Token);
            await pipe.FlushAsync(timeout.Token);
            var header = new byte[4];
            await ReadExactlyAsync(pipe, header, timeout.Token);
            var length = BinaryPrimitives.ReadUInt32BigEndian(header);
            if (length is < 1 or > MaxFrameBytes)
                return false;
            var response = new byte[length];
            await ReadExactlyAsync(pipe, response, timeout.Token);
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
        catch (IOException)
        {
            return false;
        }
        catch (InvalidDataException)
        {
            return false;
        }
        finally
        {
            CryptographicOperations.ZeroMemory(body);
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
