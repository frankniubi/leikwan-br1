using System.Text;
using System.Text.Json;
using System.Net;
using LeikwanBridge.Core.Models;
using LeikwanBridge.Core.Services;
using Xunit;

namespace LeikwanBridge.Core.Tests.Runtime;

public sealed class ExternalUotPolicyGrantCacheTests
{
    [Fact]
    public async Task MasterApiCarriesPullGrantIntoLeaseAndRetainsItAcross304()
    {
        using var handler = new PolicyOnlyGrantHandler();
        using var http = new HttpClient(handler);
        using var api = new MasterApiClient("https://panel.example.test", "fixture-agent", "fixture-device",
            httpClientFactory: () => http, ownsHttpClient: false);
        var pull = await api.PullConfigsAsync();
        var forward = Assert.Single(pull.Forwards);
        var originalPolicyExpiry = forward.UltraAccess!.ExpiresAt;
        var unchanged = await api.PullConfigsAsync("revision");
        Assert.True(unchanged.NotModified);
        var leases = await api.ResolveUltraLeasesAsync(forward);
        using var lease = Assert.Single(leases);
        Assert.Equal("reliable_datagram_v1", lease.ExternalUot?.DeliveryMode);
        Assert.Equal("fixture-sidecar-token", Encoding.UTF8.GetString(lease.ExternalUot!.Token));
        Assert.True(forward.UltraAccess!.ExpiresAt > originalPolicyExpiry);
        Assert.Equal(lease.PolicyExpiresAt, forward.UltraAccess.ExpiresAt);
        Assert.Equal(lease.PolicyRenewAfter, forward.UltraAccess.RenewAfter);
        Assert.True(lease.IsUsable(forward.UltraAccess, forward, DateTimeOffset.UtcNow));
    }

    [Fact]
    public async Task MismatchedLeaseGenerationCannotExtendCachedPolicyExpiry()
    {
        using var handler = new PolicyOnlyGrantHandler(mismatchedLeaseGeneration: true);
        using var http = new HttpClient(handler);
        using var api = new MasterApiClient("https://panel.example.test", "fixture-agent", "fixture-device",
            httpClientFactory: () => http, ownsHttpClient: false);

        var pull = await api.PullConfigsAsync();
        var forward = Assert.Single(pull.Forwards);
        var originalPolicyExpiry = forward.UltraAccess!.ExpiresAt;

        var leases = await api.ResolveUltraLeasesAsync(forward);

        Assert.Empty(leases);
        Assert.Equal(originalPolicyExpiry, forward.UltraAccess!.ExpiresAt);
    }

    private sealed class PolicyOnlyGrantHandler : HttpMessageHandler
    {
        private readonly bool _mismatchedLeaseGeneration;
        private int _pulls;

        public PolicyOnlyGrantHandler(bool mismatchedLeaseGeneration = false)
        {
            _mismatchedLeaseGeneration = mismatchedLeaseGeneration;
        }

        protected override Task<HttpResponseMessage> SendAsync(HttpRequestMessage request, CancellationToken ct)
        {
            Assert.Equal("fixture-device", Assert.Single(request.Headers.GetValues("X-Device-Id")));
            var generation = new string('a', 64);
            var digest = UltraPolicy.ComputeTargetDigest("192.0.2.1:10001");
            var now = DateTimeOffset.UtcNow;
            object payload;
            if (request.Method == HttpMethod.Get)
            {
                if (++_pulls > 1)
                    return Task.FromResult(new HttpResponseMessage(HttpStatusCode.NotModified));
                payload = new
                {
                    ok = true,
                    forwards = new[]
                    {
                        new
                        {
                            forward_id = 170, target = "192.0.2.1:10001", backend_mode = "ultra", transport = "tcp+udp",
                            ultra_access = new
                            {
                                protocol = UltraPolicy.Protocol, forward_id = 170, policy_generation = generation,
                                session_ticket = "fixture-ticket", target_digest = digest, transport = "tcp+udp",
                                issued_at = now, renew_after = now.AddMinutes(1), expires_at = now.AddMinutes(4),
                                service_state = "active", quota_remaining_bytes = 1048576,
                                external_uot = JsonSerializer.Deserialize<JsonElement>(Valid),
                            },
                        },
                    },
                };
            }
            else
            {
                payload = new
                {
                    ok = true,
                    leases = new[]
                    {
                        new
                        {
                            protocol = UltraPolicy.Protocol, forward_id = 170,
                            policy_generation = _mismatchedLeaseGeneration ? new string('c', 64) : generation,
                            session_ticket = "fixture-ticket", target_digest = digest, transport = "tcp+udp",
                            policy_expires_at = now.AddMinutes(4.5), policy_renew_after = now.AddMinutes(1),
                            lease_id = "fixture-lease", expires_at = now.AddMinutes(3), quota_remaining_bytes = 1048576, rate_limit_mbps = 0,
                            selection_ref = new string('r', 32), nonce_hash = new string('b', 64),
                            endpoint = new { ip = "203.0.113.20", port = 5438 },
                            credential = new { user = "fixture-user", password = "fixture-password" },
                        },
                    },
                };
            }
            return Task.FromResult(new HttpResponseMessage(HttpStatusCode.OK)
            {
                Content = new StringContent(JsonSerializer.Serialize(payload), Encoding.UTF8, "application/json"),
            });
        }
    }

