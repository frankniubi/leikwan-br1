using System.Globalization;
using Serilog;

namespace LeikwanBridge.Core.Services;

/// <summary>
/// Everything the apply step needs from the OS, behind an interface so the
/// swap/verify/rollback sequence can be tested without a real SCM.
/// </summary>
public interface IUpdateApplyEnvironment
{
    DateTimeOffset UtcNow { get; }

    Task DelayAsync(TimeSpan delay, CancellationToken ct);

    /// <summary>Stop the service and wait (bounded) until it is STOPPED; kill
    /// its process if it does not stop in time. False when it is still alive.</summary>
    Task<bool> StopServiceAsync(TimeSpan timeout, CancellationToken ct);

    /// <summary>Wait until no process runs from under <paramref name="installDirectory"/>
    /// (service host, child runtimes such as bin\ultra-client.exe, stray CLIs),
    /// killing stragglers after <paramref name="timeout"/>. False when some survive.</summary>
    Task<bool> WaitForInstallProcessesExitAsync(string installDirectory, TimeSpan timeout, CancellationToken ct);

    Task<bool> StartServiceAsync(CancellationToken ct);

    Task<bool> IsServiceRunningAsync(CancellationToken ct);

    ClientStatusDocument? ReadStatus();
}

public sealed class UpdateApplyOptions
{
    public required string StagedDirectory { get; init; }
    public required string InstallDirectory { get; init; }
    public required string BackupRoot { get; init; }
    public required string StatePath { get; init; }
    public required string PreviousVersion { get; init; }
    public required string NewVersion { get; init; }

    /// <summary>File whose presence proves a directory is a client install.</summary>
    public string SentinelFileName { get; init; } = "leikwan-bridge-headless.exe";

    public TimeSpan StopTimeout { get; init; } = TimeSpan.FromSeconds(60);
    public TimeSpan ProcessExitTimeout { get; init; } = TimeSpan.FromSeconds(30);
    public TimeSpan HealthTimeout { get; init; } = TimeSpan.FromSeconds(180);
    public TimeSpan HealthPollInterval { get; init; } = TimeSpan.FromSeconds(5);

    /// <summary>How long the new build must stay healthy before it is accepted.</summary>
    public TimeSpan StabilityWindow { get; init; } = TimeSpan.FromSeconds(20);
    public int CopyRetries { get; init; } = 5;
    public int KeepBackups { get; init; } = 3;
}

public enum UpdateApplyOutcome
{
    Applied,
    RejectedRolledBack,
    StopFailed,
    BackupFailed,
    CopyFailedRestored,
    RollbackFailed,
    InvalidRequest,
}

public enum UpdateHealthVerdict
{
    Pending,
    Healthy,
    Unhealthy,
}

/// <summary>
/// Pure health rule for a freshly swapped build. Healthy means: the SCM says
/// RUNNING, and the new process wrote a status file after the swap with
/// Connected=true (and, when the pre-update build had forwards running, at
/// least one forward running). Anything short of that by the deadline is
/// unhealthy and triggers a rollback.
/// </summary>
public static class UpdateHealthGate
{
    public static UpdateHealthVerdict Evaluate(
        bool serviceRunning,
        ClientStatusDocument? status,
        DateTimeOffset swappedAt,
        string expectedVersion,
        int baselineRunningForwards,
        DateTimeOffset now,
        DateTimeOffset deadline)
        => IsHealthy(serviceRunning, status, swappedAt, expectedVersion, baselineRunningForwards)
            ? UpdateHealthVerdict.Healthy
            : now >= deadline ? UpdateHealthVerdict.Unhealthy : UpdateHealthVerdict.Pending;

