using System.Security.Cryptography;
using System.Text.Json;
using System.Text.Json.Nodes;
using LeikwanBridge.Core.Models;
using LeikwanBridge.Core.Services.Platform;

namespace LeikwanBridge.Mac.Services;

public sealed class MacAgentConfigStore
{
    private static readonly JsonSerializerOptions JsonOptions = new()
    {
        WriteIndented = true,
        PropertyNameCaseInsensitive = true,
    };

    private readonly MacPlatformPaths _paths;
    private readonly ISecretStore _secrets;
    private readonly SemaphoreSlim _gate = new(1, 1);

    public MacAgentConfigStore(MacPlatformPaths paths, ISecretStore secrets)
    {
        _paths = paths;
        _secrets = secrets;
    }

    public async Task<AgentConfig> LoadAsync(CancellationToken ct)
    {
        await _gate.WaitAsync(ct);
        try
        {
            AgentConfig config;
            if (!File.Exists(_paths.ConfigPath))
            {
                config = new AgentConfig();
            }
            else
            {
                try
                {
                    var encoded = await File.ReadAllBytesAsync(_paths.ConfigPath, ct);
                    try
                    {
                        config = JsonSerializer.Deserialize<AgentConfig>(encoded, JsonOptions) ?? new AgentConfig();
                    }
                    finally
                    {
                        CryptographicOperations.ZeroMemory(encoded);
                    }
                }
                catch (JsonException)
                {
                    config = new AgentConfig();
                }
            }
            ApplyMacPaths(config);
            config.DesktopSessionToken = await _secrets.GetAsync("desktop-session", ct) ?? string.Empty;
            var clientId = EffectiveClientId(config);
            config.AgentToken = clientId > 0
                ? await _secrets.GetAsync($"client-agent-{clientId}", ct) ?? string.Empty
                : string.Empty;
            return config;
        }
        finally
        {
            _gate.Release();
        }
    }

    public async Task SaveAsync(AgentConfig config, CancellationToken ct)
    {
        await _gate.WaitAsync(ct);
        try
        {
            var clientId = EffectiveClientId(config);
            if (string.IsNullOrEmpty(config.DesktopSessionToken))
                await _secrets.DeleteAsync("desktop-session", ct);
            else
                await _secrets.SetAsync("desktop-session", config.DesktopSessionToken, ct);
            if (clientId > 0)
            {
                var account = $"client-agent-{clientId}";
                if (string.IsNullOrEmpty(config.AgentToken))
                    await _secrets.DeleteAsync(account, ct);
                else
                    await _secrets.SetAsync(account, config.AgentToken, ct);
            }

            var node = JsonSerializer.SerializeToNode(config, JsonOptions) as JsonObject
                ?? throw new InvalidOperationException("config_serialization_failed");
            node["agent_token"] = string.Empty;
            node["desktop_session_token"] = string.Empty;
            node["state_dir"] = _paths.StateDirectory;
            node["bond_bin"] = BinaryPath("leikwan-bond");
            node["bond_ingress_bin"] = BinaryPath("leikwan-ingress");
            node["wstunnel_bin"] = BinaryPath("wstunnel");
            node["uot_bond_bin"] = BinaryPath("leikwan-uot-bond");
            node["mptcp_relay_bin"] = string.Empty;
            node["ultra_bin"] = BinaryPath("ultra-client");
            node["wg_bin"] = string.Empty;
            var encoded = JsonSerializer.SerializeToUtf8Bytes(node, JsonOptions);
            var temporary = _paths.ConfigPath + "." + Guid.NewGuid().ToString("N") + ".tmp";
            try
            {
                await File.WriteAllBytesAsync(temporary, encoded, ct);
                SetPrivateFileMode(temporary);
                File.Move(temporary, _paths.ConfigPath, overwrite: true);
                SetPrivateFileMode(_paths.ConfigPath);
            }
            finally
            {
                CryptographicOperations.ZeroMemory(encoded);
                try
                {
                    File.Delete(temporary);
                }
                catch (IOException)
                {
                }
            }
            ApplyMacPaths(config);
        }
        finally
        {
            _gate.Release();
        }
    }

    private void ApplyMacPaths(AgentConfig config)
    {
        config.StateDir = _paths.StateDirectory;
        config.BondBin = BinaryPath("leikwan-bond");
        config.BondIngressBin = BinaryPath("leikwan-ingress");
        config.WstunnelBin = BinaryPath("wstunnel");
        config.UotBondBin = BinaryPath("leikwan-uot-bond");
        config.MptcpRelayBin = string.Empty;
        config.UltraBin = BinaryPath("ultra-client");
        config.WgBin = string.Empty;
    }

    private string BinaryPath(string name) => _paths.BinDirectory.TrimEnd('/', '\\') + "/" + name;

    private static int EffectiveClientId(AgentConfig config)
    {
        return config.ActiveClientId > 0 ? config.ActiveClientId : config.ClientId;
    }

    private static void SetPrivateFileMode(string path)
    {
        if (!OperatingSystem.IsWindows())
            File.SetUnixFileMode(path, UnixFileMode.UserRead | UnixFileMode.UserWrite);
    }
}
