using System.Globalization;
using System.Runtime.InteropServices;
using System.Text.Json;
using System.Text.Json.Serialization;
using LeikwanBridge.Core.Models;
using LeikwanBridge.Core.Services;
using Serilog;

namespace LeikwanBridge.Mac.Services;

/// <summary>
/// The shared headless update source contract on top of the macOS app updater: the
/// entry is the published update zip for the running architecture, the
/// download is checked against the manifest size and sha256, and the extracted
/// bundle must be a valid LeiKwan bundle whose CFBundleShortVersionString is
/// the manifest version (<see cref="MacBundleValidator"/>).
/// </summary>
public sealed class MacHeadlessUpdateSource(MacAppUpdateService updater, Func<string> panelUrl) : IClientUpdateSource
{
    private static readonly TimeSpan StagingKeep = TimeSpan.FromHours(1);
    private ClientAppArtifact? _artifact;

    public PreparedAppUpdate? Prepared { get; private set; }

    public async Task<PublishedClientUpdate?> ReadPublishedAsync(CancellationToken ct)
    {
        // "0.0.0" makes the updater return the validated entry whatever is
        // installed, so the shared policy (not this adapter) decides between
        // up to date, downgrade, rejected and apply.
        var artifact = await updater.CheckAsync(panelUrl(), "0.0.0", progress: null, ct).ConfigureAwait(false);
        _artifact = artifact;
        if (artifact is null)
            return null;
        var fileName = Uri.TryCreate(artifact.ArtifactUrl, UriKind.Absolute, out var uri)
            ? Path.GetFileName(uri.LocalPath)
            : "macos-update.zip";
        return new PublishedClientUpdate(artifact.Version, artifact.ArtifactUrl, artifact.Sha256, artifact.SizeBytes, fileName);
    }

    public async Task<string> DownloadVerifiedAsync(PublishedClientUpdate update, CancellationToken ct)
    {
        var artifact = _artifact;
        if (artifact is null || !HeadlessUpdatePolicy.IsSameVersion(artifact.Version, update.Version))
            throw new ClientUpdateVerificationException("manifest entry changed between check and download");
        var stateDirectory = Path.GetDirectoryName(updater.UpdatesRoot.TrimEnd('/'));
        if (!string.IsNullOrWhiteSpace(stateDirectory))
            MacRootOnlyDirectory.Ensure(stateDirectory);
        MacRootOnlyDirectory.Ensure(updater.UpdatesRoot);
        PruneStaging(updater.UpdatesRoot);
        try
        {
            Prepared = await updater.PrepareAsync(artifact, progress: null, ct).ConfigureAwait(false);
        }
        catch (InvalidDataException exception)
        {
            // Size, hash, archive layout, bundle identity/version/architecture.
            throw new ClientUpdateVerificationException(exception.Message);
        }

        return Prepared.StagedBundlePath;
    }

    /// <summary>Drops downloads and extractions left by earlier cycles.</summary>
    public static void PruneStaging(string updatesRoot)
    {
        try
        {
            if (!Directory.Exists(updatesRoot))
                return;
            var now = DateTime.UtcNow;
            foreach (var directory in Directory.EnumerateDirectories(updatesRoot, "stage-*")
                         .Concat(Directory.EnumerateDirectories(updatesRoot, "applier-*.app")))
            {
                if (now - Directory.GetCreationTimeUtc(directory) >= StagingKeep)
                    TryDeleteDirectory(directory);
            }

            foreach (var file in Directory.EnumerateFiles(updatesRoot, "update-*.zip"))
            {
                if (now - File.GetLastWriteTimeUtc(file) >= StagingKeep)
                    TryDeleteFile(file);
            }
        }
        catch (Exception exception) when (exception is IOException or UnauthorizedAccessException)
        {
        }
    }

