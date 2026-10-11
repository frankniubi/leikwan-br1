using LeikwanBridge.Core.Helpers;
using LeikwanBridge.Core.Models;
using LeikwanBridge.Core.Services;
using Xunit;

namespace LeikwanBridge.Core.Tests.Runtime;

public sealed class HealthCheckServiceTests
{
    [Fact]
    public async Task RunCheckOnceAsync_BondMissingLegPort_RestartsWholeForward()
    {
        using var tempDir = new TempStateDirectory();
        var supervisor = new TestProcessSupervisor();
        var stateManager = new StateManager(tempDir.Path);
        var runtimeManager = new TestForwardRuntimeManager(stateManager, supervisor);
        stateManager.SaveApplied(new Dictionary<string, AppliedForwardState>
        {
            ["26"] = CreateAppliedBondState(),
        });
        supervisor.RunningRoles.UnionWith(["bond-ingress-leg-0", "bond-ingress-leg-1", "bond-runtime"]);
        var service = new HealthCheckService(
            supervisor,
            runtimeManager,
            stateManager,
            port => port is 11024 or 20962);

        await service.RunCheckOnceAsync([CreatePlainTcpMuxBondForward()]);

        Assert.Contains(26, runtimeManager.RestartedForwardIds);
    }

    [Fact]
    public void ProcessExited_BondIngressExit_RequestsForceRestart()
    {
        using var tempDir = new TempStateDirectory();
        var supervisor = new TestProcessSupervisor();
        var stateManager = new StateManager(tempDir.Path);
        var runtimeManager = new ForceRecordingForwardRuntimeManager(stateManager, supervisor);
        stateManager.SaveApplied(new Dictionary<string, AppliedForwardState>
        {
            ["26"] = CreateAppliedBondState(),
        });
        supervisor.RunningRoles.UnionWith(["bond-ingress-leg-0", "bond-ingress-leg-1", "bond-runtime"]);
        var service = new HealthCheckService(
            supervisor,
            runtimeManager,
            stateManager,
            port => port is 11024 or 20962 or 20963);

        service.UpdateForwards([CreatePlainTcpMuxBondForward()]);
        supervisor.TriggerExit(26, "bond-ingress-leg-0");

        Assert.Contains((26, true, "critical_process_exit:bond-ingress-leg-0"), runtimeManager.RestartRequests);
    }

    [Fact]
    public void ProcessExited_DoesNotThrashRestart_WhenCooldownAlreadyActive()
    {
        using var tempDir = new TempStateDirectory();
        var supervisor = new TestProcessSupervisor();
        var stateManager = new StateManager(tempDir.Path);
        var runtimeManager = new TestForwardRuntimeManager(stateManager, supervisor);
        stateManager.SaveApplied(new Dictionary<string, AppliedForwardState>
        {
            ["26"] = CreateAppliedBondState(),
        });
        supervisor.RunningRoles.UnionWith(["bond-ingress-leg-0", "bond-ingress-leg-1", "bond-runtime"]);
        var service = new HealthCheckService(
            supervisor,
            runtimeManager,
            stateManager,
            port => port is 11024 or 20962 or 20963);

        service.UpdateForwards([CreatePlainTcpMuxBondForward()]);
        supervisor.TriggerExit(26, "bond-ingress-leg-0");
        supervisor.TriggerExit(26, "bond-ingress-leg-1");

        Assert.Single(runtimeManager.RestartedForwardIds);
    }

    [Fact]
    public void ProcessExited_UltraRuntimeExit_WaitsForConfigSyncInsteadOfRestartingWithoutLease()
    {
        using var tempDir = new TempStateDirectory();
        var supervisor = new TestProcessSupervisor();
        var stateManager = new StateManager(tempDir.Path);
        var runtimeManager = new ForceRecordingForwardRuntimeManager(stateManager, supervisor);
        stateManager.SaveApplied(new Dictionary<string, AppliedForwardState>
        {
            ["42"] = new AppliedForwardState
            {
                ForwardId = 42,
                Mode = "ultra",
                ListenPort = 12042,
                ProcessRoles = ["ultra-runtime"],
            },
        });
        supervisor.RunningRoles.Add("ultra-runtime");
        var service = new HealthCheckService(
            supervisor,
            runtimeManager,
            stateManager,
            _ => true);

        service.UpdateForwards([
            new ForwardConfig
            {
                ForwardId = 42,
                ServiceStatus = "active",
                BackendMode = "ultra",
                ListenPort = 12042,
                Target = "198.51.100.10:443",
                Transport = "tcp",
            },
        ]);
        supervisor.TriggerExit(42, "ultra-runtime");

        Assert.Empty(runtimeManager.RestartRequests);
    }

