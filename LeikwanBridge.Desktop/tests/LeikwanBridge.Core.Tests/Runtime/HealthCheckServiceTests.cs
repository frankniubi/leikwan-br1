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
    public void ProcessExited_BondIngressExit_RestartsWholeForward()
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

        Assert.Contains(26, runtimeManager.RestartedForwardIds);
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
    public async Task RunCheckOnceAsync_CfBondOneLegFailure_QuarantinesEndpointAndRefreshesForward()
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
            cfBondLegProbe: (_, endpoint, _) => endpoint.EndpointId == 184
                ? (false, null, "cf_bond_leg_probe_failed")
                : (true, 12.5, null));

        var forward = CreatePlainTcpMuxBondForward();
        forward.BackendMode = "cf_mode";
        forward.Endpoints[0].EndpointId = 184;
        forward.Endpoints[1].EndpointId = 185;

        await service.RunCheckOnceAsync([forward]);

        Assert.Contains(26, runtimeManager.RestartedForwardIds);
        var entry = stateManager.LoadBondEndpointHealth()["26"]["184"];
        Assert.Equal("quarantined", entry.Role);
        Assert.Equal("cf_bond_leg_probe_failed", entry.LastError);
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
}
