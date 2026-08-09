using System.Text.Json;
using LeikwanBridge.Core.Models;
using LeikwanBridge.Core.Services;
using Xunit;

namespace LeikwanBridge.Core.Tests.Runtime;

public sealed class ForwardRuntimeManagerTests
{
    [Theory]
    [InlineData("single", 1)]
    [InlineData("race", 2)]
    public void ApplyForward_SidecarFrontend_DispatchesToLockedBondRuntime(string mode, int expectedLegs)
    {
        using var tempDir = new TempStateDirectory();
        var supervisor = new TestProcessSupervisor();
        var stateManager = new StateManager(tempDir.Path);
        var manager = new ForwardRuntimeManager(
            CreateConfig(),
            supervisor,
            stateManager,
            binaryExists: _ => true,
            portListeningProbe: _ => true);

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

        manager.ApplyForward(forward);

        Assert.Equal(expectedLegs, supervisor.StartedRoles.Count(role => role.StartsWith("bond-ingress-leg-")));
        Assert.Contains("bond-runtime", supervisor.StartedRoles);
        Assert.DoesNotContain("single-runtime", supervisor.StartedRoles);
        Assert.DoesNotContain("race-runtime", supervisor.StartedRoles);
        Assert.Equal(mode, stateManager.LoadApplied()["26"].Mode);
    }

    [Fact]
    public void ApplyForward_BondPlainTcpMux_StartsIngressBeforeBondRuntime()
    {
        using var tempDir = new TempStateDirectory();
        var supervisor = new TestProcessSupervisor();
        var stateManager = new StateManager(tempDir.Path);
        var manager = new ForwardRuntimeManager(
            CreateConfig(),
            supervisor,
            stateManager,
            binaryExists: _ => true,
            portListeningProbe: port => port is 20962 or 20963);

        manager.ApplyForward(CreatePlainTcpMuxBondForward());

        Assert.Equal(
            ["bond-ingress-leg-0", "bond-ingress-leg-1", "bond-runtime"],
            supervisor.StartedRoles);
    }

    [Fact]
    public void ApplyForward_BondPlainTcpMux_PersistsLegTopology()
    {
        using var tempDir = new TempStateDirectory();
        var supervisor = new TestProcessSupervisor();
        var stateManager = new StateManager(tempDir.Path);
        var manager = new ForwardRuntimeManager(
            CreateConfig(),
            supervisor,
            stateManager,
            binaryExists: _ => true,
            portListeningProbe: _ => true);

        manager.ApplyForward(CreatePlainTcpMuxBondForward());

        var applied = stateManager.LoadApplied()["26"];
        Assert.Equal("plain_tcp_mux", applied.IngressMode);
        Assert.Equal(2, applied.EndpointCount);
        Assert.Equal([20962, 20963], applied.LegPorts);
        Assert.Contains("bond-runtime", applied.ProcessRoles);
    }

    [Fact]
    public void ApplyForward_CfBondPlainTcpMux_ExcludesQuarantinedEndpointFromRuntime()
    {
        using var tempDir = new TempStateDirectory();
        var supervisor = new TestProcessSupervisor();
        var stateManager = new StateManager(tempDir.Path);
        var health = new BondEndpointHealthService(stateManager);
        health.ForceQuarantine(26, 184, "cf_bond_leg_probe_failed", null, nowUnixSeconds: 1_000);
        var manager = new ForwardRuntimeManager(
            CreateConfig(),
            supervisor,
            stateManager,
            binaryExists: _ => true,
            portListeningProbe: _ => true);

        manager.ApplyForward(CreateCfPlainTcpMuxBondForward());

        Assert.Equal(["bond-ingress-leg-0", "bond-runtime"], supervisor.StartedRoles);
        var ingress = Assert.Single(supervisor.StartedCommands, command => command.role == "bond-ingress-leg-0");
        Assert.Contains("--remote 8.163.90.235:7001", ingress.arguments);
        Assert.Contains("--auth-token token-b", ingress.arguments);
        var runtime = Assert.Single(supervisor.StartedCommands, command => command.role == "bond-runtime");
        Assert.Contains("endpoint_id=185", runtime.arguments);
        Assert.DoesNotContain("endpoint_id=184", runtime.arguments);
    }

