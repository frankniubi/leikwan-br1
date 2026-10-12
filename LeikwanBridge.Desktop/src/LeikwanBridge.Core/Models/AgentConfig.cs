using System.Text.Json.Serialization;
using System.Text.Json;

namespace LeikwanBridge.Core.Models;

public sealed class AgentConfig
{
    [JsonPropertyName("master_url")]
    public string MasterUrl { get; set; } = "";

    [JsonPropertyName("agent_token")]
    public string AgentToken { get; set; } = "";

    [JsonPropertyName("remember_login_info")]
    public bool RememberLoginInfo { get; set; } = true;

    // macOS only: opts the secret store back into the legacy login-keychain
    // backend instead of the default private file store.
    [JsonPropertyName("use_mac_keychain")]
    public bool UseMacKeychain { get; set; }

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
    public string LogLevel { get; set; } = "WARNING";

    [JsonPropertyName("preferred_egress_interface")]
    public string PreferredEgressInterface { get; set; } = "";

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

    [JsonPropertyName("ultra_bin")]
    public string UltraBin { get; set; } = "";

    [JsonPropertyName("wg_bin")]
    public string WgBin { get; set; } = "";

    [JsonPropertyName("proxy_sing_box_bin")]
    public string ProxySingBoxBin { get; set; } = "";

    [JsonPropertyName("proxy_redirector_bin")]
    public string ProxyRedirectorBin { get; set; } = "";

    [JsonPropertyName("proxy_netfilter_driver")]
    public string ProxyNetfilterDriver { get; set; } = "";

    [JsonPropertyName("proxy_port")]
    public int ProxyPort { get; set; } = 2080;

    [JsonPropertyName("proxy_tun_mtu")]
    public int ProxyTunMtu { get; set; } = 1280;

    [JsonPropertyName("proxy_tun_stack")]
    public string ProxyTunStack { get; set; } = "gvisor";

    [JsonPropertyName("proxy_dns_mode")]
    public string ProxyDnsMode { get; set; } = nameof(WindowsProxyDnsMode.Remote);

    [JsonPropertyName("proxy_dns_servers")]
    public string ProxyDnsServers { get; set; } = WindowsProxyDns.DefaultCustomServer;

    [JsonPropertyName("proxy_active_profile_id")]
    public int ProxyActiveProfileId { get; set; }

    [JsonPropertyName("proxy_selected_forward_id")]
    public int ProxySelectedForwardId { get; set; }

    [JsonPropertyName("proxy_profile_ids_by_forward_id")]
    public Dictionary<int, int> ProxyProfileIdsByForwardId { get; set; } = [];

    [JsonPropertyName("proxy_active_mode")]
    public string ProxyActiveMode { get; set; } = string.Empty;

    [JsonPropertyName("proxy_mode_chosen")]
    public bool ProxyModeChosen { get; set; }

    [JsonPropertyName("proxy_active_game_id")]
    public string ProxyActiveGameId { get; set; } = "";

    [JsonPropertyName("proxy_bypass_china_mainland")]
    public bool ProxyBypassChinaMainland { get; set; }

    [JsonPropertyName("proxy_china_ip_list")]
    public string ProxyChinaIpList { get; set; } = "";

    [JsonPropertyName("proxy_netch_mode_path")]
    public string ProxyNetchModePath { get; set; } = "";

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

    public string GetEffectiveUltraBin()
    {
        var installedPath = Path.Combine(AppContext.BaseDirectory, "bin", "ultra-client.exe");
        if (string.IsNullOrWhiteSpace(UltraBin))
            return installedPath;

        // Older installers persisted a per-user runtime path. Prefer the
        // runtime shipped beside the GUI when that legacy path is still set.
        if (IsLegacyUltraBinPath(UltraBin) && File.Exists(installedPath))
            return installedPath;

        return UltraBin;
    }

    public bool MigrateLegacyUltraBin(string installRoot)
    {
        if (string.IsNullOrWhiteSpace(installRoot))
            return false;

        var installedPath = Path.Combine(installRoot.Trim(), "bin", "ultra-client.exe");
        if (!File.Exists(installedPath)
            || string.IsNullOrWhiteSpace(UltraBin)
            || !IsLegacyUltraBinPath(UltraBin)
            || PathsEqual(UltraBin, installedPath))
            return false;

        UltraBin = installedPath;
        return true;
    }

    public string GetEffectiveWgBin()
    {
        if (!string.IsNullOrWhiteSpace(WgBin))
            return WgBin;
        return Path.Combine(AppContext.BaseDirectory, "bin", "wg.exe");
    }

