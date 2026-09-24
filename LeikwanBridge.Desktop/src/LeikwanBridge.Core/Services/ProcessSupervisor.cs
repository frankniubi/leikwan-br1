using System.Collections.Concurrent;
using System.Diagnostics;
using System.Security.Cryptography;
using LeikwanBridge.Core.Services.Platform;
using LeikwanBridge.Core.Services.Ultra;
using Serilog;

namespace LeikwanBridge.Core.Services;

public class ProcessSupervisor : IProcessSupervisor, IUltraRuntimeFailureSource
{
    public record ManagedProcess(
        int ForwardId,
        string Role,
        string Label,
        Process Process,
        DateTime StartedAt);

    private readonly ConcurrentDictionary<int, List<ManagedProcess>> _processes = new();
    private readonly ConcurrentDictionary<int, WindowsJobGroup> _jobGroups = new();
    private readonly Action<int, string, string>? _onOutput;
    private readonly UltraRuntimeFailureLog _ultraFailures = new();
    private bool _disposed;

    public event Action<int, string>? ProcessExited;

    /// <inheritdoc />
    public event Action<int, string>? UltraCredentialSuspect;

    public ProcessSupervisor(Action<int, string, string>? onOutput = null)
    {
        _onOutput = onOutput;
    }

    public IReadOnlyDictionary<int, List<ManagedProcess>> RunningProcesses => _processes;

    public virtual Process StartProcess(
        int forwardId,
        string role,
        string label,
        string exePath,
        string arguments,
        byte[]? standardInput = null,
        bool logArguments = true,
        string? workingDirectory = null)
    {
        if (logArguments)
        {
            Log.Information("starting process for forward {ForwardId} [{Label}]: {Exe} {Args}",
                forwardId, label, exePath, arguments);
        }
        else
        {
            Log.Information("starting process for forward {ForwardId} [{Role}]: {Exe}",
                forwardId, role, Path.GetFileName(exePath));
        }

        try
        {
            var psi = new ProcessStartInfo
            {
                FileName = exePath,
                Arguments = arguments,
                UseShellExecute = false,
                CreateNoWindow = true,
                RedirectStandardInput = standardInput is not null,
                RedirectStandardOutput = true,
                RedirectStandardError = true,
                WorkingDirectory = workingDirectory ?? Path.GetDirectoryName(exePath) ?? "",
            };

            var process = new Process { StartInfo = psi, EnableRaisingEvents = true };

            process.OutputDataReceived += (_, e) =>
            {
                if (e.Data != null)
                {
                    Log.Debug("[fwd-{ForwardId}/{Label}] {Line}", forwardId, label, e.Data);
                    _onOutput?.Invoke(forwardId, label, e.Data);
                }
            };
            process.ErrorDataReceived += (_, e) =>
            {
                if (e.Data != null)
                {
                    // Native helpers commonly use stderr for progress and
                    // connection telemetry. The process exit/health path owns
                    // actual failures; keep this stream available in Debug.
                    Log.Debug("[fwd-{ForwardId}/{Label}] stderr: {Line}", forwardId, label, e.Data);
                    // Ultra runtime data-plane failures (stream close stage,
                    // candidate/setup failures) must survive the default log
                    // level; rate-limited and address-redacted.
                    if (role == UltraRuntimeFailureLog.RuntimeRole)
                        ObserveUltraRuntimeStderr(forwardId, e.Data);
                    _onOutput?.Invoke(forwardId, label, $"[stderr] {e.Data}");
                }
            };
            process.Exited += (_, _) =>
            {
                var exitCode = process.ExitCode;
                // HealthCheckService distinguishes an unexpected exit from a
                // user stop and owns the warning/error decision.
                Log.Debug("process exited for forward {ForwardId} [{Label}] exitCode={ExitCode}",
                    forwardId, label, exitCode);
                ProcessExited?.Invoke(forwardId, role);
            };

            process.Start();
            var job = _jobGroups.GetOrAdd(forwardId, WindowsJobGroup.Create);
            if (!job.Attach(process))
            {
                process.Kill(entireProcessTree: true);
                process.Dispose();
                throw new InvalidOperationException("process could not be attached to forward job group");
            }
            if (standardInput is not null)
            {
                process.StandardInput.BaseStream.Write(standardInput);
                process.StandardInput.BaseStream.Flush();
                process.StandardInput.Close();
            }
            process.BeginOutputReadLine();
            process.BeginErrorReadLine();

            var managed = new ManagedProcess(forwardId, role, label, process, DateTime.UtcNow);
            _processes.AddOrUpdate(forwardId,
                _ => [managed],
                (_, list) => { list.Add(managed); return list; });

            return process;
        }
        finally
        {
            if (standardInput is not null)
                CryptographicOperations.ZeroMemory(standardInput);
        }
    }

