using System.Text;
using System.Text.Json;
using LeikwanBridge.Core.Models;
using Xunit;

namespace LeikwanBridge.Core.Tests.Runtime;

public sealed class ExternalUotGrantTests
{
    private const string TokenCanary = "sidecar-token-canary-0123456789";
    private const string TargetCanary = "sidecar-a.example.com:7443";

    [Fact]
    public void TryParse_EnabledGrant_ParsesTargetTokenAndKnobs()
    {
        var grant = Parse($$"""
            {"enabled":true,"sidecar_target":"{{TargetCanary}}","token":"{{TokenCanary}}",
             "queue_packets":1024,"keepalive_seconds":12,"reconnect_grace_seconds":45,"frame_payload_max":1400}
            """);

        Assert.Equal(TargetCanary, grant.SidecarTarget);
        Assert.Equal(TokenCanary, Encoding.UTF8.GetString(grant.Token));
        Assert.Equal(1024, grant.QueuePackets);
        Assert.Equal(12, grant.KeepaliveSeconds);
        Assert.Equal(45, grant.ReconnectGraceSeconds);
        Assert.Equal(1400, grant.FramePayloadMax);
    }

    [Fact]
    public void TryParse_InvalidKnobs_FallBackToTheRuntimeDefaults()
    {
        var grant = Parse("""
            {"enabled":true,"sidecar_target":"sidecar-a.example.com:7443","token":"t",
             "queue_packets":0,"keepalive_seconds":4,"reconnect_grace_seconds":"45","frame_payload_max":255}
            """);

        Assert.Equal(0, grant.QueuePackets);
        Assert.Equal(0, grant.KeepaliveSeconds);
        Assert.Equal(0, grant.ReconnectGraceSeconds);
        Assert.Equal(0, grant.FramePayloadMax);
    }

    [Theory]
    [InlineData("queue_packets", 4096, 4096)]
    [InlineData("queue_packets", 4097, 0)]
    [InlineData("keepalive_seconds", 30, 30)]
    [InlineData("keepalive_seconds", 31, 0)]
    [InlineData("reconnect_grace_seconds", 300, 300)]
    [InlineData("reconnect_grace_seconds", 301, 0)]
    [InlineData("frame_payload_max", 65507, 65507)]
    [InlineData("frame_payload_max", 65508, 0)]
    [InlineData("frame_payload_max", 255, 0)]
    public void TryParse_KnobWindowsFollowTheRuntimeContract(string knob, int value, int expected)
    {
        using var document = JsonDocument.Parse(
            $$"""{"enabled":true,"sidecar_target":"{{TargetCanary}}","token":"t","{{knob}}":{{value}}}""");

        var grant = ExternalUotGrant.TryParse(document.RootElement, out var failure);

        Assert.Equal("", failure);
        var actual = knob switch
        {
            "queue_packets" => grant!.QueuePackets,
            "keepalive_seconds" => grant!.KeepaliveSeconds,
            "reconnect_grace_seconds" => grant!.ReconnectGraceSeconds,
            _ => grant!.FramePayloadMax,
        };
        Assert.Equal(expected, actual);
    }

    [Theory]
    [InlineData("{}")]
    [InlineData("[]")]
    [InlineData("""{"sidecar_target":"sidecar-a.example.com:7443","token":"t"}""")]
    [InlineData("""{"enabled":false,"sidecar_target":"sidecar-a.example.com:7443","token":"t"}""")]
    [InlineData("""{"enabled":"true","sidecar_target":"sidecar-a.example.com:7443","token":"t"}""")]
    public void TryParse_DisabledOrAbsentGrant_ReturnsNullSilently(string json)
    {
        using var document = JsonDocument.Parse(json);

        Assert.Null(ExternalUotGrant.TryParse(document.RootElement, out var failure));
        Assert.Equal("", failure);
    }