    public string GetEffectiveProxySingBoxBin()
    {
        if (!string.IsNullOrWhiteSpace(ProxySingBoxBin))
            return ProxySingBoxBin;
        return Path.Combine(AppContext.BaseDirectory, "bin", "sing-box.exe");
    }

    public string GetEffectiveProxyRedirectorBin()
    {
        if (!string.IsNullOrWhiteSpace(ProxyRedirectorBin))
            return ProxyRedirectorBin;
        return Path.Combine(AppContext.BaseDirectory, "bin", "Redirector.bin");
    }

    public string GetEffectiveProxyNetfilterDriver()
    {
        if (!string.IsNullOrWhiteSpace(ProxyNetfilterDriver))
            return ProxyNetfilterDriver;
        return Path.Combine(AppContext.BaseDirectory, "bin", "netfilter2.sys");
    }

    public string GetEffectiveProxyChinaIpList()
    {
        if (!string.IsNullOrWhiteSpace(ProxyChinaIpList))
            return ProxyChinaIpList;
        return Path.Combine(AppContext.BaseDirectory, "bin", "CN.conf");
    }

    /// <summary>
    /// Directory holding the bundled geoip-cn.srs / geosite-cn.srs sing-box
    /// rule-sets, installed next to CN.conf.
    /// </summary>
    public string GetEffectiveProxyRuleSetDirectory()
        => Path.Combine(AppContext.BaseDirectory, "bin");

    public string GetEffectiveProxyNetchModePath()
    {
        if (!string.IsNullOrWhiteSpace(ProxyNetchModePath))
            return ProxyNetchModePath;

        var candidates = new[]
        {
            Path.Combine(AppContext.BaseDirectory, "mode"),
            Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.UserProfile), "Downloads", "Netch", "mode"),
            Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.UserProfile), "Netch", "mode"),
        };
        return candidates.FirstOrDefault(Directory.Exists) ?? string.Empty;
    }

    public int GetEffectiveProxyPort()
        => ProxyPort is >= 1 and <= 65535 ? ProxyPort : 2080;

    public int GetEffectiveProxyTunMtu()
        => ProxyTunMtu is >= 576 and <= 9000 ? ProxyTunMtu : 1280;

    public string GetEffectiveProxyTunStack()
    {
        var normalized = ProxyTunStack?.Trim().ToLowerInvariant();
        return normalized is "system" or "gvisor" or "mixed" ? normalized : "gvisor";
    }

    public WindowsProxyDnsMode GetEffectiveProxyDnsMode()
        => WindowsProxyDns.NormalizeMode(ProxyDnsMode);

    public IReadOnlyList<string> GetEffectiveProxyDnsServers()
    {
        var servers = WindowsProxyDns.ParseServers(ProxyDnsServers);
        return servers.Count > 0
            ? servers
            : [WindowsProxyDns.DefaultCustomServer];
    }

    public AgentConfig Clone()
    {
        var json = JsonSerializer.Serialize(this);
        return JsonSerializer.Deserialize<AgentConfig>(json) ?? new AgentConfig();
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

    // Locations that the product itself owns and rewrites on every update.
    // A runtime path persisted here is never a deliberate user override: it is
    // left behind by an older installer or by the legacy per-machine client
    // agent, and it goes stale the moment the GUI updates. Running it silently
    // downgrades the helper and makes the update check report permanent runtime
    // drift, so the GUI re-prompts for the update it just installed.
    private static bool IsLegacyUltraBinPath(string path)
    {
        var normalized = path.Trim().Replace('/', '\\');
        return normalized.Contains(@"\AppData\Local\LeiKwan Bridge\", StringComparison.OrdinalIgnoreCase)
            || normalized.Contains(@"\AppData\Local\Programs\LeiKwan Bridge\", StringComparison.OrdinalIgnoreCase)
            || normalized.Contains(@"\LeiKwan Bridge Backup\", StringComparison.OrdinalIgnoreCase)
            || normalized.Contains(@"\Leikwan\ClientAgent\runtime\", StringComparison.OrdinalIgnoreCase)
            || normalized.Contains(@"\LeiKwan\ClientAgent\runtime\", StringComparison.OrdinalIgnoreCase);
    }

    private static bool PathsEqual(string left, string right)
    {
        try
        {
            return string.Equals(Path.GetFullPath(left), Path.GetFullPath(right), StringComparison.OrdinalIgnoreCase);
        }
        catch
        {
            return string.Equals(left.Trim(), right.Trim(), StringComparison.OrdinalIgnoreCase);
        }
    }
}
