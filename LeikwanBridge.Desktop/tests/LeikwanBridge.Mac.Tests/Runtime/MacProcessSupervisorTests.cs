using System.Diagnostics;
using LeikwanBridge.Mac.Services;
using Xunit;

namespace LeikwanBridge.Mac.Tests.Runtime;

public sealed class MacProcessSupervisorTests
{
    [Fact]
    public void StartAndStop_UsesDedicatedProcessGroupAndClearsStandardInput()
    {
        Process? launched = null;
        ProcessStartInfo? capturedStart = null;
        var setGroups = new List<(int Pid, int GroupId)>();
        var signals = new List<(int GroupId, int Signal)>();
        var supervisor = new MacProcessSupervisor(
            processStarter: startInfo =>
            {
                capturedStart = startInfo;
                launched = Process.Start(startInfo) ?? throw new InvalidOperationException("test process did not start");
                return launched;
            },
            setProcessGroup: (pid, groupId) =>
            {
                setGroups.Add((pid, groupId));
                return 0;
            },
            signalProcessGroup: (groupId, signal) =>
            {
                signals.Add((groupId, signal));
                if (launched is { HasExited: false })
                    launched.Kill(entireProcessTree: true);
                return 0;
            },
            stopTimeout: TimeSpan.FromMilliseconds(100));
        var (executable, arguments) = LongRunningCommand();
        var standardInput = "bootstrap-canary"u8.ToArray();

        var process = supervisor.StartProcess(
            42,
            "ultra-runtime",
            "Ultra runtime",
            executable,
            arguments,
            standardInput,
            logArguments: false);
        var processId = process.Id;

        Assert.Equal([(processId, processId)], setGroups);
        Assert.All(standardInput, value => Assert.Equal(0, value));
        Assert.False(capturedStart!.UseShellExecute);
        Assert.True(capturedStart.RedirectStandardInput);
        Assert.Equal([processId], supervisor.GetProcessIds(42));

        supervisor.StopAll(42);

        Assert.Contains(signals, item => item.GroupId == -processId && item.Signal == MacProcessSupervisor.SigTerm);
        Assert.False(supervisor.IsRunning(42));
        supervisor.Dispose();
    }

    [Fact]
    public void StopAll_EscalatesToSigKillAfterTimeout()
    {
        Process? launched = null;
        var signals = new List<int>();
        var supervisor = new MacProcessSupervisor(
            processStarter: startInfo =>
            {
                launched = Process.Start(startInfo) ?? throw new InvalidOperationException("test process did not start");
                return launched;
            },
            setProcessGroup: (_, _) => 0,
            signalProcessGroup: (_, signal) =>
            {
                signals.Add(signal);
                if (signal == MacProcessSupervisor.SigKill && launched is { HasExited: false })
                    launched.Kill(entireProcessTree: true);
                return 0;
            },
            stopTimeout: TimeSpan.FromMilliseconds(25));
        var (executable, arguments) = LongRunningCommand();
        supervisor.StartProcess(43, "ultra-runtime", "Ultra runtime", executable, arguments);

        supervisor.StopAll(43);

        Assert.Equal([MacProcessSupervisor.SigTerm, MacProcessSupervisor.SigKill], signals);
        supervisor.Dispose();
    }

    private static (string Executable, string Arguments) LongRunningCommand()
    {
        return OperatingSystem.IsWindows()
            ? ("powershell.exe", "-NoProfile -Command Start-Sleep -Seconds 30")
            : ("/bin/sleep", "30");
    }
}
