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
    [JsonIgnore] public string NonceHash { get; set; } = "";
    [JsonIgnore] public string ProviderIp { get; set; } = "";
    [JsonIgnore] public int ProviderPort { get; set; }
    [JsonIgnore] public byte[] User { get; init; } = [];
    [JsonIgnore] public byte[] Password { get; init; } = [];

    public bool IsUsable(UltraPolicy policy, ForwardConfig forward, DateTimeOffset now)
    {
        return string.Equals(Protocol, UltraPolicy.Protocol, StringComparison.Ordinal)
            && !string.IsNullOrWhiteSpace(LeaseId)
            && !string.IsNullOrWhiteSpace(SessionTicket)
            && ForwardId == forward.ForwardId
            && string.Equals(PolicyGeneration, policy.PolicyGeneration, StringComparison.Ordinal)
            && string.Equals(TargetDigest, policy.TargetDigest, StringComparison.Ordinal)
            && string.Equals(Transport, policy.Transport, StringComparison.OrdinalIgnoreCase)
            && ExpiresAt > now && ExpiresAt <= policy.ExpiresAt.AddSeconds(1)
            && RateLimitMbps is >= 0 and <= 100000
            && QuotaRemainingBytes > 0 && QuotaRemainingBytes <= 107374182400
            && ProviderPort is > 0 and <= 65535
            && System.Net.IPAddress.TryParse(ProviderIp, out _)
            && User.Length is > 0 and <= 255
            && Password.Length is > 0 and <= 255
            && SelectionRef.Length is >= 16 and <= 128
            && IsSelectionTier(SelectionTier)
            && NonceHash.Length == 64
            && NonceHash.All(c => c is >= '0' and <= '9' or >= 'a' and <= 'f');
    }

    public void Dispose()
    {
        CryptographicOperations.ZeroMemory(User);
        CryptographicOperations.ZeroMemory(Password);
        LeaseId = "";
        SessionTicket = "";
        SelectionRef = "";
        SelectionTier = SelectionTierUnknown;
        NonceHash = "";
        ProviderIp = "";
        ProviderPort = 0;
    }

    public static bool IsSelectionTier(string value)
        => value is SelectionTierPaid or SelectionTierMixed or SelectionTierFree or SelectionTierUnknown;
}
