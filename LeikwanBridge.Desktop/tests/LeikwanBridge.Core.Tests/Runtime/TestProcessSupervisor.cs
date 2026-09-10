using LeikwanBridge.Core.Services;

namespace LeikwanBridge.Core.Tests.Runtime;

internal sealed class TestProcessSupervisor : ProcessSupervisor
{
    public List<string> StartedRoles { get; } = [];
    public List<(string role, string arguments)> StartedCommands { get; } = [];
    public HashSet<string> RunningRoles { get; } = [];

    public TestProcessSupervisor() : base()
    {
    }

    public override System.Diagnostics.Process StartProcess(
        int forwardId,
        string role,
        string label,
        string exePath,
        string arguments,
        byte[]? standardInput = null,
        bool logArguments = true,
        string? workingDirectory = null)
    {
        StartedRoles.Add(role);
        StartedCommands.Add((role, arguments));
        RunningRoles.Add(role);
        return new System.Diagnostics.Process();
    }

    public override void StopAll(int forwardId)
    {
        RunningRoles.Clear();
    }

    public override bool IsRunning(int forwardId)
    {
        return RunningRoles.Count > 0;
    }

    public override IReadOnlyList<string> GetRunningRoles(int forwardId)
    {
        return RunningRoles.ToList();
    }

    public void TriggerExit(int forwardId, string role)
    {
        RunningRoles.Remove(role);
        RaiseProcessExited(forwardId, role);
    }
}
