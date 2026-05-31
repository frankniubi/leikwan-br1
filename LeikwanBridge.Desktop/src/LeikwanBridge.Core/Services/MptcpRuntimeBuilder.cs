using System.Net;
using System.Net.Sockets;
using System.Text.Json;
using LeikwanBridge.Core.Models;

namespace LeikwanBridge.Core.Services;

/// <summary>
/// Builds runtime process specs for Windows-compatible MPTCP forwards.
///
/// Windows does not expose Linux kernel MPTCP sockets. The bundled Rust
/// relay still understands the MPTCP sidecar protocol and uses its
/// cross-platform TCP fallback on Windows, so the same panel-published MPTCP
/// forward can run instead of being filtered out. This is compatibility MPTCP,
/// not native multi-subflow kernel MPTCP.
/// </summary>
public sealed class MptcpRuntimeBuilder
{
    private readonly AgentConfig _config;

    public MptcpRuntimeBuilder(AgentConfig config) => _config = config;

    public BondRuntimeBuilder.RuntimeSpec Build(ForwardConfig forward)
    {
        var mptcp = forward.Mptcp ?? throw new InvalidOperationException("mptcp mode missing mptcp config");
        // Windows cannot bring up the panel-published Linux/WireGuard underlay.
        // If a slave pre-connect relay is available on a Windows-visible address,
        // prefer it — the relay handles MPTCP to sidecar on our behalf. When the
        // relay host is only a private underlay address, Windows must fall back to
        // the public relay endpoint instead.
        var slaveRelayHost = (mptcp.SlaveRelayHost ?? "").Trim();
        var slaveRelayPort = mptcp.SlaveRelayPort;
        var useSlaveRelay = ShouldUseWindowsVisibleSlaveRelay(slaveRelayHost, slaveRelayPort);
        string remoteHost;
        int remotePort;
        if (useSlaveRelay)
        {
            remoteHost = slaveRelayHost;
            remotePort = slaveRelayPort;
        }
        else
        {
            // Fallback: use the public relay endpoint with TCP fallback.
            remoteHost = FirstNonEmpty(mptcp.RelayHost, mptcp.RemoteHost, mptcp.SidecarHost);
            remotePort = FirstPositive(mptcp.RelayPort, mptcp.RemotePort, mptcp.SidecarPort);
            slaveRelayHost = string.Empty;
            slaveRelayPort = 0;
        }
        if (string.IsNullOrWhiteSpace(remoteHost) || remotePort <= 0)
            throw new InvalidOperationException("mptcp mode missing sidecar host/port");

        var transport = (forward.Transport ?? "tcp").Trim().ToLowerInvariant();
        var tcpSessionToken = FirstNonEmpty(mptcp.TcpSessionToken, mptcp.SessionToken);
        var udpSessionToken = FirstNonEmpty(mptcp.UdpSessionToken, tcpSessionToken);
        if ((transport is "tcp" or "tcp+udp") && string.IsNullOrWhiteSpace(tcpSessionToken))
            throw new InvalidOperationException("mptcp mode missing mptcp.tcp_session_token");
        if ((transport is "udp" or "tcp+udp") && string.IsNullOrWhiteSpace(udpSessionToken))
            throw new InvalidOperationException("mptcp mode missing mptcp.udp_session_token");

        var runtimeConfigPath = Path.Combine(_config.GetEffectiveStateDir(), "runtime", $"mptcp-forward-{forward.ForwardId}.json");
        var statsPath = Path.Combine(_config.GetEffectiveStateDir(), "runtime", $"mptcp-forward-{forward.ForwardId}.stats.json");
        var runtimePayload = BuildRuntimePayload(forward, mptcp, remoteHost, remotePort, tcpSessionToken, udpSessionToken, statsPath);

        var generatedFiles = new List<BondRuntimeBuilder.GeneratedFile>
        {
            new(
                runtimeConfigPath,
                JsonSerializer.Serialize(runtimePayload, new JsonSerializerOptions { WriteIndented = true })),
        };

        var processes = new List<BondRuntimeBuilder.RuntimeProcess>
        {
            new(
                "mptcp-runtime",
                "mptcp-runtime",
                _config.GetEffectiveMptcpRelayBin(),
                $"client --config \"{runtimeConfigPath}\"",
                RequiredForReadiness: true,
                CriticalForForwardHealth: true),
        };

        return new BondRuntimeBuilder.RuntimeSpec(
            "mptcp",
            "tcp_fallback",
            forward.ListenPort,
            [forward.ListenPort],
            processes,
            generatedFiles);
    }

