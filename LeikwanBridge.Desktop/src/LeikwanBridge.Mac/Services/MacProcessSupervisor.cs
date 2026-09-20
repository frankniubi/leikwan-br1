using System.Collections.Concurrent;
using System.Diagnostics;
using System.Runtime.InteropServices;
using System.Security.Cryptography;
using LeikwanBridge.Core.Services.Platform;
using Serilog;

namespace LeikwanBridge.Mac.Services;

public sealed class MacProcessSupervisor : IProcessSupervisor
{
    public const int SigKill = 9;
    public const int SigTerm = 15;

    private sealed record ManagedProcess(int ForwardId, string Role, string Label, Process Process, DateTime StartedAt, bool OwnsProcessGroup);

    private readonly ConcurrentDictionary<int, List<ManagedProcess>> _processes = new();
    private readonly Action<int, string, string>? _onOutput;
    private readonly Func<ProcessStartInfo, Process> _processStarter;
    private readonly Func<int, int, int> _setProcessGroup;
    private readonly Func<int, int, int> _signalProcessGroup;
    private readonly TimeSpan _stopTimeout;
    private bool _disposed;

    public MacProcessSupervisor(
        Action<int, string, string>? onOutput = null,
        Func<ProcessStartInfo, Process>? processStarter = null,
        Func<int, int, int>? setProcessGroup = null,
        Func<int, int, int>? signalProcessGroup = null,
        TimeSpan? stopTimeout = null)
    {
        _onOutput = onOutput;
        _processStarter = processStarter ?? StartNativeProcess;
        _setProcessGroup = setProcessGroup ?? NativeSetProcessGroup;
        _signalProcessGroup = signalProcessGroup ?? NativeSignalProcessGroup;
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
            var ownsProcessGroup = _setProcessGroup(process.Id, process.Id) == 0;
            if (!ownsProcessGroup)
            {
                var errno = Marshal.GetLastWin32Error();
                Log.Warning(
                    "process_group_create_failed for forward {ForwardId} [{Role}] errno={Errno}; using direct-pid signal fallback",
                    forwardId,
                    role,
                    errno);
            }

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
                _onOutput?.Invoke(forwardId, label, $"[stderr] {eventArgs.Data}");
            };
            process.Exited += (_, _) => ProcessExited?.Invoke(forwardId, role);

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

            var managed = new ManagedProcess(forwardId, role, label, process, DateTime.UtcNow, ownsProcessGroup);
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

        foreach (var item in snapshot.Where(item => IsAlive(item.Process)))
        {
            var processId = TryGetProcessId(item.Process);
            if (processId > 0)
                _signalProcessGroup(item.OwnsProcessGroup ? -processId : processId, SigTerm);
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
                _signalProcessGroup(item.OwnsProcessGroup ? -processId : processId, SigKill);
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

    private static int NativeSetProcessGroup(int processId, int processGroupId)
    {
        return setpgid(processId, processGroupId);
    }

    private static int NativeSignalProcessGroup(int processGroupId, int signal)
    {
        return kill(processGroupId, signal);
    }

    [DllImport("libSystem.B.dylib", SetLastError = true)]
    private static extern int setpgid(int processId, int processGroupId);

    [DllImport("libSystem.B.dylib", SetLastError = true)]
    private static extern int kill(int processId, int signal);
}
