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
        if (ExpiresAt > now.AddMinutes(5))
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
