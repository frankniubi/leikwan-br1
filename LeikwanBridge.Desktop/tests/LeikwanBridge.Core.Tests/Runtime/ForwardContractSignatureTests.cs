using System.Text.Json;
using LeikwanBridge.Core.Helpers;
using LeikwanBridge.Core.Models;
using Xunit;

namespace LeikwanBridge.Core.Tests.Runtime;

public sealed class ForwardContractSignatureTests
{
    [Fact]
    public void Compute_IgnoresObservationOnlyFieldsIncludingHealthStatus_ButChangesWhenRelayPortChanges()
    {
        var forward = new ForwardConfig
        {
            ForwardId = 24,
            Mode = "mptcp",
            ListenPort = 11023,
            Mptcp = new MptcpConfig { SlaveRelayHost = "relay.example.com", SlaveRelayPort = 38012 },
            Endpoints =
            [
                new EndpointConfig { EndpointId = 184, Host = "x", Port = 7001, QualityScore = 0.2, HealthStatus = "active" },
                new EndpointConfig { EndpointId = 185, Host = "y", Port = 7002, QualityScore = 0.4, HealthStatus = "active" },
            ],
        };

        var a = ForwardContractSignature.Compute(EffectiveForwardConfigBuilder.Build(forward));
        forward.Endpoints[0].QualityScore = 0.95;
        forward.Endpoints[1].HealthStatus = "ACTIVE";
        var b = ForwardContractSignature.Compute(EffectiveForwardConfigBuilder.Build(forward));
        forward.Mptcp!.SlaveRelayPort = 38013;
        var c = ForwardContractSignature.Compute(EffectiveForwardConfigBuilder.Build(forward));

        Assert.Equal(a, b);
        Assert.NotEqual(b, c);
    }

    [Fact]
    public void Compute_IsStableWhenEndpointOrderChanges()
    {
        var forward = new ForwardConfig
        {
            ForwardId = 52,
            Mode = "mptcp",
            ListenPort = 12001,
            Mptcp = new MptcpConfig { SlaveRelayHost = "relay.example.com", SlaveRelayPort = 39001 },
            Endpoints =
            [
                new EndpointConfig { EndpointId = 301, Host = "b.example.com", Port = 7002, CdnHost = "cdn-b.example.com" },
                new EndpointConfig { EndpointId = 201, Host = "a.example.com", Port = 7001, CdnHost = "cdn-a.example.com" },
            ],
        };

        var a = ForwardContractSignature.Compute(EffectiveForwardConfigBuilder.Build(forward));
        forward.Endpoints = [ forward.Endpoints[1], forward.Endpoints[0] ];
        var b = ForwardContractSignature.Compute(EffectiveForwardConfigBuilder.Build(forward));

        Assert.Equal(a, b);
    }

    [Fact]
    public void Compute_ChangesWhenRuntimeAffectingMptcpFieldsChange()
    {
        var forward = new ForwardConfig
        {
            ForwardId = 62,
            Mode = "mptcp",
            ListenPort = 11023,
            Mptcp = new MptcpConfig
            {
                SlaveRelayHost = "relay.example.com",
                SlaveRelayPort = 38012,
                UdpMode = "stream_compat",
                SocketBufferBytes = 4 * 1024 * 1024,
                ConnectTimeoutMs = 3000,
                RelayIdleTimeoutSeconds = 120,
                SubflowLimit = 2,
                Underlays =
                [
                    new UnderlayConfig
                    {
                        InterfaceName = "lw-mptcp-62-0",
                        ListenPort = 51820,
                        Legs =
                        [
                            new UnderlayLeg
                            {
                                EndpointId = 184,
                                PeerAddress = "10.203.15.202",
                                OuterTransport = "wstunnel_udp_via_slave",
                                SlavePreconnect = true,
                                PreconnectRelayPort = 38012,
                            },
                        ],
                    },
                ],
            },
            Endpoints =
            [
                new EndpointConfig { EndpointId = 184, Host = "x", Port = 7001, HealthStatus = "active" },
            ],
        };

        var a = ForwardContractSignature.Compute(EffectiveForwardConfigBuilder.Build(forward));
        forward.Mptcp.UdpMode = "packet";
        var b = ForwardContractSignature.Compute(EffectiveForwardConfigBuilder.Build(forward));
        forward.Mptcp.UdpMode = "stream_compat";
        forward.Mptcp.Underlays[0].Legs[0].PreconnectRelayPort = 38013;
        var c = ForwardContractSignature.Compute(EffectiveForwardConfigBuilder.Build(forward));

        Assert.NotEqual(a, b);
        Assert.NotEqual(a, c);
    }