    [Fact]
    public void ApplyForward_DoesNotThrow_WhenSupervisorSnapshotContainsUnassociatedProcesses()
    {
        using var tempDir = new TempStateDirectory();
        var supervisor = new UnassociatedSnapshotProcessSupervisor();
        var stateManager = new StateManager(tempDir.Path);
        var manager = new ForwardRuntimeManager(
            CreateConfig(),
            supervisor,
            stateManager,
            binaryExists: _ => true,
            portListeningProbe: port => port is 20962 or 20963);

        var ex = Record.Exception(() => manager.ApplyForward(CreatePlainTcpMuxBondForward()));

        Assert.Null(ex);
    }

    [Fact]
    public void IsForwardRunning_ReturnsTrue_WhenAppliedStateIsActiveAndCriticalRolesAreStillAlive()
    {
        using var tempDir = new TempStateDirectory();
        var supervisor = new TestProcessSupervisor();
        var stateManager = new StateManager(tempDir.Path);
        var manager = new ForwardRuntimeManager(
            CreateConfig(),
            supervisor,
            stateManager,
            binaryExists: _ => true,
            portListeningProbe: _ => true);

        manager.ApplyForward(CreatePlainTcpMuxBondForward());

        supervisor.RunningRoles.Clear();
        supervisor.RunningRoles.Add("bond-ingress-leg-0");
        supervisor.RunningRoles.Add("bond-ingress-leg-1");
        supervisor.RunningRoles.Add("bond-runtime");

        Assert.True(manager.IsForwardRunning(26));
    }



    [Fact]
    public void ApplyForward_MptcpWindowsCompatibility_ConnectsToPublicRelayHostInsteadOfPrivateUnderlayAddress()
    {
        using var tempDir = new TempStateDirectory();
        var supervisor = new TestProcessSupervisor();
        var stateManager = new StateManager(tempDir.Path);
        var config = CreateConfig();
        config.StateDir = tempDir.Path;
        config.MptcpRelayBin = @"C:\leikwan\bin\leikwan-mptcp-relay.exe";
        var manager = new ForwardRuntimeManager(
            config,
            supervisor,
            stateManager,
            binaryExists: _ => true,
            portListeningProbe: _ => true);

        var forward = CreateMptcpForward();
        forward.Mptcp!.SidecarHost = "10.203.201.135";
        forward.Mptcp.SidecarPort = 8898;
        forward.Mptcp.RelayHost = "185.220.238.143";
        forward.Mptcp.RelayPort = 8898;

        manager.ApplyForward(forward);

        var runtimeConfigPath = Path.Combine(tempDir.Path, "runtime", "mptcp-forward-24.json");
        using var document = JsonDocument.Parse(File.ReadAllText(runtimeConfigPath));
        var root = document.RootElement;
        Assert.Equal("185.220.238.143", root.GetProperty("remote_host").GetString());
        Assert.Equal(8898, root.GetProperty("remote_port").GetInt32());
        Assert.Equal("185.220.238.143", root.GetProperty("relay_host").GetString());
        Assert.Equal(8898, root.GetProperty("relay_port").GetInt32());
    }