    public static bool IsHealthy(
        bool serviceRunning,
        ClientStatusDocument? status,
        DateTimeOffset swappedAt,
        string expectedVersion,
        int baselineRunningForwards)
    {
        if (!serviceRunning || status is null)
            return false;
        if (status.UpdatedAt <= swappedAt)
            return false; // written by the old build (or never)
        if (!status.Connected)
            return false;
        if (!VersionMatches(status.Version, expectedVersion))
            return false;
        if (baselineRunningForwards > 0 && !status.Forwards.Any(forward => forward.Running))
            return false;
        return true;
    }

    /// <summary>Older builds wrote a three-part version into the status file;
    /// accept that as a prefix of the four-part product version.</summary>
    public static bool VersionMatches(string reported, string expected)
    {
        if (string.IsNullOrWhiteSpace(reported))
            return true; // nothing to contradict the fresh write
        if (string.Equals(reported, expected, StringComparison.OrdinalIgnoreCase))
            return true;
        return expected.StartsWith(reported + ".", StringComparison.OrdinalIgnoreCase)
            && reported.Count(character => character == '.') == 2;
    }

    public static int RunningForwards(ClientStatusDocument? status, DateTimeOffset now, TimeSpan maxAge)
    {
        if (status is null || now - status.UpdatedAt > maxAge)
            return 0;
        return status.Connected ? status.Forwards.Count(forward => forward.Running) : 0;
    }
}

/// <summary>Copy/backup/restore of an install directory.</summary>
public static class InstallDirectoryBackup
{
    public const string Prefix = "install-";

    public static string BackupName(string version, DateTimeOffset now)
    {
        var safeVersion = string.Concat((string.IsNullOrWhiteSpace(version) ? "unknown" : version)
            .Select(character => char.IsLetterOrDigit(character) || character is '.' or '-' or '_' ? character : '_'));
        return Prefix + safeVersion + "-" + now.UtcDateTime.ToString("yyyyMMdd'T'HHmmss'Z'", CultureInfo.InvariantCulture);
    }

    public static string Create(string installDirectory, string backupRoot, string version, DateTimeOffset now)
    {
        Directory.CreateDirectory(backupRoot);
        var target = Path.Combine(backupRoot, BackupName(version, now));
        var suffix = 1;
        while (Directory.Exists(target))
            target = Path.Combine(backupRoot, BackupName(version, now) + "-" + suffix++);
        var partial = target + ".partial";
        if (Directory.Exists(partial))
            Directory.Delete(partial, recursive: true);
        CopyTree(installDirectory, partial);
        Directory.Move(partial, target);
        return target;
    }

    /// <summary>Keep the newest <paramref name="keep"/> backups (by creation time,
    /// then name) and drop interrupted ".partial" copies.</summary>
    public static IReadOnlyList<string> Prune(string backupRoot, int keep, string? protect = null)
    {
        var removed = new List<string>();
        if (!Directory.Exists(backupRoot))
            return removed;
        foreach (var partial in Directory.EnumerateDirectories(backupRoot, Prefix + "*.partial"))
        {
            if (TryDeleteDirectory(partial))
                removed.Add(partial);
        }

        var backups = Directory.EnumerateDirectories(backupRoot, Prefix + "*")
            .Where(path => !path.EndsWith(".partial", StringComparison.OrdinalIgnoreCase))
            .Select(path => new DirectoryInfo(path))
            .OrderByDescending(info => info.CreationTimeUtc)
            .ThenByDescending(info => info.Name, StringComparer.Ordinal)
            .ToList();
        foreach (var stale in backups.Skip(Math.Max(keep, 1)))
        {
            if (protect is not null && string.Equals(stale.FullName, Path.GetFullPath(protect), StringComparison.OrdinalIgnoreCase))
                continue;
            if (TryDeleteDirectory(stale.FullName))
                removed.Add(stale.FullName);
        }

        return removed;
    }

