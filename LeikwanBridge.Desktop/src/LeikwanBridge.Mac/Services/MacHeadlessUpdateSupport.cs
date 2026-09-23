using System.Diagnostics;
using System.Globalization;
using System.Runtime.InteropServices;
using LeikwanBridge.Core.Services;
using Serilog;

namespace LeikwanBridge.Mac.Services;

/// <summary>
/// Where the macOS LaunchDaemon keeps its self-update bookkeeping. Everything
/// lives under the daemon's root-only state directory (the installer creates it
/// 0700 root:wheel), because what is staged there is what root runs next.
/// </summary>
public static class MacHeadlessUpdatePaths
{
    public const string Label = "com.leikwan.bridge.headless";
    public const string PlistPath = "/Library/LaunchDaemons/" + Label + ".plist";
    public const string SystemStateDirectory = "/Library/Application Support/Leikwan/ClientAgent";
    public const string LogDirectory = "/Library/Logs/Leikwan/ClientAgent";
    public const string DefaultBundle = "/Applications/LeiKwan Bridge.app";

    /// <summary>Main executable, relative to the bundle (the apply sentinel).</summary>
    public const string ExecutableRelativePath = "Contents/MacOS/LeikwanBridge.Mac";

    public const string ApplyCommand = "--apply-headless-update";

    public static string StatusFile => SystemStateDirectory + "/headless-status.json";

    /// <summary>Root-only: downloads, extracted bundles, helper copies, state, lock.</summary>
    public static string UpdatesRoot => SystemStateDirectory + "/updates";

    public static string StatePath => UpdatesRoot + "/update-state.json";

    public static string LockPath => UpdatesRoot + "/apply.lock";

    /// <summary>Outcome of the most recent detached apply, keyed by request id.</summary>
    public static string ResultPath => UpdatesRoot + "/headless-apply-result.json";

    /// <summary>Root-only: install-&lt;version&gt;-&lt;timestamp&gt; bundle copies (newest 3 kept).</summary>
    public static string BackupRoot => SystemStateDirectory + "/backups";
}

/// <summary>Creates a directory that only root can read or write.</summary>
public static class MacRootOnlyDirectory
{
    public static void Ensure(string path)
    {
        Directory.CreateDirectory(path);
        if (OperatingSystem.IsWindows())
            return;
        // The parent state directory is 0700 root, so nobody else can have
        // pre-created anything in here; forcing owner and mode is enough.
        if (MacNative.geteuid() == 0 && MacNative.chown(path, 0, 0) != 0)
            throw new IOException("update_dir_chown_failed");
        File.SetUnixFileMode(path, UnixFileMode.UserRead | UnixFileMode.UserWrite | UnixFileMode.UserExecute);
    }
}

/// <summary>Whole-bundle filesystem operations, behind an interface so the
/// swap/rollback sequence runs in tests on any OS.</summary>
public interface IMacBundleFileSystem
{
    /// <summary>Full copy preserving modes and symlinks (APFS clone when possible).</summary>
    void Clone(string source, string destination);

    /// <summary>Atomically exchange two existing paths; false when unsupported.</summary>
    bool TrySwap(string first, string second);

    /// <summary>rename(2); throws IOException when it fails (for example across volumes).</summary>
    void Move(string source, string destination);

    void Delete(string path);
}

public sealed class MacNativeBundleFileSystem : IMacBundleFileSystem
{
    private const uint RenameSwap = 0x00000002;

    public static MacNativeBundleFileSystem Instance { get; } = new();

    public void Clone(string source, string destination)
    {
        if (Directory.Exists(destination))
            throw new IOException("bundle_clone_target_exists");
        // cp -c uses clonefile(2): an APFS copy costs no blocks. It fails across
        // volumes, where ditto makes a real copy (symlinks, modes, xattrs kept).
        if (Run("/bin/cp", ["-cRp", source, destination]) && Directory.Exists(destination))
            return;
        TryDelete(destination);
        if (Run("/usr/bin/ditto", [source, destination]) && Directory.Exists(destination))
            return;
        TryDelete(destination);
        throw new IOException("bundle_clone_failed");
    }