    private static void TryDeleteDirectory(string path)
    {
        try
        {
            Directory.Delete(path, recursive: true);
        }
        catch (Exception exception) when (exception is IOException or UnauthorizedAccessException)
        {
        }
    }

    private static void TryDeleteFile(string path)
    {
        try
        {
            File.Delete(path);
        }
        catch (Exception exception) when (exception is IOException or UnauthorizedAccessException)
        {
        }
    }
}

/// <summary>
/// Hands a verified, extracted bundle to the detached apply step. The step runs
/// from a clone of the new bundle under the root-only updates directory (never
/// from a bundle the swap renames) in its own session, so it outlives the
/// daemon it stops.
/// </summary>
public sealed class MacHeadlessUpdateLauncher(
    string installBundle,
    string updatesRoot,
    string logDirectory,
    IMacDetachedSpawner spawner,
    IMacBundleFileSystem fileSystem) : IHeadlessUpdateLauncher
{
    public string InstallBundle => installBundle;

    public Task<bool> LaunchAsync(string stagedFile, string version, CancellationToken ct)
        => LaunchAsync(stagedFile, version, Guid.NewGuid().ToString("N"), excludePid: 0, ct);

    public Task<bool> LaunchAsync(string stagedBundle, string version, string requestId, int excludePid, CancellationToken ct)
    {
        if (!Directory.Exists(stagedBundle) || !stagedBundle.TrimEnd('/', '\\').EndsWith(".app", StringComparison.OrdinalIgnoreCase))
        {
            Log.Warning("headless_update_launch_refused reason=staged_bundle_missing");
            return Task.FromResult(false);
        }

        // A mislabelled publish would otherwise be "applied" every cycle without
        // ever becoming the installed version.
        var payloadVersion = MacBundleValidator.ReadBundleVersion(stagedBundle) ?? string.Empty;
        if (!HeadlessUpdatePolicy.IsSameVersion(payloadVersion, version))
        {
            Log.Warning("headless_update_launch_refused reason=payload_version payload={Payload} manifest={Manifest}", payloadVersion, version);
            return Task.FromResult(false);
        }

        var previousVersion = MacBundleValidator.ReadBundleVersion(installBundle) ?? "unknown";
        string executable;
        try
        {
            MacRootOnlyDirectory.Ensure(updatesRoot);
            var applier = Path.Combine(updatesRoot, $"applier-{Guid.NewGuid():N}.app");
            fileSystem.Clone(stagedBundle, applier);
            executable = MacBundleValidator.GetMainExecutablePath(applier);
            Directory.CreateDirectory(logDirectory);
        }
        catch (Exception exception) when (exception is IOException or UnauthorizedAccessException or InvalidDataException)
        {
            Log.Warning("headless_update_launch_failed reason=helper_copy type={ErrorType}", exception.GetType().Name);
            return Task.FromResult(false);
        }

        var arguments = new List<string>
        {
            MacHeadlessUpdatePaths.ApplyCommand,
            "--staged", stagedBundle,
            "--install", installBundle,
            "--version", version,
            "--previous-version", previousVersion,
            "--request-id", requestId,
        };
        if (excludePid > 0)
        {
            arguments.Add("--exclude-pid");
            arguments.Add(excludePid.ToString(CultureInfo.InvariantCulture));
        }

        var pid = spawner.Spawn(executable, arguments, Path.Combine(logDirectory, "update-helper.out.log"));
        if (pid <= 0)
            return Task.FromResult(false);
        Log.Information("headless_update_helper_started pid={Pid} from={OldVersion} to={NewVersion} request={Request}", pid, previousVersion, version, requestId);
        LastHelperPid = pid;
        return Task.FromResult(true);
    }

    public int LastHelperPid { get; private set; }
}

/// <summary>Outcome of one detached apply, published for a waiting CLI.</summary>
public sealed record MacHeadlessApplyResult
{
    [JsonPropertyName("request_id")]
    public string RequestId { get; init; } = string.Empty;

