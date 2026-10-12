using LeikwanBridge.Core.Models;
using Serilog;

namespace LeikwanBridge.Core.Services;

/// <summary>
/// Converts an MPTCP forward config to Bond mode when the portable MPTCP relay cannot be used.
/// </summary>
public static class MptcpFallbackService
{
    public static ForwardConfig ConvertToBond(ForwardConfig forward)
    {
        Log.Information("forward {ForwardId}: converting mptcp to bond fallback",
            forward.ForwardId);

        var mptcp = forward.Mptcp ?? new MptcpConfig();

        var sidecarHost = FirstNonEmpty(
            mptcp.SidecarHost,
            mptcp.RemoteHost,
            mptcp.RelayHost
        );
        var sidecarPort = FirstPositive(
            mptcp.SidecarPort,
            mptcp.RemotePort,
            mptcp.RelayPort
        );
        var tcpToken = FirstNonEmpty(mptcp.TcpSessionToken, mptcp.SessionToken);
        var udpToken = FirstNonEmpty(mptcp.UdpSessionToken, tcpToken);
        var hasSidecarTarget = !string.IsNullOrWhiteSpace(sidecarHost) && sidecarPort > 0;
        var remoteTarget = hasSidecarTarget
            ? $"{sidecarHost}:{sidecarPort}"
            : FirstNonEmpty(forward.Target);

        var result = new ForwardConfig
        {
            ForwardId = forward.ForwardId,
            ServiceStatus = forward.ServiceStatus,
            Mode = "bond",
            BackendMode = forward.BackendMode,
            ContractVersion = forward.ContractVersion,
            ClientMode = forward.ClientMode,
            RuntimePreference = forward.RuntimePreference,
            BondIngressMode = forward.BondIngressMode,
            ActiveFamily = forward.ActiveFamily,
            AggregationEnabled = forward.AggregationEnabled,
            TargetContract = forward.TargetContract,
            SidecarRequired = forward.SidecarRequired,
            BondSidecarPointId = forward.BondSidecarPointId,
            ListenPort = forward.ListenPort,
            Target = forward.Target,
            Sni = forward.Sni,
            PathPrefix = forward.PathPrefix,
            Transport = forward.Transport,
            SpeedLimitMbps = forward.SpeedLimitMbps,
            Endpoints = forward.Endpoints,
            Bonding = new BondingConfig
            {
                Enabled = true,
                HybridMode = "quic_ws",
                SidecarHost = sidecarHost,
                SidecarPort = sidecarPort,
                AggregatorHost = sidecarHost,
                AggregatorPort = sidecarPort,
                RelayHost = sidecarHost,
                RelayPort = sidecarPort,
                RemoteTarget = remoteTarget,
                SessionToken = tcpToken,
                UdpSessionToken = udpToken,
                ChunkSize = 65536,
                MaxWindowBytes = 8388608,
                RetransmitMs = 700,
                ConnectTimeoutMs = mptcp.ConnectTimeoutMs > 0 ? mptcp.ConnectTimeoutMs : 3000,
                JoinTimeoutMs = 400,
                InteractiveChunkBytes = 16384,
                LatencyGuardBytes = 262144,
                InteractiveRttSlackMs = 30,
                QuicEnabled = hasSidecarTarget,
                QuicViaSlave = hasSidecarTarget,
                QuicDirectEnabled = false,
                ActiveLegLimit = 2,
                QuicActiveLegLimit = 2,
                WssStandbyEnabled = hasSidecarTarget,
                WssStandbyMode = "warm-passive",
                DisableLocalRateLimit = true,
                QuicServerName = sidecarHost,
                QuicInsecure = true,
                QuicPriority = 0,
                WsPriority = 10,
            },
        };

        return result;
    }

    private static string FirstNonEmpty(params string[] values)
    {
        foreach (var v in values)
            if (!string.IsNullOrWhiteSpace(v))
                return v.Trim();
        return "";
    }

    private static int FirstPositive(params int[] values)
    {
        foreach (var v in values)
            if (v > 0) return v;
        return 0;
    }
}