    [Fact]
    public void ApplyForward_MptcpWindowsCompatibility_IgnoresPrivateSlaveRelayAddressWhenPublicRelayHostExists()
    {
        using var tempDir = new TempStateDirectory();
        var supervisor = new TestProcessSupervisor();
        var stateManager = new StateManager(tempDir.Path);
        var config = CreateConfig();
        config.StateDir = tempDir.Path;
        config.MptcpRelayBin = @"C:\leikwan\bin\leikwan-mptcp-relay.exe";
        var manager = new ForwardRuntimeManager(
            config,
            supervisor,
            stateManager,
            binaryExists: _ => true,
            portListeningProbe: _ => true);

        var forward = CreateMptcpForward();
        forward.Mptcp!.SlaveRelayHost = "10.203.201.178";
        forward.Mptcp.SlaveRelayPort = 38638;
        forward.Mptcp.RelayHost = "185.220.238.143";
        forward.Mptcp.RelayPort = 8898;
        forward.Mptcp.SidecarHost = "10.203.201.179";
        forward.Mptcp.SidecarPort = 38638;

        manager.ApplyForward(forward);

        var runtimeConfigPath = Path.Combine(tempDir.Path, "runtime", "mptcp-forward-24.json");
        using var document = JsonDocument.Parse(File.ReadAllText(runtimeConfigPath));
        var root = document.RootElement;
        Assert.Equal("185.220.238.143", root.GetProperty("remote_host").GetString());
        Assert.Equal(8898, root.GetProperty("remote_port").GetInt32());
        Assert.Equal(string.Empty, root.GetProperty("slave_relay_host").GetString());
        Assert.Equal(0, root.GetProperty("slave_relay_port").GetInt32());
    }

    [Fact]
    public void ApplyForward_MptcpTcpUdp_EmitsPublicListenHostAndKeepsUdpTransport()
    {
        using var tempDir = new TempStateDirectory();
        var supervisor = new TestProcessSupervisor();
        var stateManager = new StateManager(tempDir.Path);
        var config = CreateConfig();
        config.StateDir = tempDir.Path;
        config.MptcpRelayBin = @"C:\leikwan\bin\leikwan-mptcp-relay.exe";
        var manager = new ForwardRuntimeManager(
            config,
            supervisor,
            stateManager,
            binaryExists: _ => true,
            portListeningProbe: _ => true);

        var forward = CreateMptcpForward();
        forward.Transport = "tcp+udp";

        manager.ApplyForward(forward);

        var runtimeConfigPath = Path.Combine(tempDir.Path, "runtime", "mptcp-forward-24.json");
        using var document = JsonDocument.Parse(File.ReadAllText(runtimeConfigPath));
        var root = document.RootElement;
        Assert.Equal("0.0.0.0", root.GetProperty("listen_host").GetString());
        Assert.Equal("tcp+udp", root.GetProperty("transport").GetString());
        Assert.Equal("udp-session", root.GetProperty("udp_session_token").GetString());
        Assert.False(root.GetProperty("manage_mptcp_endpoints").GetBoolean());
    }

    [Fact]
    public void ApplyForward_Mptcp_WaitsForLocalListenPortReadiness()
    {
        using var tempDir = new TempStateDirectory();
        var supervisor = new TestProcessSupervisor();
        var stateManager = new StateManager(tempDir.Path);
        var config = CreateConfig();
        config.StateDir = tempDir.Path;
        config.MptcpRelayBin = @"C:\leikwan\bin\leikwan-mptcp-relay.exe";
        var probedPorts = new List<int>();
        var manager = new ForwardRuntimeManager(
            config,
            supervisor,
            stateManager,
            binaryExists: _ => true,
            portListeningProbe: port =>
            {
                probedPorts.Add(port);
                return true;
            });

        manager.ApplyForward(CreateMptcpForward());

        Assert.Contains(11023, probedPorts);
        Assert.Contains(11023, stateManager.LoadApplied()["24"].LegPorts);
    }

