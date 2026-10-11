using System.Buffers.Binary;
using System.ComponentModel;
using System.IO.Pipes;
using System.Net.Sockets;
using System.Security.Cryptography;
using System.Text;
using System.Text.Json;
using LeikwanBridge.Core.Models;

namespace LeikwanBridge.Core.Services;

public sealed class UltraLeaseClient
{
    public const int MaxFrameBytes = 64 * 1024;
    public static readonly TimeSpan BatchTimeout = TimeSpan.FromSeconds(70);

    public static string PipeName(int forwardId) => $"leikwan-ultra-forward-{forwardId}";

    /// <summary>
    /// Classifies failures that mean the private native control pipe is not
    /// usable right now. The relay supervisor treats these as a recoverable
    /// delivery failure and may refresh/restart with a fresh lease. Caller
    /// cancellation and permission errors deliberately stay outside this set.
    /// </summary>
    public static bool IsRecoverableNativePipeException(Exception exception)
        => exception is TimeoutException
            or SocketException
            or IOException
            or InvalidDataException
            or JsonException
            or Win32Exception;

    public static string NativePipeName(int forwardId, string instanceId)
    {
        if (forwardId <= 0)
            throw new ArgumentOutOfRangeException(nameof(forwardId));
        var normalized = instanceId?.Trim() ?? string.Empty;
        if (normalized.Length == 0
            || normalized.Any(character => !char.IsLetterOrDigit(character)
                && character is not '-' and not '_'))
            throw new ArgumentException("native relay instance id is invalid", nameof(instanceId));
        return $"leikwan-ultra-native-{forwardId}-{normalized}";
    }

    public static async Task<bool> SendAsync(int forwardId, UltraLease lease, CancellationToken ct = default)
        => await SendAsync(forwardId, new[] { lease }, ct);

    /// <summary>Injects a native-outbound lease into the app-private adapter.</summary>
    public static async Task<bool> SendNativeAsync(
        int forwardId,
        NativeOutboundLease lease,
        CancellationToken ct = default)
        => await SendNativeAsync(PipeName(forwardId), forwardId, lease, ct).ConfigureAwait(false);

    /// <summary>
    /// Injects a native lease through an explicitly selected private pipe.
    /// Native relays use a per-instance pipe so an ordinary Ultra runtime for
    /// the same forward cannot consume the frame.
    /// </summary>
    public static async Task<bool> SendNativeAsync(
        string pipeName,
        int forwardId,
        NativeOutboundLease lease,
        CancellationToken ct = default)
    {
        if (lease is null)
            return false;
        return await SendNativeAsync(pipeName, forwardId, new[] { lease }, ct).ConfigureAwait(false);
    }

    public static async Task<bool> SendNativeAsync(
        int forwardId,
        IReadOnlyList<NativeOutboundLease> leases,
        CancellationToken ct = default)
        => await SendNativeAsync(PipeName(forwardId), forwardId, leases, ct).ConfigureAwait(false);

    public static async Task<bool> SendNativeAsync(
        string pipeName,
        int forwardId,
        IReadOnlyList<NativeOutboundLease> leases,
        CancellationToken ct = default)
    {
        if (string.IsNullOrWhiteSpace(pipeName)
            || pipeName.IndexOfAny(['\\', '/']) >= 0
            || forwardId <= 0
            || leases is null
            || leases.Count is < 1 or > 4
            || leases.Any(lease => lease is null
                || lease.ForwardId != forwardId
                || !lease.IsRuntimeUsable(DateTimeOffset.UtcNow)))
            return false;

        static object Wire(NativeOutboundLease lease) => new
        {
            protocol = "leikwan-ultra-native-socks5-v1",
            purpose = "native_outbound",
            forward_id = lease.ForwardId,
            lease_id = lease.LeaseId,
            policy_generation = lease.PolicyGeneration,
            expires_at = lease.ExpiresAt.UtcDateTime.ToString("yyyy-MM-dd'T'HH:mm:ss'Z'"),
            transport = lease.Transport,
            rate_limit_mbps = lease.RateLimitMbps,
            quota_remaining_bytes = lease.QuotaRemainingBytes,
            target_digest = lease.ScopeDigest,
            selection_ref = lease.SelectionRef,
            selection_tier = lease.SelectionTier,
            candidate_key_hash = lease.CandidateKeyHash,
            direction = lease.Direction,
            route_scope = lease.RouteScope,
            route_policy = lease.RoutePolicy,
            endpoint = new { ip = lease.EndpointHost, port = lease.EndpointPort },
            credential = new
            {
                user = Encoding.UTF8.GetString(lease.User),
                password = Encoding.UTF8.GetString(lease.Password),
            },
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
            timeout.CancelAfter(leases.Count > 1 ? BatchTimeout : TimeSpan.FromSeconds(5));
            await using var pipe = new NamedPipeClientStream(
                ".", pipeName, PipeDirection.InOut, PipeOptions.Asynchronous);
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
        catch (Exception exception) when (IsRecoverableNativePipeException(exception))
        {
            return false;
        }
        finally
        {
            CryptographicOperations.ZeroMemory(body);
        }
    }

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
            selection_tier = lease.SelectionTier,
            candidate_key_hash = lease.CandidateKeyHash,
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
            // Fail fast if the local pipe is absent. Once connected, even one
            // lease can require carrier/sidecar verification before the ACK.
            timeout.CancelAfter(TimeSpan.FromSeconds(2));
            await using var pipe = new NamedPipeClientStream(
                ".", PipeName(forwardId), PipeDirection.InOut, PipeOptions.Asynchronous);
            await pipe.ConnectAsync(timeout.Token);
            timeout.CancelAfter(BatchTimeout);
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