    public bool TrySwap(string first, string second)
    {
        if (OperatingSystem.IsWindows())
            return false;
        try
        {
            return MacNative.renamex_np(first, second, RenameSwap) == 0;
        }
        catch (Exception exception) when (exception is DllNotFoundException or EntryPointNotFoundException)
        {
            return false;
        }
    }

    public void Move(string source, string destination) => Directory.Move(source, destination);

    public void Delete(string path)
    {
        if (Directory.Exists(path))
            Directory.Delete(path, recursive: true);
    }

    private static bool Run(string executable, IReadOnlyList<string> arguments)
    {
        try
        {
            var startInfo = new ProcessStartInfo(executable)
            {
                UseShellExecute = false,
                CreateNoWindow = true,
                RedirectStandardError = true,
                RedirectStandardOutput = true,
            };
            foreach (var argument in arguments)
                startInfo.ArgumentList.Add(argument);
            using var process = Process.Start(startInfo);
            if (process is null)
                return false;
            var output = process.StandardOutput.ReadToEndAsync();
            var error = process.StandardError.ReadToEndAsync();
            if (!process.WaitForExit(600_000))
            {
                try { process.Kill(); } catch (InvalidOperationException) { }
                return false;
            }

            process.WaitForExit();
            _ = output.GetAwaiter().GetResult();
            var message = error.GetAwaiter().GetResult();
            if (process.ExitCode != 0)
                Log.Warning("update_bundle_command_failed exe={Exe} code={Code} error={Error}", Path.GetFileName(executable), process.ExitCode, Bound(message));
            return process.ExitCode == 0;
        }
        catch (Exception exception) when (exception is IOException or InvalidOperationException or System.ComponentModel.Win32Exception)
        {
            return false;
        }
    }

    private static void TryDelete(string path)
    {
        try
        {
            if (Directory.Exists(path))
                Directory.Delete(path, recursive: true);
        }
        catch (Exception exception) when (exception is IOException or UnauthorizedAccessException)
        {
        }
    }

    private static string Bound(string value)
    {
        var text = value.Replace('\r', ' ').Replace('\n', ' ').Trim();
        return text.Length > 200 ? text[..200] : text;
    }
}

/// <summary>
/// macOS swap strategy for <see cref="UpdateApplyOrchestrator"/>: the bundle is
/// replaced as a whole, never file by file (a half-overlaid bundle breaks its
/// code signature and mixes two builds). The backup is an APFS clone of the
/// installed bundle; the swap is one renamex_np(RENAME_SWAP) where the volume
/// supports it, otherwise two renames with undo. The bundle that was swapped out
/// is moved (not deleted) into the root-only updates directory, because a
/// `leikwan update` CLI may still be running from it.
/// </summary>
public sealed class MacBundleSwapper(IMacBundleFileSystem fileSystem, string workRoot) : IInstallSwapper
{
    public const string RetiredPrefix = "retired-";
    private static readonly TimeSpan RetiredKeep = TimeSpan.FromHours(1);

    public string CreateBackup(string installDirectory, string backupRoot, string version, DateTimeOffset now)
    {
        Directory.CreateDirectory(backupRoot);
        var target = Path.Combine(backupRoot, InstallDirectoryBackup.BackupName(version, now));
        var suffix = 1;
        while (Directory.Exists(target))
            target = Path.Combine(backupRoot, InstallDirectoryBackup.BackupName(version, now) + "-" + suffix++);
        // No ".app" suffix: LaunchServices must never register a backup as an app.
        var partial = target + ".partial";
        if (Directory.Exists(partial))
            fileSystem.Delete(partial);
        fileSystem.Clone(installDirectory, partial);
        Directory.Move(partial, target);
        return target;
    }

    public IReadOnlyList<string> Prune(string backupRoot, int keep, string? protect)
        => InstallDirectoryBackup.Prune(backupRoot, keep, protect);

