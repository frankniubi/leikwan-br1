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
