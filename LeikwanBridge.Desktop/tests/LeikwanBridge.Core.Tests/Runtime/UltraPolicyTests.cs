using LeikwanBridge.Core.Models;
using Xunit;

namespace LeikwanBridge.Core.Tests.Runtime;

public sealed class UltraPolicyTests
{
    [Fact]
    public void TCPFirstFlight_IsRestrictedToTheAuthorizedCandidateAndCurrentScope()
    {
        var candidate = new string('b', 64);
        var grant = new UltraTCPFirstFlight
        {
            Target = "198.51.100.10:443",
            PolicyGeneration = new string('a', 64),
            CandidateHashes = [candidate],
            ExpiresAt = DateTimeOffset.UtcNow.AddMinutes(1),
            PrefixBytes = 4,
            DelayMillis = 300,
        };
        var lease = new UltraLease
        {
            ForwardId = 42,
            PolicyGeneration = grant.PolicyGeneration,
            CandidateKeyHash = candidate,
            ExpiresAt = grant.ExpiresAt,
        };
        var forward = CreateForward();
        Assert.NotNull(grant.ToBootstrapPayload(forward, [lease]));
        lease.CandidateKeyHash = new string('c', 64);
        Assert.Null(grant.ToBootstrapPayload(forward, [lease]));
        lease.CandidateKeyHash = candidate;
        var wrongGeneration = new UltraLease
        {
            ForwardId = 42,
            PolicyGeneration = new string('d', 64),
            CandidateKeyHash = candidate,
            ExpiresAt = grant.ExpiresAt,
        };
        Assert.Null(grant.ToBootstrapPayload(forward, [wrongGeneration]));
    }

    [Fact]
    public void GetValidationFailure_AllowsPanelTtlWithSmallClockSkew()
    {
        var now = DateTimeOffset.UtcNow;
        var forward = CreateForward();
        var policy = CreatePolicy(now.AddMinutes(5).AddSeconds(20));

        Assert.Null(policy.GetValidationFailure(forward, now));
    }

    [Fact]
    public void GetValidationFailure_RejectsUnexpectedlyLongTtl()
    {
        var now = DateTimeOffset.UtcNow;
        var forward = CreateForward();
        var policy = CreatePolicy(now.AddMinutes(6));

        Assert.Equal("expiry_window", policy.GetValidationFailure(forward, now));
    }

    private static ForwardConfig CreateForward()
        => new()
        {
            ForwardId = 42,
            BackendMode = "ultra",
            Target = "198.51.100.10:443",
            Transport = "tcp",
        };

    private static UltraPolicy CreatePolicy(DateTimeOffset expiresAt)
        => new()
        {
            ProtocolValue = UltraPolicy.Protocol,
            ForwardId = 42,
            PolicyGeneration = new string('a', 64),
            SessionTicket = "ticket",
            IssuedAt = DateTimeOffset.UtcNow,
            ExpiresAt = expiresAt,
            ServiceState = "active",
            Transport = "tcp",
            QuotaRemainingBytes = 1024,
            TargetDigest = UltraPolicy.ComputeTargetDigest("198.51.100.10:443"),
        };
}
