using System.Text.Json;
using LeikwanBridge.Core.Models;
using LeikwanBridge.Core.Services;
using Xunit;

namespace LeikwanBridge.Core.Tests.Runtime;

public sealed class BondEndpointHealthServiceTests
{
    [Fact]
    public void SelectRuntimeEndpoints_CfBond_ExcludesInactiveEndpointEvenWhenItsQualityIsBest()
    {
        using var tempDir = new TempStateDirectory();
        var stateManager = new StateManager(tempDir.Path);
        var service = new BondEndpointHealthService(stateManager);

        stateManager.SaveBondEndpointHealth(new Dictionary<string, Dictionary<string, BondEndpointHealthEntry>>
        {
            ["47"] = new()
            {
                ["316"] = new BondEndpointHealthEntry { Role = "healthy", ConsecutiveSuccesses = 4, QualityScore = 1.0 },
                ["317"] = new BondEndpointHealthEntry { Role = "healthy", ConsecutiveSuccesses = 4, QualityScore = 0.2 },
            }
        });

        var selected = service.SelectRuntimeEndpoints(CreateCfBondForwardWithInactiveBestLeg(), nowUnixSeconds: 1_001);

        Assert.Equal([317], selected.Select(endpoint => endpoint.EndpointId).ToList());
    }

    [Fact]
    public void SelectRuntimeEndpoints_NonLatencyFirstBond_ExcludesPanelInactiveEndpoints()
    {
        using var tempDir = new TempStateDirectory();
        var stateManager = new StateManager(tempDir.Path);
        var service = new BondEndpointHealthService(stateManager);

        var forward = new ForwardConfig
        {
            ForwardId = 52,
            Mode = "bond",
            ListenPort = 12000,
            Transport = "tcp",
            Endpoints =
            [
                new EndpointConfig { EndpointId = 1, Host = "a", Port = 1, HealthStatus = "inactive" },
                new EndpointConfig { EndpointId = 2, Host = "b", Port = 1, HealthStatus = "active" },
            ],
            Bonding = new BondingConfig { PolicyMode = "balanced" },
        };

        var selected = service.SelectRuntimeEndpoints(forward, nowUnixSeconds: 100);

        Assert.Equal(new[] { 2 }, selected.Select(endpoint => endpoint.EndpointId).ToArray());
    }

    [Fact]
    public void SelectRuntimeEndpoints_LatencyFirstBond_AllInactive_ReturnsEmpty()
    {
        using var tempDir = new TempStateDirectory();
        var stateManager = new StateManager(tempDir.Path);
        var service = new BondEndpointHealthService(stateManager);

        var forward = CreateCfBondForward();
        foreach (var endpoint in forward.Endpoints)
            endpoint.HealthStatus = "inactive";

        var selected = service.SelectRuntimeEndpoints(forward, nowUnixSeconds: 100);

        Assert.Empty(selected);
    }

    [Fact]
    public void SelectRuntimeEndpoints_CfBond_ExcludesQuarantinedEndpointWhenHealthyExists()
    {
        using var tempDir = new TempStateDirectory();
        var stateManager = new StateManager(tempDir.Path);
        var service = new BondEndpointHealthService(stateManager);
        service.ForceQuarantine(47, 316, "cf_bond_leg_probe_failed", 1200, nowUnixSeconds: 1_000);

        var selected = service.SelectRuntimeEndpoints(CreateCfBondForward(), nowUnixSeconds: 1_001);

        Assert.Equal([317], selected.Select(endpoint => endpoint.EndpointId).ToList());
        Assert.All(selected, endpoint => Assert.Equal("healthy", endpoint.HealthRole));
    }

    [Fact]
    public void SelectRuntimeEndpoints_CfBond_UsesSingleProbationEndpointWhenOnlyOlderQuarantineHasExpired()
    {
        using var tempDir = new TempStateDirectory();
        var stateManager = new StateManager(tempDir.Path);
        var service = new BondEndpointHealthService(stateManager);
        service.ForceQuarantine(47, 316, "failed-a", null, nowUnixSeconds: 1_000);
        service.ForceQuarantine(47, 317, "failed-b", null, nowUnixSeconds: 900);

        var selected = service.SelectRuntimeEndpoints(CreateCfBondForward(), nowUnixSeconds: 2_701);

        Assert.Single(selected);
        Assert.Equal(317, selected[0].EndpointId);
        Assert.Equal("probation", selected[0].HealthRole);
        Assert.Equal(25, selected[0].DynamicPriority);
    }

