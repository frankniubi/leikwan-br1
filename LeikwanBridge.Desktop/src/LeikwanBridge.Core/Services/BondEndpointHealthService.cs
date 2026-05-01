using LeikwanBridge.Core.Helpers;
using LeikwanBridge.Core.Models;

namespace LeikwanBridge.Core.Services;

public sealed class BondEndpointHealthService
{
    private readonly StateManager _stateManager;

    public BondEndpointHealthService(StateManager stateManager)
    {
        _stateManager = stateManager;
    }

    public BondEndpointHealthEntry RecordProbeResult(
        int forwardId,
        int endpointId,
        bool ok,
        double? latencyMs,
        string? error,
        double? nowUnixSeconds = null)
    {
        var now = nowUnixSeconds ?? DateTimeOffset.UtcNow.ToUnixTimeMilliseconds() / 1000.0;
        var entry = GetOrCreateEntry(forwardId, endpointId);

        if (ok)
        {
            entry.ConsecutiveSuccesses += 1;
            entry.ConsecutiveFailures = 0;
            entry.LastError = "";
            entry.LastLatencyMs = latencyMs;
            if (entry.QuarantineUntil > 0)
            {
                entry.Role = "probation";
                entry.ProbationUntil = Math.Max(entry.ProbationUntil, now + 60);
                entry.QuarantineUntil = 0;
            }
            else if (entry.ConsecutiveSuccesses >= 3)
            {
                entry.Role = "healthy";
                entry.ProbationUntil = 0;
            }
        }
        else
        {
            entry.ConsecutiveFailures += 1;
            entry.ConsecutiveSuccesses = 0;
            entry.LastError = string.IsNullOrWhiteSpace(error) ? "probe_failed" : error;
            entry.LastLatencyMs = latencyMs;
            if (entry.ConsecutiveFailures >= 3)
            {
                entry.Role = "quarantined";
                entry.QuarantineUntil = now + 90;
                entry.ProbationUntil = 0;
            }
            else if (entry.ConsecutiveFailures >= 2)
            {
                entry.Role = "probation";
                entry.ProbationUntil = Math.Max(entry.ProbationUntil, now + 60);
            }
        }

        SaveEntry(forwardId, endpointId, entry);
        return entry;
    }

    public BondEndpointHealthEntry ForceQuarantine(
        int forwardId,
        int endpointId,
        string? error,
        double? latencyMs,
        double? nowUnixSeconds = null)
    {
        var now = nowUnixSeconds ?? DateTimeOffset.UtcNow.ToUnixTimeMilliseconds() / 1000.0;
        var entry = GetOrCreateEntry(forwardId, endpointId);
        entry.Role = "quarantined";
        entry.ConsecutiveFailures = Math.Max(3, entry.ConsecutiveFailures + 1);
        entry.ConsecutiveSuccesses = 0;
        entry.QuarantineUntil = now + 90;
        entry.ProbationUntil = 0;
        entry.LastError = string.IsNullOrWhiteSpace(error) ? "cf_bond_leg_probe_failed" : error;
        entry.LastLatencyMs = latencyMs;
        SaveEntry(forwardId, endpointId, entry);
        return entry;
    }

    public List<EndpointConfig> SelectRuntimeEndpoints(ForwardConfig forward, double? nowUnixSeconds = null)
    {
        var endpoints = forward.Endpoints.Select(endpoint => endpoint.Clone()).ToList();
        if (!IsCfPlainTcpBond(forward))
            return endpoints;

        var now = nowUnixSeconds ?? DateTimeOffset.UtcNow.ToUnixTimeMilliseconds() / 1000.0;
        var basePriority = forward.Bonding?.WsPriority > 0 ? forward.Bonding.WsPriority : 10;
        var health = _stateManager.LoadBondEndpointHealth();
        health.TryGetValue(forward.ForwardId.ToString(), out var scoped);
        scoped ??= [];

        foreach (var endpoint in endpoints)
        {
            scoped.TryGetValue(endpoint.EndpointId.ToString(), out var entry);
            var role = ResolveEffectiveRole(entry, now);
            endpoint.HealthRole = role;
            endpoint.DynamicPriority = role switch
            {
                "healthy" => basePriority,
                "probation" => basePriority + 15,
                _ => basePriority + 30,
            };
        }

        var healthy = SortEndpoints(endpoints.Where(endpoint => endpoint.HealthRole == "healthy")).ToList();
        if (healthy.Count > 0)
            return healthy;

        var probation = SortEndpoints(endpoints.Where(endpoint => endpoint.HealthRole == "probation")).ToList();
        if (probation.Count > 0)
            return [probation[0]];

        var fallback = SortEndpoints(endpoints).ToList();
        return fallback.Count > 0 ? [fallback[0]] : [];
    }

    public static bool IsCfPlainTcpBond(ForwardConfig forward)
    {
        return ModeNormalizer.Normalize(forward.Mode) == "bond"
            && string.Equals(forward.BackendMode, "cf_mode", StringComparison.OrdinalIgnoreCase)
            && string.Equals(forward.Bonding?.IngressMode ?? "wss_path_mux", "plain_tcp_mux", StringComparison.OrdinalIgnoreCase);
    }

    private BondEndpointHealthEntry GetOrCreateEntry(int forwardId, int endpointId)
    {
        var health = _stateManager.LoadBondEndpointHealth();
        var scoped = health.setdefault(forwardId.ToString());
        return scoped.TryGetValue(endpointId.ToString(), out var entry) ? entry : new BondEndpointHealthEntry();
    }

    private void SaveEntry(int forwardId, int endpointId, BondEndpointHealthEntry entry)
    {
        var health = _stateManager.LoadBondEndpointHealth();
        var scoped = health.setdefault(forwardId.ToString());
        scoped[endpointId.ToString()] = entry;
        _stateManager.SaveBondEndpointHealth(health);
    }

    private static string ResolveEffectiveRole(BondEndpointHealthEntry? entry, double now)
    {
        if (entry == null)
            return "healthy";

        var role = string.IsNullOrWhiteSpace(entry.Role) ? "healthy" : entry.Role;
        if (role == "quarantined" && entry.QuarantineUntil <= now)
            return "probation";
        if (role == "probation" && entry.ProbationUntil <= now && entry.ConsecutiveSuccesses >= 3)
            return "healthy";
        return role;
    }

    private static IEnumerable<EndpointConfig> SortEndpoints(IEnumerable<EndpointConfig> endpoints)
    {
        return endpoints.OrderBy(endpoint => endpoint.DynamicPriority).ThenBy(endpoint => endpoint.EndpointId);
    }
}

file static class DictionaryExtensions
{
    public static Dictionary<string, BondEndpointHealthEntry> setdefault(
        this Dictionary<string, Dictionary<string, BondEndpointHealthEntry>> health,
        string key)
    {
        if (!health.TryGetValue(key, out var scoped))
        {
            scoped = [];
            health[key] = scoped;
        }

        return scoped;
    }
}
