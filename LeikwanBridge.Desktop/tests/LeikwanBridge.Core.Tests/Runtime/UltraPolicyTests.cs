using LeikwanBridge.Core.Models;
using Xunit;

namespace LeikwanBridge.Core.Tests.Runtime;

public sealed class UltraPolicyTests
{
    [Fact]
    public void TCPFirstFlight_IsRestrictedToTheCurrentScope()
    {
        var candidate = new string('b', 64);
        var grant = CreateFirstFlight([candidate], DateTimeOffset.UtcNow.AddMinutes(1));
        var lease = CreateLease(grant, candidate);
        var forward = CreateForward();
        Assert.NotNull(grant.ToBootstrapPayload(forward, [lease]));

        var wrongGeneration = CreateLease(grant, candidate, policyGeneration: new string('d', 64));
        Assert.Null(grant.ToBootstrapPayload(forward, [wrongGeneration]));

        Assert.Null(CreateFirstFlight([candidate], DateTimeOffset.UtcNow.AddSeconds(-1)).ToBootstrapPayload(forward, [lease]));
        Assert.Null(CreateFirstFlight([candidate], grant.ExpiresAt, target: "198.51.100.11:443").ToBootstrapPayload(forward, [lease]));
        Assert.Null(CreateFirstFlight([candidate], grant.ExpiresAt, prefixBytes: 0).ToBootstrapPayload(forward, [lease]));
        Assert.Null(CreateFirstFlight([candidate], grant.ExpiresAt, prefixBytes: 17).ToBootstrapPayload(forward, [lease]));
        Assert.Null(CreateFirstFlight([candidate], grant.ExpiresAt, delayMillis: 0).ToBootstrapPayload(forward, [lease]));
        Assert.Null(CreateFirstFlight([candidate], grant.ExpiresAt, delayMillis: 1001).ToBootstrapPayload(forward, [lease]));
        Assert.Null(CreateFirstFlight([], grant.ExpiresAt).ToBootstrapPayload(forward, [lease]));
        Assert.Null(CreateFirstFlight(["not-a-hash", new string('B', 64)], grant.ExpiresAt).ToBootstrapPayload(forward, [lease]));
        Assert.Null(grant.ToBootstrapPayload(forward, []));
    }

    [Fact]
    public void TCPFirstFlight_IsEmittedWhenTheStartingLeaseIsOnAnotherLine()
    {
        // The runtime matches each stream's authenticated candidate against the
        // list. A runtime that starts on an unlisted line and later rotates onto
        // a listed one must still carry the pilot (macOS PEK->FRA, 2026-09-24).
        var listed = new string('b', 64);
        var grant = CreateFirstFlight([listed], DateTimeOffset.UtcNow.AddMinutes(20));
        var unlisted = CreateLease(grant, new string('c', 64), expiresAt: DateTimeOffset.UtcNow.AddMinutes(4));

        var payload = grant.ToBootstrapPayload(CreateForward(), [unlisted]);

        Assert.NotNull(payload);
        var emitted = (string[])payload!.GetType().GetProperty("candidate_hashes")!.GetValue(payload)!;
        Assert.Equal([listed], emitted);
        // Bootstrap-only and runtime-lifetime: the grant's own expiry, not the
        // (shorter, rotating) lease expiry.
        var expiresAt = (string)payload.GetType().GetProperty("expires_at")!.GetValue(payload)!;
        Assert.Equal(grant.ExpiresAt.UtcDateTime.ToString("yyyy-MM-dd'T'HH:mm:ss'Z'"), expiresAt);
    }

    private static UltraTCPFirstFlight CreateFirstFlight(
        List<string> hashes,
        DateTimeOffset expiresAt,
        string target = "198.51.100.10:443",
        int prefixBytes = 4,
        int delayMillis = 300) => new()
        {
            Target = target,
            PolicyGeneration = new string('a', 64),
            CandidateHashes = hashes,
            ExpiresAt = expiresAt,
            PrefixBytes = prefixBytes,
            DelayMillis = delayMillis,
        };

    private static UltraLease CreateLease(
        UltraTCPFirstFlight grant,
        string candidateKeyHash,
        string? policyGeneration = null,
        DateTimeOffset? expiresAt = null) => new()
        {
            ForwardId = 42,
            PolicyGeneration = policyGeneration ?? grant.PolicyGeneration,
            CandidateKeyHash = candidateKeyHash,
            ExpiresAt = expiresAt ?? grant.ExpiresAt,
        };

    [Fact]
    public void TCPFirstFlight_KeepsEveryDirectionCandidateBeyondFour()
    {
        // A rule without an explicit allowlist covers all of a direction's
        // lines (PEK -> FRA has seven); the lease may be on any of them.
        var hashes = Enumerable.Range(1, 7).Select(i => new string((char)('0' + i), 64)).ToList();
        var grant = new UltraTCPFirstFlight
        {
            Target = "198.51.100.10:443",
            PolicyGeneration = new string('a', 64),
            CandidateHashes = hashes,
            ExpiresAt = DateTimeOffset.UtcNow.AddMinutes(1),
            PrefixBytes = 4,
            DelayMillis = 300,
        };
        var lease = new UltraLease
        {
            ForwardId = 42,
            PolicyGeneration = grant.PolicyGeneration,
            CandidateKeyHash = hashes[6],
            ExpiresAt = grant.ExpiresAt,
        };
        var payload = grant.ToBootstrapPayload(CreateForward(), [lease]);
        Assert.NotNull(payload);
        var emitted = (string[])payload!.GetType().GetProperty("candidate_hashes")!.GetValue(payload)!;
        Assert.Equal(hashes, emitted);
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