    [Fact]
    public void SelectRuntimeEndpoints_IgnoresPanelInactiveEndpointEvenWhenLocalQualityIsBest()
    {
        using var tempDir = new TempStateDirectory();
        var stateManager = new StateManager(tempDir.Path);
        var service = new BondEndpointHealthService(stateManager);

        stateManager.SaveBondEndpointHealth(new Dictionary<string, Dictionary<string, BondEndpointHealthEntry>>
        {
            ["40"] = new()
            {
                ["254"] = new BondEndpointHealthEntry { Role = "healthy", ConsecutiveSuccesses = 4, QualityScore = 1.0 },
                ["256"] = new BondEndpointHealthEntry { Role = "healthy", ConsecutiveSuccesses = 4, QualityScore = 0.4 },
            }
        });

        var forward = CreateLatencyFirstNormalBondForward();
        var parsed = JsonSerializer.Deserialize<ForwardConfig>("""
        {
          "forward_id": 40,
          "service_status": "active",
          "mode": "bond",
          "listen_port": 11030,
          "transport": "tcp",
          "backend_mode": "current_mode",
          "endpoints": [
            { "endpoint_id": 254, "host": "8.163.90.132", "port": 7000, "dynamic_priority": 10, "health_status": "inactive" },
            { "endpoint_id": 256, "host": "8.163.87.42", "port": 7000, "dynamic_priority": 10, "health_status": "active" }
          ],
          "bonding": {
            "ingress_mode": "plain_tcp_mux",
            "route_key": "forward-40",
            "session_token": "tcp-session",
            "remote_target": "45.196.220.47:5201",
            "policy_mode": "latency_first",
            "ws_priority": 10
          }
        }
        """, new JsonSerializerOptions { PropertyNameCaseInsensitive = true })!;

        var selected = service.SelectRuntimeEndpoints(parsed, nowUnixSeconds: 100);

        Assert.Equal([256], selected.Select(endpoint => endpoint.EndpointId).ToArray());
    }

    [Fact]
    public void SelectRuntimeEndpoints_CfBond_FallbackDoesNotSelectInactiveEndpoint()
    {
        using var tempDir = new TempStateDirectory();
        var stateManager = new StateManager(tempDir.Path);
        var service = new BondEndpointHealthService(stateManager);

        stateManager.SaveBondEndpointHealth(new Dictionary<string, Dictionary<string, BondEndpointHealthEntry>>
        {
            ["47"] = new()
            {
                ["316"] = new BondEndpointHealthEntry
                {
                    Role = "healthy",
                    ConsecutiveSuccesses = 4,
                    QualityScore = 1.0,
                },
                ["317"] = new BondEndpointHealthEntry
                {
                    Role = "quarantined",
                    ConsecutiveFailures = 3,
                    QuarantineUntil = 2_000,
                    QualityScore = 0.0,
                },
            },
        });

        var selected = service.SelectRuntimeEndpoints(CreateCfBondForwardWithInactiveBestLeg(), nowUnixSeconds: 1_000);

        Assert.Single(selected);
        Assert.Equal(317, selected[0].EndpointId);
        Assert.Equal("primary", selected[0].LegRole);
        Assert.Equal("quarantined", selected[0].HealthRole);
    }

