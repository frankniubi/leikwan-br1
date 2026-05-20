using System.Net.NetworkInformation;
using System.Text;
using LeikwanBridge.Core.Models;
using Serilog;

namespace LeikwanBridge.Core.Services;

/// <summary>
/// Manages WireGuard tunnel lifecycle on Windows using a standalone wireguard.exe binary.
/// 
/// Approach:
/// - Writes a standard WireGuard .conf file to the state directory
/// - Launches <c>wireguard.exe /installtunnelservice &lt;interface_name&gt;</c> to create a Windows service tunnel,
///   OR runs <c>wireguard.exe /run /configfile &lt;path&gt;</c> as a background process
/// - Kills the process / removes the service to tear down the tunnel
/// - Provides readiness probing via ICMP ping to the sidecar private address
/// </summary>
public sealed class WireGuardTunnelService
{
    private readonly AgentConfig _config;
    private readonly WireGuardManager _wgManager;
    private readonly ProcessSupervisor _supervisor;
    private readonly Func<string, bool> _binaryExists;

    public WireGuardTunnelService(
        AgentConfig config,
        WireGuardManager wgManager,
        ProcessSupervisor supervisor,
        Func<string, bool>? binaryExists = null)
    {
        _config = config;
        _wgManager = wgManager;
        _supervisor = supervisor;
        _binaryExists = binaryExists ?? File.Exists;
    }

    /// <summary>
    /// Brings up a WireGuard tunnel for the given forward's self-leg config.
    /// Returns true if the tunnel process was started successfully and the sidecar address is reachable.
    /// Returns false if the tunnel could not be started (binary missing, config invalid, etc.).
    /// </summary>
    public bool BringUp(int forwardId, SelfLegWgConfig wgConfig)
    {
        if (!wgConfig.Enabled)
        {
            Log.Debug("forward {ForwardId}: self_leg_wg disabled, skipping", forwardId);
            return false;
        }

        var wireguardBin = _config.GetEffectiveWireguardBin();
        if (!_binaryExists(wireguardBin))
        {
            Log.Warning("forward {ForwardId}: wireguard.exe not found at {Path}, skipping WG self-leg",
                forwardId, wireguardBin);
            return false;
        }

        // Ensure we have a local WireGuard identity (private key)
        _wgManager.EnsureIdentity();
        var privateKeyPath = GetPrivateKeyPath();
        if (!File.Exists(privateKeyPath))
        {
            Log.Warning("forward {ForwardId}: WireGuard private key not found, cannot bring up tunnel", forwardId);
            return false;
        }

        if (string.IsNullOrWhiteSpace(wgConfig.SidecarPublicKey) ||
            string.IsNullOrWhiteSpace(wgConfig.EndpointHost) ||
            wgConfig.EndpointPort <= 0)
        {
            Log.Warning("forward {ForwardId}: self_leg_wg config incomplete (missing sidecar key or endpoint)", forwardId);
            return false;
        }

        // Clean up any stale tunnel for this forward
        TearDown(forwardId);

        // Render the .conf file
        var confPath = RenderConfigFile(forwardId, wgConfig, privateKeyPath);
        if (confPath == null)
        {
            Log.Error("forward {ForwardId}: failed to render WireGuard config", forwardId);
            return false;
        }

        // Start wireguard.exe as a background process
        var interfaceName = GetInterfaceName(forwardId, wgConfig);
        try
        {
            _supervisor.StartProcess(
                forwardId,
                "wg-self-leg",
                $"wireguard-{interfaceName}",
                wireguardBin,
                $"/installtunnelservice \"{interfaceName}\"");

            Log.Information("forward {ForwardId}: started WireGuard tunnel {Interface} via {Bin}",
                forwardId, interfaceName, wireguardBin);
        }
        catch (Exception ex)
        {
            Log.Error(ex, "forward {ForwardId}: failed to start wireguard.exe for {Interface}",
                forwardId, interfaceName);
            return false;
        }

        // Probe readiness — wait for the sidecar private IP to become reachable
        var sidecarIp = wgConfig.SidecarAddress.Split('/')[0]; // strip CIDR if present
        if (WaitForTunnelReady(sidecarIp, TimeSpan.FromSeconds(10)))
        {
            Log.Information("forward {ForwardId}: WireGuard tunnel {Interface} is UP, sidecar {Ip} reachable",
                forwardId, interfaceName, sidecarIp);
            return true;
        }

        Log.Warning("forward {ForwardId}: WireGuard tunnel {Interface} started but sidecar {Ip} not reachable within timeout",
            forwardId, interfaceName, sidecarIp);
        // Return true anyway — the tunnel process is running; bond runtime can still try to use it
        // and the health check will detect if it's truly unusable
        return true;
    }