    [Fact]
    public void ApplyForward_MptcpPreconnectLeg_UsesSlaveRelayAndPreservesStructuredUnderlayFields()
    {
        using var tempDir = new TempStateDirectory();
        var supervisor = new TestProcessSupervisor();
        var stateManager = new StateManager(tempDir.Path);
        var config = CreateConfig();
        config.StateDir = tempDir.Path;
        config.MptcpRelayBin = @"C:\leikwan\bin\leikwan-mptcp-relay.exe";
        var manager = new ForwardRuntimeManager(
            config,
            supervisor,
            stateManager,
            binaryExists: _ => true,
            portListeningProbe: _ => true);

        var forward = CreateMptcpForward();
        forward.Mptcp!.SlaveRelayHost = "127.0.0.1";
        forward.Mptcp.SlaveRelayPort = 38012;
        forward.Mptcp.RelayHost = "relay-public.example.com";
        forward.Mptcp.RelayPort = 38012;
        forward.Mptcp.Underlays =
        [
            new UnderlayConfig
            {
                InterfaceName = "lw-mptcp-24-0",
                Legs =
                [
                    new UnderlayLeg
                    {
                        EndpointId = 184,
                        LocalAddress = "10.9.0.2",
                        PeerAddress = "10.9.0.1",
                        MptcpRemoteAddress = "10.203.15.202",
                        PeerPublicHost = "8.163.90.132",
                        PeerUdpPort = 51820,
                        OuterTransport = "wstunnel_udp_via_slave",
                        PeerTcpFallbackPort = 7001,
                        SlavePreconnect = true,
                        PreconnectRelayPort = 38012,
                        HealthStatus = "active",
                    },
                ],
            },
        ];

        manager.ApplyForward(forward, contractSignature: "sig", applyReason: "config_sync");

        var runtimeConfigPath = Path.Combine(tempDir.Path, "runtime", "mptcp-forward-24.json");
        using var document = JsonDocument.Parse(File.ReadAllText(runtimeConfigPath));
        var root = document.RootElement;

        Assert.Equal("127.0.0.1", root.GetProperty("remote_host").GetString());
        Assert.Equal(38012, root.GetProperty("remote_port").GetInt32());
        Assert.Equal("relay-public.example.com", root.GetProperty("relay_host").GetString());
        Assert.Equal(38012, root.GetProperty("relay_port").GetInt32());
        Assert.Equal("127.0.0.1", root.GetProperty("slave_relay_host").GetString());
        Assert.Equal(38012, root.GetProperty("slave_relay_port").GetInt32());

        var underlays = root.GetProperty("underlays");
        var underlay = Assert.Single(underlays.EnumerateArray());
        var leg = Assert.Single(underlay.GetProperty("legs").EnumerateArray());
        Assert.Equal("wstunnel_udp_via_slave", leg.GetProperty("outer_transport").GetString());
        Assert.True(leg.GetProperty("slave_preconnect").GetBoolean());
        Assert.Equal(38012, leg.GetProperty("preconnect_relay_port").GetInt32());
        Assert.Equal("active", leg.GetProperty("health_status").GetString());
    }

    [Fact]
    public void ApplyForward_Mptcp_WritesRuntimeConfigBeforeStartingRelay()
    {
        using var tempDir = new TempStateDirectory();
        var runtimeConfigPath = Path.Combine(tempDir.Path, "runtime", "mptcp-forward-24.json");
        var supervisor = new ConfigFileMustExistBeforeStartSupervisor(runtimeConfigPath);
        var stateManager = new StateManager(tempDir.Path);
        var config = CreateConfig();
        config.StateDir = tempDir.Path;
        config.MptcpRelayBin = @"C:\leikwan\bin\leikwan-mptcp-relay.exe";
        var manager = new ForwardRuntimeManager(
            config,
            supervisor,
            stateManager,
            binaryExists: _ => true,
            portListeningProbe: _ => true);

        manager.ApplyForward(CreateMptcpForward());

        Assert.True(supervisor.ConfigExistedWhenMptcpStarted);
    }