    [Fact]
    public void Compute_ChangesWhenCanonicalBackendContractChanges()
    {
        var forward = new ForwardConfig
        {
            ForwardId = 47,
            Mode = "single",
            BackendMode = "leigod_mode",
            ContractVersion = 1,
            ClientMode = "single",
            RuntimePreference = "single",
            BondIngressMode = "plain_tcp_mux",
            ActiveFamily = "leigod_only",
            AggregationEnabled = false,
            TargetContract = "dedicated_forward_node",
            SidecarRequired = false,
            BondSidecarPointId = null,
            ListenPort = 11047,
            Target = "162.245.221.78:80",
            Endpoints =
            [
                new EndpointConfig { EndpointId = 316, Host = "203.0.113.12", Port = 443, HealthStatus = "active" },
            ],
        };

        var dedicated = ForwardContractSignature.Compute(EffectiveForwardConfigBuilder.Build(forward));
        forward.ClientMode = "bond";
        forward.ActiveFamily = "sidecar_aggregation";
        forward.AggregationEnabled = true;
        forward.TargetContract = "sidecar_aggregation";
        forward.SidecarRequired = true;
        forward.BondSidecarPointId = 4;
        var aggregation = ForwardContractSignature.Compute(EffectiveForwardConfigBuilder.Build(forward));

        Assert.NotEqual(dedicated, aggregation);
    }

    [Fact]
    public void Compute_UltraPolicyGenerationRotationDoesNotRestartStaticRuntime()
    {
        const string target = "198.51.100.10:443";
        var forward = new ForwardConfig
        {
            ForwardId = 158,
            Mode = "single",
            BackendMode = "ultra",
            ListenPort = 30522,
            Target = target,
            Transport = "tcp+udp",
            UltraAccess = new UltraPolicy
            {
                PolicyGeneration = new string('a', 64),
                TargetDigest = UltraPolicy.ComputeTargetDigest(target),
                Transport = "tcp+udp",
            },
        };

        var before = ForwardContractSignature.Compute(forward);
        forward.UltraAccess = new UltraPolicy
        {
            PolicyGeneration = new string('b', 64),
            TargetDigest = UltraPolicy.ComputeTargetDigest(target),
            Transport = "tcp+udp",
        };
        var after = ForwardContractSignature.Compute(forward);

        Assert.Equal(before, after);
    }

    [Fact]
    public void Compute_ChangesWhenUotSchedulerOrNativeIdentityChanges()
    {
        var forward = new ForwardConfig
        {
            ForwardId = 26,
            Mode = "bond",
            ListenPort = 11024,
        };
        var sidepath = new UdpUotSidepathConfig
        {
            EnabledForwardIds = [26],
            SchedulerMode = "feedback_drr",
            DeliveryMode = "unordered",
            WireguardAware = true,
        };

        var baseline = ForwardContractSignature.Compute(forward, sidepath, "sha-a:build-a");
        sidepath.AggregateRateBps = 200_000_000;
        var rateChanged = ForwardContractSignature.Compute(forward, sidepath, "sha-a:build-a");
        var binaryChanged = ForwardContractSignature.Compute(forward, sidepath, "sha-b:build-b");

        Assert.NotEqual(baseline, rateChanged);
        Assert.NotEqual(rateChanged, binaryChanged);
    }

