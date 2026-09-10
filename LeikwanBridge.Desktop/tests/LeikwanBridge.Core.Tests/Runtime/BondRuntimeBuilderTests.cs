using LeikwanBridge.Core.Models;
using LeikwanBridge.Core.Services;
using System.Text.Json;
using Xunit;

namespace LeikwanBridge.Core.Tests.Runtime;

public sealed class BondRuntimeBuilderTests : IDisposable
{
    private readonly TempStateDirectory _stateDirectory = new();

    public void Dispose() => _stateDirectory.Dispose();

    [Fact]
    public void BondingConfig_DeserializesSidecarFrontendContract()
    {
        var bonding = JsonSerializer.Deserialize<BondingConfig>(
            """{"frontend_mode":"race","strategy_locked":true}""");

        Assert.NotNull(bonding);
        Assert.Equal("race", bonding.FrontendMode);
        Assert.True(bonding.StrategyLocked);
    }

    [Theory]
    [InlineData("single", 1)]
    [InlineData("race", 2)]
    public void BuildFrontend_UsesLockedBondGraphWithExpectedLegCount(string mode, int expectedLegs)
    {
        var builder = CreateBuilder();
        var forward = CreatePlainTcpMuxBondForward();
        forward.Mode = mode;
        forward.ClientMode = mode;
        forward.ActiveFamily = "sidecar_aggregation";
        forward.TargetContract = "sidecar_aggregation";
        forward.SidecarRequired = true;
        forward.AggregationEnabled = false;
        forward.BondSidecarPointId = 10;
        forward.Bonding!.FrontendMode = mode;
        forward.Bonding.StrategyLocked = true;

        var spec = builder.BuildFrontend(forward);

        Assert.Equal(mode, spec.Mode);
        Assert.Equal(expectedLegs, spec.Processes.Count(process => process.Role.StartsWith("bond-ingress-leg-")));
        Assert.All(
            spec.Processes.Where(process => process.Role is "bond-runtime" or "bond-udp-runtime"),
            process => Assert.DoesNotContain("--strategy-file", process.Arguments));
        Assert.All(
            spec.Processes.Where(process => process.Role is "bond-runtime" or "bond-udp-runtime"),
            process => Assert.Contains("--default-strategy latency_best", process.Arguments));
        if (mode == "single")
            Assert.DoesNotContain(spec.Processes, process => process.Arguments.Contains("8.163.90.235:7001"));
    }

    [Fact]
    public void BuildSingle_UsesDirectWstunnelRuntime()
    {
        var builder = CreateBuilder();

        var spec = builder.BuildSingle(CreateSingleForward());

        Assert.Equal("single", spec.Mode);
        Assert.Single(spec.Processes);
        Assert.Equal("single-runtime", spec.Processes[0].Role);
        Assert.Contains("wss://edge-a.example.com:443", spec.Processes[0].Arguments);
        Assert.Equal([], spec.LegPorts);
    }

    [Fact]
    public void BuildBond_PlainTcpMux_UsesIngressHelpersAndSkipsUdp()
    {
        var builder = CreateBuilder();

        var spec = builder.Build(CreatePlainTcpMuxBondForward());

        Assert.Equal("bond", spec.Mode);
        Assert.Equal("plain_tcp_mux", spec.IngressMode);
        Assert.Equal([20962, 20963], spec.LegPorts);
        Assert.Contains(spec.Processes, process => process.Role == "bond-ingress-leg-0");
        Assert.Contains(spec.Processes, process => process.Role == "bond-ingress-leg-1");
        Assert.Single(spec.Processes, process => process.Role == "bond-runtime");
        Assert.DoesNotContain(spec.Processes, process => process.Role == "bond-udp-runtime");
    }