    /// <summary>An <see cref="UpdateApplyOutcome"/> name, "Locked" or "Crashed".</summary>
    [JsonPropertyName("outcome")]
    public string Outcome { get; init; } = string.Empty;

    [JsonPropertyName("previous_version")]
    public string PreviousVersion { get; init; } = string.Empty;

    [JsonPropertyName("new_version")]
    public string NewVersion { get; init; } = string.Empty;

    [JsonPropertyName("at")]
    public DateTimeOffset At { get; init; }

    public bool Applied => string.Equals(Outcome, nameof(UpdateApplyOutcome.Applied), StringComparison.Ordinal);

    public static void Write(string path, MacHeadlessApplyResult result)
    {
        try
        {
            var directory = Path.GetDirectoryName(path);
            if (!string.IsNullOrWhiteSpace(directory))
                Directory.CreateDirectory(directory);
            var temporary = path + "." + Guid.NewGuid().ToString("N") + ".tmp";
            File.WriteAllText(temporary, JsonSerializer.Serialize(result));
            File.Move(temporary, path, overwrite: true);
        }
        catch (Exception exception) when (exception is IOException or UnauthorizedAccessException)
        {
            Log.Warning("update_apply_result_write_failed type={ErrorType}", exception.GetType().Name);
        }
    }

    public static MacHeadlessApplyResult? Read(string path)
    {
        try
        {
            return File.Exists(path) ? JsonSerializer.Deserialize<MacHeadlessApplyResult>(File.ReadAllText(path)) : null;
        }
        catch (Exception exception) when (exception is IOException or UnauthorizedAccessException or JsonException)
        {
            return null;
        }
    }
}

/// <summary>
/// The detached apply step (`LeikwanBridge.Mac --apply-headless-update ...`),
/// shared by the daemon's requested update and `leikwan update`: wait for the apply
/// lock, boot the daemon out, clone the installed bundle into a root-only
/// backup, swap the new bundle in, start the daemon and hold it to the same
/// health gate as Windows (<see cref="UpdateHealthGate"/>, 180 s + stability
/// window). An unhealthy build is swapped back out, the old one restarted and
/// the version recorded as rejected.
/// </summary>
public static class MacHeadlessUpdateApply
{
    public sealed record Request(
        string Staged,
        string Install,
        string Version,
        string PreviousVersion,
        string RequestId,
        IReadOnlyList<int> ExcludePids);

    public static bool TryParse(IReadOnlyList<string> arguments, out Request? request, out string error)
    {
        request = null;
        string? Option(string name)
        {
            for (var index = 0; index < arguments.Count - 1; index++)
            {
                if (string.Equals(arguments[index], name, StringComparison.Ordinal))
                    return arguments[index + 1];
            }

            return null;
        }

        var staged = Option("--staged");
        var install = Option("--install");
        var version = Option("--version");
        if (!IsBundlePath(staged) || !IsBundlePath(install))
        {
            error = "--staged and --install must be absolute .app paths";
            return false;
        }

        if (string.IsNullOrWhiteSpace(version) || !Version.TryParse(version, out _))
        {
            error = "--version is required";
            return false;
        }

        var excluded = new List<int>();
        for (var index = 0; index < arguments.Count - 1; index++)
        {
            if (arguments[index] == "--exclude-pid"
                && int.TryParse(arguments[index + 1], NumberStyles.None, CultureInfo.InvariantCulture, out var pid)
                && pid > 1)
            {
                excluded.Add(pid);
            }
        }

        request = new Request(
            staged!.TrimEnd('/'),
            install!.TrimEnd('/'),
            version,
            Option("--previous-version") ?? MacBundleValidator.ReadBundleVersion(install!) ?? "unknown",
            Option("--request-id") ?? string.Empty,
            excluded);
        error = string.Empty;
        return true;
    }