    [Fact]
    public void RecordProbeResult_ThirdCfFailureQuarantinesEndpointForThirtyMinutes()
    {
        using var tempDir = new TempStateDirectory();
        var stateManager = new StateManager(tempDir.Path);
        var service = new BondEndpointHealthService(stateManager);

        service.RecordProbeResult(47, 316, ok: false, latencyMs: null, error: "one", nowUnixSeconds: 2_000);
        service.RecordProbeResult(47, 316, ok: false, latencyMs: null, error: "two", nowUnixSeconds: 2_001);
        var entry = service.RecordProbeResult(47, 316, ok: false, latencyMs: null, error: "three", nowUnixSeconds: 2_002);

        Assert.Equal("quarantined", entry.Role);
        Assert.Equal(3, entry.ConsecutiveFailures);
        Assert.Equal(3_802, entry.QuarantineUntil);
        Assert.Equal("three", entry.LastError);
    }

    [Fact]
    public void ForceQuarantine_HoldsEndpointForThirtyMinutes()
    {
        using var tempDir = new TempStateDirectory();
        var stateManager = new StateManager(tempDir.Path);
        var service = new BondEndpointHealthService(stateManager);

        var entry = service.ForceQuarantine(47, 316, "cf_bond_leg_probe_failed", null, nowUnixSeconds: 10_000);

        Assert.Equal("quarantined", entry.Role);
        Assert.Equal(11_800, entry.QuarantineUntil);
    }

    [Fact]
    public void RecordProbeResult_TracksQualityScoreFromLatency()
    {
        using var tempDir = new TempStateDirectory();
        var stateManager = new StateManager(tempDir.Path);
        var service = new BondEndpointHealthService(stateManager);

        var fast = service.RecordProbeResult(47, 316, ok: true, latencyMs: 12.0, error: null, nowUnixSeconds: 3_000);
        var slow = service.RecordProbeResult(47, 317, ok: true, latencyMs: 180.0, error: null, nowUnixSeconds: 3_000);

        Assert.True(fast.QualityScore > slow.QualityScore);
        Assert.True(fast.QualityScore >= 0.9);
        Assert.True(slow.QualityScore <= 0.5);
    }

    [Fact]
    public void SelectRuntimeEndpoints_CfBond_LimitsHealthySetToBestThreeByPriority()
    {
        using var tempDir = new TempStateDirectory();
        var stateManager = new StateManager(tempDir.Path);
        var service = new BondEndpointHealthService(stateManager);

        stateManager.SaveBondEndpointHealth(new Dictionary<string, Dictionary<string, BondEndpointHealthEntry>>
        {
            ["40"] = new()
            {
                ["254"] = new BondEndpointHealthEntry { Role = "healthy", ConsecutiveSuccesses = 4, QualityScore = 1.0 },
                ["255"] = new BondEndpointHealthEntry { Role = "healthy", ConsecutiveSuccesses = 4, QualityScore = 0.2 },
                ["256"] = new BondEndpointHealthEntry { Role = "healthy", ConsecutiveSuccesses = 4, QualityScore = 0.95 },
                ["257"] = new BondEndpointHealthEntry { Role = "healthy", ConsecutiveSuccesses = 4, QualityScore = 0.1 },
                ["258"] = new BondEndpointHealthEntry { Role = "healthy", ConsecutiveSuccesses = 4, QualityScore = 0.9 },
            }
        });

        var selected = service.SelectRuntimeEndpoints(CreateCfBondForwardWithFiveHealthyLegs(), nowUnixSeconds: 5_000);

        Assert.Equal([254, 256, 258], selected.Select(endpoint => endpoint.EndpointId).ToList());
    }

    [Fact]
    public void SelectRuntimeEndpoints_LatencyFirstNormalBond_MarksPrimaryThenHelpers()
    {
        using var tempDir = new TempStateDirectory();
        var stateManager = new StateManager(tempDir.Path);
        var service = new BondEndpointHealthService(stateManager);

        stateManager.SaveBondEndpointHealth(new Dictionary<string, Dictionary<string, BondEndpointHealthEntry>>
        {
            ["40"] = new()
            {
                ["254"] = new BondEndpointHealthEntry { Role = "healthy", ConsecutiveSuccesses = 4, QualityScore = 0.75 },
                ["256"] = new BondEndpointHealthEntry { Role = "healthy", ConsecutiveSuccesses = 4, QualityScore = 0.95 },
                ["258"] = new BondEndpointHealthEntry { Role = "healthy", ConsecutiveSuccesses = 4, QualityScore = 0.60 },
            }
        });

        var forward = CreateLatencyFirstNormalBondForward();
        var selected = service.SelectRuntimeEndpoints(forward, nowUnixSeconds: 100);

        Assert.Equal(new[] { 256, 254, 258 }, selected.Select(endpoint => endpoint.EndpointId).ToArray());
        Assert.Equal(new[] { "primary", "helper", "helper" }, selected.Select(endpoint => endpoint.LegRole).ToArray());
    }