    [Fact]
    public void BuildBond_PlainTcpMux_WithUdpUotSidepath_AddsUdpAuxRuntime()
    {
        var builder = CreateBuilder(CreateUdpSidepathConfig());

        var spec = builder.Build(CreatePlainTcpMuxBondForward());

        var tcpRuntime = Assert.Single(spec.Processes, process => process.Role == "bond-runtime");
        Assert.Contains("--protocol tcp", tcpRuntime.Arguments);
        Assert.DoesNotContain(spec.Processes, process => process.Role == "bond-udp-runtime");

        var udpRuntime = Assert.Single(spec.Processes, process => process.Role == "bond-udp-uot-runtime");
        Assert.Contains("--config", udpRuntime.Arguments);
        var configFile = Assert.Single(spec.GeneratedFiles!, file => file.FilePath.EndsWith("uot-bond-forward-26.json", StringComparison.Ordinal));
        using var doc = JsonDocument.Parse(configFile.Content);
        var root = doc.RootElement;
        Assert.Equal("feedback_drr", root.GetProperty("scheduler_mode").GetString());
        Assert.Equal(300_000_000L, root.GetProperty("aggregate_rate_bps").GetInt64());
        Assert.Equal(5, root.GetProperty("queue_target_ms").GetInt32());
        Assert.Equal(10, root.GetProperty("queue_deadline_ms").GetInt32());
        Assert.Equal(1_048_576, root.GetProperty("global_queue_bytes").GetInt32());
        Assert.Equal(8_192, root.GetProperty("global_queue_packets").GetInt32());
        Assert.Equal(262_144, root.GetProperty("per_session_queue_bytes").GetInt32());
        Assert.Equal(512, root.GetProperty("per_session_queue_packets").GetInt32());
        Assert.Equal(16_384, root.GetProperty("drr_quantum_bytes").GetInt32());
        Assert.Equal(32_768, root.GetProperty("chunk_target_bytes").GetInt32());
        Assert.Equal(8_388_608, root.GetProperty("max_total_outstanding_bytes").GetInt32());
        Assert.Equal(256, root.GetProperty("control_queue_packets").GetInt32());
    }

    [Fact]
    public void BuildBond_FeedbackWithInvalidQueueContract_FailsBeforeRuntimeCreation()
    {
        using var tempDir = new TempStateDirectory();
        var config = CreateUdpSidepathConfig();
        config.StateDir = tempDir.Path;
        config.UdpUotSidepath!.QueueTargetMs = 11;
        config.UdpUotSidepath.QueueDeadlineMs = 10;
        var builder = new BondRuntimeBuilder(config);

        var error = Assert.Throws<InvalidOperationException>(() => builder.Build(CreatePlainTcpMuxBondForward()));

        Assert.Contains("queue target/deadline", error.Message);
    }

    [Fact]
    public void BuildBond_PlainTcpMux_PassesWarmupAndCooldownFlagsToBondRuntime()
    {
        var builder = CreateBuilder();

        var spec = builder.Build(CreatePlainTcpMuxBondForward());
        var runtime = Assert.Single(spec.Processes, process => process.Role == "bond-runtime");

        Assert.Contains("--warmup-trigger-bytes 65536", runtime.Arguments);
        Assert.Contains("--warmup-slice-bytes 65536", runtime.Arguments);
        Assert.Contains("--warmup-leg-delay-ms 80", runtime.Arguments);
        Assert.Contains("--bulk-promote-bytes 262144", runtime.Arguments);
        Assert.Contains("--leg-cooldown-ms 3000", runtime.Arguments);
    }

    [Fact]
    public void BuildBond_ReferencesLocalStrategyFileWithoutOverwritingChoice()
    {
        var stateDir = Path.Combine(Path.GetTempPath(), $"leikwan-builder-strategy-{Guid.NewGuid():N}");
        try
        {
            var config = new AgentConfig
            {
                MasterUrl = "https://panel-a.example.test",
                ClientId = 9,
                StateDir = stateDir,
                BondBin = @"C:\leikwan\bin\leikwan-bond.exe",
                BondIngressBin = @"C:\leikwan\bin\leikwan-ingress.exe",
                WstunnelBin = @"C:\leikwan\bin\wstunnel.exe",
            };
            var store = new ForwardStrategyStore(stateDir, config.MasterUrl, config.ClientId);
            store.Set(26, ForwardStrategy.Random);

            var spec = CreateBuilder(config).Build(CreatePlainTcpMuxBondForward());
            var runtime = Assert.Single(spec.Processes, process => process.Role == "bond-runtime");

            Assert.Contains($"--strategy-file \"{store.RuntimePath(26)}\"", runtime.Arguments);
            Assert.Contains("--default-strategy latency_best", runtime.Arguments);
            Assert.Equal("random\n", File.ReadAllText(store.RuntimePath(26)));
        }
        finally
        {
            if (Directory.Exists(stateDir))
                Directory.Delete(stateDir, recursive: true);
        }
    }

