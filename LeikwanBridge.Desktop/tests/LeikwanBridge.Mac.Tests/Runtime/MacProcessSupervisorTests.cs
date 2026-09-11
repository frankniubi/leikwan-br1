using System.Diagnostics;
using LeikwanBridge.Mac.Services;
using Xunit;

namespace LeikwanBridge.Mac.Tests.Runtime;

public sealed class MacProcessSupervisorTests
{
    [Fact]
    public void StopAll_WhenProcessGroupCreationFailed_SignalsOwnedPidDirectly()
    {
        Process? process = null;
        var signals = new List<int>();
        using var supervisor = new MacProcessSupervisor(
            processStarter: info => process = Process.Start(info)!,
            setProcessGroup: (_, _) => -1,
            signalProcessGroup: (pid, _) =>
            {
                signals.Add(pid);
                if (pid == process!.Id && !process.HasExited) process.Kill(entireProcessTree: true);
                return pid > 0 ? 0 : -1;
            },
            stopTimeout: TimeSpan.FromMilliseconds(50));
        var command = LongRunningCommand();
        var child = supervisor.StartProcess(77, "ultra-runtime", "test", command.Executable, command.Arguments);
        var id = child.Id;
        supervisor.StopAll(77);
        Assert.Contains(id, signals);
        Assert.DoesNotContain(-id, signals);
    }

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

    [Fact]
    public void StartProcess_ForwardsActualStderrLineInsteadOfGenericMarker()
    {
        var output = new List<string>();
        using var supervisor = new MacProcessSupervisor(
            onOutput: (_, _, line) =>
            {
                lock (output)
                    output.Add(line);
            },
            setProcessGroup: (_, _) => 0,
            signalProcessGroup: (_, _) => 0);
        var (executable, arguments) = StderrCommand();

        using var process = supervisor.StartProcess(44, "ultra-runtime", "Ultra runtime", executable, arguments);
        process.WaitForExit(2000);
        Assert.True(SpinWait.SpinUntil(() =>
        {
            lock (output)
                return output.Any(line => line.EndsWith("probe-line", StringComparison.Ordinal));
        }, TimeSpan.FromSeconds(2)));
        lock (output)
        {
            Assert.Contains(output, line => line.EndsWith("probe-line", StringComparison.Ordinal));
            Assert.DoesNotContain("process_stderr", output);
        }
    }

    private static (string Executable, string Arguments) LongRunningCommand()
    {
        return OperatingSystem.IsWindows()
            ? ("powershell.exe", "-NoProfile -Command Start-Sleep -Seconds 30")
            : ("/bin/sleep", "30");
    }

    private static (string Executable, string Arguments) StderrCommand()
    {
        return OperatingSystem.IsWindows()
            ? ("powershell.exe", "-NoProfile -Command [Console]::Error.WriteLine('probe-line'); Start-Sleep -Seconds 1")
            : ("/bin/sh", "-c \"printf 'probe-line\\n' >&2; sleep 1\"");
    }
}