    /// <summary>Copy every file of <paramref name="source"/> over <paramref name="target"/>.</summary>
    public static async Task<int> OverlayAsync(
        string source,
        string target,
        int retries,
        Func<TimeSpan, CancellationToken, Task> delay,
        CancellationToken ct)
    {
        var copied = 0;
        foreach (var file in Directory.EnumerateFiles(source, "*", SearchOption.AllDirectories))
        {
            var relative = Path.GetRelativePath(source, file);
            var destination = Path.Combine(target, relative);
            Directory.CreateDirectory(Path.GetDirectoryName(destination)!);
            await CopyWithRetryAsync(file, destination, retries, delay, ct).ConfigureAwait(false);
            copied++;
        }

        return copied;
    }

    /// <summary>Make <paramref name="installDirectory"/> an exact copy of the
    /// backup again: overwrite every backed-up file, then delete files the
    /// failed update added.</summary>
    public static async Task RestoreAsync(
        string backup,
        string installDirectory,
        int retries,
        Func<TimeSpan, CancellationToken, Task> delay,
        CancellationToken ct)
    {
        await OverlayAsync(backup, installDirectory, retries, delay, ct).ConfigureAwait(false);
        var kept = new HashSet<string>(
            Directory.EnumerateFiles(backup, "*", SearchOption.AllDirectories).Select(file => Path.GetRelativePath(backup, file)),
            StringComparer.OrdinalIgnoreCase);
        foreach (var file in Directory.EnumerateFiles(installDirectory, "*", SearchOption.AllDirectories).ToList())
        {
            if (kept.Contains(Path.GetRelativePath(installDirectory, file)))
                continue;
            try
            {
                File.SetAttributes(file, FileAttributes.Normal);
                File.Delete(file);
            }
            catch (Exception exception) when (exception is IOException or UnauthorizedAccessException)
            {
                Log.Warning("update_restore_extra_file_kept file={File} type={ErrorType}", Path.GetRelativePath(installDirectory, file), exception.GetType().Name);
            }
        }
    }

    private static void CopyTree(string source, string target)
    {
        Directory.CreateDirectory(target);
        foreach (var directory in Directory.EnumerateDirectories(source, "*", SearchOption.AllDirectories))
            Directory.CreateDirectory(Path.Combine(target, Path.GetRelativePath(source, directory)));
        foreach (var file in Directory.EnumerateFiles(source, "*", SearchOption.AllDirectories))
            File.Copy(file, Path.Combine(target, Path.GetRelativePath(source, file)), overwrite: true);
    }

    private static async Task CopyWithRetryAsync(
        string source,
        string destination,
        int retries,
        Func<TimeSpan, CancellationToken, Task> delay,
        CancellationToken ct)
    {
        for (var attempt = 0; ; attempt++)
        {
            try
            {
                if (File.Exists(destination))
                    File.SetAttributes(destination, FileAttributes.Normal);
                File.Copy(source, destination, overwrite: true);
                return;
            }
            catch (IOException) when (attempt < retries)
            {
                // Sharing violation from a process that is still exiting.
                await delay(TimeSpan.FromSeconds(1), ct).ConfigureAwait(false);
            }
        }
    }

    private static bool TryDeleteDirectory(string path)
    {
        try
        {
            Directory.Delete(path, recursive: true);
            return true;
        }
        catch (Exception exception) when (exception is IOException or UnauthorizedAccessException)
        {
            Log.Warning("update_backup_prune_failed dir={Dir} type={ErrorType}", Path.GetFileName(path), exception.GetType().Name);
            return false;
        }
    }
}

/// <summary>
/// How the install is backed up, replaced and restored. Windows overlays files
/// onto the install directory (<see cref="OverlayInstallSwapper"/>); macOS
/// swaps the whole application bundle with a rename, so a bundle is never a
/// mix of two builds (a stale file would break its code signature).
/// </summary>
public interface IInstallSwapper
{
    /// <summary>Full copy of the current install under <paramref name="backupRoot"/>,
    /// taken while the service is stopped. Returns the backup path.</summary>
    string CreateBackup(string installDirectory, string backupRoot, string version, DateTimeOffset now);