    [Fact]
    public void ProcessExited_AfterStop_DoesNotRestartAForwardDuringShutdown()
    {
        using var tempDir = new TempStateDirectory();
        var supervisor = new TestProcessSupervisor();
        var stateManager = new StateManager(tempDir.Path);
        var runtimeManager = new ForceRecordingForwardRuntimeManager(stateManager, supervisor);
        stateManager.SaveApplied(new Dictionary<string, AppliedForwardState>
        {
            ["26"] = CreateAppliedBondState(),
        });
        supervisor.RunningRoles.UnionWith(["bond-ingress-leg-0", "bond-ingress-leg-1", "bond-runtime"]);
        var service = new HealthCheckService(
            supervisor,
            runtimeManager,
            stateManager,
            port => port is 11024 or 20962 or 20963);

        service.UpdateForwards([CreatePlainTcpMuxBondForward()]);
        service.Stop();
        supervisor.TriggerExit(26, "bond-ingress-leg-0");

        Assert.Empty(runtimeManager.RestartRequests);
    }

    [Fact]
    public async Task RunCheckOnceAsync_UserStoppedForward_DoesNotRestart()
    {
        using var tempDir = new TempStateDirectory();
        var supervisor = new TestProcessSupervisor();
        var stateManager = new StateManager(tempDir.Path);
        var runtimeManager = new TestForwardRuntimeManager(stateManager, supervisor);
        var stoppedState = CreateAppliedBondState();
        stoppedState.UserStopped = true;
        stateManager.SaveApplied(new Dictionary<string, AppliedForwardState>
        {
            ["26"] = stoppedState,
        });
        var service = new HealthCheckService(
            supervisor,
            runtimeManager,
            stateManager,
            port => false);

        await service.RunCheckOnceAsync([CreatePlainTcpMuxBondForward()]);

        Assert.Empty(runtimeManager.RestartedForwardIds);
    }

    [Fact]
    public async Task RunCheckOnceAsync_PlainTcpBondOneLegFailure_QuarantinesEndpointAndRefreshesForward()
    {
        using var tempDir = new TempStateDirectory();
        var supervisor = new TestProcessSupervisor();
        var stateManager = new StateManager(tempDir.Path);
        var runtimeManager = new TestForwardRuntimeManager(stateManager, supervisor);
        stateManager.SaveApplied(new Dictionary<string, AppliedForwardState>
        {
            ["26"] = CreateAppliedBondState(),
        });
        supervisor.RunningRoles.UnionWith(["bond-ingress-leg-0", "bond-ingress-leg-1", "bond-runtime"]);
        var service = new HealthCheckService(
            supervisor,
            runtimeManager,
            stateManager,
            port => port is 11024 or 20962 or 20963,
            bondLegProbe: (_, endpoint, _) => endpoint.EndpointId == 184
                ? (false, null, "bond_leg_probe_failed")
                : (true, 12.5, null));

        var forward = CreatePlainTcpMuxBondForward();
        forward.BackendMode = "current_mode";
        forward.Endpoints[0].EndpointId = 184;
        forward.Endpoints[1].EndpointId = 185;

        await service.RunCheckOnceAsync([forward]);

        Assert.Contains(26, runtimeManager.RestartedForwardIds);
        var entry = stateManager.LoadBondEndpointHealth()["26"]["184"];
        Assert.Equal("quarantined", entry.Role);
        Assert.Equal("bond_leg_probe_failed", entry.LastError);
    }

