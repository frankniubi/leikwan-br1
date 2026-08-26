using System.Text.Json;
using LeikwanBridge.Core.Models;
using Serilog;

namespace LeikwanBridge.Core.Services;

public sealed class StateManager
{
    private static readonly JsonSerializerOptions JsonOptions = new()
    {
        WriteIndented = true,
        PropertyNamingPolicy = JsonNamingPolicy.SnakeCaseLower,
    };

    private readonly string _stateDir;
    private readonly object _lock = new();

    public StateManager(string stateDir)
    {
        _stateDir = stateDir;
        Directory.CreateDirectory(_stateDir);
    }

    public Dictionary<string, AppliedForwardState> LoadApplied()
    {
        var path = Path.Combine(_stateDir, "applied.json");
        return LoadJson<Dictionary<string, AppliedForwardState>>(path) ?? [];
    }

    public void SaveApplied(Dictionary<string, AppliedForwardState> applied)
    {
        var path = Path.Combine(_stateDir, "applied.json");
        SaveJson(path, applied);
    }

    public bool RemoveApplied(int forwardId)
    {
        lock (_lock)
        {
            var applied = LoadApplied();
            if (!applied.Remove(forwardId.ToString()))
                return false;
            SaveApplied(applied);
            return true;
        }
    }

    public PullState LoadPullState()
    {
        var path = Path.Combine(_stateDir, "config_pull_state.json");
        return LoadJson<PullState>(path) ?? new PullState();
    }

    public void SavePullState(PullState state)
    {
        var path = Path.Combine(_stateDir, "config_pull_state.json");
        SaveJson(path, state);
    }

    public UsageState LoadUsageState()
    {
        var path = Path.Combine(_stateDir, "usage_state.json");
        return LoadJson<UsageState>(path) ?? new UsageState();
    }

    public string EffectiveStateDir => _stateDir;

    public void SaveUsageState(UsageState state)
    {
        var path = Path.Combine(_stateDir, "usage_state.json");
        SaveJson(path, state);
    }

    public Dictionary<string, Dictionary<string, BondEndpointHealthEntry>> LoadBondEndpointHealth()
    {
        var path = Path.Combine(_stateDir, "bond-endpoint-health.json");
        return LoadJson<Dictionary<string, Dictionary<string, BondEndpointHealthEntry>>>(path) ?? [];
    }

    public void SaveBondEndpointHealth(Dictionary<string, Dictionary<string, BondEndpointHealthEntry>> health)
    {
        var path = Path.Combine(_stateDir, "bond-endpoint-health.json");
        SaveJson(path, health);
    }

    public AgentConfig LoadConfig(string configPath)
    {
        return LoadJson<AgentConfig>(configPath) ?? new AgentConfig();
    }

    public void SaveConfig(string configPath, AgentConfig config)
    {
        SaveJson(configPath, config);
    }

    private T? LoadJson<T>(string path) where T : class
    {
        lock (_lock)
        {
            if (!File.Exists(path)) return null;
            try
            {
                var json = File.ReadAllText(path);
                return JsonSerializer.Deserialize<T>(json, JsonOptions);
            }
            catch (Exception ex)
            {
                Log.Warning(ex, "failed to load {Path}", path);
                return null;
            }
        }
    }

    private void SaveJson<T>(string path, T data)
    {
        lock (_lock)
        {
            var dir = Path.GetDirectoryName(path);
            if (!string.IsNullOrEmpty(dir))
                Directory.CreateDirectory(dir);
            var json = JsonSerializer.Serialize(data, JsonOptions);
            File.WriteAllText(path, json);
        }
    }
}