    [Theory]
    [InlineData("""{"enabled":true,"sidecar_target":"","token":"t"}""", "sidecar_target_missing")]
    [InlineData("""{"enabled":true,"sidecar_target":"sidecar-a.example.com","token":"t"}""", "sidecar_target_invalid")]
    [InlineData("""{"enabled":true,"sidecar_target":"203.0.113.7:70000","token":"t"}""", "sidecar_target_invalid")]
    [InlineData("""{"enabled":true,"sidecar_target":"2001:db8::1:7443","token":"t"}""", "sidecar_target_invalid")]
    [InlineData("""{"enabled":true,"sidecar_target":"sidecar-a.example.com:7443"}""", "token_missing")]
    [InlineData("""{"enabled":true,"sidecar_target":"sidecar-a.example.com:7443","token":""}""", "token_missing")]
    public void TryParse_EnabledButUnusable_ReportsTheReasonOnly(string json, string expectedFailure)
    {
        using var document = JsonDocument.Parse(json);

        Assert.Null(ExternalUotGrant.TryParse(document.RootElement, out var failure));
        Assert.Equal(expectedFailure, failure);
        Assert.Matches("^[a-z_]+$", failure);
    }

    [Fact]
    public void TryParse_OversizedToken_IsRejectedAtTheRuntimeByteLimit()
    {
        var atLimit = TryParseToken(new string('t', ExternalUotGrant.MaxTokenBytes));
        var overLimit = TryParseToken(new string('t', ExternalUotGrant.MaxTokenBytes + 1));

        Assert.NotNull(atLimit.Grant);
        Assert.Null(overLimit.Grant);
        Assert.Equal("token_too_long", overLimit.Failure);
    }

    [Fact]
    public void TryParse_TokenLengthIsMeasuredInUtf8Bytes()
    {
        // Three bytes per character: 2730 characters fit in 8192 bytes, 2731 do not.
        var fits = TryParseToken(new string('\u4e2d', 2730));
        var overflows = TryParseToken(new string('\u4e2d', 2731));

        Assert.Equal(8190, fits.Grant!.Token.Length);
        Assert.NotNull(fits.Grant);
        Assert.Null(overflows.Grant);
        Assert.Equal("token_too_long", overflows.Failure);
    }

    [Theory]
    [InlineData("sidecar-a.example.com:7443", "sidecar-a.example.com", 7443)]
    [InlineData("203.0.113.7:1", "203.0.113.7", 1)]
    [InlineData("[2001:db8::1]:65535", "2001:db8::1", 65535)]
    public void TryParseSidecarTarget_AcceptsHostPortForms(string value, string expectedHost, int expectedPort)
    {
        Assert.True(ExternalUotGrant.TryParseSidecarTarget(value, out var host, out var port));
        Assert.Equal(expectedHost, host);
        Assert.Equal(expectedPort, port);
    }

    [Theory]
    [InlineData("")]
    [InlineData("sidecar-a.example.com")]
    [InlineData(":7443")]
    [InlineData("sidecar-a.example.com:0")]
    [InlineData("sidecar-a.example.com:65536")]
    [InlineData("sidecar-a.example.com:74a3")]
    [InlineData("2001:db8::1:7443")]
    [InlineData("[2001:db8::1]")]
    [InlineData("[2001:db8::1]7443")]
    [InlineData("sidecar-a.example.com:7443:extra")]
    public void TryParseSidecarTarget_RejectsNonHostPortForms(string value)
    {
        Assert.False(ExternalUotGrant.TryParseSidecarTarget(value, out var host, out var port));
        Assert.Equal("", host);
        Assert.Equal(0, port);
    }

    [Fact]
    public void ToBootstrapPayload_UsesTheRuntimeContractKeys()
    {
        var grant = Parse($$"""
            {"enabled":true,"sidecar_target":"{{TargetCanary}}","token":"{{TokenCanary}}",
             "queue_packets":1024,"keepalive_seconds":12,"reconnect_grace_seconds":45,"frame_payload_max":1400}
            """);

        var payload = grant.ToBootstrapPayload();

        string[] expectedKeys =
        [
            "enabled",
            "sidecar_target",
            "token",
            "queue_packets",
            "keepalive_seconds",
            "reconnect_grace_seconds",
            "frame_payload_max",
        ];
        Assert.Equal(expectedKeys, payload.Keys.ToArray());
        Assert.True((bool)payload["enabled"]!);
        Assert.Equal(TargetCanary, payload["sidecar_target"]);
        Assert.Equal(TokenCanary, payload["token"]);
        Assert.Equal(1024, payload["queue_packets"]);
        Assert.Equal(12, payload["keepalive_seconds"]);
        Assert.Equal(45, payload["reconnect_grace_seconds"]);
        Assert.Equal(1400, payload["frame_payload_max"]);
        Assert.False(payload.ContainsKey("delivery_mode"));

        var json = JsonSerializer.Serialize(payload);
        Assert.Contains("enabled", json, StringComparison.Ordinal);
        Assert.Contains(TargetCanary, json, StringComparison.Ordinal);
        Assert.Contains(TokenCanary, json, StringComparison.Ordinal);
    }