    public Task<int> SwapInAsync(string stagedDirectory, string installDirectory, int retries, Func<TimeSpan, CancellationToken, Task> delay, CancellationToken ct)
    {
        PruneRetired();
        var incoming = Sibling(installDirectory, "incoming");
        try
        {
            fileSystem.Move(stagedDirectory, incoming);
        }
        catch (IOException)
        {
            // Staged on another volume: rename(2) cannot cross it.
            fileSystem.Clone(stagedDirectory, incoming);
        }

        var previous = Replace(incoming, installDirectory);
        if (previous is not null)
            Retire(previous);
        return Task.FromResult(1);
    }

    public Task RestoreAsync(string backup, string installDirectory, int retries, Func<TimeSpan, CancellationToken, Task> delay, CancellationToken ct)
    {
        var restore = Sibling(installDirectory, "restore");
        fileSystem.Clone(backup, restore);
        var failed = Replace(restore, installDirectory);
        if (failed is not null)
        {
            try
            {
                fileSystem.Delete(failed);
            }
            catch (Exception exception) when (exception is IOException or UnauthorizedAccessException)
            {
                Log.Warning("update_restore_failed_bundle_kept path={Path} type={ErrorType}", failed, exception.GetType().Name);
            }
        }

        return Task.CompletedTask;
    }

    /// <summary>Puts <paramref name="incoming"/> at <paramref name="install"/>.
    /// Returns where the previous bundle now is (null when there was none).</summary>
    private string? Replace(string incoming, string install)
    {
        if (!Directory.Exists(install))
        {
            fileSystem.Move(incoming, install);
            return null;
        }

        if (fileSystem.TrySwap(incoming, install))
        {
            Log.Information("update_bundle_swapped atomic=true");
            return incoming; // now holds the previous bundle
        }

        var previous = Sibling(install, "previous");
        fileSystem.Move(install, previous);
        try
        {
            fileSystem.Move(incoming, install);
        }
        catch (Exception exception) when (exception is IOException or UnauthorizedAccessException)
        {
            // Put the previous bundle back before reporting the failure.
            fileSystem.Move(previous, install);
            TryDelete(incoming);
            throw;
        }

        Log.Information("update_bundle_swapped atomic=false");
        return previous;
    }

    private void Retire(string path)
    {
        try
        {
            Directory.CreateDirectory(workRoot);
            fileSystem.Move(path, Path.Combine(workRoot, RetiredPrefix + Guid.NewGuid().ToString("N")));
        }
        catch (Exception exception) when (exception is IOException or UnauthorizedAccessException)
        {
            TryDelete(path);
        }
    }

    private void PruneRetired()
    {
        try
        {
            if (!Directory.Exists(workRoot))
                return;
            foreach (var directory in Directory.EnumerateDirectories(workRoot, RetiredPrefix + "*"))
            {
                if (DateTime.UtcNow - Directory.GetCreationTimeUtc(directory) >= RetiredKeep)
                    TryDelete(directory);
            }
        }
        catch (Exception exception) when (exception is IOException or UnauthorizedAccessException)
        {
        }
    }

    private void TryDelete(string path)
    {
        try
        {
            fileSystem.Delete(path);
        }
        catch (Exception exception) when (exception is IOException or UnauthorizedAccessException)
        {
        }
    }

    internal static string Sibling(string install, string purpose)
    {
        var trimmed = install.TrimEnd('/', '\\');
        var parent = Path.GetDirectoryName(trimmed) ?? throw new IOException("update_bundle_parent_invalid");
        return Path.Combine(parent, "." + Path.GetFileName(trimmed) + "." + purpose + "-" + Guid.NewGuid().ToString("N")[..12]);
    }
}

/// <summary>Starts a process that survives the caller's launchd job.</summary>
public interface IMacDetachedSpawner
{
    /// <summary>Returns the child pid, or a value &lt;= 0 when it did not start.</summary>
    int Spawn(string executable, IReadOnlyList<string> arguments, string outputLogPath);
}