    public static async Task<int> RunAsync(string[] arguments)
    {
        ConfigureLogging();
        Request? request = null;
        try
        {
            if (!TryParse(arguments, out request, out var parseError))
            {
                Console.Error.WriteLine("apply-headless-update: " + parseError);
                Log.Warning("update_apply_invalid reason={Reason}", parseError);
                return 2;
            }

            if (!OperatingSystem.IsMacOS() || MacNative.geteuid() != 0)
            {
                Console.Error.WriteLine("apply-headless-update: must run as root on macOS");
                return 2;
            }

            MacRootOnlyDirectory.Ensure(MacHeadlessUpdatePaths.SystemStateDirectory);
            MacRootOnlyDirectory.Ensure(MacHeadlessUpdatePaths.UpdatesRoot);
            MacRootOnlyDirectory.Ensure(MacHeadlessUpdatePaths.BackupRoot);
            using var applyLock = await UpdateApplyLock.AcquireAsync(MacHeadlessUpdatePaths.LockPath, TimeSpan.FromSeconds(120)).ConfigureAwait(false);
            if (applyLock is null)
            {
                Log.Warning("update_apply_locked from={OldVersion} to={NewVersion}", request!.PreviousVersion, request.Version);
                Publish(request, "Locked");
                return 3;
            }

            var environment = new MacUpdateApplyEnvironment(
                MacHeadlessUpdatePaths.Label,
                MacHeadlessUpdatePaths.PlistPath,
                MacHeadlessUpdatePaths.StatusFile,
                request!.ExcludePids);
            var outcome = await ApplyAsync(
                request,
                environment,
                new MacBundleSwapper(MacNativeBundleFileSystem.Instance, MacHeadlessUpdatePaths.UpdatesRoot),
                MacHeadlessUpdatePaths.BackupRoot,
                MacHeadlessUpdatePaths.StatePath,
                CancellationToken.None).ConfigureAwait(false);
            Publish(request, outcome?.ToString() ?? "Crashed");
            Log.Information("update_apply_finished outcome={Outcome} from={OldVersion} to={NewVersion}", outcome?.ToString() ?? "Crashed", request.PreviousVersion, request.Version);
            return outcome switch
            {
                UpdateApplyOutcome.Applied => 0,
                UpdateApplyOutcome.InvalidRequest => 2,
                _ => 1,
            };
        }
        catch (Exception exception)
        {
            Log.Error("update_apply_crashed type={ErrorType}", exception.GetType().Name);
            if (request is not null)
                Publish(request, "Crashed");
            return 1;
        }
        finally
        {
            Log.CloseAndFlush();
        }
    }

    /// <summary>
    /// Runs the shared orchestrator with the macOS environment and bundle swap.
    /// Returns null when the step crashed; in that case it still makes sure the
    /// daemon is running again, because a booted-out daemon has nobody else to
    /// restart it before the next boot.
    /// </summary>
    public static async Task<UpdateApplyOutcome?> ApplyAsync(
        Request request,
        IUpdateApplyEnvironment environment,
        IInstallSwapper swapper,
        string backupRoot,
        string statePath,
        CancellationToken ct)
    {
        var options = new UpdateApplyOptions
        {
            StagedDirectory = request.Staged,
            InstallDirectory = request.Install,
            BackupRoot = backupRoot,
            StatePath = statePath,
            PreviousVersion = request.PreviousVersion,
            NewVersion = request.Version,
            SentinelFileName = MacHeadlessUpdatePaths.ExecutableRelativePath,
        };
        try
        {
            return await new UpdateApplyOrchestrator(environment, options, swapper).RunAsync(ct).ConfigureAwait(false);
        }
        catch (Exception exception) when (exception is not OperationCanceledException || !ct.IsCancellationRequested)
        {
            Log.Error("update_apply_unexpected_failure type={ErrorType}; making sure the daemon runs", exception.GetType().Name);
            try
            {
                if (!await environment.IsServiceRunningAsync(ct).ConfigureAwait(false))
                    await environment.StartServiceAsync(ct).ConfigureAwait(false);
            }
            catch (Exception restartException)
            {
                Log.Error("update_apply_restart_after_failure_failed type={ErrorType}", restartException.GetType().Name);
            }

            try
            {
                var state = HeadlessUpdateStateStore.Load(statePath);
                HeadlessUpdatePolicy.RecordApplyFailure(state, request.Version, HeadlessUpdateResults.RollbackFailed, "apply step crashed: " + exception.GetType().Name, environment.UtcNow);
                HeadlessUpdateStateStore.Save(statePath, state);
            }
            catch (Exception stateException) when (stateException is IOException or UnauthorizedAccessException)
            {
            }

            return null;
        }
    }

