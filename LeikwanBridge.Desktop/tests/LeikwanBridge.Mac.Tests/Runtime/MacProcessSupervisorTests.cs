using System.Diagnostics;
using LeikwanBridge.Mac.Services;
using Serilog;
using Serilog.Core;
using Serilog.Events;
using Xunit;

namespace LeikwanBridge.Mac.Tests.Runtime;

[Collection("SerilogGlobalLogger")]
public sealed class MacProcessSupervisorTests
{
    [Fact]
    public void StopAll_SignalsTheChildPidNeverAProcessGroup()
    {
        // Children share the host's process group (setpgid after exec always
        // failed with EACCES, and a separate group escapes launchd's job
        // cleanup). A negative pid would signal the host's own group.
        Process? process = null;
        var signals = new List<int>();
        using var supervisor = new MacProcessSupervisor(
            processStarter: info => process = Process.Start(info)!,
            signalProcess: (pid, _) =>
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
        Assert.All(signals, pid => Assert.True(pid > 0));
    }

    [Fact]
    public void StartAndStop_SignalsPidAndClearsStandardInput()
    {
        Process? launched = null;
        ProcessStartInfo? capturedStart = null;
        var signals = new List<(int Pid, int Signal)>();
        var supervisor = new MacProcessSupervisor(
            processStarter: startInfo =>
            {
                capturedStart = startInfo;
                launched = Process.Start(startInfo) ?? throw new InvalidOperationException("test process did not start");
                return launched;
            },
            signalProcess: (pid, signal) =>
            {
                signals.Add((pid, signal));
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

        Assert.All(standardInput, value => Assert.Equal(0, value));
        Assert.False(capturedStart!.UseShellExecute);
        Assert.True(capturedStart.RedirectStandardInput);
        Assert.Equal([processId], supervisor.GetProcessIds(42));

        supervisor.StopAll(42);

        Assert.Contains(signals, item => item.Pid == processId && item.Signal == MacProcessSupervisor.SigTerm);
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
            signalProcess: (_, signal) =>
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
            signalProcess: (_, _) => 0);
        var (executable, arguments) = StderrCommand("probe-line");

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

    [Fact]
    public void UltraRuntimeStderrFailure_IsLoggedAtWarningNotOnlyDebug()
    {
        // macOS must surface runtime data-plane failures like Windows does,
        // through ProcessSupervisor.ObserveUltraRuntimeFailure.
        var sink = new CollectingSink();
        var previous = Log.Logger;
        Log.Logger = new LoggerConfiguration().MinimumLevel.Information().WriteTo.Sink(sink).CreateLogger();
        try
        {
            using var supervisor = new MacProcessSupervisor(signalProcess: (_, _) => 0);
            var (executable, arguments) = StderrCommand("udp candidate failed candidate=abc reason=timeout");
            using var process = supervisor.StartProcess(45, "ultra-runtime", "Ultra runtime", executable, arguments);
            process.WaitForExit(3000);
            Assert.True(SpinWait.SpinUntil(
                () => sink.Snapshot().Any(item => item.Level == LogEventLevel.Warning
                    && item.MessageTemplate.Text.StartsWith("ultra runtime failure", StringComparison.Ordinal)),
                TimeSpan.FromSeconds(3)));
        }
        finally
        {
            Log.Logger = previous;
        }
    }

    [Fact]
    public void UltraRuntimeRelayReset_RaisesCredentialSuspectForFailureDrivenRenewal()
    {
        // A line re-acquisition kills the credential; the relay then aborts
        // every stream. ConfigPollingService renews on this event.
        var raised = new List<(int ForwardId, string Kind)>();
        using var supervisor = new MacProcessSupervisor(signalProcess: (_, _) => 0);
        supervisor.UltraCredentialSuspect += (forwardId, kind) =>
        {
            lock (raised)
                raised.Add((forwardId, kind));
        };
        var (executable, arguments) = StderrCommand(
            "tcp stream ended forward_id=46 direction=upstream stage=remote_write reason=reset generation=g");
        using var process = supervisor.StartProcess(46, "ultra-runtime", "Ultra runtime", executable, arguments);
        process.WaitForExit(3000);
        Assert.True(SpinWait.SpinUntil(() => { lock (raised) return raised.Count == 1; }, TimeSpan.FromSeconds(3)));
        lock (raised)
            Assert.Equal((46, "tcp_stream_ended"), raised[0]);
    }

    [Fact]
    public void UltraRuntimeLocalClose_DoesNotRaiseCredentialSuspect()
    {
        var raised = 0;
        using var supervisor = new MacProcessSupervisor(signalProcess: (_, _) => 0);
        supervisor.UltraCredentialSuspect += (_, _) => Interlocked.Increment(ref raised);
        var (executable, arguments) = StderrCommand(
            "tcp stream ended forward_id=47 direction=upstream stage=local_read reason=reset generation=g");
        using var process = supervisor.StartProcess(47, "ultra-runtime", "Ultra runtime", executable, arguments);
        process.WaitForExit(3000);
        Thread.Sleep(300);
        Assert.Equal(0, Volatile.Read(ref raised));
    }

    [Fact]
    public void Reaper_SelectsOnlyOrphansOfThisBundleAndUid()
    {
        const string exe = "/Applications/LeiKwan Bridge.app/Contents/Resources/bin/ultra-client";
        var processes = new List<MacProcessEntry>
        {
            new(100, 1, 0, exe + " --config-stdin"),                       // orphan: reap
            new(101, 555, 0, exe + " --config-stdin"),                     // live host's child
            new(102, 1, 501, exe + " --config-stdin"),                     // another uid
            new(103, 1, 0, "/opt/other/ultra-client --config-stdin"),      // another bundle
            new(104, 1, 0, exe + "-helper --x"),                           // prefix but a different binary
            new(105, 1, 0, exe),                                           // orphan without arguments
            new(4242, 1, 0, exe + " --config-stdin"),                      // this process
            new(1, 0, 0, "/sbin/launchd"),
        };

        var orphans = MacOrphanRuntimeReaper.SelectOrphans(processes, [exe], currentUid: 0, selfPid: 4242);

        Assert.Equal([100, 105], orphans.Select(item => item.Pid).ToArray());
    }

    [Fact]
    public void Reaper_TermsThenKillsSurvivors()
    {
        const string exe = "/Applications/LeiKwan Bridge.app/Contents/Resources/bin/ultra-client";
        var alive = new HashSet<int> { 200, 201 };
        var signals = new List<(int Pid, int Signal)>();
        var reaper = new MacOrphanRuntimeReaper(
            () => [new(200, 1, 0, exe + " --config-stdin"), new(201, 1, 0, exe + " --config-stdin")],
            (pid, signal) =>
            {
                signals.Add((pid, signal));
                // 200 honours SIGTERM, 201 ignores it.
                if (pid == 200 || signal == MacProcessSupervisor.SigKill)
                    alive.Remove(pid);
                return 0;
            },
            pid => alive.Contains(pid),
            currentUid: 0,
            selfPid: 1234,
            gracePeriod: TimeSpan.FromMilliseconds(50),
            sleep: _ => Thread.Sleep(5));

        var reaped = reaper.Reap([exe]);

        Assert.Equal(2, reaped.Count);
        Assert.Contains((200, MacProcessSupervisor.SigTerm), signals);
        Assert.Contains((201, MacProcessSupervisor.SigTerm), signals);
        Assert.Contains((201, MacProcessSupervisor.SigKill), signals);
        Assert.DoesNotContain((200, MacProcessSupervisor.SigKill), signals);
        Assert.Empty(alive);
    }

    [Fact]
    public void Reaper_ScanFailureIsHarmless()
    {
        var reaper = new MacOrphanRuntimeReaper(
            () => throw new InvalidOperationException("ps_failed"),
            (_, _) => throw new InvalidOperationException("must not signal"),
            _ => false,
            currentUid: 0,
            selfPid: 1);

        Assert.Empty(reaper.Reap(["/x/ultra-client"]));
    }

    [Fact]
    public void Reaper_ParsesPsOutputWithSpacesInPaths()
    {
        const string output = """
              PID  PPID   UID COMMAND
              1     0     0 /sbin/launchd
            812     1     0 /Applications/LeiKwan Bridge.app/Contents/Resources/bin/ultra-client --config-stdin
            garbage line
            913   812   501 /bin/sleep 30
            """;

        var entries = MacOrphanRuntimeReaper.ParsePsOutput(output);

        Assert.Equal(3, entries.Count);
        Assert.Equal(new MacProcessEntry(812, 1, 0,
            "/Applications/LeiKwan Bridge.app/Contents/Resources/bin/ultra-client --config-stdin"), entries[1]);
        Assert.Equal(913, entries[2].Pid);
        Assert.Equal(501, entries[2].Uid);
    }

    private static (string Executable, string Arguments) LongRunningCommand()
    {
        return OperatingSystem.IsWindows()
            ? ("powershell.exe", "-NoProfile -Command Start-Sleep -Seconds 30")
            : ("/bin/sleep", "30");
    }

    private static (string Executable, string Arguments) StderrCommand(string line)
    {
        return OperatingSystem.IsWindows()
            ? ("powershell.exe", $"-NoProfile -Command [Console]::Error.WriteLine('{line}'); Start-Sleep -Seconds 1")
            : ("/bin/sh", $"-c \"printf '{line}\\n' >&2; sleep 1\"");
    }

    private sealed class CollectingSink : ILogEventSink
    {
        private readonly List<LogEvent> _events = [];

        public void Emit(LogEvent logEvent)
        {
            lock (_events)
                _events.Add(logEvent);
        }

        public IReadOnlyList<LogEvent> Snapshot()
        {
            lock (_events)
                return [.. _events];
        }
    }
}

/// <summary>
/// Tests that swap the global Serilog logger run alone, so a parallel test that
/// reconfigures logging (the headless host does) cannot steal their events.
/// </summary>
[CollectionDefinition("SerilogGlobalLogger", DisableParallelization = true)]
public sealed class SerilogGlobalLoggerCollection;
