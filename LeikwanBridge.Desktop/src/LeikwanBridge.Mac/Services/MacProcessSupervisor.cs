using System.Collections.Concurrent;
using System.Diagnostics;
using System.Runtime.InteropServices;
using System.Security.Cryptography;
using LeikwanBridge.Core.Services;
using LeikwanBridge.Core.Services.Platform;
using LeikwanBridge.Core.Services.Ultra;
using Serilog;

namespace LeikwanBridge.Mac.Services;

/// <summary>
/// Runs the forward helpers (ultra-client and friends) as direct children.
/// <para>
/// Children deliberately stay in the host's process group. The previous design
/// called setpgid(child, child) after Process.Start, which can never work: once
/// the child has exec'd, POSIX refuses setpgid from the parent with EACCES, so
/// every start logged process_group_create_failed errno=13 and fell back to
/// the direct pid anyway. Worse, a child in its own group would escape
/// launchd's job cleanup: when a LaunchDaemon's main process dies, launchd kills
/// the remaining processes of the job's process group (unless
/// AbandonProcessGroup is set), which is exactly what reaps a runtime orphaned
/// by a host crash. Orphans that survive anyway (an interactive run, a GUI
/// crash) are reaped on the next start by <see cref="MacOrphanRuntimeReaper"/>.
/// </para>
/// </summary>
public sealed class MacProcessSupervisor : IProcessSupervisor
{
    public const int SigKill = 9;
    public const int SigTerm = 15;

    private sealed class ManagedProcess(int forwardId, string role, string label, Process process, DateTime startedAt)
    {
        public int ForwardId { get; } = forwardId;
        public string Role { get; } = role;
        public string Label { get; } = label;
        public Process Process { get; } = process;
        public DateTime StartedAt { get; } = startedAt;
        public volatile bool Stopping;
    }

    private readonly ConcurrentDictionary<int, List<ManagedProcess>> _processes = new();
    private readonly Action<int, string, string>? _onOutput;
    private readonly UltraRuntimeFailureLog _ultraFailures = new();
    private readonly Func<ProcessStartInfo, Process> _processStarter;
    private readonly Func<int, int, int> _signalProcess;
    private readonly TimeSpan _stopTimeout;
    private bool _disposed;

    public MacProcessSupervisor(
        Action<int, string, string>? onOutput = null,
        Func<ProcessStartInfo, Process>? processStarter = null,
        Func<int, int, int>? signalProcess = null,
        TimeSpan? stopTimeout = null)
    {
        _onOutput = onOutput;
        _processStarter = processStarter ?? StartNativeProcess;
        _signalProcess = signalProcess ?? NativeSignalProcess;
        _stopTimeout = stopTimeout ?? TimeSpan.FromSeconds(3);
    }

    public event Action<int, string>? ProcessExited;

    public Process StartProcess(
        int forwardId,
        string role,
        string label,
        string exePath,
        string arguments,
        byte[]? standardInput = null,
        bool logArguments = true,
        string? workingDirectory = null)
    {
        ObjectDisposedException.ThrowIf(_disposed, this);
        Log.Information("starting macOS process for forward {ForwardId} [{Role}]: {Exe}",
            forwardId, role, Path.GetFileName(exePath));

        Process? process = null;
        try
        {
            var startInfo = new ProcessStartInfo
            {
                FileName = exePath,
                Arguments = arguments,
                UseShellExecute = false,
                CreateNoWindow = true,
                RedirectStandardInput = standardInput is not null,
                RedirectStandardOutput = true,
                RedirectStandardError = true,
                WorkingDirectory = workingDirectory ?? Path.GetDirectoryName(exePath) ?? string.Empty,
            };
            process = _processStarter(startInfo);
            Log.Information("macOS process spawned for forward {ForwardId} [{Role}] pid={ProcessId}",
                forwardId, role, process.Id);
            process.EnableRaisingEvents = true;
            var managed = new ManagedProcess(forwardId, role, label, process, DateTime.UtcNow);

            process.OutputDataReceived += (_, eventArgs) =>
            {
                if (eventArgs.Data is null)
                    return;
                Log.Debug("process_output for forward {ForwardId} [{Role}]", forwardId, role);
                _onOutput?.Invoke(forwardId, label, "process_output");
            };
            process.ErrorDataReceived += (_, eventArgs) =>
            {
                if (eventArgs.Data is null)
                    return;
                // Native helpers commonly use stderr for progress and
                // connection telemetry. Health/exit handling owns failures.
                Log.Debug("[fwd-{ForwardId}/{Role}] stderr: {Line}", forwardId, role, eventArgs.Data);
                if (role == UltraRuntimeFailureLog.RuntimeRole)
                    ProcessSupervisor.ObserveUltraRuntimeFailure(_ultraFailures, forwardId, eventArgs.Data);
                _onOutput?.Invoke(forwardId, label, $"[stderr] {eventArgs.Data}");
            };
            process.Exited += (_, _) =>
            {
                if (!managed.Stopping)
                {
                    // Unexpected exits must be visible at the default level;
                    // HealthCheckService decides what to do about them.
                    Log.Information("macOS process exited for forward {ForwardId} [{Role}] exitCode={ExitCode}",
                        forwardId, role, TryGetExitCode(process));
                }
                ProcessExited?.Invoke(forwardId, role);
            };

            if (standardInput is not null)
            {
                Log.Information("writing macOS process bootstrap for forward {ForwardId} [{Role}]", forwardId, role);
                using var inputTimeout = new CancellationTokenSource(TimeSpan.FromSeconds(3));
                var writeTask = process.StandardInput.BaseStream
                    .WriteAsync(standardInput.AsMemory(), inputTimeout.Token)
                    .AsTask();
                if (!writeTask.Wait(TimeSpan.FromSeconds(3)))
                    throw new TimeoutException("process_stdin_write_timeout");
                writeTask.GetAwaiter().GetResult();
                var flushTask = process.StandardInput.BaseStream.FlushAsync(inputTimeout.Token);
                if (!flushTask.Wait(TimeSpan.FromSeconds(3)))
                    throw new TimeoutException("process_stdin_flush_timeout");
                flushTask.GetAwaiter().GetResult();
                process.StandardInput.Close();
                Log.Information("macOS process bootstrap written for forward {ForwardId} [{Role}]", forwardId, role);
            }
            process.BeginOutputReadLine();
            process.BeginErrorReadLine();

            _processes.AddOrUpdate(
                forwardId,
                _ => [managed],
                (_, existing) =>
                {
                    lock (existing)
                        existing.Add(managed);
                    return existing;
                });
            return process;
        }
        catch
        {
            if (process is not null)
            {
                try
                {
                    if (!process.HasExited)
                        process.Kill(entireProcessTree: true);
                }
                catch
                {
                }
                process.Dispose();
            }
            throw;
        }
        finally
        {
            if (standardInput is not null)
                CryptographicOperations.ZeroMemory(standardInput);
        }
    }

