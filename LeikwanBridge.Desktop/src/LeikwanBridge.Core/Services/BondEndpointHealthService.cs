using LeikwanBridge.Core.Helpers;
using LeikwanBridge.Core.Models;

namespace LeikwanBridge.Core.Services;

public sealed class BondEndpointHealthService
{
    private const double ProbationSeconds = 60;
    private const double QuarantineSeconds = 30 * 60;
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
            entry.QualityScore = latencyMs is null
                ? 1.0
                : Math.Round(Math.Max(0.1, 1.0 - (Math.Min(Math.Max(latencyMs.Value, 0.0), 200.0) / 200.0)), 4);
            if (entry.QuarantineUntil > 0)
            {
                entry.Role = "probation";
                entry.ProbationUntil = Math.Max(entry.ProbationUntil, now + ProbationSeconds);
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
                entry.QuarantineUntil = now + QuarantineSeconds;
                entry.ProbationUntil = 0;
                entry.QualityScore = 0.0;
            }
            else if (entry.ConsecutiveFailures >= 2)
            {
                entry.Role = "probation";
                entry.ProbationUntil = Math.Max(entry.ProbationUntil, now + ProbationSeconds);
                entry.QualityScore = Math.Min(entry.QualityScore, 0.5);
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
        entry.QuarantineUntil = now + QuarantineSeconds;
        entry.ProbationUntil = 0;
        entry.LastError = string.IsNullOrWhiteSpace(error) ? "cf_bond_leg_probe_failed" : error;
        entry.LastLatencyMs = latencyMs;
        entry.QualityScore = 0.0;
        SaveEntry(forwardId, endpointId, entry);
        return entry;
    }

    public List<EndpointConfig> SelectRuntimeEndpoints(ForwardConfig forward, double? nowUnixSeconds = null)
    {
        var endpoints = forward.Endpoints.Select(endpoint => endpoint.Clone()).ToList();
        if (ModeNormalizer.Normalize(forward.Mode) != "bond"
            || !string.Equals(forward.Bonding?.PolicyMode, "latency_first", StringComparison.OrdinalIgnoreCase))
            return endpoints;

        var activeEndpoints = endpoints
            .Where(endpoint => string.Equals(endpoint.HealthStatus, "active", StringComparison.OrdinalIgnoreCase))
            .ToList();
        if (activeEndpoints.Count == 0)
            return [];

        var now = nowUnixSeconds ?? DateTimeOffset.UtcNow.ToUnixTimeMilliseconds() / 1000.0;
        var basePriority = forward.Bonding?.WsPriority > 0 ? forward.Bonding.WsPriority : 10;
        var health = _stateManager.LoadBondEndpointHealth();
        health.TryGetValue(forward.ForwardId.ToString(), out var scoped);
        scoped ??= [];

        foreach (var endpoint in activeEndpoints)
        {
            scoped.TryGetValue(endpoint.EndpointId.ToString(), out var entry);
            var role = ResolveEffectiveRole(entry, now);
            endpoint.HealthRole = role;
            endpoint.QualityScore = entry?.QualityScore > 0 ? entry.QualityScore : role switch
            {
                "healthy" => 0.6,
                "probation" => 0.4,
                _ => 0.0,
            };
            endpoint.DynamicPriority = role switch
            {
                "healthy" => basePriority,
                "probation" => basePriority + 15,
                _ => basePriority + 30,
            };
        }

        var healthy = SortEndpoints(activeEndpoints.Where(endpoint => endpoint.HealthRole == "healthy")).ToList();
        if (healthy.Count > 0)
        {
            var selected = string.Equals(forward.BackendMode, "cf_mode", StringComparison.OrdinalIgnoreCase)
                ? healthy.Take(3).ToList()
                : healthy;
            for (var index = 0; index < selected.Count; index++)
                selected[index].LegRole = index == 0 ? "primary" : "helper";
            return selected;
        }

        var probation = SortEndpoints(activeEndpoints.Where(endpoint => endpoint.HealthRole == "probation")).ToList();
        if (probation.Count > 0)
        {
            probation[0].LegRole = "primary";
            return [probation[0]];
        }

        var fallback = SortEndpoints(activeEndpoints).ToList();
        if (fallback.Count > 0)
        {
            fallback[0].LegRole = "primary";
            return [fallback[0]];
        }
        return [];
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
        return endpoints.OrderByDescending(endpoint => endpoint.QualityScore).ThenBy(endpoint => endpoint.DynamicPriority).ThenBy(endpoint => endpoint.EndpointId);
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
