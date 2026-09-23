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

    [Theory]
    [InlineData(1800)] // what this build asks for with the offline-validity header
    [InlineData(3600)] // the panel's own ceiling
    public void GetValidationFailure_AcceptsOfflineValidityUpToOneHour(int seconds)
    {
        var now = DateTimeOffset.UtcNow;
        var forward = CreateForward();

        Assert.Null(CreatePolicy(now.AddSeconds(seconds)).GetValidationFailure(forward, now));
        // Clock skew/transit allowance on top of the ceiling.
        Assert.Null(CreatePolicy(now.AddSeconds(seconds) + UltraGrantValidity.ClockSkewAllowance).GetValidationFailure(forward, now));
    }

    [Fact]
    public void GetValidationFailure_RejectsUnexpectedlyLongTtl()
    {
        var now = DateTimeOffset.UtcNow;
        var forward = CreateForward();
        var policy = CreatePolicy(now.AddSeconds(UltraGrantValidity.MaxAcceptedValiditySeconds)
            + UltraGrantValidity.ClockSkewAllowance
            + TimeSpan.FromSeconds(1));

        Assert.Equal("expiry_window", policy.GetValidationFailure(forward, now));
        Assert.Equal("expiry_window", CreatePolicy(now.AddHours(2)).GetValidationFailure(forward, now));
    }

    [Fact]
    public void UltraGrantValidity_RequestsThirtyMinutesAndAcceptsAnHour()
    {
        Assert.Equal("X-Leikwan-Ultra-Offline-Validity", UltraGrantValidity.OfflineValidityHeader);
        Assert.Equal("1800", UltraGrantValidity.RequestedOfflineValidityHeaderValue);
        Assert.Equal(3600, UltraGrantValidity.MaxAcceptedValiditySeconds);
        var now = DateTimeOffset.UtcNow;
        Assert.True(UltraGrantValidity.IsWithinAcceptedWindow(now.AddSeconds(3630), now));
        Assert.False(UltraGrantValidity.IsWithinAcceptedWindow(now.AddSeconds(3631), now));
    }

    [Fact]
    public void NativeOutboundLease_AcceptsLongGrantButNotBeyondTheCeiling()
    {
        var now = DateTimeOffset.UtcNow;
        NativeOutboundLease Create(DateTimeOffset expiresAt) => new(
            42, "lease", new string('a', 64), expiresAt, "tcp+udp", "dir", "global",
            new string('b', 64), "203.0.113.1", 443, [1], [2], 10, 1024,
            "selection-reference-000-abcdefghijklmnop", "paid", "restricted", "");
        using var shortLease = Create(now.AddMinutes(5));
        using var longLease = Create(now.AddSeconds(3600));
        using var tooLong = Create(now.AddSeconds(3700));

        Assert.True(shortLease.IsUsable(now));
        Assert.True(longLease.IsUsable(now));
        Assert.False(tooLong.IsUsable(now));
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