    [Fact]
    public void Compute_FoldsInTheExternalUotGrantAndRotatesWithItsToken()
    {
        const string target = "198.51.100.10:443";
        var forward = new ForwardConfig
        {
            ForwardId = 158,
            Mode = "single",
            BackendMode = "ultra",
            ListenPort = 30522,
            Target = target,
            Transport = "tcp+udp",
            UltraAccess = new UltraPolicy
            {
                PolicyGeneration = new string('a', 64),
                TargetDigest = UltraPolicy.ComputeTargetDigest(target),
                Transport = "tcp+udp",
            },
        };
        var grant = CreateGrant();
        var sameGrant = CreateGrant();
        var rotatedToken = CreateGrant(token: "token-b");
        var otherSidecar = CreateGrant(sidecarTarget: "sidecar-b.example.com:7443");
        var otherKnobs = CreateGrant(queuePackets: 256);
        var reliableMode = CreateGrant(deliveryMode: "reliable_datagram_v1");

        var withoutGrant = ForwardContractSignature.Compute(forward);
        var withGrant = ForwardContractSignature.Compute(forward, externalUot: grant);

        Assert.NotEqual(withoutGrant, withGrant);
        Assert.Equal(withGrant, ForwardContractSignature.Compute(forward, externalUot: sameGrant));
        Assert.NotEqual(withGrant, ForwardContractSignature.Compute(forward, externalUot: rotatedToken));
        Assert.NotEqual(withGrant, ForwardContractSignature.Compute(forward, externalUot: otherSidecar));
        Assert.NotEqual(withGrant, ForwardContractSignature.Compute(forward, externalUot: otherKnobs));
        Assert.NotEqual(withGrant, ForwardContractSignature.Compute(forward, externalUot: reliableMode));
        Assert.DoesNotContain("token-a", withGrant, StringComparison.Ordinal);
    }

    private static ExternalUotGrant CreateGrant(
        string sidecarTarget = "sidecar-a.example.com:7443",
        string token = "token-a",
        int queuePackets = 512,
        string deliveryMode = "raw")
    {
        using var document = JsonDocument.Parse(
            $$"""{"enabled":true,"sidecar_target":"{{sidecarTarget}}","token":"{{token}}","queue_packets":{{queuePackets}},"delivery_mode":"{{deliveryMode}}"}""");
        return ExternalUotGrant.TryParse(document.RootElement, out var failure)
            ?? throw new InvalidOperationException(failure);
    }

    [Fact]
    public void AppliedForwardState_RoundTripsContractSignature_AndEndpointClonePreservesHealthStatus()
    {
        var endpoint = new EndpointConfig
        {
            EndpointId = 184,
            Host = "8.163.90.132",
            Port = 7001,
            HealthStatus = "active",
            HealthRole = "healthy",
            QualityScore = 0.9,
        };

        var clone = endpoint.Clone();
        Assert.Equal("active", clone.HealthStatus);

        var leg = new UnderlayLeg
        {
            EndpointId = 184,
            SlavePreconnect = true,
            PreconnectRelayPort = 38012,
            HealthStatus = "active",
        };

        var legJson = JsonSerializer.Serialize(leg);
        Assert.Contains("\"slave_preconnect\":true", legJson);
        Assert.Contains("\"preconnect_relay_port\":38012", legJson);
        Assert.Contains("\"health_status\":\"active\"", legJson);

        var state = new AppliedForwardState
        {
            ForwardId = 26,
            Mode = "bond",
            ContractSignature = "abc123",
            LastApplyReason = "config_sync",
        };

        var json = JsonSerializer.Serialize(state);
        Assert.Contains("\"contract_signature\":\"abc123\"", json);
        Assert.Contains("\"last_apply_reason\":\"config_sync\"", json);
    }
}