    private static Dictionary<string, object?> BuildRuntimePayload(
        ForwardConfig forward,
        MptcpConfig mptcp,
        string remoteHost,
        int remotePort,
        string tcpSessionToken,
        string udpSessionToken,
        string statsPath)
    {
        var transport = string.IsNullOrWhiteSpace(forward.Transport) ? "tcp" : forward.Transport.Trim().ToLowerInvariant();
        var socketBufferBytes = mptcp.SocketBufferBytes > 0 ? mptcp.SocketBufferBytes : 8 * 1024 * 1024;
        var connectTimeoutMs = mptcp.ConnectTimeoutMs > 0 ? mptcp.ConnectTimeoutMs : 3000;
        var idleTimeoutSeconds = mptcp.RelayIdleTimeoutSeconds > 0 ? mptcp.RelayIdleTimeoutSeconds : 120;
        var subflowLimit = mptcp.SubflowLimit > 0 ? mptcp.SubflowLimit : Math.Max(1, mptcp.MptcpEndpoints.Count);
        if (subflowLimit <= 0)
            subflowLimit = 1;

        var effectiveSlaveRelayHost = ShouldUseWindowsVisibleSlaveRelay(mptcp.SlaveRelayHost, mptcp.SlaveRelayPort)
            ? FirstNonEmpty(mptcp.SlaveRelayHost)
            : string.Empty;
        var effectiveSlaveRelayPort = string.IsNullOrWhiteSpace(effectiveSlaveRelayHost)
            ? 0
            : mptcp.SlaveRelayPort;

        return new Dictionary<string, object?>
        {
            ["forward_id"] = forward.ForwardId,
            ["listen_host"] = "0.0.0.0",
            ["listen_port"] = forward.ListenPort,
            ["transport"] = transport,
            ["remote_host"] = remoteHost,
            ["remote_port"] = remotePort,
            ["relay_host"] = FirstNonEmpty(mptcp.RelayHost, remoteHost),
            ["relay_port"] = FirstPositive(mptcp.RelayPort, remotePort),
            ["tcp_session_token"] = tcpSessionToken,
            ["udp_session_token"] = udpSessionToken,
            ["udp_mode"] = FirstNonEmpty(mptcp.UdpMode, "stream_compat"),
            ["tcp_congestion_control"] = FirstNonEmpty(mptcp.TcpCongestionControl, ""),
            ["socket_buffer_bytes"] = socketBufferBytes,
            ["connect_timeout_ms"] = connectTimeoutMs,
            ["relay_idle_timeout_seconds"] = idleTimeoutSeconds,
            ["speed_limit_mbps"] = forward.SpeedLimitMbps is > 0 ? forward.SpeedLimitMbps.Value : 0,
            ["allow_tcp_fallback"] = true,
            // Windows compatibility mode must not try Linux-only endpoint policy commands.
            ["manage_mptcp_endpoints"] = false,
            ["mptcp_endpoint_device"] = mptcp.MptcpEndpointDevice,
            ["mptcp_endpoint_id_base"] = mptcp.MptcpEndpointIdBase,
            ["mptcp_endpoints"] = mptcp.MptcpEndpoints,
            ["subflow_limit"] = subflowLimit,
            ["underlay"] = mptcp.Underlay,
            ["underlays"] = mptcp.Underlays,
            ["stats_path"] = statsPath,
            ["windows_compatibility_mode"] = true,
            // Slave pre-connect relay: when available on a Windows-visible address,
            // Windows client connects via plain TCP to the slave relay.
            ["slave_relay_host"] = effectiveSlaveRelayHost,
            ["slave_relay_port"] = effectiveSlaveRelayPort,
        };
    }

    private static bool ShouldUseWindowsVisibleSlaveRelay(string host, int port)
    {
        if (string.IsNullOrWhiteSpace(host) || port <= 0)
            return false;

        if (!IPAddress.TryParse(host, out var ipAddress))
            return true;

        if (IPAddress.IsLoopback(ipAddress))
            return true;

        if (ipAddress.AddressFamily != AddressFamily.InterNetwork)
            return true;

        var bytes = ipAddress.GetAddressBytes();
        if (bytes[0] == 10)
            return false;
        if (bytes[0] == 127)
            return false;
        if (bytes[0] == 169 && bytes[1] == 254)
            return false;
        if (bytes[0] == 172 && bytes[1] >= 16 && bytes[1] <= 31)
            return false;
        if (bytes[0] == 192 && bytes[1] == 168)
            return false;

        return true;
    }

    private static string FirstNonEmpty(params string?[] values)
    {
        foreach (var value in values)
        {
            if (!string.IsNullOrWhiteSpace(value))
                return value.Trim();
        }

        return "";
    }

    private static int FirstPositive(params int[] values)
    {
        foreach (var value in values)
        {
            if (value > 0)
                return value;
        }

        return 0;
    }
}


