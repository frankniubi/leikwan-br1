using LeikwanBridge.Core.Models;
using LeikwanBridge.Core.Services;
using Xunit;

namespace LeikwanBridge.Core.Tests.Runtime;

public sealed class ForwardRuntimeManagerTests
{
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
                LegCooldownMs = 3000,
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
        return forward;
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
