using System.Diagnostics;
using System.Globalization;
using System.Runtime.InteropServices;
using Serilog;

namespace LeikwanBridge.Mac.Services;

public sealed record MacProcessEntry(int Pid, int ParentPid, int Uid, string Command);

/// <summary>
/// Stops Ultra runtimes left behind by a host that died without tearing them
/// down (crash, SIGKILL, an interactive run whose terminal went away).
/// <para>
/// An orphan is recognised conservatively: its parent is launchd (ppid 1), it
/// runs as this host's effective uid, and its command starts with the exact
/// path of this bundle's helper binary. The host never detaches a helper on
/// purpose, so such a process has no owner left; a live host's own children
/// always have that host as their parent and are never matched. Anything else
/// (another user's client, a sing-box started through the privileged helper,
/// a binary from another bundle) is left alone.
/// </para>
/// </summary>
public sealed class MacOrphanRuntimeReaper
{
    private readonly Func<IReadOnlyList<MacProcessEntry>> _listProcesses;
    private readonly Func<int, int, int> _signal;
    private readonly Func<int, bool> _isAlive;
    private readonly int _currentUid;
    private readonly int _selfPid;
    private readonly TimeSpan _gracePeriod;
    private readonly Action<TimeSpan> _sleep;

    public MacOrphanRuntimeReaper(
        Func<IReadOnlyList<MacProcessEntry>> listProcesses,
        Func<int, int, int> signal,
        Func<int, bool> isAlive,
        int currentUid,
        int selfPid,
        TimeSpan? gracePeriod = null,
        Action<TimeSpan>? sleep = null)
    {
        _listProcesses = listProcesses;
        _signal = signal;
        _isAlive = isAlive;
        _currentUid = currentUid;
        _selfPid = selfPid;
        _gracePeriod = gracePeriod ?? TimeSpan.FromSeconds(3);
        _sleep = sleep ?? Thread.Sleep;
    }

    public static MacOrphanRuntimeReaper CreateDefault()
        => new(ListWithPs, NativeKill, IsProcessAlive, (int)geteuid(), Environment.ProcessId);

    /// <summary>Orphans of <paramref name="executablePaths"/> owned by <paramref name="currentUid"/>.</summary>
    public static IReadOnlyList<MacProcessEntry> SelectOrphans(
        IEnumerable<MacProcessEntry> processes,
        IReadOnlyCollection<string> executablePaths,
        int currentUid,
        int selfPid)
    {
        var paths = executablePaths
            .Where(path => !string.IsNullOrWhiteSpace(path) && Path.IsPathRooted(path))
            .Distinct(StringComparer.Ordinal)
            .ToArray();
        if (paths.Length == 0)
            return [];
        return processes
            .Where(process => process.Pid > 1
                && process.Pid != selfPid
                && process.ParentPid == 1
                && process.Uid == currentUid
                && paths.Any(path => IsCommandOf(process.Command, path)))
            .ToList();
    }

    /// <summary>
    /// Sends SIGTERM to every orphan, waits up to the grace period, then SIGKILLs
    /// the ones still alive. Returns the orphans that were found.
    /// </summary>
    public IReadOnlyList<MacProcessEntry> Reap(IReadOnlyCollection<string> executablePaths)
    {
        IReadOnlyList<MacProcessEntry> orphans;
        try
        {
            orphans = SelectOrphans(_listProcesses(), executablePaths, _currentUid, _selfPid);
        }
        catch (Exception exception)
        {
            Log.Warning("orphan_runtime_scan_failed type={ErrorType}", exception.GetType().Name);
            return [];
        }
        if (orphans.Count == 0)
            return orphans;

        foreach (var orphan in orphans)
        {
            Log.Warning("orphan_runtime_found pid={Pid} exe={Exe}; stopping it", orphan.Pid, ExecutableName(orphan.Command));
            _signal(orphan.Pid, MacProcessSupervisor.SigTerm);
        }

        var deadline = DateTime.UtcNow + _gracePeriod;
        while (DateTime.UtcNow < deadline && orphans.Any(orphan => _isAlive(orphan.Pid)))
            _sleep(TimeSpan.FromMilliseconds(100));

        foreach (var orphan in orphans.Where(orphan => _isAlive(orphan.Pid)))
        {
            Log.Warning("orphan_runtime_kill pid={Pid}", orphan.Pid);
            _signal(orphan.Pid, MacProcessSupervisor.SigKill);
        }
        return orphans;
    }