    [Fact]
    public async Task RunCheckOnceAsync_MptcpPreconnectHealthFailure_RequestsForceRestart()
    {
        using var tempDir = new TempStateDirectory();
        var supervisor = new TestProcessSupervisor();
        var stateManager = new StateManager(tempDir.Path);
        var runtimeManager = new ForceRecordingForwardRuntimeManager(stateManager, supervisor);
        var forward = CreateMptcpPreconnectForward();
        var signature = ForwardContractSignature.Compute(EffectiveForwardConfigBuilder.Build(forward));
        stateManager.SaveApplied(new Dictionary<string, AppliedForwardState>
        {
            ["24"] = new AppliedForwardState
            {
                ForwardId = 24,
                Mode = "mptcp",
                ContractSignature = signature,
            },
        });
        supervisor.RunningRoles.Add("mptcp-runtime");
        var service = new HealthCheckService(
            supervisor,
            runtimeManager,
            stateManager,
            port => port == 11023,
            relayHealthProbe: (_, _) => false);

        await service.RunCheckOnceAsync([forward]);

        Assert.Contains((24, true, "preconnect_relay_unhealthy"), runtimeManager.RestartRequests);
    }

    [Fact]
    public async Task RunCheckOnceAsync_MptcpLegRelayHealth_ProbesLegPortWhenTopLevelRelayPortIsZero()
    {
        using var tempDir = new TempStateDirectory();
        var supervisor = new TestProcessSupervisor();
        var stateManager = new StateManager(tempDir.Path);
        var runtimeManager = new ForceRecordingForwardRuntimeManager(stateManager, supervisor);
        var forward = CreateMptcpPreconnectForward();
        forward.Mptcp!.SlaveRelayPort = 0;
        forward.Mptcp.Underlays[0].Legs[0].PreconnectRelayPort = 38012;
        var probedPorts = new List<int>();
        var signature = ForwardContractSignature.Compute(EffectiveForwardConfigBuilder.Build(forward));
        stateManager.SaveApplied(new Dictionary<string, AppliedForwardState>
        {
            ["24"] = new AppliedForwardState
            {
                ForwardId = 24,
                Mode = "mptcp",
                ContractSignature = signature,
            },
        });
        supervisor.RunningRoles.Add("mptcp-runtime");
        var service = new HealthCheckService(
            supervisor,
            runtimeManager,
            stateManager,
            port => port == 11023,
            relayHealthProbe: (_, relayPort) =>
            {
                probedPorts.Add(relayPort);
                return relayPort == 38012;
            });

        await service.RunCheckOnceAsync([forward]);

        Assert.Empty(runtimeManager.RestartRequests);
        Assert.Equal([38012], probedPorts);
    }

    [Fact]
    public async Task RunCheckOnceAsync_MptcpIgnoresStandbyLegRelayWhenActiveLegRelayIsHealthy()
    {
        using var tempDir = new TempStateDirectory();
        var supervisor = new TestProcessSupervisor();
        var stateManager = new StateManager(tempDir.Path);
        var runtimeManager = new ForceRecordingForwardRuntimeManager(stateManager, supervisor);
        var forward = CreateMptcpPreconnectForward();
        forward.Mptcp!.SlaveRelayPort = 38100;
        forward.Mptcp.Underlays[0].Legs =
        [
            new UnderlayLeg
            {
                EndpointId = 184,
                SlavePreconnect = true,
                PreconnectRelayPort = 38012,
                HealthStatus = "active",
            },
            new UnderlayLeg
            {
                EndpointId = 185,
                SlavePreconnect = true,
                PreconnectRelayPort = 38013,
                HealthStatus = "standby",
            },
        ];
        var probedPorts = new List<int>();
        var signature = ForwardContractSignature.Compute(EffectiveForwardConfigBuilder.Build(forward));
        stateManager.SaveApplied(new Dictionary<string, AppliedForwardState>
        {
            ["24"] = new AppliedForwardState
            {
                ForwardId = 24,
                Mode = "mptcp",
                ContractSignature = signature,
            },
        });
        supervisor.RunningRoles.Add("mptcp-runtime");
        var service = new HealthCheckService(
            supervisor,
            runtimeManager,
            stateManager,
            port => port == 11023,
            relayHealthProbe: (_, relayPort) =>
            {
                probedPorts.Add(relayPort);
                return relayPort == 38012;
            });

        await service.RunCheckOnceAsync([forward]);

        Assert.Empty(runtimeManager.RestartRequests);
        Assert.Equal([38012], probedPorts);
    }