/// <summary>
/// posix_spawn with POSIX_SPAWN_SETSID: the child gets its own session and
/// process group, so launchd's job cleanup (it kills the job's process group
/// when the daemon exits, AbandonProcessGroup=false) cannot take the updater
/// down with the daemon it is replacing. CLOEXEC_DEFAULT keeps the daemon's
/// sockets (listening forward ports!) out of the child; signals are reset.
/// </summary>
public sealed class MacPosixDetachedSpawner : IMacDetachedSpawner
{
    private const short PosixSpawnSetSigDef = 0x0004;
    private const short PosixSpawnSetSigMask = 0x0008;
    private const short PosixSpawnSetSid = 0x0400;
    private const short PosixSpawnCloExecDefault = 0x4000;
    private const int ORdOnly = 0x0000;
    private const int OWrOnly = 0x0001;
    private const int OAppend = 0x0008;
    private const int OCreat = 0x0200;

    public static MacPosixDetachedSpawner Instance { get; } = new();

    public int Spawn(string executable, IReadOnlyList<string> arguments, string outputLogPath)
    {
        if (OperatingSystem.IsWindows())
            return -1;
        var allocated = new List<IntPtr>();
        var attributes = IntPtr.Zero;
        var fileActions = IntPtr.Zero;
        var attributesReady = false;
        var actionsReady = false;
        try
        {
            if (MacNative.posix_spawnattr_init(ref attributes) != 0)
                return -1;
            attributesReady = true;
            uint empty = 0;
            // Every signal back to its default, except SIGKILL (9) and SIGSTOP
            // (17), which cannot be caught or reset.
            uint all = 0xFFFFFFFF & ~(1u << (9 - 1)) & ~(1u << (17 - 1));
            if (MacNative.posix_spawnattr_setflags(ref attributes, (short)(PosixSpawnSetSid | PosixSpawnCloExecDefault | PosixSpawnSetSigMask | PosixSpawnSetSigDef)) != 0
                || MacNative.posix_spawnattr_setsigmask(ref attributes, ref empty) != 0
                || MacNative.posix_spawnattr_setsigdefault(ref attributes, ref all) != 0)
            {
                return -1;
            }

            if (MacNative.posix_spawn_file_actions_init(ref fileActions) != 0)
                return -1;
            actionsReady = true;
            if (MacNative.posix_spawn_file_actions_addopen(ref fileActions, 0, "/dev/null", ORdOnly, 0) != 0
                || MacNative.posix_spawn_file_actions_addopen(ref fileActions, 1, outputLogPath, OWrOnly | OCreat | OAppend, 0x180) != 0
                || MacNative.posix_spawn_file_actions_addopen(ref fileActions, 2, outputLogPath, OWrOnly | OCreat | OAppend, 0x180) != 0)
            {
                return -1;
            }

            var argv = new IntPtr[arguments.Count + 2];
            argv[0] = Track(allocated, executable);
            for (var index = 0; index < arguments.Count; index++)
                argv[index + 1] = Track(allocated, arguments[index]);
            argv[^1] = IntPtr.Zero;

            var environment = Environment.GetEnvironmentVariables();
            var envp = new IntPtr[environment.Count + 1];
            var cursor = 0;
            foreach (System.Collections.DictionaryEntry entry in environment)
                envp[cursor++] = Track(allocated, entry.Key + "=" + entry.Value);
            envp[cursor] = IntPtr.Zero;

            var result = MacNative.posix_spawn(out var pid, executable, ref fileActions, ref attributes, argv, envp);
            if (result != 0)
            {
                Log.Warning("update_helper_spawn_failed errno={Errno}", result);
                return -1;
            }

            return pid;
        }
        catch (Exception exception) when (exception is DllNotFoundException or EntryPointNotFoundException)
        {
            Log.Warning("update_helper_spawn_unavailable type={ErrorType}", exception.GetType().Name);
            return -1;
        }
        finally
        {
            if (actionsReady)
                MacNative.posix_spawn_file_actions_destroy(ref fileActions);
            if (attributesReady)
                MacNative.posix_spawnattr_destroy(ref attributes);
            foreach (var pointer in allocated)
                Marshal.FreeCoTaskMem(pointer);
        }
    }