    /// <summary>Keep the newest <paramref name="keep"/> backups; never removes <paramref name="protect"/>.</summary>
    IReadOnlyList<string> Prune(string backupRoot, int keep, string? protect);

    /// <summary>Put the staged build in place. Returns the number of files (or
    /// bundles) moved in. Throws IOException/UnauthorizedAccessException.</summary>
    Task<int> SwapInAsync(string stagedDirectory, string installDirectory, int retries, Func<TimeSpan, CancellationToken, Task> delay, CancellationToken ct);

    /// <summary>Make the install an exact copy of <paramref name="backup"/> again.</summary>
    Task RestoreAsync(string backup, string installDirectory, int retries, Func<TimeSpan, CancellationToken, Task> delay, CancellationToken ct);
}

/// <summary>The Windows strategy: copy files over the install directory.</summary>
public sealed class OverlayInstallSwapper : IInstallSwapper
{
    public static OverlayInstallSwapper Instance { get; } = new();

    public string CreateBackup(string installDirectory, string backupRoot, string version, DateTimeOffset now)
        => InstallDirectoryBackup.Create(installDirectory, backupRoot, version, now);

    public IReadOnlyList<string> Prune(string backupRoot, int keep, string? protect)
        => InstallDirectoryBackup.Prune(backupRoot, keep, protect);

    public Task<int> SwapInAsync(string stagedDirectory, string installDirectory, int retries, Func<TimeSpan, CancellationToken, Task> delay, CancellationToken ct)
        => InstallDirectoryBackup.OverlayAsync(stagedDirectory, installDirectory, retries, delay, ct);

    public Task RestoreAsync(string backup, string installDirectory, int retries, Func<TimeSpan, CancellationToken, Task> delay, CancellationToken ct)
        => InstallDirectoryBackup.RestoreAsync(backup, installDirectory, retries, delay, ct);
}