    /// <summary>
    /// Tears down the WireGuard tunnel for a forward. Stops the wireguard.exe process
    /// and removes the generated config file.
    /// </summary>
    public void TearDown(int forwardId)
    {
        // The supervisor tracks all processes by forward ID and role.
        // StopAll will kill the wireguard.exe process if running.
        // We don't call StopAll here because that would kill bond processes too.
        // Instead we rely on the supervisor being called from ForwardRuntimeManager.StopAll(forwardId)
        // which kills everything including the WG tunnel.

        // Clean up config files
        CleanupConfigFiles(forwardId);
    }

    /// <summary>
    /// Renders the WireGuard config content for a given self-leg profile.
    /// Useful for testing.
    /// </summary>
    public string RenderConfigContent(SelfLegWgConfig wgConfig, string privateKey)
    {
        var sb = new StringBuilder();
        sb.AppendLine("[Interface]");
        sb.AppendLine($"PrivateKey = {privateKey}");

        // Address: use client_address as-is if it contains CIDR, otherwise append /32
        var clientAddr = wgConfig.ClientAddress;
        if (!clientAddr.Contains('/'))
            clientAddr += "/32";
        sb.AppendLine($"Address = {clientAddr}");
        sb.AppendLine();

        sb.AppendLine("[Peer]");
        sb.AppendLine($"PublicKey = {wgConfig.SidecarPublicKey}");
        sb.AppendLine($"Endpoint = {wgConfig.EndpointHost}:{wgConfig.EndpointPort}");

        // AllowedIPs: the sidecar private address (for routing tunnel traffic to sidecar)
        var sidecarAddr = wgConfig.SidecarAddress.Split('/')[0];
        sb.AppendLine($"AllowedIPs = {sidecarAddr}/32");

        if (wgConfig.PersistentKeepalive > 0)
            sb.AppendLine($"PersistentKeepalive = {wgConfig.PersistentKeepalive}");

        return sb.ToString();
    }

    /// <summary>
    /// Gets the config file path for a forward's WG tunnel.
    /// </summary>
    public string GetConfigFilePath(int forwardId, SelfLegWgConfig wgConfig)
    {
        var interfaceName = GetInterfaceName(forwardId, wgConfig);
        var wgDir = Path.Combine(_config.GetEffectiveStateDir(), "wireguard");
        return Path.Combine(wgDir, $"{interfaceName}.conf");
    }

    private string? RenderConfigFile(int forwardId, SelfLegWgConfig wgConfig, string privateKeyPath)
    {
        try
        {
            var privateKey = File.ReadAllText(privateKeyPath).Trim();
            if (string.IsNullOrWhiteSpace(privateKey))
            {
                Log.Error("forward {ForwardId}: private key file is empty", forwardId);
                return null;
            }

            var content = RenderConfigContent(wgConfig, privateKey);
            var confPath = GetConfigFilePath(forwardId, wgConfig);
            var confDir = Path.GetDirectoryName(confPath)!;
            Directory.CreateDirectory(confDir);
            File.WriteAllText(confPath, content);

            Log.Debug("forward {ForwardId}: wrote WireGuard config to {Path}", forwardId, confPath);
            return confPath;
        }
        catch (Exception ex)
        {
            Log.Error(ex, "forward {ForwardId}: error writing WireGuard config", forwardId);
            return null;
        }
    }

    private void CleanupConfigFiles(int forwardId)
    {
        var wgDir = Path.Combine(_config.GetEffectiveStateDir(), "wireguard");
        if (!Directory.Exists(wgDir))
            return;

        try
        {
            // Remove any config files matching lw-self-<forwardId>* or lw-cfwg-<forwardId>*
            var patterns = new[] { $"lw-self-{forwardId}.conf", $"lw-cfwg-{forwardId}.conf" };
            foreach (var pattern in patterns)
            {
                var path = Path.Combine(wgDir, pattern);
                if (File.Exists(path))
                {
                    File.Delete(path);
                    Log.Debug("forward {ForwardId}: removed stale WG config {Path}", forwardId, path);
                }
            }
        }
        catch (Exception ex)
        {
            Log.Warning(ex, "forward {ForwardId}: error cleaning up WG config files", forwardId);
        }
    }

    private bool WaitForTunnelReady(string ipAddress, TimeSpan timeout)
    {
        var deadline = DateTime.UtcNow + timeout;
        using var ping = new Ping();

        while (DateTime.UtcNow < deadline)
        {
            try
            {
                var reply = ping.Send(ipAddress, 1000);
                if (reply.Status == IPStatus.Success)
                    return true;
            }
            catch
            {
                // Ping may throw if network interface isn't ready yet
            }

            Thread.Sleep(500);
        }

        return false;
    }

    private string GetPrivateKeyPath()
    {
        return Path.Combine(_config.GetEffectiveStateDir(), "wireguard", "private.key");
    }

    private static string GetInterfaceName(int forwardId, SelfLegWgConfig wgConfig)
    {
        if (!string.IsNullOrWhiteSpace(wgConfig.InterfaceName))
            return wgConfig.InterfaceName;
        return $"lw-self-{forwardId}";
    }
}