    private static void Publish(Request request, string outcome)
        => MacHeadlessApplyResult.Write(MacHeadlessUpdatePaths.ResultPath, new MacHeadlessApplyResult
        {
            RequestId = request.RequestId,
            Outcome = outcome,
            PreviousVersion = request.PreviousVersion,
            NewVersion = request.Version,
            At = DateTimeOffset.UtcNow,
        });

    private static bool IsBundlePath(string? path)
        => !string.IsNullOrWhiteSpace(path)
            && path.StartsWith('/')
            && path.TrimEnd('/').EndsWith(".app", StringComparison.OrdinalIgnoreCase);

    private static void ConfigureLogging()
    {
        try
        {
            Directory.CreateDirectory(MacHeadlessUpdatePaths.LogDirectory);
            Log.Logger = new LoggerConfiguration()
                .MinimumLevel.Information()
                .WriteTo.File(
                    new RedactingTextFormatter("{Timestamp:yyyy-MM-dd HH:mm:ss} [{Level:u3}] {Message:lj}{NewLine}{Exception}"),
                    Path.Combine(MacHeadlessUpdatePaths.LogDirectory, "update-.log"),
                    rollingInterval: RollingInterval.Day,
                    retainedFileCountLimit: 14,
                    fileSizeLimitBytes: 5 * 1024 * 1024,
                    rollOnFileSizeLimit: true)
                .CreateLogger();
        }
        catch (Exception exception) when (exception is IOException or UnauthorizedAccessException)
        {
            // Logging is best effort; the swap itself must still run.
        }
    }
}

/// <summary>Everything the daemon's update loop needs to know about where it runs.</summary>
public sealed record MacHeadlessUpdateContext(
    string BundleDirectory,
    string UpdatesRoot,
    string StatePath,
    string LockPath,
    string LogDirectory,
    IReadOnlyList<string> ConfigPaths)
{
    public static MacHeadlessUpdateContext ForDaemon(MacPlatformPaths paths) => new(
        paths.BundleDirectory,
        MacHeadlessUpdatePaths.UpdatesRoot,
        MacHeadlessUpdatePaths.StatePath,
        MacHeadlessUpdatePaths.LockPath,
        MacHeadlessUpdatePaths.LogDirectory,
        [paths.ConfigPath, MacHeadlessUpdatePaths.SystemStateDirectory + "/config.json"]);
}

/// <summary>
/// Service-mode update for the LaunchDaemon, on the same scheduling rules as
/// the Windows service (<see cref="HeadlessAutoUpdateCoordinator"/>: first
/// check after 1-10 min, then 6 h ± 30 min, backoff 15 min doubling to 24 h,
/// never a downgrade, rejected versions remembered, disable_auto_updates
/// re-read every cycle, apply lock). It only installs an update requested from
/// the panel console, whose token the daemon's own config pulls deliver
/// (<see cref="HeadlessUpdateRequestSignal"/>). The windowed app never runs it.
/// </summary>
public static class MacHeadlessAutoUpdate
{
    /// <summary>Only the launchd daemon updates itself: root, parent launchd, not a --once smoke run.</summary>
    public static bool ShouldRun(HeadlessClientOptions options, string bundleDirectory, uint effectiveUid, int parentPid)
        => !options.Once
            && effectiveUid == 0
            && parentPid == 1
            && bundleDirectory.TrimEnd('/').EndsWith(".app", StringComparison.OrdinalIgnoreCase);