    [Fact]
    public void ApplyForward_Mptcp_StartsPortableRelayAndWritesRuntimeConfig()
    {
        using var tempDir = new TempStateDirectory();
        var supervisor = new TestProcessSupervisor();
        var stateManager = new StateManager(tempDir.Path);
        var config = CreateConfig();
        config.StateDir = tempDir.Path;
        config.MptcpRelayBin = @"C:\leikwan\bin\leikwan-mptcp-relay.exe";
        var manager = new ForwardRuntimeManager(
            config,
            supervisor,
            stateManager,
            binaryExists: _ => true,
            portListeningProbe: _ => true);

        manager.ApplyForward(CreateMptcpForward());

        Assert.Equal(["mptcp-runtime"], supervisor.StartedRoles);
        var runtime = Assert.Single(supervisor.StartedCommands, command => command.role == "mptcp-runtime");
        Assert.Equal("client --config \"" + Path.Combine(tempDir.Path, "runtime", "mptcp-forward-24.json") + "\"", runtime.arguments);

        var runtimeConfigPath = Path.Combine(tempDir.Path, "runtime", "mptcp-forward-24.json");
        Assert.True(File.Exists(runtimeConfigPath));
        var payload = File.ReadAllText(runtimeConfigPath);
        Assert.Contains("\"forward_id\": 24", payload);
        Assert.Contains("\"listen_port\": 11023", payload);
        Assert.Contains("\"remote_host\": \"sidecar.example.com\"", payload);
        Assert.Contains("\"remote_port\": 14969", payload);
        Assert.Contains("\"tcp_session_token\": \"tcp-session\"", payload);
        Assert.Contains("\"allow_tcp_fallback\": true", payload);
        Assert.Contains("\"windows_compatibility_mode\": true", payload);

        var applied = stateManager.LoadApplied()["24"];
        Assert.Equal("mptcp", applied.Mode);
        Assert.Equal("tcp_fallback", applied.IngressMode);
        Assert.Contains("mptcp-runtime", applied.ProcessRoles);
    }

    [Fact]
    public void ApplyForward_MptcpWithoutUsableRelay_FallsBackToBondRuntime()
    {
        using var tempDir = new TempStateDirectory();
        var supervisor = new TestProcessSupervisor();
        var stateManager = new StateManager(tempDir.Path);
        var config = CreateConfig();
        config.StateDir = tempDir.Path;
        config.MptcpRelayBin = @"C:\leikwan\bin\leikwan-mptcp-relay.exe";
        var manager = new ForwardRuntimeManager(
            config,
            supervisor,
            stateManager,
            binaryExists: _ => true,
            portListeningProbe: _ => true);

        var forward = CreateMptcpForward();
        forward.Mptcp!.SlaveRelayHost = string.Empty;
        forward.Mptcp.SlaveRelayPort = 0;
        forward.Mptcp.SidecarHost = string.Empty;
        forward.Mptcp.SidecarPort = 0;
        forward.Mptcp.RelayHost = string.Empty;
        forward.Mptcp.RelayPort = 0;

        manager.ApplyForward(forward, contractSignature: "sig", applyReason: "config_sync");

        Assert.Contains("bond-runtime", supervisor.StartedRoles);
        Assert.DoesNotContain("mptcp-runtime", supervisor.StartedRoles);

        var applied = stateManager.LoadApplied()["24"];
        Assert.Equal("bond", applied.Mode);
    }

    [Fact]
    public void ComputeContractSignature_IgnoresSidepathForUnselectedForward()
    {
        using var tempDir = new TempStateDirectory();
        var config = CreateConfig();
        config.StateDir = tempDir.Path;
        config.UdpUotSidepath = new UdpUotSidepathConfig
        {
            EnabledForwardIds = [99],
            SchedulerMode = "feedback_drr",
            DeliveryMode = "unordered",
            WireguardAware = true,
        };
        var manager = new ForwardRuntimeManager(
            config,
            new TestProcessSupervisor(),
            new StateManager(tempDir.Path),
            binaryExists: _ => true,
            portListeningProbe: _ => true);
        var forward = CreatePlainTcpMuxBondForward();

        Assert.Equal(
            LeikwanBridge.Core.Helpers.ForwardContractSignature.Compute(forward),
            manager.ComputeContractSignature(forward));
    }

    [Fact]
    public void ApplyForward_FeedbackCapabilityFailure_PreservesExistingRuntime()
    {
        using var tempDir = new TempStateDirectory();
        var config = CreateConfig();
        config.StateDir = tempDir.Path;
        config.UotBondBin = Path.Combine(tempDir.Path, "missing-uot-runtime.exe");
        config.UdpUotSidepath = new UdpUotSidepathConfig
        {
            EnabledForwardIds = [26],
            SchedulerMode = "feedback_drr",
            DeliveryMode = "unordered",
            WireguardAware = true,
        };
        var supervisor = new TestProcessSupervisor();
        supervisor.RunningRoles.Add("existing-runtime");
        var stateManager = new StateManager(tempDir.Path);
        var manager = new ForwardRuntimeManager(
            config,
            supervisor,
            stateManager,
            binaryExists: _ => true,
            portListeningProbe: _ => true);

        manager.ApplyForward(CreatePlainTcpMuxBondForward());

        Assert.Contains("existing-runtime", supervisor.RunningRoles);
        Assert.Empty(supervisor.StartedRoles);
        Assert.Empty(stateManager.LoadApplied());
    }