/// <summary>
/// The detached swap step: stop the service and everything running from the
/// install directory, back the install up, copy the new build in, start it,
/// and hold it to <see cref="UpdateHealthGate"/>. A build that fails the gate
/// is swapped back out, the old build restarted, and the version recorded as
/// rejected so the auto-update loop never retries it.
/// </summary>
public sealed class UpdateApplyOrchestrator(
    IUpdateApplyEnvironment environment,
    UpdateApplyOptions options,
    IInstallSwapper? swapper = null)
{
    private readonly IInstallSwapper _swapper = swapper ?? OverlayInstallSwapper.Instance;

    public string? BackupPath { get; private set; }

    public async Task<UpdateApplyOutcome> RunAsync(CancellationToken ct)
    {
        var install = options.InstallDirectory;
        if (!Directory.Exists(install) || !File.Exists(Path.Combine(install, options.SentinelFileName)))
        {
            Log.Warning("update_apply_invalid reason=install_dir_not_a_client_install");
            return UpdateApplyOutcome.InvalidRequest;
        }

        if (!Directory.Exists(options.StagedDirectory) || !File.Exists(Path.Combine(options.StagedDirectory, options.SentinelFileName)))
        {
            Log.Warning("update_apply_invalid reason=staged_payload_incomplete version={NewVersion}", options.NewVersion);
            return UpdateApplyOutcome.InvalidRequest;
        }

        if (string.Equals(Path.GetFullPath(install).TrimEnd('\\', '/'), Path.GetFullPath(options.StagedDirectory).TrimEnd('\\', '/'), StringComparison.OrdinalIgnoreCase))
        {
            Log.Warning("update_apply_invalid reason=staged_equals_install");
            return UpdateApplyOutcome.InvalidRequest;
        }

        Log.Information("update_apply_begin from={OldVersion} to={NewVersion}", options.PreviousVersion, options.NewVersion);
        var baseline = UpdateHealthGate.RunningForwards(environment.ReadStatus(), environment.UtcNow, TimeSpan.FromMinutes(5));
        Log.Information("update_apply_baseline running_forwards={Running}", baseline);

        if (!await StopEverythingAsync(ct).ConfigureAwait(false))
        {
            Log.Warning("update_apply_stop_failed from={OldVersion} to={NewVersion}; restarting the old build", options.PreviousVersion, options.NewVersion);
            await environment.StartServiceAsync(ct).ConfigureAwait(false);
            Record(state => HeadlessUpdatePolicy.RecordApplyFailure(state, options.NewVersion, HeadlessUpdateResults.StopFailed, "service or install processes did not exit", environment.UtcNow));
            return UpdateApplyOutcome.StopFailed;
        }

        try
        {
            BackupPath = _swapper.CreateBackup(install, options.BackupRoot, options.PreviousVersion, environment.UtcNow);
            Log.Information("update_apply_backup_created version={OldVersion} path={Backup}", options.PreviousVersion, BackupPath);
            foreach (var removed in _swapper.Prune(options.BackupRoot, options.KeepBackups, BackupPath))
                Log.Information("update_apply_backup_pruned dir={Dir}", Path.GetFileName(removed));
        }
        catch (Exception exception) when (exception is IOException or UnauthorizedAccessException)
        {
            Log.Warning("update_apply_backup_failed version={OldVersion} type={ErrorType}; restarting the old build", options.PreviousVersion, exception.GetType().Name);
            await environment.StartServiceAsync(ct).ConfigureAwait(false);
            Record(state => HeadlessUpdatePolicy.RecordApplyFailure(state, options.NewVersion, HeadlessUpdateResults.BackupFailed, exception.GetType().Name, environment.UtcNow));
            return UpdateApplyOutcome.BackupFailed;
        }

        try
        {
            var copied = await _swapper.SwapInAsync(options.StagedDirectory, install, options.CopyRetries, environment.DelayAsync, ct).ConfigureAwait(false);
            Log.Information("update_apply_swapped files={Count} to={NewVersion}", copied, options.NewVersion);
        }
        catch (Exception exception) when (exception is IOException or UnauthorizedAccessException)
        {
            Log.Warning("update_apply_copy_failed to={NewVersion} type={ErrorType}; restoring {OldVersion}", options.NewVersion, exception.GetType().Name, options.PreviousVersion);
            var restored = await RestoreAndRestartAsync(ct).ConfigureAwait(false);
            Record(state => HeadlessUpdatePolicy.RecordApplyFailure(
                state,
                options.NewVersion,
                restored ? HeadlessUpdateResults.CopyFailed : HeadlessUpdateResults.RollbackFailed,
                exception.GetType().Name,
                environment.UtcNow));
            return restored ? UpdateApplyOutcome.CopyFailedRestored : UpdateApplyOutcome.RollbackFailed;
        }

        var swappedAt = environment.UtcNow;
        var started = await environment.StartServiceAsync(ct).ConfigureAwait(false);
        Log.Information("update_apply_service_started version={NewVersion} running={Started}", options.NewVersion, started);

        // Healthy must also *stay* healthy for the stability window, so a build
        // that signs in once and then crashes (SCM restarting it) is caught.
        var deadline = swappedAt + options.HealthTimeout;
        DateTimeOffset? healthySince = null;
        while (true)
        {
            var running = await environment.IsServiceRunningAsync(ct).ConfigureAwait(false);
            var now = environment.UtcNow;
            var verdict = UpdateHealthGate.Evaluate(running, environment.ReadStatus(), swappedAt, options.NewVersion, baseline, now, deadline);
            if (verdict == UpdateHealthVerdict.Healthy)
            {
                healthySince ??= now;
                if (now - healthySince.Value >= options.StabilityWindow)
                {
                    Log.Information("update_apply_healthy version={NewVersion} after={Seconds:F0}s", options.NewVersion, (now - swappedAt).TotalSeconds);
                    Record(state => HeadlessUpdatePolicy.RecordApplied(state, options.NewVersion, environment.UtcNow));
                    return UpdateApplyOutcome.Applied;
                }
            }
            else
            {
                healthySince = null;
            }

            if (now >= deadline + (healthySince is null ? TimeSpan.Zero : options.StabilityWindow))
                break;
            await environment.DelayAsync(options.HealthPollInterval, ct).ConfigureAwait(false);
        }

        var last = environment.ReadStatus();
        var reason = DescribeUnhealthy(await environment.IsServiceRunningAsync(ct).ConfigureAwait(false), last, swappedAt, options.NewVersion);
        Log.Warning(
            "update_apply_unhealthy version={NewVersion} reason={Reason} timeout={Seconds:F0}s; rolling back to {OldVersion}",
            options.NewVersion,
            reason,
            options.HealthTimeout.TotalSeconds,
            options.PreviousVersion);
        var rolledBack = await RestoreAndRestartAsync(ct).ConfigureAwait(false);
        Record(state => HeadlessUpdatePolicy.RecordRejected(state, options.NewVersion, "health gate: " + reason, environment.UtcNow));
        Log.Warning("update_apply_rejected version={NewVersion} rolled_back={RolledBack}", options.NewVersion, rolledBack);
        return rolledBack ? UpdateApplyOutcome.RejectedRolledBack : UpdateApplyOutcome.RollbackFailed;
    }

    private async Task<bool> StopEverythingAsync(CancellationToken ct)
    {
        var stopped = await environment.StopServiceAsync(options.StopTimeout, ct).ConfigureAwait(false);
        Log.Information("update_apply_service_stopped ok={Stopped}", stopped);
        var drained = await environment.WaitForInstallProcessesExitAsync(options.InstallDirectory, options.ProcessExitTimeout, ct).ConfigureAwait(false);
        Log.Information("update_apply_install_processes_drained ok={Drained}", drained);
        return stopped && drained;
    }

    private async Task<bool> RestoreAndRestartAsync(CancellationToken ct)
    {
        if (BackupPath is null)
            return false;
        var restored = false;
        try
        {
            await StopEverythingAsync(ct).ConfigureAwait(false);
            await _swapper.RestoreAsync(BackupPath, options.InstallDirectory, options.CopyRetries, environment.DelayAsync, ct).ConfigureAwait(false);
            restored = true;
            Log.Information("update_apply_restored version={OldVersion} from={Backup}", options.PreviousVersion, BackupPath);
        }
        catch (Exception exception) when (exception is IOException or UnauthorizedAccessException)
        {
            Log.Error("update_apply_restore_failed version={OldVersion} backup={Backup} type={ErrorType}", options.PreviousVersion, BackupPath, exception.GetType().Name);
        }

        var started = await environment.StartServiceAsync(ct).ConfigureAwait(false);
        Log.Information("update_apply_old_build_restarted version={OldVersion} running={Started}", options.PreviousVersion, started);
        return restored;
    }

    internal static string DescribeUnhealthy(bool running, ClientStatusDocument? status, DateTimeOffset swappedAt, string expectedVersion)
    {
        if (!running)
            return "service_not_running";
        if (status is null || status.UpdatedAt <= swappedAt)
            return "status_not_refreshed";
        if (!status.Connected)
            return "not_connected";
        if (!UpdateHealthGate.VersionMatches(status.Version, expectedVersion))
            return "version_mismatch";
        return "no_forward_running";
    }

    private void Record(Action<HeadlessUpdateState> change)
    {
        try
        {
            var state = HeadlessUpdateStateStore.Load(options.StatePath);
            change(state);
            HeadlessUpdateStateStore.Save(options.StatePath, state);
        }
        catch (Exception exception) when (exception is IOException or UnauthorizedAccessException)
        {
            Log.Warning("update_apply_state_write_failed type={ErrorType}", exception.GetType().Name);
        }
    }
}
