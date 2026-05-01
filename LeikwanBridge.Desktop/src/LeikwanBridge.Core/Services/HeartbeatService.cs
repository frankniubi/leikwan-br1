using LeikwanBridge.Core.Models;
using Serilog;

namespace LeikwanBridge.Core.Services;

/// <summary>
/// Sends periodic heartbeats with usage info.
/// </summary>
public sealed class HeartbeatService
{
    private readonly MasterApiClient _api;
    private readonly StateManager _stateManager;
    private readonly string _wireguardPublicKey;
    private readonly int _wireguardListenPort;
    private readonly int _wireguardFallbackTcpPort;

    public HeartbeatService(
        MasterApiClient api,
        StateManager stateManager,
        string wireguardPublicKey = "",
        int wireguardListenPort = 0,
        int wireguardFallbackTcpPort = 0)
    {
        _api = api;
        _stateManager = stateManager;
        _wireguardPublicKey = wireguardPublicKey;
        _wireguardListenPort = wireguardListenPort;
        _wireguardFallbackTcpPort = wireguardFallbackTcpPort;
    }

    public async Task SendHeartbeatAsync(CancellationToken ct = default)
    {
        var usage = _stateManager.LoadUsageState();
        long bytesIn = usage.TotalBytesIn - usage.LastReportedBytesIn;
        long bytesOut = usage.TotalBytesOut - usage.LastReportedBytesOut;

        var payload = new HeartbeatPayload
        {
            AgentVersion = MasterApiClient.AgentVersion,
            UsageBytesIn = Math.Max(0, bytesIn),
            UsageBytesOut = Math.Max(0, bytesOut),
            SupportsMptcp = false, // Windows doesn't support kernel MPTCP
            WireguardPublicKey = _wireguardPublicKey,
            WireguardListenPort = _wireguardListenPort,
            WireguardFallbackTcpPort = _wireguardFallbackTcpPort,
        };

        await _api.SendHeartbeatAsync(payload, ct);

        usage.LastReportedBytesIn = usage.TotalBytesIn;
        usage.LastReportedBytesOut = usage.TotalBytesOut;
        _stateManager.SaveUsageState(usage);

        Log.Debug("heartbeat sent: in={BytesIn} out={BytesOut}", bytesIn, bytesOut);
    }
}
