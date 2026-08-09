using System.Text.Json.Serialization;
using System.Text.Json;

namespace LeikwanBridge.Core.Models;

public sealed class AgentConfig
{
    [JsonPropertyName("master_url")]
    public string MasterUrl { get; set; } = "";

    [JsonPropertyName("agent_token")]
    public string AgentToken { get; set; } = "";

    [JsonPropertyName("client_id")]
    public int ClientId { get; set; }

    [JsonPropertyName("desktop_session_token")]
    public string DesktopSessionToken { get; set; } = "";

    [JsonPropertyName("signed_in_user_id")]
    public int SignedInUserId { get; set; }

    [JsonPropertyName("signed_in_username")]
    public string SignedInUsername { get; set; } = "";

    [JsonPropertyName("signed_in_email")]
    public string SignedInEmail { get; set; } = "";

    [JsonPropertyName("available_clients")]
    public List<DesktopClientChoice> AvailableClients { get; set; } = [];

    [JsonPropertyName("active_client_id")]
    public int ActiveClientId { get; set; }

    [JsonPropertyName("active_client_name")]
    public string ActiveClientName { get; set; } = "";

    [JsonPropertyName("pending_update_version")]
    public string PendingUpdateVersion { get; set; } = "";

    [JsonPropertyName("pending_update_installer_path")]
    public string PendingUpdateInstallerPath { get; set; } = "";

    [JsonPropertyName("pending_update_sha256")]
    public string PendingUpdateSha256 { get; set; } = "";

    [JsonPropertyName("pending_update_downloaded_at")]
    public string PendingUpdateDownloadedAt { get; set; } = "";

    [JsonPropertyName("last_update_check_at")]
    public string LastUpdateCheckAt { get; set; } = "";

    [JsonPropertyName("state_dir")]
    public string StateDir { get; set; } = "";

    [JsonPropertyName("poll_interval_seconds")]
    public int PollIntervalSeconds { get; set; } = 30;

    [JsonPropertyName("health_check_interval_seconds")]
    public int HealthCheckIntervalSeconds { get; set; } = 20;

    [JsonPropertyName("log_level")]
    public string LogLevel { get; set; } = "INFO";

    [JsonPropertyName("bond_bin")]
    public string BondBin { get; set; } = "";

    [JsonPropertyName("bond_ingress_bin")]
    public string BondIngressBin { get; set; } = "";

    [JsonPropertyName("wstunnel_bin")]
    public string WstunnelBin { get; set; } = "";

    [JsonPropertyName("uot_bond_bin")]
    public string UotBondBin { get; set; } = "";

    [JsonPropertyName("mptcp_relay_bin")]
    public string MptcpRelayBin { get; set; } = "";

    [JsonPropertyName("wg_bin")]
    public string WgBin { get; set; } = "";

    [JsonPropertyName("wireguard_listen_port")]
    public int WireguardListenPort { get; set; }

    [JsonPropertyName("wireguard_fallback_tcp_port")]
    public int WireguardFallbackTcpPort { get; set; }

    [JsonPropertyName("disable_auto_updates")]
    public bool DisableAutoUpdates { get; set; }

    [JsonPropertyName("language")]
    public string Language { get; set; } = "en-US";

    [JsonPropertyName("auto_start")]
    public bool AutoStart { get; set; }

    [JsonPropertyName("minimize_to_tray")]
    public bool MinimizeToTray { get; set; } = true;

    [JsonPropertyName("udp_uot_sidepath")]
    public UdpUotSidepathConfig? UdpUotSidepath { get; set; }

    public string GetEffectiveStateDir()
    {
        if (!string.IsNullOrWhiteSpace(StateDir))
            return StateDir;
        return Path.Combine(
            Environment.GetFolderPath(Environment.SpecialFolder.CommonApplicationData),
            "Leikwan", "ClientAgent", "state");
    }

    public string GetEffectiveBondBin()
    {
        if (!string.IsNullOrWhiteSpace(BondBin))
            return BondBin;
        return Path.Combine(AppContext.BaseDirectory, "bin", "leikwan-bond.exe");
    }

    public string GetEffectiveBondIngressBin()
    {
        if (!string.IsNullOrWhiteSpace(BondIngressBin))
            return BondIngressBin;
        return Path.Combine(AppContext.BaseDirectory, "bin", "leikwan-ingress.exe");
    }

    public string GetEffectiveWstunnelBin()
    {
        if (!string.IsNullOrWhiteSpace(WstunnelBin))
            return WstunnelBin;
        return Path.Combine(AppContext.BaseDirectory, "bin", "wstunnel.exe");
    }

    public string GetEffectiveUotBondBin()
    {
        if (!string.IsNullOrWhiteSpace(UotBondBin))
            return UotBondBin;
        return Path.Combine(AppContext.BaseDirectory, "bin", "leikwan-uot-bond.exe");
    }

    public string GetEffectiveMptcpRelayBin()
    {
        if (!string.IsNullOrWhiteSpace(MptcpRelayBin))
            return MptcpRelayBin;
        return Path.Combine(AppContext.BaseDirectory, "bin", "leikwan-mptcp-relay.exe");
    }

    public string GetEffectiveWgBin()
    {
        if (!string.IsNullOrWhiteSpace(WgBin))
            return WgBin;
        return Path.Combine(AppContext.BaseDirectory, "bin", "wg.exe");
    }

    public int GetEffectiveWireguardListenPort()
    {
        if (WireguardListenPort > 0) return WireguardListenPort;
        return 43000 + (ClientId % 1000);
    }

    public int GetEffectiveWireguardFallbackTcpPort()
    {
        if (WireguardFallbackTcpPort > 0) return WireguardFallbackTcpPort;
        return GetEffectiveWireguardListenPort() + 10000;
    }

    public void ApplyAuthoritativeConfig(JsonElement? agentConfigJson)
    {
        if (agentConfigJson is not JsonElement element || element.ValueKind != JsonValueKind.Object)
        {
            UdpUotSidepath = null;
            return;
        }

        if (!element.TryGetProperty("udp_uot_sidepath", out var sidepathElement))
        {
            UdpUotSidepath = null;
            return;
        }

        if (sidepathElement.ValueKind == JsonValueKind.Object)
        {
            UdpUotSidepath = sidepathElement.Deserialize<UdpUotSidepathConfig>();
            return;
        }

        UdpUotSidepath = null;
    }
}