    [Fact]
    public void BuildBond_PlainTcpMux_UsesLinuxParityJoinTimeoutForWssOnlyLegs()
    {
        var builder = CreateBuilder();

        var spec = builder.Build(CreatePlainTcpMuxBondForward());
        var runtime = Assert.Single(spec.Processes, process => process.Role == "bond-runtime");

        Assert.Contains("--join-timeout-ms 400", runtime.Arguments);
    }

    [Fact]
    public void BuildBond_PlainTcpMux_IncludesEndpointMetadataInLegTargets()
    {
        var builder = CreateBuilder();

        var spec = builder.Build(CreatePlainTcpMuxBondForward());
        var runtime = Assert.Single(spec.Processes, process => process.Role == "bond-runtime");

        Assert.Contains("tcp://127.0.0.1:20962?priority=9&endpoint_id=184&display_host=8.163.90.132", runtime.Arguments);
        Assert.Contains("tcp://127.0.0.1:20963?priority=10&endpoint_id=185&display_host=8.163.90.235", runtime.Arguments);
    }

    [Fact]
    public void BuildBond_LegacyIngressMode_FallsBackToPerLegWstunnel()
    {
        var builder = CreateBuilder();

        var spec = builder.Build(CreateLegacyBondForward());

        Assert.Equal("wss_path_mux", spec.IngressMode);
        Assert.Contains(spec.Processes, process => process.Role == "bond-wss-leg-0");
        Assert.Single(spec.Processes, process => process.Role == "bond-runtime");
    }

    [Fact]
    public void BuildBond_LatencyFirst_EmitsRolesAndFlags()
    {
        var builder = CreateBuilder();
        var forward = CreatePlainTcpMuxBondForward();
        forward.Bonding!.PolicyMode = "latency_first";
        forward.Bonding.UdpPrimaryOnly = true;
        forward.Endpoints[0].LegRole = "primary";
        forward.Endpoints[1].LegRole = "helper";

        var spec = builder.Build(forward);
        var runtime = Assert.Single(spec.Processes, process => process.Role == "bond-runtime");

        Assert.Contains("role=primary", runtime.Arguments);
        Assert.Contains("role=helper", runtime.Arguments);
        Assert.Contains("--latency-first", runtime.Arguments);
    }

    private BondRuntimeBuilder CreateBuilder(AgentConfig? config = null)
    {
        config ??= new AgentConfig
        {
            BondBin = @"C:\leikwan\bin\leikwan-bond.exe",
            BondIngressBin = @"C:\leikwan\bin\leikwan-ingress.exe",
            WstunnelBin = @"C:\leikwan\bin\wstunnel.exe",
        };
        if (string.IsNullOrWhiteSpace(config.StateDir))
            config.StateDir = _stateDirectory.Path;
        return new BondRuntimeBuilder(config);
    }

