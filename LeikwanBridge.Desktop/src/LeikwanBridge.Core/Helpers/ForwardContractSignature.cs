using System.Security.Cryptography;
using System.Text;
using System.Text.Json;
using LeikwanBridge.Core.Models;

namespace LeikwanBridge.Core.Helpers;

public static class ForwardContractSignature
{
    public static string Compute(
        ForwardConfig forward,
        UdpUotSidepathConfig? udpUotSidepath = null,
        string uotBinaryIdentity = "",
        ExternalUotGrant? externalUot = null)
    {
        var payload = new
        {
            forward.ForwardId,
            forward.Mode,
            forward.ListenPort,
            forward.Target,
            forward.Sni,
            forward.PathPrefix,
            forward.Transport,
            forward.BackendMode,
            forward.ContractVersion,
            forward.ClientMode,
            forward.RuntimePreference,
            forward.ForwardCombinationDirection,
            forward.NativeOutboundEnabled,
            forward.NativeDirection,
            forward.BondIngressMode,
            forward.ActiveFamily,
            forward.AggregationEnabled,
            forward.TargetContract,
            forward.SidecarRequired,
            forward.BondSidecarPointId,
            forward.SpeedLimitMbps,
            UltraTargetDigest = forward.UltraAccess?.TargetDigest ?? "",
            UltraTransport = forward.UltraAccess?.Transport ?? "",
            UdpUotSidepath = udpUotSidepath is null ? null : new
            {
                EnabledForwardIds = udpUotSidepath.EnabledForwardIds.OrderBy(id => id).ToList(),
                udpUotSidepath.ShadowRouteSuffix,
                udpUotSidepath.SidecarPort,
                udpUotSidepath.ReorderWindowPackets,
                udpUotSidepath.ReorderWindowBytes,
                udpUotSidepath.ReorderTimeoutMs,
                udpUotSidepath.DeliveryMode,
                udpUotSidepath.WireguardAware,
                udpUotSidepath.SchedulerMode,
                udpUotSidepath.AggregateRateBps,
                udpUotSidepath.QueueTargetMs,
                udpUotSidepath.QueueDeadlineMs,
                udpUotSidepath.GlobalQueueBytes,
                udpUotSidepath.GlobalQueuePackets,
                udpUotSidepath.PerSessionQueueBytes,
                udpUotSidepath.PerSessionQueuePackets,
                udpUotSidepath.DrrQuantumBytes,
                udpUotSidepath.ChunkMinBytes,
                udpUotSidepath.ChunkTargetBytes,
                udpUotSidepath.ChunkMaxBytes,
                udpUotSidepath.MinLegWindowBytes,
                udpUotSidepath.MaxLegWindowBytes,
                udpUotSidepath.MaxTotalOutstandingBytes,
                udpUotSidepath.ProgressIntervalBytes,
                udpUotSidepath.ProgressIntervalFrames,
                udpUotSidepath.ProgressIntervalMs,
                udpUotSidepath.FinishSkewBudgetMs,
                udpUotSidepath.ProbeIntervalMs,
                udpUotSidepath.MaxSessions,
                udpUotSidepath.ControlQueuePackets,
            },
            UotBinaryIdentity = uotBinaryIdentity,
            // The external UoT grant is bootstrap-only for the runtime: the
            // sidecar endpoint and knobs feed the startup config and the token
            // is handed over once at start. A rotated token therefore has to
            // restart the runtime, and this field is what makes the applied
            // signature change when it does. Only a fingerprint of the token
            // is folded in because the signature is persisted to disk.
            UotExternalGrant = externalUot is null ? null : new
            {
                externalUot.SidecarTarget,
                externalUot.QueuePackets,
                externalUot.KeepaliveSeconds,
                externalUot.ReconnectGraceSeconds,
                externalUot.FramePayloadMax,
                externalUot.DeliveryMode,
                TokenFingerprint = externalUot.TokenFingerprint(),
            },
            Bonding = forward.Bonding is null ? null : new
            {
                forward.Bonding.FrontendMode,
                forward.Bonding.StrategyLocked,
                forward.Bonding.IngressMode,
                forward.Bonding.RouteKey,
                forward.Bonding.SessionToken,
                forward.Bonding.UdpSessionToken,
                forward.Bonding.PolicyMode,
                forward.Bonding.WsPriority,
                forward.Bonding.RemoteTarget,
            },
            Mptcp = forward.Mptcp is null ? null : new
            {
                forward.Mptcp.SlaveRelayHost,
                forward.Mptcp.SlaveRelayPort,
                forward.Mptcp.SidecarHost,
                forward.Mptcp.SidecarPort,
                forward.Mptcp.RemoteHost,
                forward.Mptcp.RemotePort,
                forward.Mptcp.RelayHost,
                forward.Mptcp.RelayPort,
                forward.Mptcp.TcpSessionToken,
                forward.Mptcp.UdpSessionToken,
                forward.Mptcp.SessionToken,
                forward.Mptcp.AllowTcpFallback,
                forward.Mptcp.ConnectTimeoutMs,
                forward.Mptcp.RelayIdleTimeoutSeconds,
                forward.Mptcp.SubflowLimit,
                forward.Mptcp.ManageMptcpEndpoints,
                forward.Mptcp.MptcpEndpointDevice,
                forward.Mptcp.MptcpEndpointIdBase,
                forward.Mptcp.UdpMode,
                forward.Mptcp.TcpCongestionControl,
                forward.Mptcp.SocketBufferBytes,
                MptcpEndpoints = forward.Mptcp.MptcpEndpoints,
                Underlay = forward.Mptcp.Underlay is null ? null : new
                {
                    forward.Mptcp.Underlay.Type,
                    forward.Mptcp.Underlay.Mode,
                    forward.Mptcp.Underlay.InterfaceName,
                    forward.Mptcp.Underlay.ListenPort,
                    Legs = forward.Mptcp.Underlay.Legs
                        .OrderBy(leg => leg.EndpointId)
                        .ThenBy(leg => leg.PreconnectRelayPort)
                        .Select(leg => new
                        {
                            leg.EndpointId,
                            leg.LocalAddress,
                            leg.PeerAddress,
                            leg.MptcpRemoteAddress,
                            leg.PeerPublicKey,
                            leg.PeerPublicHost,
                            leg.PeerUdpPort,
                            leg.OuterTransport,
                            leg.PeerTcpFallbackPort,
                            leg.LocalUotUdpPort,
                            leg.SlavePreconnect,
                            leg.PreconnectRelayPort,
                        })
                        .ToList(),
                },
                Underlays = forward.Mptcp.Underlays
                    .OrderBy(underlay => underlay.InterfaceName, StringComparer.Ordinal)
                    .ThenBy(underlay => underlay.ListenPort)
                    .Select(underlay => new
                    {
                        underlay.Type,
                        underlay.Mode,
                        underlay.InterfaceName,
                        underlay.ListenPort,
                        Legs = underlay.Legs
                            .OrderBy(leg => leg.EndpointId)
                            .ThenBy(leg => leg.PreconnectRelayPort)
                            .Select(leg => new
                            {
                                leg.EndpointId,
                                leg.LocalAddress,
                                leg.PeerAddress,
                                leg.MptcpRemoteAddress,
                                leg.PeerPublicKey,
                                leg.PeerPublicHost,
                                leg.PeerUdpPort,
                                leg.OuterTransport,
                                leg.PeerTcpFallbackPort,
                                leg.LocalUotUdpPort,
                                leg.SlavePreconnect,
                                leg.PreconnectRelayPort,
                            })
                            .ToList(),
                    })
                    .ToList(),
            },
            Endpoints = forward.Endpoints
                .OrderBy(endpoint => endpoint.EndpointId)
                .ThenBy(endpoint => endpoint.Host, StringComparer.Ordinal)
                .ThenBy(endpoint => endpoint.Port)
                .Select(endpoint => new
                {
                    endpoint.EndpointId,
                    endpoint.Host,
                    endpoint.Port,
                    endpoint.CdnHost,
                    endpoint.RouteKey,
                    endpoint.IngressProtocol,
                    endpoint.BackendProtocol,
                    endpoint.IngressAuthToken,
                    endpoint.SupportsQuicLeg,
                })
                .ToList(),
        };

        var json = JsonSerializer.Serialize(payload);
        // Toggling inbound proxying rebinds the listener, so it must change the
        // signature; folded in only when enabled so existing signatures stay stable.
        if (forward.AllowInboundProxy)
            json += "|allow_inbound_proxy";
        var bytes = SHA256.HashData(Encoding.UTF8.GetBytes(json));
        return Convert.ToHexString(bytes).ToLowerInvariant();
    }
}