    [Theory]
    [InlineData("\"reliable_datagram_v1\"", "reliable_datagram_v1")]
    [InlineData("\"guaranteed_delivery\"", "guaranteed_delivery")]
    [InlineData("\"unsupported\"", "unsupported")]
    [InlineData("null", "invalid")]
    [InlineData("42", "invalid")]
    public void DeliveryMode_IsNeverSilentlyDowngraded(string value, string expected)
    {
        using var grant = Parse($$"""
            {"enabled":true,"sidecar_target":"{{TargetCanary}}","token":"{{TokenCanary}}",
             "delivery_mode":{{value}}}
            """);
        Assert.Equal(expected, grant.ToBootstrapPayload()["delivery_mode"]);
    }

    [Fact]
    public void Select_PrefersTheFirstCandidateGrant()
    {
        var plain = CreateLease();
        var carried = CreateLease();
        carried.ExternalUot = Parse($$"""
            {"enabled":true,"sidecar_target":"{{TargetCanary}}","token":"{{TokenCanary}}"}
            """);

        Assert.Null(ExternalUotGrant.Select([plain]));
        Assert.Same(carried.ExternalUot, ExternalUotGrant.Select([plain, carried]));
    }

    [Fact]
    public void Dispose_ZeroesTheTokenAndClearsTheTarget()
    {
        var grant = Parse($$"""
            {"enabled":true,"sidecar_target":"{{TargetCanary}}","token":"{{TokenCanary}}"}
            """);
        var token = grant.Token;

        grant.Dispose();

        Assert.All(token, value => Assert.Equal(0, value));
        Assert.Equal("", grant.SidecarTarget);
    }

    [Fact]
    public void UltraLeaseDispose_DisposesTheCarriedGrant()
    {
        var lease = CreateLease();
        lease.ExternalUot = Parse($$"""
            {"enabled":true,"sidecar_target":"{{TargetCanary}}","token":"{{TokenCanary}}"}
            """);
        var grant = lease.ExternalUot!;
        var token = grant.Token;

        lease.Dispose();

        Assert.Null(lease.ExternalUot);
        Assert.All(token, value => Assert.Equal(0, value));
        Assert.Equal("", grant.SidecarTarget);
    }

    [Fact]
    public void UltraLeaseSerialization_NeverEmitsTheGrant()
    {
        // The applied-state file is written through this serializer, so the grant
        // has to stay provider-material-equivalent and never reach disk.
        var lease = CreateLease();
        lease.ExternalUot = Parse($$"""
            {"enabled":true,"sidecar_target":"{{TargetCanary}}","token":"{{TokenCanary}}"}
            """);

        var json = JsonSerializer.Serialize(lease);

        Assert.Equal("{}", json);
        Assert.DoesNotContain(TokenCanary, json, StringComparison.Ordinal);
    }

    private static ExternalUotGrant Parse(string json)
    {
        using var document = JsonDocument.Parse(json);
        return ExternalUotGrant.TryParse(document.RootElement, out var failure)
            ?? throw new InvalidOperationException(failure);
    }

    private static (ExternalUotGrant? Grant, string Failure) TryParseToken(string token)
    {
        using var document = JsonDocument.Parse(
            $$"""{"enabled":true,"sidecar_target":"{{TargetCanary}}","token":"{{token}}"}""");
        var grant = ExternalUotGrant.TryParse(document.RootElement, out var failure);
        return (grant, failure);
    }

    private static UltraLease CreateLease() => new()
    {
        LeaseId = "ul2_test",
        SessionTicket = "ticket",
        ForwardId = 42,
        PolicyGeneration = new string('a', 64),
        TargetDigest = new string('c', 64),
        ExpiresAt = DateTimeOffset.UtcNow.AddMinutes(3),
        Transport = "tcp+udp",
        RateLimitMbps = 20,
        QuotaRemainingBytes = 1024,
        SelectionRef = "selection-reference-abcdefghijklmnop",
        NonceHash = new string('b', 64),
        ProviderIp = "203.0.113.20",
        ProviderPort = 5438,
        User = "line-user"u8.ToArray(),
        Password = "line-password"u8.ToArray(),
    };
}