    public static async Task RunIfDaemonAsync(MacPlatformPaths paths, HeadlessClientOptions options, Func<string> panelUrl, CancellationToken ct)
    {
        if (!OperatingSystem.IsMacOS()
            || !ShouldRun(options, paths.BundleDirectory, MacNative.geteuid(), MacNative.getppid()))
        {
            Log.Information("headless_update_loop_disabled reason=not_the_launchd_daemon");
            return;
        }

        // The macOS client ships for Apple Silicon only; there is no Intel update to apply.
        if (RuntimeInformation.ProcessArchitecture != Architecture.Arm64)
        {
            Log.Warning("headless_update_loop_disabled reason=mac_architecture_unsupported");
            return;
        }

        try
        {
            var context = MacHeadlessUpdateContext.ForDaemon(paths);
            using var updater = new MacAppUpdateService(
                context.UpdatesRoot,
                context.BundleDirectory,
                "arm64",
                waitForCallerExit: false);
            var coordinator = Create(context, panelUrl, updater, MacPosixDetachedSpawner.Instance, MacNativeBundleFileSystem.Instance);
            await coordinator.RunLoopAsync(new Random(), ct: ct).ConfigureAwait(false);
        }
        catch (OperationCanceledException) when (ct.IsCancellationRequested)
        {
        }
        catch (Exception exception)
        {
            // The tunnel keeps running even if self-update cannot.
            Log.Warning("headless_update_loop_failed type={ErrorType}", exception.GetType().Name);
        }
    }

    public static HeadlessAutoUpdateCoordinator Create(
        MacHeadlessUpdateContext context,
        Func<string> panelUrl,
        MacAppUpdateService updater,
        IMacDetachedSpawner spawner,
        IMacBundleFileSystem fileSystem,
        Func<DateTimeOffset>? clock = null,
        Func<string?>? requestToken = null)
        => new(
            InstalledVersion(context.BundleDirectory),
            context.StatePath,
            context.LockPath,
            () => IsEnabled(context.ConfigPaths),
            requestToken ?? (() => HeadlessUpdateRequestSignal.Shared.Current),
            new MacHeadlessUpdateSource(updater, panelUrl),
            new MacHeadlessUpdateLauncher(context.BundleDirectory, context.UpdatesRoot, context.LogDirectory, spawner, fileSystem),
            clock);

    public static string InstalledVersion(string bundleDirectory)
        => MacBundleValidator.ReadBundleVersion(bundleDirectory)
            ?? typeof(MacHeadlessAutoUpdate).Assembly.GetName().Version?.ToString(3)
            ?? "0.0.0";

    /// <summary>Re-read on every cycle so "disable_auto_updates": true in either
    /// config takes effect without a restart; it also refuses updates requested
    /// from the console. An unreadable config throws, which
    /// the coordinator treats as "skip this cycle".</summary>
    public static bool IsEnabled(IReadOnlyList<string> configPaths)
    {
        foreach (var path in configPaths)
        {
            if (!File.Exists(path))
                continue;
            using var document = JsonDocument.Parse(File.ReadAllBytes(path));
            if (document.RootElement.ValueKind != JsonValueKind.Object)
                continue;
            foreach (var property in document.RootElement.EnumerateObject())
            {
                if (string.Equals(property.Name, "disable_auto_updates", StringComparison.OrdinalIgnoreCase)
                    && property.Value.ValueKind == JsonValueKind.True)
                {
                    return false;
                }
            }
        }

        return true;
    }
}