    private static ForwardConfig Forward(string generation = "generation", bool expired = false) => new()
    {
        ForwardId = 170,
        Target = "192.0.2.1:10001",
        UltraAccess = new UltraPolicy
        {
            ForwardId = 170,
            PolicyGeneration = generation,
            TargetDigest = UltraPolicy.ComputeTargetDigest("192.0.2.1:10001"),
            ExpiresAt = DateTimeOffset.UtcNow.AddMinutes(expired ? -1 : 2),
        },
    };

    private static UltraLease Lease(ForwardConfig forward) => new()
    {
        ForwardId = forward.ForwardId,
        PolicyGeneration = forward.UltraAccess!.PolicyGeneration,
        TargetDigest = forward.UltraAccess.TargetDigest,
    };

    private static void Fill(ExternalUotPolicyGrantCache cache, ForwardConfig forward, string grant)
    {
        using var doc = JsonDocument.Parse(
            "{\"forwards\":[{\"forward_id\":170,\"ultra_access\":{\"external_uot\":" + grant + "}}]}");
        cache.Replace(new PullResponse { Ok = true, Forwards = [forward] }, doc.RootElement);
    }

    private const string Valid = """
        {"enabled":true,"sidecar_target":"sidecar.example:7443","token":"fixture-sidecar-token",
         "delivery_mode":"reliable_datagram_v1","queue_packets":4096}
        """;

    [Fact]
    public void PolicyGrantIsInheritedWithoutSerializingCredentials()
    {
        using var cache = new ExternalUotPolicyGrantCache();
        var forward = Forward();
        Fill(cache, forward, Valid);
        using var lease = Lease(forward);
        Assert.True(cache.TryAttach(forward, lease));
        Assert.NotNull(lease.ExternalUot);
        Assert.Equal("reliable_datagram_v1", lease.ExternalUot.DeliveryMode);
        Assert.Equal("fixture-sidecar-token", Encoding.UTF8.GetString(lease.ExternalUot.Token));
        Assert.DoesNotContain("fixture-sidecar-token", JsonSerializer.Serialize(forward));
        Assert.Equal("{}", JsonSerializer.Serialize(lease));
        Assert.Equal("{}", JsonSerializer.Serialize(cache));
    }

    [Fact]
    public void LeaseCopiesSurviveCacheRotationAndAreIndependentlyZeroed()
    {
        using var cache = new ExternalUotPolicyGrantCache();
        var forward = Forward();
        Fill(cache, forward, Valid);
        using var first = Lease(forward);
        using var second = Lease(forward);
        Assert.True(cache.TryAttach(forward, first));
        Assert.True(cache.TryAttach(forward, second));
        var firstBytes = first.ExternalUot!.Token;
        var secondBytes = second.ExternalUot!.Token;
        Fill(cache, forward, Valid);
        first.Dispose();
        Assert.All(firstBytes, value => Assert.Equal(0, value));
        Assert.Equal("fixture-sidecar-token", Encoding.UTF8.GetString(secondBytes));
    }

    [Theory]
    [InlineData("""{"enabled":true,"sidecar_target":"sidecar.example:7443"}""")]
    [InlineData("""{"enabled":true,"sidecar_target":"sidecar.example:7443","token":"fixture","target_digest":"wrong"}""")]
    public void MalformedRequiredGrantDoesNotFallBackToNativeUdp(string grant)
    {
        using var cache = new ExternalUotPolicyGrantCache();
        var forward = Forward();
        Fill(cache, forward, grant);
        using var lease = Lease(forward);
        Assert.False(cache.TryAttach(forward, lease));
        Assert.Null(lease.ExternalUot);
    }

    [Fact]
    public void OldGenerationAndExpiredPolicyCannotReuseGrant()
    {
        using var cache = new ExternalUotPolicyGrantCache();
        var old = Forward();
        Fill(cache, old, Valid);
        var renewed = Forward("different-generation");
        using var lease = Lease(renewed);
        Assert.False(cache.TryAttach(renewed, lease));
        var expired = Forward(expired: true);
        Fill(cache, expired, Valid);
        using var stale = Lease(expired);
        Assert.False(cache.TryAttach(expired, stale));
    }

    [Fact]
    public void OmittedPolicyRetainsOnlyUnexpiredSameTargetGrant()
    {
        using var cache = new ExternalUotPolicyGrantCache();
        var forward = Forward();
        Fill(cache, forward, Valid);
        var omitted = new ForwardConfig { ForwardId = forward.ForwardId, Target = forward.Target };
        using var document = JsonDocument.Parse("""{"forwards":[{"forward_id":170}]}""");
        cache.Replace(new PullResponse { Ok = true, Forwards = [omitted] }, document.RootElement);
        using var retained = Lease(forward);
        Assert.True(cache.TryAttach(forward, retained));
        Assert.NotNull(retained.ExternalUot);
        omitted.Target = "192.0.2.2:10001";
        cache.Replace(new PullResponse { Ok = true, Forwards = [omitted] }, document.RootElement);
        using var moved = Lease(forward);
        Assert.False(cache.TryAttach(forward, moved));
    }

    [Fact]
    public void FullPullRemovalAndDisposalFailClosed()
    {
        var cache = new ExternalUotPolicyGrantCache();
        var forward = Forward();
        Fill(cache, forward, Valid);
        using var empty = JsonDocument.Parse("""{"forwards":[]}""");
        cache.Replace(new PullResponse { Ok = true }, empty.RootElement);
        using var lease = Lease(forward);
        Assert.False(cache.TryAttach(forward, lease));
        cache.Dispose();
        Assert.False(cache.TryAttach(forward, lease));
    }
}