    public virtual void StopAll(int forwardId)
    {
        if (_jobGroups.TryRemove(forwardId, out var job))
        {
            job.Terminate();
            job.Dispose();
        }
        if (!_processes.TryRemove(forwardId, out var list)) return;
        foreach (var mp in list)
        {
            KillProcess(mp);
        }
        Log.Information("stopped all processes for forward {ForwardId}", forwardId);
    }

    public virtual void StopAll()
    {
        foreach (var job in _jobGroups.Values)
        {
            job.Terminate();
            job.Dispose();
        }
        _jobGroups.Clear();
        foreach (var kvp in _processes)
        {
            foreach (var mp in kvp.Value)
                KillProcess(mp);
        }
        _processes.Clear();
    }

    public virtual bool IsRunning(int forwardId)
    {
        if (!_processes.TryGetValue(forwardId, out var list)) return false;
        return list.Any(mp => IsProcessAlive(mp.Process));
    }

    public virtual List<(string label, int pid, bool alive, TimeSpan uptime)> GetProcessInfo(int forwardId)
    {
        if (!_processes.TryGetValue(forwardId, out var list)) return [];
        return list.Select(mp => (
            mp.Label,
            TryGetProcessId(mp.Process),
            IsProcessAlive(mp.Process),
            DateTime.UtcNow - mp.StartedAt
        )).ToList();
    }

    public virtual IReadOnlyList<ManagedProcess> GetProcesses(int forwardId)
    {
        if (!_processes.TryGetValue(forwardId, out var list)) return [];
        return list.ToList();
    }

    public virtual IReadOnlyList<string> GetRunningRoles(int forwardId)
    {
        return GetProcesses(forwardId)
            .Where(process => IsProcessAlive(process.Process))
            .Select(process => process.Role)
            .ToList();
    }

    public virtual IReadOnlyList<int> GetProcessIds(int forwardId)
    {
        return GetProcesses(forwardId)
            .Select(process => TryGetProcessId(process.Process))
            .Where(processId => processId > 0)
            .ToList();
    }

    /// <summary>Handles one Ultra runtime stderr line (failure log + credential-suspect event).</summary>
    protected void ObserveUltraRuntimeStderr(int forwardId, string line)
        => ObserveUltraRuntimeLine(_ultraFailures, forwardId, line, UltraCredentialSuspect);

    protected void RaiseProcessExited(int forwardId, string role)
    {
        ProcessExited?.Invoke(forwardId, role);
    }

    /// <summary>
    /// Logs a runtime failure line (rate-limited) and raises
    /// <paramref name="credentialSuspect"/> for every line that suggests a dead
    /// provider credential. Shared by the Windows and macOS supervisors.
    /// </summary>
    public static void ObserveUltraRuntimeLine(
        UltraRuntimeFailureLog failures,
        int forwardId,
        string line,
        Action<int, string>? credentialSuspect)
    {
        var kind = ObserveUltraRuntimeFailure(failures, forwardId, line);
        if (kind is null || credentialSuspect is null || !UltraRuntimeFailureLog.IsCredentialSuspect(kind, line))
            return;
        try
        {
            credentialSuspect(forwardId, kind);
        }
        catch (Exception ex)
        {
            // Never let a subscriber break the output reader.
            Log.Warning(ex, "ultra credential-suspect handler failed for forward {ForwardId}", forwardId);
        }
    }

    public static string? ObserveUltraRuntimeFailure(UltraRuntimeFailureLog failures, int forwardId, string line)
    {
        return failures.ObserveKind(forwardId, line, DateTimeOffset.UtcNow, (kind, redacted, suppressed) =>
        {
            if (suppressed > 0)
            {
                Log.Warning("ultra runtime failure forward={ForwardId} kind={Kind} suppressed_previous={Suppressed}: {Line}",
                    forwardId, kind, suppressed, redacted);
            }
            else
            {
                Log.Warning("ultra runtime failure forward={ForwardId} kind={Kind}: {Line}",
                    forwardId, kind, redacted);
            }
        });
    }

    private static void KillProcess(ManagedProcess mp)
    {
        try
        {
            if (!mp.Process.HasExited)
            {
                mp.Process.Kill(entireProcessTree: true);
                mp.Process.WaitForExit(5000);
            }
        }
        catch (Exception ex)
        {
            Log.Warning(ex, "failed to kill process {Label} pid={Pid}", mp.Label, mp.Process.Id);
        }
        finally
        {
            mp.Process.Dispose();
        }
    }

    private static bool IsProcessAlive(Process process)
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

    public void Dispose()
    {
        if (_disposed) return;
        _disposed = true;
        StopAll();
    }
}