/// <summary>`leikwan update` against the installed daemon.</summary>
public interface IMacHeadlessUpdateInstaller
{
    /// <summary>True when the LaunchDaemon is installed (its plist exists).</summary>
    bool IsDaemonInstalled { get; }

    /// <summary>Why the update cannot be applied from this process, or null.</summary>
    string? PreconditionError();

    /// <summary>Launch the detached apply step for a prepared bundle and wait for
    /// its outcome (health gate included). Null when it did not start or did not
    /// report in time.</summary>
    Task<MacHeadlessApplyResult?> ApplyAsync(PreparedAppUpdate prepared, TextWriter output, CancellationToken ct);
}

public sealed class MacHeadlessCliUpdateInstaller(
    MacHeadlessUpdateLauncher launcher,
    string plistPath,
    string resultPath,
    Func<uint> effectiveUid,
    TimeSpan? timeout = null,
    Func<TimeSpan, CancellationToken, Task>? delay = null) : IMacHeadlessUpdateInstaller
{
    // stop (60 s) + drain (30 s) + health (180 s) + stability (20 s) + a restore.
    private readonly TimeSpan _timeout = timeout ?? TimeSpan.FromMinutes(10);
    private readonly Func<TimeSpan, CancellationToken, Task> _delay = delay ?? ((span, token) => Task.Delay(span, token));

    public static MacHeadlessCliUpdateInstaller CreateDefault(string installBundle)
        => new(
            new MacHeadlessUpdateLauncher(
                installBundle,
                MacHeadlessUpdatePaths.UpdatesRoot,
                MacHeadlessUpdatePaths.LogDirectory,
                MacPosixDetachedSpawner.Instance,
                MacNativeBundleFileSystem.Instance),
            MacHeadlessUpdatePaths.PlistPath,
            MacHeadlessUpdatePaths.ResultPath,
            () => OperatingSystem.IsWindows() ? 1u : MacNative.geteuid());

    public bool IsDaemonInstalled => File.Exists(plistPath);

    public string? PreconditionError()
        => effectiveUid() == 0 ? null : "the daemon update must run as root; use: sudo leikwan update";

    public async Task<MacHeadlessApplyResult?> ApplyAsync(PreparedAppUpdate prepared, TextWriter output, CancellationToken ct)
    {
        var requestId = Guid.NewGuid().ToString("N");
        var version = prepared.Artifact.Version;
        // Warm the result reader before the swap replaces the bundle this CLI runs from.
        _ = MacHeadlessApplyResult.Read(resultPath);
        // This CLI process runs from the installed bundle: the apply step must
        // not treat it as a straggler of the daemon.
        if (!await launcher.LaunchAsync(prepared.StagedBundlePath, version, requestId, Environment.ProcessId, ct).ConfigureAwait(false))
        {
            output.WriteLine("apply     : could not start the detached updater");
            return null;
        }

        output.WriteLine($"apply     : detached updater pid {launcher.LastHelperPid}; the daemon restarts on {version} and is rolled back if it does not come up healthy");
        var started = DateTimeOffset.UtcNow;
        var waited = TimeSpan.Zero;
        var nextReport = TimeSpan.FromSeconds(30);
        while (waited < _timeout)
        {
            var result = MacHeadlessApplyResult.Read(resultPath);
            if (result is not null && string.Equals(result.RequestId, requestId, StringComparison.Ordinal))
                return result;
            if (waited >= nextReport)
            {
                output.WriteLine($"apply     : waiting for the new daemon to pass the health gate ({waited.TotalSeconds:F0}s)");
                nextReport += TimeSpan.FromSeconds(30);
            }

            await _delay(TimeSpan.FromSeconds(1), ct).ConfigureAwait(false);
            waited += TimeSpan.FromSeconds(1);
        }

        output.WriteLine($"apply     : no result after {(DateTimeOffset.UtcNow - started).TotalSeconds:F0}s; see {MacHeadlessUpdatePaths.LogDirectory}/update-*.log");
        return null;
    }
}
