using System.Diagnostics;

namespace LeikwanBridge.Core.Services.Platform;

public interface IProcessSupervisor : IDisposable
{
    event Action<int, string>? ProcessExited;

    Process StartProcess(
        int forwardId,
        string role,
        string label,
        string exePath,
        string arguments,
        byte[]? standardInput = null,
        bool logArguments = true,
        string? workingDirectory = null);

    void StopAll(int forwardId);
    void StopAll();
    bool IsRunning(int forwardId);
    IReadOnlyList<int> GetProcessIds(int forwardId);
    IReadOnlyList<string> GetRunningRoles(int forwardId);
}