    private static IntPtr Track(List<IntPtr> allocated, string value)
    {
        var pointer = Marshal.StringToCoTaskMemUTF8(value);
        allocated.Add(pointer);
        return pointer;
    }
}

/// <summary>launchd + process table + status file access for
/// <see cref="UpdateApplyOrchestrator"/> on macOS.</summary>
public sealed class MacUpdateApplyEnvironment : IUpdateApplyEnvironment
{
    private readonly string _label;
    private readonly string _plistPath;
    private readonly string _statusFile;
    private readonly HashSet<int> _excludePids;
    private readonly Func<IReadOnlyList<string>, CancellationToken, Task<(int ExitCode, string Output)>> _launchctl;
    private readonly Func<IReadOnlyList<MacProcessEntry>> _listProcesses;
    private readonly Func<int, int, int> _signal;
    private readonly Func<TimeSpan, CancellationToken, Task> _delay;
    private readonly Func<DateTimeOffset> _clock;
    private int _trackedPid;

    public MacUpdateApplyEnvironment(
        string label,
        string plistPath,
        string statusFile,
        IEnumerable<int>? excludePids = null,
        Func<IReadOnlyList<string>, CancellationToken, Task<(int ExitCode, string Output)>>? launchctl = null,
        Func<IReadOnlyList<MacProcessEntry>>? listProcesses = null,
        Func<int, int, int>? signal = null,
        Func<TimeSpan, CancellationToken, Task>? delay = null,
        Func<DateTimeOffset>? clock = null)
    {
        _label = label;
        _plistPath = plistPath;
        _statusFile = statusFile;
        _excludePids = [.. excludePids ?? [], Environment.ProcessId];
        _launchctl = launchctl ?? MacServiceController.LaunchctlAsync;
        _listProcesses = listProcesses ?? MacOrphanRuntimeReaper.ListWithPs;
        _signal = signal ?? ((pid, sig) => pid > 1 ? MacNative.kill(pid, sig) : -1);
        _delay = delay ?? ((span, token) => Task.Delay(span, token));
        _clock = clock ?? (() => DateTimeOffset.UtcNow);
    }

    public DateTimeOffset UtcNow => _clock();

    public Task DelayAsync(TimeSpan delay, CancellationToken ct) => _delay(delay, ct);

    /// <summary>bootout, not SIGTERM: a booted-out job cannot be respawned by
    /// KeepAlive halfway through the swap. launchd delivers SIGTERM (graceful
    /// forward teardown), waits ExitTimeOut, then kills the job's process group
    /// (the ultra-client children); this updater runs in its own session.</summary>
    public async Task<bool> StopServiceAsync(TimeSpan timeout, CancellationToken ct)
    {
        var (loaded, _, pid) = await PrintAsync(ct).ConfigureAwait(false);
        if (!loaded)
            return true;
        await _launchctl(["bootout", Target], ct).ConfigureAwait(false);
        if (await WaitUnloadedAsync(timeout, ct).ConfigureAwait(false))
            return true;

        (loaded, _, pid) = await PrintAsync(ct).ConfigureAwait(false);
        if (pid > 0)
        {
            Log.Warning("update_apply_service_stop_timeout label={Label} pid={Pid}; killing", _label, pid);
            _signal(pid, MacProcessSupervisor.SigKill);
        }

        return !loaded || await WaitUnloadedAsync(TimeSpan.FromSeconds(15), ct).ConfigureAwait(false);
    }