    /// <summary>Parses <c>ps -axo pid=,ppid=,uid=,command=</c>.</summary>
    public static IReadOnlyList<MacProcessEntry> ParsePsOutput(string output)
    {
        var result = new List<MacProcessEntry>();
        foreach (var rawLine in (output ?? string.Empty).Split('\n'))
        {
            var line = rawLine.TrimEnd('\r').TrimStart();
            if (line.Length == 0)
                continue;
            var fields = new string[3];
            var cursor = 0;
            var ok = true;
            for (var index = 0; index < 3; index++)
            {
                while (cursor < line.Length && line[cursor] == ' ')
                    cursor++;
                var start = cursor;
                while (cursor < line.Length && line[cursor] != ' ')
                    cursor++;
                if (cursor == start)
                {
                    ok = false;
                    break;
                }
                fields[index] = line[start..cursor];
            }
            if (!ok)
                continue;
            while (cursor < line.Length && line[cursor] == ' ')
                cursor++;
            var command = cursor < line.Length ? line[cursor..] : string.Empty;
            if (!int.TryParse(fields[0], NumberStyles.None, CultureInfo.InvariantCulture, out var pid)
                || !int.TryParse(fields[1], NumberStyles.None, CultureInfo.InvariantCulture, out var ppid)
                || !int.TryParse(fields[2], NumberStyles.None, CultureInfo.InvariantCulture, out var uid)
                || command.Length == 0)
                continue;
            result.Add(new MacProcessEntry(pid, ppid, uid, command));
        }
        return result;
    }

    private static bool IsCommandOf(string command, string executablePath)
        => string.Equals(command, executablePath, StringComparison.Ordinal)
            || (command.Length > executablePath.Length
                && command.StartsWith(executablePath, StringComparison.Ordinal)
                && command[executablePath.Length] == ' ');

    private static string ExecutableName(string command)
    {
        var name = command.Split(" --", 2)[0];
        return Path.GetFileName(name);
    }

    internal static IReadOnlyList<MacProcessEntry> ListWithPs()
    {
        var startInfo = new ProcessStartInfo("/bin/ps")
        {
            UseShellExecute = false,
            RedirectStandardOutput = true,
            RedirectStandardError = true,
            CreateNoWindow = true,
        };
        foreach (var argument in new[] { "-axww", "-o", "pid=,ppid=,uid=,command=" })
            startInfo.ArgumentList.Add(argument);
        using var process = Process.Start(startInfo) ?? throw new InvalidOperationException("ps_start_failed");
        // Read while ps runs: the full process table can exceed a pipe buffer,
        // and a reader that waits for exit first would deadlock against it.
        var output = process.StandardOutput.ReadToEndAsync();
        var error = process.StandardError.ReadToEndAsync();
        if (!process.WaitForExit(5000))
        {
            try { process.Kill(); } catch { }
            throw new TimeoutException("ps_timeout");
        }
        process.WaitForExit();
        if (process.ExitCode != 0)
            throw new InvalidOperationException("ps_failed");
        _ = error.GetAwaiter().GetResult();
        return ParsePsOutput(output.GetAwaiter().GetResult());
    }

    private static bool IsProcessAlive(int processId)
        => processId > 0 && kill(processId, 0) == 0;

    private static int NativeKill(int processId, int signal)
        => processId > 1 ? kill(processId, signal) : -1;

    [DllImport("libSystem.B.dylib", SetLastError = true)]
    private static extern int kill(int processId, int signal);

    [DllImport("libSystem.B.dylib")]
    private static extern uint geteuid();
}
