using System.Collections.Concurrent;
using System.Diagnostics;
using Serilog;

namespace LeikwanBridge.Core.Services;

public class ProcessSupervisor : IDisposable
{
    public record ManagedProcess(
        int ForwardId,
        string Role,
        string Label,
        Process Process,
        DateTime StartedAt);

    private readonly ConcurrentDictionary<int, List<ManagedProcess>> _processes = new();
    private readonly Action<int, string, string>? _onOutput;
    private bool _disposed;

    public event Action<int, string>? ProcessExited;

    public ProcessSupervisor(Action<int, string, string>? onOutput = null)
    {
        _onOutput = onOutput;
    }

    public IReadOnlyDictionary<int, List<ManagedProcess>> RunningProcesses => _processes;

    public virtual Process StartProcess(int forwardId, string role, string label, string exePath, string arguments, string? workingDir = null)
    {
        Log.Information("starting process for forward {ForwardId} [{Label}]: {Exe} {Args}",
            forwardId, label, exePath, arguments);

        var psi = new ProcessStartInfo
        {
            FileName = exePath,
            Arguments = arguments,
            UseShellExecute = false,
            CreateNoWindow = true,
            RedirectStandardOutput = true,
            RedirectStandardError = true,
            WorkingDirectory = workingDir ?? Path.GetDirectoryName(exePath) ?? "",
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
                Log.Warning("[fwd-{ForwardId}/{Label}] stderr: {Line}", forwardId, label, e.Data);
                _onOutput?.Invoke(forwardId, label, $"[stderr] {e.Data}");
            }
        };
        process.Exited += (_, _) =>
        {
            Log.Warning("process exited for forward {ForwardId} [{Label}] exitCode={ExitCode}",
                forwardId, label, process.ExitCode);
            ProcessExited?.Invoke(forwardId, role);
        };

        process.Start();
        process.BeginOutputReadLine();
        process.BeginErrorReadLine();

        var managed = new ManagedProcess(forwardId, role, label, process, DateTime.UtcNow);
        _processes.AddOrUpdate(forwardId,
            _ => [managed],
            (_, list) => { list.Add(managed); return list; });

        return process;
    }

    public virtual void StopAll(int forwardId)
    {
        if (!_processes.TryRemove(forwardId, out var list)) return;
        foreach (var mp in list)
        {
            KillProcess(mp);
        }
        Log.Information("stopped all processes for forward {ForwardId}", forwardId);
    }

    public virtual void StopAll()
    {
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

    protected void RaiseProcessExited(int forwardId, string role)
    {
        ProcessExited?.Invoke(forwardId, role);
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
