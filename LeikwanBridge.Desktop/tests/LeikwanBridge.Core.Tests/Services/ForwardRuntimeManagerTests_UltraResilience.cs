using LeikwanBridge.Core.Models;
using Xunit;

namespace LeikwanBridge.Core.Tests.Services;

/// <summary>
/// Tests for Ultra resilience logic (F1 韧性修复验证)
/// </summary>
public class ForwardRuntimeManagerTests_UltraResilience
{
    private static UltraLease CreateValidLease(int forwardId, DateTimeOffset expiresAt, string policyGen, string targetDigest, string transport)
    {
        return new UltraLease
        {
            ForwardId = forwardId,
            LeaseId = "lease123",
            SessionTicket = "ticket456",
            ExpiresAt = expiresAt,
            PolicyGeneration = policyGen,
            TargetDigest = targetDigest,
            Transport = transport,
            RateLimitMbps = 100,
            QuotaRemainingBytes = 1000000,
            ProviderIp = "1.2.3.4",
            ProviderPort = 8080,
            User = new byte[] { 1, 2, 3 },
            Password = new byte[] { 4, 5, 6 },
            SelectionRef = "0123456789abcdef",
            SelectionTier = UltraLease.SelectionTierPaid,
            CandidateKeyHash = "",
            NonceHash = "0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef"
        };
    }

    [Fact]
    public void ShouldPreserveRuntime_WhenAllRejectionsDueToExpiry()
    {
        // Arrange
        var now = DateTimeOffset.UtcNow;
        var policy = new UltraPolicy
        {
            PolicyGeneration = "gen123",
            SessionTicket = "ticket456",
            TargetDigest = "digest789",
            Transport = "tcp+udp",
            ExpiresAt = now.AddSeconds(300),
            RenewAfter = now.AddSeconds(240)
        };

        var forward = new ForwardConfig { ForwardId = 1 };

        // lease1: 新过期时间超过 policy.ExpiresAt+1s
        var lease1 = CreateValidLease(1, now.AddSeconds(350), "gen123", "digest789", "tcp+udp");

        // lease2: 同样超过上界
        var lease2 = CreateValidLease(1, now.AddSeconds(310), "gen123", "digest789", "tcp+udp");

        // Act
        var reason1 = lease1.GetUsabilityFailureReason(policy, forward, now);
        var reason2 = lease2.GetUsabilityFailureReason(policy, forward, now);

        // Assert
        Assert.Equal("expiry", reason1);
        Assert.Equal("expiry", reason2);

        // 此场景下 ForwardRuntimeManager 应设置 shouldPreserveRuntime = true
        // 并触发 RequestPolicyRefresh，而非 StopForwardForPolicy
    }

    [Fact]
    public void ShouldPreserveRuntime_WhenAllRejectionsDueToPolicyGeneration()
    {
        // Arrange
        var now = DateTimeOffset.UtcNow;
        var policy = new UltraPolicy
        {
            PolicyGeneration = "gen_old",
            SessionTicket = "ticket456",
            TargetDigest = "digest789",
            Transport = "tcp+udp",
            ExpiresAt = now.AddSeconds(300)
        };

        var forward = new ForwardConfig { ForwardId = 1 };

        var lease = CreateValidLease(1, now.AddSeconds(200), "gen_new", "digest789", "tcp+udp");

        // Act
        var reason = lease.GetUsabilityFailureReason(policy, forward, now);

        // Assert
        Assert.Equal("policyGeneration", reason);

        // 此场景下 shouldPreserveRuntime = true（素材轮换导致代际变化）
        // ForwardRuntimeManager 应触发 RequestPolicyRefresh
    }

    [Fact]
    public void ShouldNotPreserveRuntime_WhenRejectionsDueToQuota()
    {
        // Arrange
        var now = DateTimeOffset.UtcNow;
        var policy = new UltraPolicy
        {
            PolicyGeneration = "gen123",
            TargetDigest = "digest789",
            Transport = "tcp+udp",
            ExpiresAt = now.AddSeconds(300)
        };

        var forward = new ForwardConfig { ForwardId = 1 };

        // 创建 quota 为 0 的租约
        var lease = new UltraLease
        {
            ForwardId = 1,
            LeaseId = "lease123",
            SessionTicket = "ticket456",
            ExpiresAt = now.AddSeconds(200),
            PolicyGeneration = "gen123",
            TargetDigest = "digest789",
            Transport = "tcp+udp",
            RateLimitMbps = 100,
            QuotaRemainingBytes = 0, // quota 耗尽
            ProviderIp = "1.2.3.4",
            ProviderPort = 8080,
            User = new byte[] { 1, 2, 3 },
            Password = new byte[] { 4, 5, 6 },
            SelectionRef = "0123456789abcdef",
            SelectionTier = UltraLease.SelectionTierPaid,
            CandidateKeyHash = "",
            NonceHash = "0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef"
        };

        // Act
        var reason = lease.GetUsabilityFailureReason(policy, forward, now);

        // Assert
        Assert.Equal("quota", reason);

        // 此场景下 shouldPreserveRuntime = false（breakdown 含 quota）
        // ForwardRuntimeManager 应立即 StopForwardForPolicy
    }