    public async Task<bool> WaitForInstallProcessesExitAsync(string installDirectory, TimeSpan timeout, CancellationToken ct)
    {
        var deadline = UtcNow + timeout;
        var reportedForeign = new HashSet<int>();
        while (true)
        {
            var alive = FindUnder(installDirectory, reportedForeign);
            if (alive.Count == 0)
                return true;
            if (UtcNow >= deadline)
                break;
            await _delay(TimeSpan.FromSeconds(1), ct).ConfigureAwait(false);
        }

        foreach (var straggler in FindUnder(installDirectory, reportedForeign))
        {
            Log.Warning("update_apply_killing_straggler pid={Pid} exe={Exe}", straggler.Pid, ExecutableName(straggler.Command));
            _signal(straggler.Pid, MacProcessSupervisor.SigTerm);
        }

        await _delay(TimeSpan.FromSeconds(5), ct).ConfigureAwait(false);
        foreach (var straggler in FindUnder(installDirectory, reportedForeign))
            _signal(straggler.Pid, MacProcessSupervisor.SigKill);
        await _delay(TimeSpan.FromSeconds(2), ct).ConfigureAwait(false);
        var survivors = FindUnder(installDirectory, reportedForeign);
        foreach (var survivor in survivors)
            Log.Warning("update_apply_process_survived pid={Pid} exe={Exe}", survivor.Pid, ExecutableName(survivor.Command));
        return survivors.Count == 0;
    }

    public async Task<bool> StartServiceAsync(CancellationToken ct)
    {
        _trackedPid = 0;
        var (loaded, _, _) = await PrintAsync(ct).ConfigureAwait(false);
        if (loaded)
        {
            await _launchctl(["kickstart", "-k", Target], ct).ConfigureAwait(false);
        }
        else
        {
            // A label disabled with "launchctl disable" cannot be bootstrapped.
            await _launchctl(["enable", Target], ct).ConfigureAwait(false);
            var bootstrapped = false;
            for (var attempt = 0; attempt < 5 && !bootstrapped; attempt++)
            {
                var (code, _) = await _launchctl(["bootstrap", "system", _plistPath], ct).ConfigureAwait(false);
                // "already loaded" (a concurrent bootstrap won) is success too.
                bootstrapped = code == 0 || (await PrintAsync(ct).ConfigureAwait(false)).Loaded;
                if (!bootstrapped)
                    await _delay(TimeSpan.FromSeconds(2), ct).ConfigureAwait(false);
            }

            if (!bootstrapped)
            {
                Log.Warning("update_apply_bootstrap_failed label={Label} plist={Plist}", _label, _plistPath);
                return false;
            }

            // RunAtLoad starts it; kickstart without -k only covers a job launchd
            // kept stopped and never kills the instance that is just starting.
            await _launchctl(["kickstart", Target], ct).ConfigureAwait(false);
        }

        for (var attempt = 0; attempt < 30; attempt++)
        {
            var (_, running, pid) = await PrintAsync(ct).ConfigureAwait(false);
            if (running && pid > 0)
            {
                _trackedPid = pid;
                return true;
            }

            await _delay(TimeSpan.FromSeconds(1), ct).ConfigureAwait(false);
        }

        return false;
    }

    /// <summary>Running with the same pid as the previous poll: a crash that
    /// launchd's KeepAlive restarts changes the pid, which resets the
    /// orchestrator's stability window.</summary>
    public async Task<bool> IsServiceRunningAsync(CancellationToken ct)
    {
        var (_, running, pid) = await PrintAsync(ct).ConfigureAwait(false);
        if (!running || pid <= 0)
            return false;
        if (_trackedPid == 0)
        {
            _trackedPid = pid;
            return true;
        }

        if (pid == _trackedPid)
            return true;
        Log.Warning("update_apply_service_restarted label={Label} old_pid={OldPid} new_pid={NewPid}", _label, _trackedPid, pid);
        _trackedPid = pid;
        return false;
    }

    public ClientStatusDocument? ReadStatus() => HeadlessCli.ReadStatusFile(_statusFile);

    private string Target => "system/" + _label;

    private async Task<bool> WaitUnloadedAsync(TimeSpan timeout, CancellationToken ct)
    {
        var deadline = UtcNow + timeout;
        while (true)
        {
            if (!(await PrintAsync(ct).ConfigureAwait(false)).Loaded)
                return true;
            if (UtcNow >= deadline)
                return false;
            await _delay(TimeSpan.FromSeconds(1), ct).ConfigureAwait(false);
        }
    }