    private static ForwardConfig CreateCfBondForward()
    {
        return new ForwardConfig
        {
            ForwardId = 47,
            BackendMode = "cf_mode",
            Mode = "bond",
            ListenPort = 11035,
            Transport = "tcp",
            Endpoints =
            [
                new EndpointConfig
                {
                    EndpointId = 316,
                    Host = "8.1.1.1",
                    Port = 7000,
                    RouteKey = "forward-47",
                    IngressAuthToken = "token-a",
                    DynamicPriority = 10,
                },
                new EndpointConfig
                {
                    EndpointId = 317,
                    Host = "8.1.1.2",
                    Port = 7000,
                    RouteKey = "forward-47",
                    IngressAuthToken = "token-b",
                    DynamicPriority = 10,
                },
            ],
            Bonding = new BondingConfig
            {
                IngressMode = "plain_tcp_mux",
                RouteKey = "forward-47",
                SessionToken = "tcp-session",
                RemoteTarget = "127.0.0.1:8899",
                PolicyMode = "latency_first",
                WsPriority = 10,
            },
        };
    }

    private static ForwardConfig CreateCfBondForwardWithInactiveBestLeg()
    {
        var forward = CreateCfBondForward();
        forward.Endpoints[0].HealthStatus = "inactive";
        return forward;
    }

    private static ForwardConfig CreateCfBondForwardWithFiveHealthyLegs()
    {
        return new ForwardConfig
        {
            ForwardId = 40,
            BackendMode = "cf_mode",
            Mode = "bond",
            ListenPort = 11030,
            Transport = "tcp",
            Endpoints =
            [
                new EndpointConfig { EndpointId = 254, Host = "8.163.90.132", Port = 7000, DynamicPriority = 10 },
                new EndpointConfig { EndpointId = 255, Host = "8.163.69.176", Port = 7000, DynamicPriority = 10 },
                new EndpointConfig { EndpointId = 256, Host = "8.163.87.42", Port = 7000, DynamicPriority = 10 },
                new EndpointConfig { EndpointId = 257, Host = "8.163.90.235", Port = 7000, DynamicPriority = 10 },
                new EndpointConfig { EndpointId = 258, Host = "8.163.71.73", Port = 7000, DynamicPriority = 10 },
            ],
            Bonding = new BondingConfig
            {
                IngressMode = "plain_tcp_mux",
                RouteKey = "forward-40",
                SessionToken = "tcp-session",
                RemoteTarget = "45.196.220.47:5201",
                PolicyMode = "latency_first",
                WsPriority = 10,
            },
        };
    }

    private static ForwardConfig CreateLatencyFirstNormalBondForward()
    {
        return new ForwardConfig
        {
            ForwardId = 40,
            BackendMode = "current_mode",
            Mode = "bond",
            ListenPort = 11030,
            Transport = "tcp",
            Endpoints =
            [
                new EndpointConfig { EndpointId = 254, Host = "8.163.90.132", Port = 7000, DynamicPriority = 10 },
                new EndpointConfig { EndpointId = 256, Host = "8.163.87.42", Port = 7000, DynamicPriority = 10 },
                new EndpointConfig { EndpointId = 258, Host = "8.163.71.73", Port = 7000, DynamicPriority = 10 },
            ],
            Bonding = new BondingConfig
            {
                IngressMode = "plain_tcp_mux",
                RouteKey = "forward-40",
                SessionToken = "tcp-session",
                RemoteTarget = "45.196.220.47:5201",
                PolicyMode = "latency_first",
                WsPriority = 10,
            },
        };
    }
}