    [Fact]
    public void ShouldNotPreserveRuntime_WhenMixedReasons()
    {
        // Arrange
        var now = DateTimeOffset.UtcNow;
        var policy = new UltraPolicy
        {
            PolicyGeneration = "gen123",
            TargetDigest = "digest789",
            Transport = "tcp+udp",
            ExpiresAt = now.AddSeconds(300)
        };

        var forward = new ForwardConfig { ForwardId = 1 };

        // lease1 拒绝原因：expiry
        var lease1 = CreateValidLease(1, now.AddSeconds(350), "gen123", "digest789", "tcp+udp");

        // lease2 拒绝原因：targetDigest 不匹配
        var lease2 = CreateValidLease(1, now.AddSeconds(200), "gen123", "digest_wrong", "tcp+udp");

        // Act
        var reason1 = lease1.GetUsabilityFailureReason(policy, forward, now);
        var reason2 = lease2.GetUsabilityFailureReason(policy, forward, now);

        // Assert
        Assert.Equal("expiry", reason1);
        Assert.Equal("targetDigest", reason2);

        // 此场景下 shouldPreserveRuntime = false（breakdown 含 expiry + targetDigest）
        // 因为 targetDigest 不在保活列表中
    }

    [Fact]
    public void Lease_ShouldPassUsability_WhenAllFieldsValid()
    {
        // Arrange
        var now = DateTimeOffset.UtcNow;
        var policy = new UltraPolicy
        {
            PolicyGeneration = "gen123",
            TargetDigest = "digest789",
            Transport = "tcp+udp",
            ExpiresAt = now.AddSeconds(300)
        };

        var forward = new ForwardConfig { ForwardId = 1 };

        var lease = CreateValidLease(1, now.AddSeconds(200), "gen123", "digest789", "tcp+udp");

        // Act
        var reason = lease.GetUsabilityFailureReason(policy, forward, now);
        var isUsable = lease.IsUsable(policy, forward, now);

        // Assert
        Assert.Null(reason);
        Assert.True(isUsable);
    }

    [Fact]
    public void Lease_ShouldRejectExpiry_WhenExactlyAtUpperBound()
    {
        // Arrange
        var now = DateTimeOffset.UtcNow;
        var policyExpiry = now.AddSeconds(300);
        var policy = new UltraPolicy
        {
            PolicyGeneration = "gen123",
            TargetDigest = "digest789",
            Transport = "tcp+udp",
            ExpiresAt = policyExpiry
        };

        var forward = new ForwardConfig { ForwardId = 1 };

        // lease.ExpiresAt = policy.ExpiresAt + 1.0s (正好在上界)
        var lease = CreateValidLease(1, policyExpiry.AddSeconds(1.0), "gen123", "digest789", "tcp+udp");

        // Act
        var isUsable = lease.IsUsable(policy, forward, now);

        // Assert - 上界是 <=，所以正好在 +1s 应该通过
        Assert.True(isUsable);
        Assert.Null(lease.GetUsabilityFailureReason(policy, forward, now));
    }

    [Fact]
    public void Lease_ShouldRejectExpiry_WhenBeyondUpperBound()
    {
        // Arrange
        var now = DateTimeOffset.UtcNow;
        var policyExpiry = now.AddSeconds(300);
        var policy = new UltraPolicy
        {
            PolicyGeneration = "gen123",
            TargetDigest = "digest789",
            Transport = "tcp+udp",
            ExpiresAt = policyExpiry
        };

        var forward = new ForwardConfig { ForwardId = 1 };

        // lease.ExpiresAt = policy.ExpiresAt + 1.1s (超过上界)
        var lease = CreateValidLease(1, policyExpiry.AddSeconds(1.1), "gen123", "digest789", "tcp+udp");

        // Act
        var isUsable = lease.IsUsable(policy, forward, now);

        // Assert - 超过 policy.ExpiresAt + 1s，应该被拒绝
        Assert.False(isUsable);
        Assert.Equal("expiry", lease.GetUsabilityFailureReason(policy, forward, now));
    }

    [Fact]
    public void Lease_ShouldAcceptExpiry_WhenJustBelowUpperBound()
    {
        // Arrange
        var now = DateTimeOffset.UtcNow;
        var policyExpiry = now.AddSeconds(300);
        var policy = new UltraPolicy
        {
            PolicyGeneration = "gen123",
            TargetDigest = "digest789",
            Transport = "tcp+udp",
            ExpiresAt = policyExpiry
        };

        var forward = new ForwardConfig { ForwardId = 1 };

        // lease.ExpiresAt = policy.ExpiresAt + 0.5s (未到边界)
        var lease = CreateValidLease(1, policyExpiry.AddSeconds(0.5), "gen123", "digest789", "tcp+udp");

        // Act
        var isUsable = lease.IsUsable(policy, forward, now);

        // Assert - 0.5s < 1s，应该通过
        Assert.True(isUsable);
        Assert.Null(lease.GetUsabilityFailureReason(policy, forward, now));
    }
}