    private static AgentConfig CreateConfig()
    {
        return new AgentConfig
        {
            BondBin = @"C:\leikwan\bin\leikwan-bond.exe",
            BondIngressBin = @"C:\leikwan\bin\leikwan-ingress.exe",
            WstunnelBin = @"C:\leikwan\bin\wstunnel.exe",
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
            PathPrefix = "forward-26",
            Transport = "tcp",
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
                WarmupTriggerBytes = 65536,
                WarmupSliceBytes = 65536,
                WarmupLegDelayMs = 80,
                BulkPromoteBytes = 262144,
                LegCooldownMs = 1800000,
                DisableLocalRateLimit = true,
                QuicEnabled = false,
                QuicViaSlave = false,
                WssStandbyEnabled = false,
            },
        };
    }

    private static ForwardConfig CreateCfPlainTcpMuxBondForward()
    {
        var forward = CreatePlainTcpMuxBondForward();
        forward.BackendMode = "cf_mode";
        forward.Bonding!.PolicyMode = "latency_first";
        return forward;
    }

    private static ForwardConfig CreateMptcpForward()
    {
        return new ForwardConfig
        {
            ForwardId = 24,
            Mode = "mptcp",
            ListenPort = 11023,
            Target = "193.227.109.82:5201",
            Sni = "v3.leikwanhost.com",
            PathPrefix = "forward-24",
            Transport = "tcp",
            Endpoints =
            [
                new EndpointConfig
                {
                    EndpointId = 184,
                    Host = "8.163.90.132",
                    Port = 7001,
                },
            ],
            Mptcp = new MptcpConfig
            {
                SidecarHost = "sidecar.example.com",
                SidecarPort = 14969,
                TcpSessionToken = "tcp-session",
                UdpSessionToken = "udp-session",
                ConnectTimeoutMs = 2500,
                RelayIdleTimeoutSeconds = 90,
                SocketBufferBytes = 4194304,
                SubflowLimit = 2,
                ManageMptcpEndpoints = true,
            },
        };
    }

    private sealed class ConfigFileMustExistBeforeStartSupervisor(string expectedConfigPath) : ProcessSupervisor
    {
        public bool ConfigExistedWhenMptcpStarted { get; private set; }
        public List<string> StartedRoles { get; } = [];

        public override System.Diagnostics.Process StartProcess(
            int forwardId,
            string role,
            string label,
            string exePath,
            string arguments,
            string? workingDir = null)
        {
            if (role == "mptcp-runtime")
                ConfigExistedWhenMptcpStarted = File.Exists(expectedConfigPath);

            StartedRoles.Add(role);
            return new System.Diagnostics.Process();
        }

        public override bool IsRunning(int forwardId) => StartedRoles.Count > 0;
    }

    private sealed class UnassociatedSnapshotProcessSupervisor : ProcessSupervisor
    {
        private readonly List<ManagedProcess> _processes = [];

        public override System.Diagnostics.Process StartProcess(
            int forwardId,
            string role,
            string label,
            string exePath,
            string arguments,
            string? workingDir = null)
        {
            var process = new System.Diagnostics.Process();
            _processes.Add(new ManagedProcess(forwardId, role, label, process, DateTime.UtcNow));
            return process;
        }

        public override IReadOnlyList<ManagedProcess> GetProcesses(int forwardId)
        {
            return _processes.Where(process => process.ForwardId == forwardId).ToList();
        }

        public override void StopAll(int forwardId)
        {
        }
    }
}