    [Fact]
    public async Task RunCheckOnceAsync_MptcpLegacyUnderlay_UsesLegRelayPortBeforeTopLevelRelayPort()
    {
        using var tempDir = new TempStateDirectory();
        var supervisor = new TestProcessSupervisor();
        var stateManager = new StateManager(tempDir.Path);
        var runtimeManager = new ForceRecordingForwardRuntimeManager(stateManager, supervisor);
        var forward = CreateMptcpPreconnectForward();
        forward.Mptcp!.SlaveRelayPort = 38100;
        forward.Mptcp.Underlay = new UnderlayConfig
        {
            Legs =
            [
                new UnderlayLeg
                {
                    EndpointId = 184,
                    SlavePreconnect = true,
                    PreconnectRelayPort = 38012,
                    HealthStatus = "active",
                },
            ],
        };
        forward.Mptcp.Underlays = [];
        var probedPorts = new List<int>();
        var signature = ForwardContractSignature.Compute(EffectiveForwardConfigBuilder.Build(forward));
        stateManager.SaveApplied(new Dictionary<string, AppliedForwardState>
        {
            ["24"] = new AppliedForwardState
            {
                ForwardId = 24,
                Mode = "mptcp",
                ContractSignature = signature,
            },
        });
        supervisor.RunningRoles.Add("mptcp-runtime");
        var service = new HealthCheckService(
            supervisor,
            runtimeManager,
            stateManager,
            port => port == 11023,
            relayHealthProbe: (_, relayPort) =>
            {
                probedPorts.Add(relayPort);
                return relayPort == 38012;
            });

        await service.RunCheckOnceAsync([forward]);

        Assert.Empty(runtimeManager.RestartRequests);
        Assert.Equal([38012], probedPorts);
    }

    private static AppliedForwardState CreateAppliedBondState()
    {
        return new AppliedForwardState
        {
            ForwardId = 26,
            Mode = "bond",
            ListenPort = 11024,
            IngressMode = "plain_tcp_mux",
            EndpointCount = 2,
            LegPorts = [20962, 20963],
            ProcessRoles = ["bond-ingress-leg-0", "bond-ingress-leg-1", "bond-runtime"],
            ProcessLabels = ["bond-ingress-leg-0", "bond-ingress-leg-1", "bond-runtime"],
        };
    }

    private static ForwardConfig CreatePlainTcpMuxBondForward()
    {
        return new ForwardConfig
        {
            ForwardId = 26,
            ServiceStatus = "active",
            Mode = "bond",
            ListenPort = 11024,
            Transport = "tcp",
            Endpoints =
            [
                new EndpointConfig { Host = "8.163.90.132", Port = 7001, RouteKey = "forward-26", IngressAuthToken = "token-a", IngressProtocol = "plain_tcp_mux" },
                new EndpointConfig { Host = "8.163.90.235", Port = 7001, RouteKey = "forward-26", IngressAuthToken = "token-b", IngressProtocol = "plain_tcp_mux" },
            ],
            Bonding = new BondingConfig
            {
                RemoteTarget = "138.252.162.120:9000",
                SessionToken = "tcp-session",
                IngressMode = "plain_tcp_mux",
                RouteKey = "forward-26",
            },
        };
    }

    private static ForwardConfig CreateMptcpPreconnectForward()
    {
        return new ForwardConfig
        {
            ForwardId = 24,
            ServiceStatus = "active",
            Mode = "mptcp",
            ListenPort = 11023,
            Transport = "tcp",
            Mptcp = new MptcpConfig
            {
                SlaveRelayHost = "127.0.0.1",
                SlaveRelayPort = 38012,
                Underlays =
                [
                    new UnderlayConfig
                    {
                        Legs =
                        [
                            new UnderlayLeg
                            {
                                EndpointId = 184,
                                SlavePreconnect = true,
                                PreconnectRelayPort = 38012,
                            },
                        ],
                    },
                ],
            },
            Endpoints =
            [
                new EndpointConfig { EndpointId = 184, Host = "8.163.90.132", Port = 7001 },
            ],
        };
    }

    private sealed class ForceRecordingForwardRuntimeManager : ForwardRuntimeManager
    {
        public ForceRecordingForwardRuntimeManager(StateManager stateManager, ProcessSupervisor supervisor)
            : base(new AgentConfig(), supervisor, stateManager, binaryExists: _ => true, portListeningProbe: _ => true)
        {
        }

        public List<(int forwardId, bool force, string reason)> RestartRequests { get; } = [];

        public override void RestartForward(ForwardConfig forward, bool force, string reason)
        {
            RestartRequests.Add((forward.ForwardId, force, reason));
        }
    }
}
