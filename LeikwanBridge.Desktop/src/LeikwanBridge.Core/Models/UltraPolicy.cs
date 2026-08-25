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
    {
        if (!string.Equals(ProtocolValue, Protocol, StringComparison.Ordinal)
            || ForwardId != forward.ForwardId
            || !string.Equals(ServiceState, "active", StringComparison.OrdinalIgnoreCase)
            || string.IsNullOrWhiteSpace(SessionTicket)
            || !IsHex64(PolicyGeneration)
            || !IsHex64(TargetDigest)
            || !string.Equals(TargetDigest, ComputeTargetDigest(forward.Target), StringComparison.OrdinalIgnoreCase)
            || ExpiresAt <= now
            || ExpiresAt > now.AddMinutes(5)
            || QuotaRemainingBytes <= 0
            || RateLimitMbps < 0
            || RateLimitMbps > 100000
            || Transport is not ("tcp" or "udp" or "tcp+udp"))
            return false;
        return true;
    }

    public static string ComputeTargetDigest(string target)
        => Convert.ToHexString(SHA256.HashData(Encoding.UTF8.GetBytes(target.Trim()))).ToLowerInvariant();

    private static bool IsHex64(string value)
        => value.Length == 64 && value.All(c => c is >= '0' and <= '9' or >= 'a' and <= 'f');
}
