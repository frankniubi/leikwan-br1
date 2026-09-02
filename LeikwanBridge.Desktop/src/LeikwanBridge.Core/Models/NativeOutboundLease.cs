using System.Net;
using System.Security.Cryptography;
using System.Text;
using System.Text.Json.Serialization;

namespace LeikwanBridge.Core.Models;

public sealed class NativeOutboundLease : IDisposable
{
    public NativeOutboundLease(
        int forwardId,
        string leaseId,
        string policyGeneration,
        DateTimeOffset expiresAt,
        string transport,
        string direction,
        string routeScope,
        string scopeDigest,
        string endpointHost,
        int endpointPort,
        byte[] user,
        byte[] password,
        long rateLimitMbps = 0,
        long quotaRemainingBytes = 0,
        string selectionRef = "",
        string selectionTier = UltraLease.SelectionTierUnknown)
    {
        ForwardId = forwardId;
        LeaseId = leaseId;
        PolicyGeneration = policyGeneration;
        ExpiresAt = expiresAt;
        Transport = transport;
        Direction = direction;
        RouteScope = routeScope;
        ScopeDigest = scopeDigest;
        EndpointHost = endpointHost;
        EndpointPort = endpointPort;
        User = user ?? throw new ArgumentNullException(nameof(user));
        Password = password ?? throw new ArgumentNullException(nameof(password));
        RateLimitMbps = rateLimitMbps;
        QuotaRemainingBytes = quotaRemainingBytes;
        SelectionRef = selectionRef ?? string.Empty;
        SelectionTier = string.IsNullOrWhiteSpace(selectionTier)
            ? UltraLease.SelectionTierUnknown
            : selectionTier.Trim().ToLowerInvariant();
    }

    [JsonIgnore] public int ForwardId { get; }
    [JsonIgnore] public string LeaseId { get; }
    [JsonIgnore] public string PolicyGeneration { get; }
    [JsonIgnore] public DateTimeOffset ExpiresAt { get; }
    [JsonIgnore] public string Transport { get; }
    [JsonIgnore] public string Direction { get; }
    [JsonIgnore] public string RouteScope { get; }
    [JsonIgnore] public string ScopeDigest { get; }
    [JsonIgnore] public string EndpointHost { get; }
    [JsonIgnore] public int EndpointPort { get; }
    [JsonIgnore] public byte[] User { get; private set; }
    [JsonIgnore] public byte[] Password { get; private set; }
    [JsonIgnore] public long RateLimitMbps { get; }
    [JsonIgnore] public long QuotaRemainingBytes { get; }
    [JsonIgnore] public string SelectionRef { get; }
    [JsonIgnore] public string SelectionTier { get; }

    public bool IsUsable(DateTimeOffset now)
        => ForwardId > 0
            && !string.IsNullOrWhiteSpace(LeaseId)
            && ExpiresAt > now
            && ExpiresAt <= now.AddMinutes(5).AddSeconds(30)
            && string.Equals(Transport, "tcp+udp", StringComparison.OrdinalIgnoreCase)
            && RouteScope is "cn_only" or "intl_only" or "global"
            && ScopeDigest.Length == 64
            && ScopeDigest.All(character => character is >= '0' and <= '9' or >= 'a' and <= 'f' or >= 'A' and <= 'F')
            && !string.IsNullOrWhiteSpace(EndpointHost)
            && EndpointPort is > 0 and <= 65535
            && (IPAddress.TryParse(EndpointHost, out _) || Uri.CheckHostName(EndpointHost) is UriHostNameType.Dns)
            && User.Length is > 0 and <= 255
            && Password.Length is > 0 and <= 255;

    public bool IsRuntimeUsable(DateTimeOffset now)
        => IsUsable(now)
            && RateLimitMbps is >= 0 and <= 100000
            && QuotaRemainingBytes is > 0 and <= 107374182400
            && SelectionRef.Length is >= 16 and <= 128
            && SelectionRef.All(character => char.IsLetterOrDigit(character) || character is '_' or '-')
            && UltraLease.IsSelectionTier(SelectionTier);

    public static string ComputeScopeDigest(int forwardId, string direction, string transport, string routeScope)
    {
        var canonical = string.Join("\n", [
            "native-outbound:v1",
            forwardId.ToString(System.Globalization.CultureInfo.InvariantCulture),
            direction.Trim().ToLowerInvariant(),
            transport.Trim().ToLowerInvariant(),
            routeScope.Trim().ToLowerInvariant(),
        ]);
        return Convert.ToHexString(SHA256.HashData(Encoding.UTF8.GetBytes(canonical))).ToLowerInvariant();
    }

    public void Dispose()
    {
        CryptographicOperations.ZeroMemory(User);
        CryptographicOperations.ZeroMemory(Password);
        User = [];
        Password = [];
    }
}

public sealed record NativeOutboundConfigFragment(string Json, int LocalPort);
