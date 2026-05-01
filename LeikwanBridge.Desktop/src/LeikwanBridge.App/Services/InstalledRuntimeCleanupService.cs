using System.Diagnostics;
using System.IO;
using Serilog;

namespace LeikwanBridge.App.Services;

public sealed class InstalledRuntimeCleanupService
{
    public record RunningProcessInfo(int ProcessId, string? ExecutablePath);

    private readonly Func<IReadOnlyList<RunningProcessInfo>> _processProvider;
    private readonly Action<int> _killProcess;

    public InstalledRuntimeCleanupService(
        Func<IReadOnlyList<RunningProcessInfo>>? processProvider = null,
        Action<int>? killProcess = null)
    {
        _processProvider = processProvider ?? SnapshotRunningProcesses;
        _killProcess = killProcess ?? KillProcessTree;
    }

    public int KillProcessesUnderInstallRoot(string installRoot, int currentProcessId)
    {
        if (string.IsNullOrWhiteSpace(installRoot))
            return 0;

        string rootPath;
        try
        {
            rootPath = NormalizeDirectoryPath(Path.GetFullPath(installRoot));
        }
        catch
        {
            return 0;
        }

        var killed = 0;
        foreach (var process in _processProvider())
        {
            if (process.ProcessId <= 0 || process.ProcessId == currentProcessId)
                continue;
            if (string.IsNullOrWhiteSpace(process.ExecutablePath))
                continue;

            string executablePath;
            try
            {
                executablePath = Path.GetFullPath(process.ExecutablePath);
            }
            catch
            {
                continue;
            }

            if (!executablePath.StartsWith(rootPath, StringComparison.OrdinalIgnoreCase))
                continue;

            try
            {
                _killProcess(process.ProcessId);
                killed++;
            }
            catch (Exception ex)
            {
                Log.Warning(ex, "failed to clean stale installed runtime pid={ProcessId} path={ExecutablePath}", process.ProcessId, executablePath);
            }
        }

        return killed;
    }

    private static string NormalizeDirectoryPath(string path)
    {
        return path.TrimEnd(Path.DirectorySeparatorChar, Path.AltDirectorySeparatorChar) + Path.DirectorySeparatorChar;
    }

    private static IReadOnlyList<RunningProcessInfo> SnapshotRunningProcesses()
    {
        var processes = new List<RunningProcessInfo>();
        foreach (var process in Process.GetProcesses())
        {
            try
            {
                string? executablePath = null;
                try
                {
                    executablePath = process.MainModule?.FileName;
                }
                catch
                {
                    executablePath = null;
                }

                processes.Add(new RunningProcessInfo(process.Id, executablePath));
            }
            finally
            {
                process.Dispose();
            }
        }

        return processes;
    }

    private static void KillProcessTree(int processId)
    {
        using var process = Process.GetProcessById(processId);
        process.Kill(entireProcessTree: true);
        process.WaitForExit(5000);
    }
}
