using System.Security.Cryptography;
using System.Text;
using System.Text.Json.Serialization;

namespace LeikwanBridge.Core.Models;

public sealed class UltraPolicy
{
    public const string Protocol = "leikwan-ultra-policy-v2";

    [JsonPropertyName("protocol")] public string ProtocolValue { get; init; } = "";
    [JsonPropertyName("forward_id")] public int ForwardId { get; init; }
    [JsonPropertyName("policy_generation")] public string PolicyGeneration { get; init; } = "";
    [JsonPropertyName("session_ticket")] public string SessionTicket { get; init; } = "";
    [JsonPropertyName("issued_at")] public DateTimeOffset IssuedAt { get; init; }
    [JsonPropertyName("renew_after")] public DateTimeOffset RenewAfter { get; init; }
    [JsonPropertyName("expires_at")] public DateTimeOffset ExpiresAt { get; init; }
    [JsonPropertyName("service_state")] public string ServiceState { get; init; } = "active";
    [JsonPropertyName("transport")] public string Transport { get; init; } = "tcp+udp";
    [JsonPropertyName("rate_limit_mbps")] public long RateLimitMbps { get; init; }
    [JsonPropertyName("quota_remaining_bytes")] public long QuotaRemainingBytes { get; init; }
    [JsonPropertyName("target_digest")] public string TargetDigest { get; init; } = "";
    [JsonPropertyName("tcp_first_flight")] public UltraTCPFirstFlight? TCPFirstFlight { get; init; }

    public bool IsUsable(ForwardConfig forward, DateTimeOffset now)
        => GetValidationFailure(forward, now) is null;

    public string? GetValidationFailure(ForwardConfig forward, DateTimeOffset now)
    {
        if (!string.Equals(ProtocolValue, Protocol, StringComparison.Ordinal))
            return "protocol";
        if (ForwardId != forward.ForwardId)
            return "forward_id";
        if (!string.Equals(ServiceState, "active", StringComparison.OrdinalIgnoreCase))
            return "service_state";
        if (string.IsNullOrWhiteSpace(SessionTicket))
            return "session_ticket";
        if (!IsHex64(PolicyGeneration))
            return "policy_generation";
        if (!IsHex64(TargetDigest))
            return "target_digest_format";
        if (!string.Equals(TargetDigest, ComputeTargetDigest(forward.Target), StringComparison.OrdinalIgnoreCase))
            return "target_digest_mismatch";
        if (ExpiresAt <= now)
            return "expired";
        // A client that sends the offline-validity header may be granted up to
        // an hour (30 min requested); renewal is still driven by renew_after.
        // Allow a small amount of clock skew and request transit time so a valid
        // grant is not rejected merely because the panel clock is a few seconds ahead.
        if (!UltraGrantValidity.IsWithinAcceptedWindow(ExpiresAt, now))
            return "expiry_window";
        if (QuotaRemainingBytes <= 0)
            return "quota";
        if (RateLimitMbps < 0 || RateLimitMbps > 100000)
            return "rate_limit";
        if (Transport is not ("tcp" or "udp" or "tcp+udp"))
            return "transport";
        return null;
    }

    public static string ComputeTargetDigest(string target)
        => Convert.ToHexString(SHA256.HashData(Encoding.UTF8.GetBytes(target.Trim()))).ToLowerInvariant();

    private static bool IsHex64(string value)
        => value.Length == 64 && value.All(c => c is >= '0' and <= '9' or >= 'a' and <= 'f');
}

public sealed class UltraTCPFirstFlight
{
    [JsonPropertyName("target")] public string Target { get; init; } = "";
    [JsonPropertyName("policy_generation")] public string PolicyGeneration { get; init; } = "";
    [JsonPropertyName("candidate_hashes")] public List<string> CandidateHashes { get; init; } = [];
    [JsonPropertyName("expires_at")] public DateTimeOffset ExpiresAt { get; init; }
    [JsonPropertyName("prefix_bytes")] public int PrefixBytes { get; init; }
    [JsonPropertyName("delay_ms")] public int DelayMillis { get; init; }

    public object? ToBootstrapPayload(ForwardConfig forward, IReadOnlyList<UltraLease> leases)
    {
        if (leases.Count is < 1 or > 4)
            return null;
        var lease = leases[0];
        // Emit the whole panel-scoped list: the runtime matches each stream's
        // authenticated candidate, and a later lease rotation onto another
        // listed line must keep the compatibility without a restart.
        var candidateHashes = CandidateHashes
            .Where(value => value.Length == 64 && value.All(c => c is >= '0' and <= '9' or >= 'a' and <= 'f'))
            .Distinct(StringComparer.Ordinal)
            .Take(4)
            .ToArray();
        var eligibleLeases = leases
            .Where(item => candidateHashes.Contains(item.CandidateKeyHash, StringComparer.Ordinal))
            .ToArray();
        if (eligibleLeases.Length == 0)
            return null;
        var effectiveExpiry = eligibleLeases.Min(item => item.ExpiresAt);
        if (ExpiresAt < effectiveExpiry)
            effectiveExpiry = ExpiresAt;
        if (!string.Equals(Target, forward.Target, StringComparison.Ordinal)
            || !string.Equals(PolicyGeneration, lease.PolicyGeneration, StringComparison.Ordinal)
            || effectiveExpiry <= DateTimeOffset.UtcNow
            || PrefixBytes is < 1 or > 16
            || DelayMillis is < 1 or > 1000
            || candidateHashes.Length is < 1 or > 4)
            return null;
        return new
        {
            target = Target,
            policy_generation = PolicyGeneration,
            candidate_hashes = candidateHashes,
            expires_at = effectiveExpiry.UtcDateTime.ToString("yyyy-MM-dd'T'HH:mm:ss'Z'"),
            prefix_bytes = PrefixBytes,
            delay_ms = DelayMillis,
        };
    }
}
