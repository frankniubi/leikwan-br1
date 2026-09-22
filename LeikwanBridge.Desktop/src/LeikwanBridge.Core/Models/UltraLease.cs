using System.Text.Json.Serialization;
using System.Security.Cryptography;

namespace LeikwanBridge.Core.Models;

// Provider material is intentionally ignored by all StateManager serializers.
public sealed class UltraLease : IDisposable
{
    public const string SelectionTierPaid = "paid";
    public const string SelectionTierMixed = "mixed";
    public const string SelectionTierFree = "free";
    public const string SelectionTierUnknown = "unknown";

    [JsonIgnore] public string Protocol { get; init; } = UltraPolicy.Protocol;
    [JsonIgnore] public string LeaseId { get; set; } = "";
    [JsonIgnore] public string SessionTicket { get; set; } = "";
    [JsonIgnore] public int ForwardId { get; init; }
    [JsonIgnore] public string PolicyGeneration { get; init; } = "";
    [JsonIgnore] public string TargetDigest { get; init; } = "";
    [JsonIgnore] public DateTimeOffset ExpiresAt { get; init; }
    [JsonIgnore] public string Transport { get; init; } = "tcp+udp";
    [JsonIgnore] public long RateLimitMbps { get; init; }
    [JsonIgnore] public long QuotaRemainingBytes { get; init; }
    [JsonIgnore] public string SelectionRef { get; set; } = "";
    [JsonIgnore] public string SelectionTier { get; set; } = SelectionTierUnknown;
    [JsonIgnore] public string CandidateKeyHash { get; set; } = "";
    [JsonIgnore] public string NonceHash { get; set; } = "";
    [JsonIgnore] public string ProviderIp { get; set; } = "";
    [JsonIgnore] public int ProviderPort { get; set; }
    [JsonIgnore] public byte[] User { get; init; } = [];
    [JsonIgnore] public byte[] Password { get; init; } = [];

    /// <summary>
    /// Optional operator-owned UoT sidecar grant ("external_uot"). Like the
    /// provider material above it is ignored by every StateManager serializer,
    /// and it is disposed together with the lease that carries it.
    /// </summary>
    [JsonIgnore] public ExternalUotGrant? ExternalUot { get; set; }
    [JsonIgnore] public DateTimeOffset PolicyExpiresAt { get; set; }
    [JsonIgnore] public DateTimeOffset PolicyRenewAfter { get; set; }

    public bool IsUsable(UltraPolicy policy, ForwardConfig forward, DateTimeOffset now)
        => GetUsabilityFailureReason(policy, forward, now) is null;

    public string? GetUsabilityFailureReason(UltraPolicy policy, ForwardConfig forward, DateTimeOffset now)
    {
        if (!string.Equals(Protocol, UltraPolicy.Protocol, StringComparison.Ordinal))
            return "protocol";
        if (string.IsNullOrWhiteSpace(LeaseId))
            return "leaseId";
        if (string.IsNullOrWhiteSpace(SessionTicket))
            return "sessionTicket";
        if (ForwardId != forward.ForwardId)
            return "forwardId";
        if (!string.Equals(PolicyGeneration, policy.PolicyGeneration, StringComparison.Ordinal))
            return "policyGeneration";
        if (!string.Equals(TargetDigest, policy.TargetDigest, StringComparison.Ordinal))
            return "targetDigest";
        if (!string.Equals(Transport, policy.Transport, StringComparison.OrdinalIgnoreCase))
            return "transport";
        if (!(ExpiresAt > now && ExpiresAt <= policy.ExpiresAt.AddSeconds(1)))
            return "expiry";
        if (!(RateLimitMbps is >= 0 and <= 100000))
            return "rateLimit";
        if (!(QuotaRemainingBytes > 0 && QuotaRemainingBytes <= 107374182400))
            return "quota";
        if (!(ProviderPort is > 0 and <= 65535))
            return "providerPort";
        if (!System.Net.IPAddress.TryParse(ProviderIp, out _))
            return "providerIp";
        if (!(User.Length is > 0 and <= 255))
            return "userLen";
        if (!(Password.Length is > 0 and <= 255))
            return "passwordLen";
        if (!(SelectionRef.Length is >= 16 and <= 128))
            return "selectionRef";
        if (!IsSelectionTier(SelectionTier))
            return "selectionTier";
        if (!(CandidateKeyHash.Length == 0 || (CandidateKeyHash.Length == 64
            && CandidateKeyHash.All(c => c is >= '0' and <= '9' or >= 'a' and <= 'f'))))
            return "candidateKeyHash";
        if (!(NonceHash.Length == 64
            && NonceHash.All(c => c is >= '0' and <= '9' or >= 'a' and <= 'f')))
            return "nonceHash";
        return null;
    }

    public void Dispose()
    {
        CryptographicOperations.ZeroMemory(User);
        CryptographicOperations.ZeroMemory(Password);
        LeaseId = "";
        SessionTicket = "";
        SelectionRef = "";
        SelectionTier = SelectionTierUnknown;
        CandidateKeyHash = "";
        NonceHash = "";
        ProviderIp = "";
        ProviderPort = 0;
        ExternalUot?.Dispose();
        ExternalUot = null;
    }

    public static bool IsSelectionTier(string value)
        => value is SelectionTierPaid or SelectionTierMixed or SelectionTierFree or SelectionTierUnknown;
}