    private AgentConfig CreateUdpSidepathConfig()
    {
        var config = JsonSerializer.Deserialize<AgentConfig>(
            """
            {
              "state_dir": "C:\\leikwan\\state",
              "bond_bin": "C:\\leikwan\\bin\\leikwan-bond.exe",
              "bond_ingress_bin": "C:\\leikwan\\bin\\leikwan-ingress.exe",
              "wstunnel_bin": "C:\\leikwan\\bin\\wstunnel.exe",
              "uot_bond_bin": "C:\\leikwan\\bin\\leikwan-uot-bond.exe",
              "udp_uot_sidepath": {
                "enabled_forward_ids": [26],
                "shadow_route_suffix": ".uot",
                "sidecar_port": 8999,
                "reorder_window_packets": 4096,
                "reorder_window_bytes": 16777216,
                "reorder_timeout_ms": 25,
                "delivery_mode": "unordered",
                "wireguard_aware": true,
                "scheduler_mode": "feedback_drr",
                "aggregate_rate_bps": 300000000,
                "queue_target_ms": 5,
                "queue_deadline_ms": 10,
                "global_queue_bytes": 1048576,
                "global_queue_packets": 8192,
                "per_session_queue_bytes": 262144,
                "per_session_queue_packets": 512,
                "drr_quantum_bytes": 16384,
                "chunk_min_bytes": 16384,
                "chunk_target_bytes": 32768,
                "chunk_max_bytes": 65536,
                "min_leg_window_bytes": 262144,
                "max_leg_window_bytes": 4194304,
                "max_total_outstanding_bytes": 8388608,
                "progress_interval_bytes": 65536,
                "progress_interval_frames": 32,
                "progress_interval_ms": 5,
                "finish_skew_budget_ms": 2,
                "probe_interval_ms": 1000,
                "max_sessions": 4096,
                "control_queue_packets": 256
              }
            }
            """)!;
        config.StateDir = _stateDirectory.Path;
        return config;
    }

    private static ForwardConfig CreateSingleForward()
    {
        return new ForwardConfig
        {
            ForwardId = 9,
            Mode = "single",
            ListenPort = 11001,
            Target = "193.227.109.82:5201",
            Sni = "v3.leikwanhost.com",
            PathPrefix = "tenant-9",
            Transport = "tcp",
            Endpoints =
            [
                new EndpointConfig
                {
                    Host = "edge-a.example.com",
                    Port = 443,
                },
            ],
        };
    }

    private static ForwardConfig CreatePlainTcpMuxBondForward()
    {
        return new ForwardConfig
        {
            ForwardId = 26,
            Mode = "bond",
            ListenPort = 11024,
            Target = "193.227.109.82:5201",
            Sni = "v3.leikwanhost.com",
            PathPrefix = "tenant-26",
            Transport = "tcp+udp",
            Endpoints =
            [
                new EndpointConfig
                {
                    EndpointId = 184,
                    Host = "8.163.90.132",
                    Port = 7001,
                    RouteKey = "forward-26",
                    IngressAuthToken = "token-a",
                    IngressProtocol = "plain_tcp_mux",
                    BackendProtocol = "tcp_relay",
                    DynamicPriority = 9,
                },
                new EndpointConfig
                {
                    EndpointId = 185,
                    Host = "8.163.90.235",
                    Port = 7001,
                    RouteKey = "forward-26",
                    IngressAuthToken = "token-b",
                    IngressProtocol = "plain_tcp_mux",
                    BackendProtocol = "tcp_relay",
                    DynamicPriority = 10,
                },
            ],
            Bonding = new BondingConfig
            {
                RemoteTarget = "138.252.162.120:9000",
                SessionToken = "tcp-session",
                UdpSessionToken = "udp-session",
                IngressMode = "plain_tcp_mux",
                RouteKey = "forward-26",
                ChunkSize = 131072,
                MaxWindowBytes = 16777216,
                RetransmitMs = 300,
                ConnectTimeoutMs = 3000,
                JoinTimeoutMs = 400,
                InteractiveChunkBytes = 16384,
                LatencyGuardBytes = 262144,
                InteractiveRttSlackMs = 30,
                WarmupTriggerBytes = 65536,
                WarmupSliceBytes = 65536,
                WarmupLegDelayMs = 80,
                BulkPromoteBytes = 262144,
                LegCooldownMs = 3000,
                DisableLocalRateLimit = true,
                QuicEnabled = false,
                QuicViaSlave = false,
                WssStandbyEnabled = false,
                WsPriority = 10,
            },
        };
    }

    private static ForwardConfig CreateLegacyBondForward()
    {
        var forward = CreatePlainTcpMuxBondForward();
        forward.Bonding!.IngressMode = "wss_path_mux";
        return forward;
    }
}