    public void StopAll(int forwardId)
    {
        if (!_processes.TryRemove(forwardId, out var managed))
            return;
        Log.Information("stopping macOS processes for forward {ForwardId} count={Count}", forwardId, managed.Count);
        List<ManagedProcess> snapshot;
        lock (managed)
            snapshot = [.. managed];

        foreach (var item in snapshot)
            item.Stopping = true;
        foreach (var item in snapshot.Where(item => IsAlive(item.Process)))
        {
            var processId = TryGetProcessId(item.Process);
            if (processId > 0)
                _signalProcess(processId, SigTerm);
        }

        var deadline = DateTime.UtcNow + _stopTimeout;
        foreach (var item in snapshot)
        {
            var remaining = deadline - DateTime.UtcNow;
            if (remaining > TimeSpan.Zero && IsAlive(item.Process))
            {
                try
                {
                    item.Process.WaitForExit(Math.Max(1, (int)remaining.TotalMilliseconds));
                }
                catch
                {
                }
            }
        }
        foreach (var item in snapshot.Where(item => IsAlive(item.Process)))
        {
            var processId = TryGetProcessId(item.Process);
            if (processId > 0)
                _signalProcess(processId, SigKill);
        }
        foreach (var item in snapshot)
        {
            try
            {
                if (IsAlive(item.Process))
                    item.Process.WaitForExit(1000);
                if (IsAlive(item.Process))
                {
                    item.Process.Kill(entireProcessTree: true);
                    item.Process.WaitForExit(1000);
                }
            }
            catch
            {
            }
            item.Process.Dispose();
        }
        Log.Information("stopped macOS processes for forward {ForwardId}", forwardId);
    }

    public void StopAll()
    {
        foreach (var forwardId in _processes.Keys)
            StopAll(forwardId);
    }

    public bool IsRunning(int forwardId)
    {
        return Snapshot(forwardId).Any(item => IsAlive(item.Process));
    }

    public IReadOnlyList<int> GetProcessIds(int forwardId)
    {
        return Snapshot(forwardId)
            .Select(item => TryGetProcessId(item.Process))
            .Where(processId => processId > 0)
            .ToList();
    }

    public IReadOnlyList<string> GetRunningRoles(int forwardId)
    {
        return Snapshot(forwardId)
            .Where(item => IsAlive(item.Process))
            .Select(item => item.Role)
            .ToList();
    }

    public void Dispose()
    {
        if (_disposed)
            return;
        _disposed = true;
        StopAll();
    }

    private IReadOnlyList<ManagedProcess> Snapshot(int forwardId)
    {
        if (!_processes.TryGetValue(forwardId, out var managed))
            return [];
        lock (managed)
            return [.. managed];
    }

    private static Process StartNativeProcess(ProcessStartInfo startInfo)
    {
        return Process.Start(startInfo) ?? throw new InvalidOperationException("process_start_failed");
    }

    private static bool IsAlive(Process process)
    {
        try
        {
            return !process.HasExited;
        }
        catch
        {
            return false;
        }
    }

    private static int TryGetProcessId(Process process)
    {
        try
        {
            return process.HasExited ? -1 : process.Id;
        }
        catch
        {
            return -1;
        }
    }

    private static int TryGetExitCode(Process process)
    {
        try
        {
            return process.ExitCode;
        }
        catch
        {
            return -1;
        }
    }

    // Only ever called with a positive pid: signalling a (negative) process
    // group would reach the host itself, which shares the children's group.
    private static int NativeSignalProcess(int processId, int signal)
    {
        return processId > 0 ? kill(processId, signal) : -1;
    }

    [DllImport("libSystem.B.dylib", SetLastError = true)]
    private static extern int kill(int processId, int signal);
}
