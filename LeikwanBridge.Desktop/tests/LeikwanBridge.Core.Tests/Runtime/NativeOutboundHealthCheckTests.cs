using LeikwanBridge.Core.Models;
using LeikwanBridge.Core.Services;
using Serilog.Events;
using Xunit;

namespace LeikwanBridge.Core.Tests.Runtime;

/// <summary>
/// A panel-declared native outbound rule owns no agent-managed runtime: its data
/// plane belongs to a user-initiated native outbound relay connection. The
/// periodic health cycle must therefore skip it completely -- no restart, no
/// applied state churn, and no warning on every tick.
/// </summary>
[Collection(SerilogCapture.CollectionName)]
public sealed class NativeOutboundHealthCheckTests
{
    [Fact]
    public async Task RunCheckOnceAsync_NativeOutboundRule_DoesNotRestartOrTouchAppliedState()
    {
        const int forwardId = 9180;
        using var tempDir = new TempStateDirectory();
        var supervisor = new TestProcessSupervisor();
        var stateManager = new StateManager(tempDir.Path);
        var runtimeManager = new TestForwardRuntimeManager(stateManager, supervisor);
        var service = new HealthCheckService(supervisor, runtimeManager, stateManager, _ => false);
        var healthIssues = new List<(int ForwardId, string Issue)>();
        service.HealthIssue += (id, issue) => healthIssues.Add((id, issue));

        using var logs = new LogCapture();
        await service.RunCheckOnceAsync([CreateNativeOutboundForward(forwardId)]);
        await service.RunCheckOnceAsync([CreateNativeOutboundForward(forwardId)]);

        Assert.Empty(runtimeManager.RestartedForwardIds);
        Assert.Empty(runtimeManager.AppliedForwardIds);
        Assert.Empty(healthIssues);
        Assert.Empty(stateManager.LoadApplied());

        var events = logs.ForForward(forwardId);
        Assert.DoesNotContain(events, logEvent => logEvent.Level >= LogEventLevel.Warning);
        Assert.DoesNotContain(events, logEvent =>
            logEvent.RenderMessage().Contains("restarting", StringComparison.Ordinal));
        Assert.DoesNotContain(events, logEvent =>
            logEvent.RenderMessage().Contains("not listening", StringComparison.Ordinal));
    }

    [Fact]
    public async Task RunCheckOnceAsync_NativeOutboundRule_ExplainsTheSkipOnlyOnce()
    {
        const int forwardId = 9181;
        using var tempDir = new TempStateDirectory();
        var supervisor = new TestProcessSupervisor();
        var stateManager = new StateManager(tempDir.Path);
        var runtimeManager = new TestForwardRuntimeManager(stateManager, supervisor);
        var service = new HealthCheckService(supervisor, runtimeManager, stateManager, _ => false);

        using var logs = new LogCapture();
        await service.RunCheckOnceAsync([CreateNativeOutboundForward(forwardId)]);
        await service.RunCheckOnceAsync([CreateNativeOutboundForward(forwardId)]);
        await service.RunCheckOnceAsync([CreateNativeOutboundForward(forwardId)]);

        var notices = logs.ForForward(forwardId)
            .Where(logEvent => logEvent.RenderMessage().Contains(
                "agent-managed runtime not applicable", StringComparison.Ordinal))
            .ToList();
        var notice = Assert.Single(notices);
        Assert.Equal(LogEventLevel.Information, notice.Level);
    }

    [Fact]
    public async Task RunCheckOnceAsync_NativeOutboundRuleWithLeftoverAppliedState_LeavesStateToTheRuntimeManager()
    {
        const int forwardId = 9182;
        using var tempDir = new TempStateDirectory();
        var supervisor = new TestProcessSupervisor();
        var stateManager = new StateManager(tempDir.Path);
        var runtimeManager = new TestForwardRuntimeManager(stateManager, supervisor);
        stateManager.SaveApplied(new Dictionary<string, AppliedForwardState>
        {
            [forwardId.ToString()] = new AppliedForwardState { ForwardId = forwardId, Mode = "ultra", ListenPort = 54544 },
        });
        var service = new HealthCheckService(supervisor, runtimeManager, stateManager, _ => false);

        await service.RunCheckOnceAsync([CreateNativeOutboundForward(forwardId)]);

        Assert.True(stateManager.LoadApplied().ContainsKey(forwardId.ToString()));
        Assert.Empty(runtimeManager.RestartedForwardIds);
    }

    [Fact]
    public async Task RunCheckOnceAsync_OrdinaryUltraForwardWithoutNativeFlag_StillUsesTheRestartPath()
    {
        const int forwardId = 9183;
        using var tempDir = new TempStateDirectory();
        var supervisor = new TestProcessSupervisor();
        var stateManager = new StateManager(tempDir.Path);
        var runtimeManager = new TestForwardRuntimeManager(stateManager, supervisor);
        var service = new HealthCheckService(supervisor, runtimeManager, stateManager, _ => false);
        var forward = CreateNativeOutboundForward(forwardId);
        forward.NativeOutboundEnabled = false;

        await service.RunCheckOnceAsync([forward]);

        Assert.Contains(forwardId, runtimeManager.RestartedForwardIds);
    }

    [Fact]
    public async Task RunCheckOnceAsync_MixedCatalog_OnlySkipsTheNativeOutboundRule()
    {
        const int nativeForwardId = 9184;
        const int ordinaryForwardId = 9185;
        using var tempDir = new TempStateDirectory();
        var supervisor = new TestProcessSupervisor();
        var stateManager = new StateManager(tempDir.Path);
        var runtimeManager = new TestForwardRuntimeManager(stateManager, supervisor);
        var service = new HealthCheckService(supervisor, runtimeManager, stateManager, _ => false);
        var ordinary = CreateNativeOutboundForward(ordinaryForwardId);
        ordinary.NativeOutboundEnabled = false;

        await service.RunCheckOnceAsync([CreateNativeOutboundForward(nativeForwardId), ordinary]);

        Assert.Equal([ordinaryForwardId], runtimeManager.RestartedForwardIds);
    }

    private static ForwardConfig CreateNativeOutboundForward(int forwardId)
    {
        return new ForwardConfig
        {
            ForwardId = forwardId,
            ServiceStatus = "active",
            BackendMode = "ultra",
            Mode = "single",
            ListenPort = 54544,
            Target = "198.51.100.10:443",
            Transport = "tcp",
            NativeOutboundEnabled = true,
            NativeDirection = "Mainland China -> Hong Kong",
        };
    }
}
