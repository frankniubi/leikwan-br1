using System.Text.Json;
using LeikwanBridge.Core.Models;
using LeikwanBridge.Core.Services;
using Xunit;

namespace LeikwanBridge.Core.Tests.Runtime;

public sealed class UltraLeaseClientTests
{
    [Fact]
    public void PolicyIsV2AndContainsNoProviderMaterial()
    {
        var policy = new UltraPolicy
        {
            ProtocolValue = UltraPolicy.Protocol,
            ForwardId = 42,
            PolicyGeneration = new string('a', 64),
            SessionTicket = "opaque-ticket",
            TargetDigest = UltraPolicy.ComputeTargetDigest("203.0.113.10:443"),
            ExpiresAt = DateTimeOffset.UtcNow.AddMinutes(1),
            Transport = "tcp",
            QuotaRemainingBytes = 1024,
        };
        var json = JsonSerializer.Serialize(policy);
        Assert.Contains("leikwan-ultra-policy-v2", json);
        Assert.DoesNotContain("credential", json, StringComparison.OrdinalIgnoreCase);
        Assert.DoesNotContain("line_ips", json, StringComparison.OrdinalIgnoreCase);
        Assert.DoesNotContain("password", json, StringComparison.OrdinalIgnoreCase);
    }

    [Fact]
    public void LeaseWireNameAndFrameAreBounded()
    {
        Assert.Equal("leikwan-ultra-forward-42", UltraLeaseClient.PipeName(42));
        Assert.Equal(64 * 1024, UltraLeaseClient.MaxFrameBytes);
    }
}
