using LeikwanBridge.Core.Models;
using LeikwanBridge.Core.Services;

namespace LeikwanBridge.Core.Tests.Runtime;

internal sealed class TestForwardRuntimeManager : ForwardRuntimeManager
{
    public TestForwardRuntimeManager(StateManager stateManager, ProcessSupervisor supervisor)
        : base(new AgentConfig(), supervisor, stateManager, binaryExists: _ => true, portListeningProbe: _ => true)
    {
    }

    public List<int> RestartedForwardIds { get; } = [];
    public List<int> AppliedForwardIds { get; } = [];

    public override void ApplyForward(ForwardConfig forward)
    {
        AppliedForwardIds.Add(forward.ForwardId);
    }

    public override void RestartForward(ForwardConfig forward)
    {
        RestartedForwardIds.Add(forward.ForwardId);
    }

    public override void RestartForward(ForwardConfig forward, bool force, string reason)
    {
        RestartedForwardIds.Add(forward.ForwardId);
    }
}