    private async Task<(bool Loaded, bool Running, int Pid)> PrintAsync(CancellationToken ct)
    {
        var (code, output) = await _launchctl(["print", Target], ct).ConfigureAwait(false);
        if (code != 0)
            return (false, false, 0);
        var (running, pid) = ParsePrint(output);
        return (true, running, pid);
    }

    public static (bool Running, int Pid) ParsePrint(string output)
    {
        var running = output.Contains("state = running", StringComparison.OrdinalIgnoreCase);
        var pid = 0;
        foreach (var line in output.Split('\n'))
        {
            var trimmed = line.Trim();
            if (!trimmed.StartsWith("pid = ", StringComparison.Ordinal))
                continue;
            if (int.TryParse(trimmed["pid = ".Length..].Trim(), NumberStyles.None, CultureInfo.InvariantCulture, out var parsed))
            {
                pid = parsed;
                break;
            }
        }

        return (running, pid);
    }

    /// <summary>Root processes started from the install bundle. A logged-in
    /// user's own windowed client (same bundle, non-root) is left alone.</summary>
    private List<MacProcessEntry> FindUnder(string installDirectory, HashSet<int> reportedForeign)
    {
        var prefix = installDirectory.TrimEnd('/') + "/";
        IReadOnlyList<MacProcessEntry> processes;
        try
        {
            processes = _listProcesses();
        }
        catch (Exception exception)
        {
            Log.Warning("update_apply_process_scan_failed type={ErrorType}", exception.GetType().Name);
            return [];
        }

        var matches = new List<MacProcessEntry>();
        foreach (var process in processes)
        {
            if (process.Pid <= 1 || _excludePids.Contains(process.Pid)
                || !process.Command.StartsWith(prefix, StringComparison.Ordinal))
            {
                continue;
            }

            if (process.Uid != 0)
            {
                if (reportedForeign.Add(process.Pid))
                    Log.Information("update_apply_user_process_kept pid={Pid} uid={Uid} exe={Exe}", process.Pid, process.Uid, ExecutableName(process.Command));
                continue;
            }

            matches.Add(process);
        }

        return matches;
    }

    private static string ExecutableName(string command)
        => Path.GetFileName(command.Split(" --", 2)[0]);
}

internal static class MacNative
{
    [DllImport("libSystem.B.dylib")]
    internal static extern uint geteuid();

    [DllImport("libSystem.B.dylib")]
    internal static extern int getppid();

    [DllImport("libSystem.B.dylib", SetLastError = true)]
    internal static extern int kill(int pid, int signal);

    [DllImport("libSystem.B.dylib", SetLastError = true)]
    internal static extern int chown(string path, uint owner, uint group);

    [DllImport("libSystem.B.dylib", SetLastError = true)]
    internal static extern int renamex_np(string from, string to, uint flags);

    [DllImport("libSystem.B.dylib")]
    internal static extern int posix_spawn(out int pid, string path, ref IntPtr fileActions, ref IntPtr attributes, IntPtr[] argv, IntPtr[] envp);

    [DllImport("libSystem.B.dylib")]
    internal static extern int posix_spawnattr_init(ref IntPtr attributes);

    [DllImport("libSystem.B.dylib")]
    internal static extern int posix_spawnattr_destroy(ref IntPtr attributes);

    [DllImport("libSystem.B.dylib")]
    internal static extern int posix_spawnattr_setflags(ref IntPtr attributes, short flags);

    [DllImport("libSystem.B.dylib")]
    internal static extern int posix_spawnattr_setsigmask(ref IntPtr attributes, ref uint mask);

    [DllImport("libSystem.B.dylib")]
    internal static extern int posix_spawnattr_setsigdefault(ref IntPtr attributes, ref uint mask);

    [DllImport("libSystem.B.dylib")]
    internal static extern int posix_spawn_file_actions_init(ref IntPtr fileActions);

    [DllImport("libSystem.B.dylib")]
    internal static extern int posix_spawn_file_actions_destroy(ref IntPtr fileActions);

    [DllImport("libSystem.B.dylib")]
    internal static extern int posix_spawn_file_actions_addopen(ref IntPtr fileActions, int fd, string path, int flags, ushort mode);
}
