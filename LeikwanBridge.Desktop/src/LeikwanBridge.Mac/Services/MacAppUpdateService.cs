using System.Diagnostics;
using System.IO.Compression;
using System.Security.Cryptography;
using System.Text.Json;
using System.Text.RegularExpressions;
using LeikwanBridge.Core.Models;
using LeikwanBridge.Core.Services;
using LeikwanBridge.Core.Services.Platform;

namespace LeikwanBridge.Mac.Services;

public sealed class MacAppUpdateService : IAppUpdater, IDisposable
{
    private const long MaximumPackageBytes = 4L * 1024 * 1024 * 1024;
    private const long MaximumExtractedBytes = 8L * 1024 * 1024 * 1024;
    private readonly string _updatesRoot;
    private readonly string _currentBundlePath;
    private readonly string _architecture;
    private readonly Func<LeikwanHttpClientKind, HttpClient> _httpClientFactory;
    private readonly bool _ownsHttpClient;
    private readonly Action<HttpRequestMessage>? _onRequestForTest;
    private readonly IMacUpdateLauncher _launcher;
    private readonly Func<string, bool> _directoryWritable;
    private readonly MacBundleValidator _validator;
    private readonly bool _waitForCallerExit;
    private readonly Dictionary<LeikwanHttpClientKind, HttpClient> _clients = [];
    private Uri? _panelOrigin;

    public MacAppUpdateService(
        string updatesRoot,
        string currentBundlePath,
        string architecture,
        Func<LeikwanHttpClientKind, HttpClient>? httpClientFactory = null,
        bool ownsHttpClient = true,
        Action<HttpRequestMessage>? onRequestForTest = null,
        IMacUpdateLauncher? launcher = null,
        Func<string, bool>? directoryWritable = null,
        MacBundleValidator? validator = null,
        bool waitForCallerExit = true)
    {
        _updatesRoot = Path.GetFullPath(updatesRoot);
        _currentBundlePath = Path.GetFullPath(currentBundlePath);
        _architecture = MacBundleValidator.NormalizeArchitecture(architecture);
        _httpClientFactory = httpClientFactory ?? LeikwanHttpClientFactory.Create;
        _ownsHttpClient = ownsHttpClient;
        _onRequestForTest = onRequestForTest;
        _launcher = launcher ?? new MacUpdateLauncher();
        _directoryWritable = directoryWritable ?? IsDirectoryWritable;
        _validator = validator ?? new MacBundleValidator();
        // A windowed/GUI caller is replaced by the update and lets the helper wait for
        // it to exit. A command-line caller stays alive to verify the result, so the
        // helper must not wait for it (that would deadlock).
        _waitForCallerExit = waitForCallerExit;
    }

    /// <summary>Bundle this service treats as the installed application.</summary>
    public string CurrentBundlePath => _currentBundlePath;

    /// <summary>Directory the apply requests, helper log and last result live in.</summary>
    public string UpdatesRoot => _updatesRoot;

    /// <summary>Installed bundle version, or null when the bundle/plist is unreadable.</summary>
    public string? ReadInstalledVersion() => MacBundleValidator.ReadBundleVersion(_currentBundlePath);

    public Task<ClientAppArtifact?> CheckAsync(
        string panelUrl,
        string currentVersion,
        CancellationToken ct = default)
        => CheckAsync(panelUrl, currentVersion, progress: null, ct);

    public async Task<ClientAppArtifact?> CheckAsync(
        string panelUrl,
        string currentVersion,
        IProgress<UpdateProgress>? progress,
        CancellationToken ct = default)
    {
        if (!Uri.TryCreate(panelUrl.TrimEnd('/'), UriKind.Absolute, out var panel)
            || panel.Scheme is not ("https" or "http"))
            throw new InvalidDataException("update_panel_url_invalid");
        _panelOrigin = panel;
        Report(progress, UpdateProgress.Checking("Checking for updates..."));
        var manifestUri = new Uri(panel.ToString().TrimEnd('/') + "/artifacts/client-manifest.json");
        using var request = new HttpRequestMessage(HttpMethod.Get, manifestUri);
        using var response = await SendAsync(request, ct);
        response.EnsureSuccessStatusCode();
        await using var responseStream = await response.Content.ReadAsStreamAsync(ct);
        var json = await ReadBoundedAsync(responseStream, 1024 * 1024, ct);
        ClientAppArtifactManifestResponse manifest;
        try
        {
            manifest = JsonSerializer.Deserialize<ClientAppArtifactManifestResponse>(json)
                ?? throw new InvalidDataException("update_manifest_invalid");
        }
        catch (JsonException)
        {
            throw new InvalidDataException("update_manifest_invalid");
        }
        finally
        {
            CryptographicOperations.ZeroMemory(json);
        }

        var suffix = _architecture == "arm64" ? "arm64" : "x64";
        if (!manifest.Manifest.TryGetValue($"macos_client_update_{suffix}", out var update)
            || !manifest.Manifest.TryGetValue($"macos_client_app_{suffix}", out var installer))
            throw new InvalidDataException("update_manifest_architecture_missing");
        update = ValidateArtifact(update, "zip", panel);
        installer = ValidateArtifact(installer, "dmg", panel);
        foreach (var (key, component) in manifest.Manifest)
        {
            if (key.Contains("runtime", StringComparison.OrdinalIgnoreCase)
                && !string.IsNullOrWhiteSpace(component.Sha256))
                update.RuntimeSha256[key] = component.Sha256;
        }
        if (!VersionsEquivalent(update.Version, installer.Version))
            throw new InvalidDataException("update_manifest_version_mismatch");
        if (!IsRemoteVersionNewer(update.Version, currentVersion)
            && !HasRuntimeDrift(update, _currentBundlePath, _architecture))
        {
            Report(progress, UpdateProgress.Checking("Update check finished."));
            return null;
        }
        Report(progress, UpdateProgress.Checking("Update check finished."));
        return update with { ManualInstaller = installer };
    }

    private static bool HasRuntimeDrift(ClientAppArtifact artifact, string bundlePath, string architecture)
    {
        if (artifact.RuntimeSha256.Count == 0)
            return false;
        foreach (var (key, expected) in artifact.RuntimeSha256)
        {
            if (!TryResolveRuntimePath(key, bundlePath, architecture, out var path))
                continue;
            if (!Regex.IsMatch(expected ?? string.Empty, "^[A-Fa-f0-9]{64}$", RegexOptions.CultureInvariant)
                || !File.Exists(path)
                || !string.Equals(MacBundleValidator.ComputeSha256(path), expected, StringComparison.OrdinalIgnoreCase))
                return true;
        }
        return false;
    }

    private static bool TryResolveRuntimePath(
        string key,
        string bundlePath,
        string architecture,
        out string path)
    {
        path = string.Empty;
        var normalized = key.Trim();
        var activeUltraKey = architecture == "arm64"
            ? "ultra_client_runtime_macos_arm64"
            : "ultra_client_runtime_macos_x64";
        if (string.Equals(normalized, activeUltraKey, StringComparison.OrdinalIgnoreCase))
        {
            path = Path.Combine(bundlePath, "Contents", "Resources", "bin", "ultra-client");
            return true;
        }

        // Mac package metadata records helper paths (for example
        // `bin/wstunnel`) so every native helper is covered by the same
        // runtime-drift check. Reject traversal before combining the path.
        if (!normalized.StartsWith("bin/", StringComparison.OrdinalIgnoreCase))
            return false;
        var relative = normalized.Replace('/', Path.DirectorySeparatorChar);
        if (Path.IsPathRooted(relative)
            || relative.Split(Path.DirectorySeparatorChar).Any(segment => segment is "" or "." or ".."))
            return false;
        path = Path.Combine(bundlePath, "Contents", "Resources", relative);
        return true;
    }

    public Task<PreparedAppUpdate> PrepareAsync(
        ClientAppArtifact artifact,
        CancellationToken ct = default)
        => PrepareAsync(artifact, progress: null, ct);

    public async Task<PreparedAppUpdate> PrepareAsync(
        ClientAppArtifact artifact,
        IProgress<UpdateProgress>? progress,
        CancellationToken ct = default)
    {
        artifact = ValidateArtifact(artifact, "zip", _panelOrigin);
        EnsurePrivateDirectory(_updatesRoot);
        var token = Guid.NewGuid().ToString("N");
        var packagePath = Path.Combine(_updatesRoot, $"update-{artifact.Version}-{_architecture}-{token}.zip");
        var stageRoot = Path.Combine(_updatesRoot, "stage-" + token);
        try
        {
            await DownloadVerifiedAsync(artifact, packagePath, progress, ct);
            Report(progress, UpdateProgress.Staging(0, "Preparing update..."));
            var bundlePath = ExtractValidatedArchive(packagePath, stageRoot);
            _validator.Validate(bundlePath, artifact);
            var executable = MacBundleValidator.GetMainExecutablePath(bundlePath);
            Report(progress, UpdateProgress.Staging(100, "Update staged."));
            return new PreparedAppUpdate(
                artifact,
                packagePath,
                bundlePath,
                MacBundleValidator.ComputeSha256(executable));
        }
        catch
        {
            TryDeleteFile(packagePath);
            TryDeleteDirectory(stageRoot);
            throw;
        }
    }

    public Task<AppUpdateLaunchResult> ApplyAsync(
        PreparedAppUpdate update,
        bool deferUntilRestart,
        CancellationToken ct = default)
        => ApplyAsync(update, deferUntilRestart, progress: null, ct);

    public async Task<AppUpdateLaunchResult> ApplyAsync(
        PreparedAppUpdate update,
        bool deferUntilRestart,
        IProgress<UpdateProgress>? progress,
        CancellationToken ct = default)
    {
        Report(progress, UpdateProgress.Applying(10, "Verifying staged update..."));
        _validator.Validate(update.StagedBundlePath, update.Artifact);
        var executable = MacBundleValidator.GetMainExecutablePath(update.StagedBundlePath);
        if (!string.Equals(
                MacBundleValidator.ComputeSha256(executable),
                update.MainExecutableSha256,
                StringComparison.OrdinalIgnoreCase))
            throw new InvalidDataException("update_staged_hash_mismatch");

        var bundleParent = Path.GetDirectoryName(_currentBundlePath)
            ?? throw new InvalidDataException("update_bundle_parent_invalid");
        if (!_directoryWritable(bundleParent))
        {
            Report(progress, UpdateProgress.Applying(50, "Preparing installer..."));
            return await OpenManualInstallerAsync(update.Artifact, progress, ct);
        }

        Report(progress, UpdateProgress.Applying(60, "Staging updated app..."));
        var baseName = Path.GetFileNameWithoutExtension(_currentBundlePath);
        var siblingStage = Path.Combine(bundleParent, $".{baseName}.update-{Guid.NewGuid():N}.app");
        var backup = Path.Combine(bundleParent, $".{baseName}.backup-{Guid.NewGuid():N}.app");
        MoveOrCopyDirectory(update.StagedBundlePath, siblingStage);
        _validator.Validate(siblingStage, update.Artifact);
        var stagedExecutable = MacBundleValidator.GetMainExecutablePath(siblingStage);
        EnsurePrivateDirectory(_updatesRoot);
        // The helper must not run from the bundle the swap renames. It resolves its
        // managed payload relative to its own bundle, so renaming that bundle out from
        // under it makes the post-swap verification throw; the catch-all in
        // ApplyFromRequestAsync then rolled the update back while the CLI still
        // reported "applied" (field-observed 2026-09-14: "applied 1.0.37", exit 0,
        // bundle still 1.0.36, reproduced 3x). Launch a stable copy instead.
        PruneStaleApplierBundles();
        var applierBundle = Path.Combine(_updatesRoot, $"applier-{Guid.NewGuid():N}.app");
        CloneOrCopyDirectory(siblingStage, applierBundle);
        var applierExecutable = MacBundleValidator.GetMainExecutablePath(applierBundle);
        var request = new MacUpdateApplyRequest
        {
            CurrentProcessId = _waitForCallerExit ? Environment.ProcessId : 0,
            CurrentBundlePath = _currentBundlePath,
            StagedBundlePath = siblingStage,
            BackupBundlePath = backup,
            ApplierBundlePath = applierBundle,
            Version = update.Artifact.Version,
            PackageSizeBytes = update.Artifact.SizeBytes,
            PackageSha256 = update.Artifact.Sha256,
            MainExecutableSha256 = MacBundleValidator.ComputeSha256(stagedExecutable),
        };
        var requestPath = Path.Combine(
            _updatesRoot,
            deferUntilRestart ? "pending-update.json" : $"apply-{Guid.NewGuid():N}.json");
        MacAppUpdateApplier.WriteRequest(requestPath, request);
        if (deferUntilRestart)
        {
            Report(progress, UpdateProgress.Applying(100, "Update will install on next start."));
            return new AppUpdateLaunchResult(false, false, true, "update_deferred", requestPath);
        }

        Report(progress, UpdateProgress.Applying(90, "Starting update helper..."));
        // Discard any previous outcome so a caller waiting for this apply cannot read
        // a stale "ok" from an earlier run.
        TryDeleteFile(Path.Combine(_updatesRoot, MacAppUpdateApplier.LastApplyResultFileName));
        if (!_launcher.StartProcess(applierExecutable, ["--apply-update", requestPath]))
        {
            TryDeleteFile(requestPath);
            TryDeleteDirectory(siblingStage);
            TryDeleteDirectory(applierBundle);
            throw new IOException("update_helper_launch_failed");
        }
        Report(progress, UpdateProgress.Applying(100, "Update started."));
        return new AppUpdateLaunchResult(true, false, false, "update_helper_started", requestPath);
    }

    public static bool TryLaunchPendingUpdate(
        string updatesRoot,
        int currentProcessId,
        IMacUpdateLauncher? launcher = null)
    {
        var requestPath = Path.Combine(Path.GetFullPath(updatesRoot), "pending-update.json");
        if (!File.Exists(requestPath))
            return false;
        try
        {
            var request = MacAppUpdateApplier.ReadRequest(requestPath) with
            {
                CurrentProcessId = currentProcessId,
            };
            MacAppUpdateApplier.WriteRequest(requestPath, request);
            // Same rule as the immediate path: never launch the helper from the bundle
            // the swap renames. Older requests carry no applier path, so they keep the
            // previous behaviour rather than failing to install at all.
            var helperBundle = !string.IsNullOrWhiteSpace(request.ApplierBundlePath)
                && Directory.Exists(request.ApplierBundlePath)
                    ? request.ApplierBundlePath
                    : request.StagedBundlePath;
            var executable = MacBundleValidator.GetMainExecutablePath(helperBundle);
            return (launcher ?? new MacUpdateLauncher()).StartProcess(
                executable,
                ["--apply-update", requestPath]);
        }
        catch
        {
            return false;
        }
    }

    /// <summary>
    /// Removes helper copies from earlier attempts. A copy created moments ago may
    /// still be the running helper, so only stale ones are pruned.
    /// </summary>
    private void PruneStaleApplierBundles()
    {
        try
        {
            var pendingHelper = ReadPendingHelperBundle();
            foreach (var directory in Directory.EnumerateDirectories(_updatesRoot, "applier-*.app"))
            {
                if (string.Equals(directory, pendingHelper, StringComparison.Ordinal))
                    continue;
                if (DateTime.UtcNow - Directory.GetCreationTimeUtc(directory) < TimeSpan.FromHours(1))
                    continue;
                TryDeleteDirectory(directory);
            }
        }
        catch (Exception exception) when (exception is IOException or UnauthorizedAccessException)
        {
        }
    }

    /// <summary>Helper bundle a deferred (next-start) update is still waiting to use.</summary>
    private string? ReadPendingHelperBundle()
    {
        try
        {
            var requestPath = Path.Combine(_updatesRoot, "pending-update.json");
            if (!File.Exists(requestPath))
                return null;
            var pending = MacAppUpdateApplier.ReadRequest(requestPath).ApplierBundlePath;
            return string.IsNullOrWhiteSpace(pending) ? null : Path.GetFullPath(pending);
        }
        catch (Exception exception) when (exception is InvalidDataException or JsonException or IOException)
        {
            return null;
        }
    }

    /// <summary>
    /// Copies a bundle cheaply when the filesystem supports cloning (APFS
    /// <c>clonefile</c> costs no extra blocks) and falls back to a real copy.
    /// </summary>
    private static void CloneOrCopyDirectory(string source, string destination)
    {
        if (!OperatingSystem.IsWindows())
        {
            try
            {
                var startInfo = new ProcessStartInfo("/bin/cp")
                {
                    UseShellExecute = false,
                    CreateNoWindow = true,
                };
                startInfo.ArgumentList.Add("-cR");
                startInfo.ArgumentList.Add(source);
                startInfo.ArgumentList.Add(destination);
                using var process = Process.Start(startInfo);
                if (process is not null)
                {
                    process.WaitForExit();
                    if (process.ExitCode == 0 && Directory.Exists(destination))
                        return;
                }
            }
            catch (Exception exception) when (exception is IOException or InvalidOperationException or System.ComponentModel.Win32Exception)
            {
            }
            TryDeleteDirectory(destination);
        }
        CopyDirectory(source, destination);
    }

    public static bool IsRemoteVersionNewer(string remoteVersion, string currentVersion)
    {
        if (!TryVersion(remoteVersion, out var remote) || !TryVersion(currentVersion, out var current))
            return false;
        return remote > current;
    }

    public void Dispose()
    {
        if (!_ownsHttpClient)
            return;
        foreach (var client in _clients.Values)
            client.Dispose();
        _clients.Clear();
    }

    private Task<AppUpdateLaunchResult> OpenManualInstallerAsync(
        ClientAppArtifact updateArtifact,
        CancellationToken ct)
        => OpenManualInstallerAsync(updateArtifact, progress: null, ct);

    private async Task<AppUpdateLaunchResult> OpenManualInstallerAsync(
        ClientAppArtifact updateArtifact,
        IProgress<UpdateProgress>? progress,
        CancellationToken ct)
    {
        var installer = updateArtifact.ManualInstaller
            ?? throw new InvalidDataException("update_manual_installer_missing");
        installer = ValidateArtifact(installer, "dmg", _panelOrigin);
        EnsurePrivateDirectory(_updatesRoot);
        var path = Path.Combine(_updatesRoot, $"LeiKwan-Bridge-{installer.Version}-{_architecture}.dmg");
        await DownloadVerifiedAsync(installer, path, progress, ct);
        if (!_launcher.OpenFile(path))
            throw new IOException("update_manual_installer_open_failed");
        Report(progress, UpdateProgress.Applying(100, "Installer opened."));
        return new AppUpdateLaunchResult(false, true, false, "update_manual_install_required", path);
    }

    private ClientAppArtifact ValidateArtifact(ClientAppArtifact artifact, string format, Uri? panelOrigin)
    {
        if (!TryVersion(artifact.Version, out _)
            || artifact.SizeBytes is <= 0 or > MaximumPackageBytes
            || !Regex.IsMatch(artifact.Sha256 ?? string.Empty, "^[A-Fa-f0-9]{64}$", RegexOptions.CultureInvariant)
            || !string.Equals(artifact.Format, format, StringComparison.OrdinalIgnoreCase)
            // NormalizeArchitecture() maps x64 -> "x86_64" while _architecture keeps
            // the process name ("x64"), so comparing a normalized manifest value with a
            // raw runtime value made every Intel/Rosetta Mac fail this check outright.
            || !string.Equals(
                MacBundleValidator.NormalizeArchitecture(artifact.Architecture),
                MacBundleValidator.NormalizeArchitecture(_architecture),
                StringComparison.Ordinal))
            throw new InvalidDataException("update_artifact_invalid");
        var url = ResolveAndValidateUrl(artifact.ArtifactUrl, panelOrigin);
        return artifact with
        {
            ArtifactUrl = url.ToString(),
            Architecture = _architecture,
            Format = format,
        };
    }

    private static Uri ResolveAndValidateUrl(string artifactUrl, Uri? panelOrigin)
    {
        Uri? resolved = null;
        if (Uri.TryCreate(artifactUrl, UriKind.Absolute, out var absolute))
            resolved = absolute;
        else if (panelOrigin is not null && Uri.TryCreate(panelOrigin, artifactUrl, out var relative))
            resolved = relative;
        if (resolved is null || resolved.Scheme is not ("https" or "http"))
            throw new InvalidDataException("update_artifact_url_invalid");

        // Mirrors advertise absolute artifact URLs on a fixed origin (the
        // panel hardcodes v3.leikwanhost.com today). When the user picked a
        // different known endpoint, redirect the download onto that endpoint
        // so manifest, package and installer traffic all honor the choice.
        if (panelOrigin is not null
            && PanelEndpoints.IsKnownEndpointOrigin(panelOrigin)
            && PanelEndpoints.IsKnownEndpointOrigin(resolved)
            && !SameOrigin(resolved, panelOrigin))
        {
            resolved = new UriBuilder(resolved)
            {
                Scheme = panelOrigin.Scheme,
                Host = panelOrigin.Host,
                Port = panelOrigin.Port,
            }.Uri;
        }

        if (resolved.Scheme == "http" && (panelOrigin is null || !SameOrigin(resolved, panelOrigin)))
            throw new InvalidDataException("update_artifact_url_invalid");
        return resolved;
    }

    private Task DownloadVerifiedAsync(
        ClientAppArtifact artifact,
        string destination,
        CancellationToken ct)
        => DownloadVerifiedAsync(artifact, destination, progress: null, ct);

    private async Task DownloadVerifiedAsync(
        ClientAppArtifact artifact,
        string destination,
        IProgress<UpdateProgress>? progress,
        CancellationToken ct)
    {
        Report(progress, UpdateProgress.Downloading(0, "Downloading update..."));
        var uri = ResolveAndValidateUrl(artifact.ArtifactUrl, _panelOrigin);
        var directory = Path.GetDirectoryName(destination)
            ?? throw new InvalidDataException("update_destination_invalid");
        EnsurePrivateDirectory(directory);
        var temporary = destination + "." + Guid.NewGuid().ToString("N") + ".tmp";
        try
        {
            // Resumable: the shared panel client caps every request at 30 s
            // including the response body, and these packages are ~70 MB, so a
            // single request only ever completes on a multi-MB/s link.
            var total = await ResumableArtifactDownloader.DownloadAsync(
                (request, token) => SendAsync(request, token, HttpCompletionOption.ResponseHeadersRead),
                uri.ToString(),
                temporary,
                progress: (received, _) => Report(progress, UpdateProgress.Downloading(
                    artifact.SizeBytes > 0
                        ? Math.Min(99.5, received * 100.0 / artifact.SizeBytes)
                        : 0,
                    $"Downloading update... ({received} bytes)")),
                expectedLength: artifact.SizeBytes,
                ct: ct);
            if (artifact.SizeBytes > 0 && total != artifact.SizeBytes)
                throw new InvalidDataException("update_size_mismatch");
            Report(progress, UpdateProgress.Downloading(100, "Download finished."));
            Report(progress, UpdateProgress.Verifying(0, "Verifying download..."));
            if (!string.Equals(
                    MacBundleValidator.ComputeSha256(temporary),
                    artifact.Sha256,
                    StringComparison.OrdinalIgnoreCase))
                throw new InvalidDataException("update_hash_mismatch");
            Report(progress, UpdateProgress.Verifying(100, "Download verified."));
            SetPrivateFile(temporary);
            File.Move(temporary, destination, overwrite: true);
            SetPrivateFile(destination);
        }
        finally
        {
            TryDeleteFile(temporary);
        }
    }

    private static string ExtractValidatedArchive(string packagePath, string stageRoot)
    {
        EnsurePrivateDirectory(stageRoot);
        using var archive = ZipFile.OpenRead(packagePath);
        if (archive.Entries.Count is 0 or > 20_000)
            throw new InvalidDataException("update_archive_invalid");
        long extractedBytes = 0;
        string? bundleName = null;
        foreach (var entry in archive.Entries)
        {
            var normalized = ValidateArchivePath(entry.FullName);
            if (normalized.StartsWith("__MACOSX/", StringComparison.Ordinal))
                continue;
            var first = normalized.Split('/', 2)[0];
            if (!first.EndsWith(".app", StringComparison.OrdinalIgnoreCase))
                throw new InvalidDataException("update_archive_layout_invalid");
            if (bundleName is null)
                bundleName = first;
            else if (!string.Equals(bundleName, first, StringComparison.Ordinal))
                throw new InvalidDataException("update_archive_bundle_count_invalid");
            if (IsSymlink(entry))
                throw new InvalidDataException("update_archive_symlink_invalid");
            extractedBytes = checked(extractedBytes + entry.Length);
            if (extractedBytes > MaximumExtractedBytes)
                throw new InvalidDataException("update_archive_too_large");
        }
        if (bundleName is null)
            throw new InvalidDataException("update_archive_bundle_count_invalid");

        var root = Path.GetFullPath(stageRoot) + Path.DirectorySeparatorChar;
        foreach (var entry in archive.Entries)
        {
            var normalized = ValidateArchivePath(entry.FullName);
            if (normalized.StartsWith("__MACOSX/", StringComparison.Ordinal))
                continue;
            var destination = Path.GetFullPath(Path.Combine(stageRoot, normalized.Replace('/', Path.DirectorySeparatorChar)));
            if (!destination.StartsWith(root, StringComparison.Ordinal))
                throw new InvalidDataException("update_archive_path_invalid");
            if (normalized.EndsWith("/", StringComparison.Ordinal))
            {
                Directory.CreateDirectory(destination);
                continue;
            }
            var parent = Path.GetDirectoryName(destination)
                ?? throw new InvalidDataException("update_archive_path_invalid");
            Directory.CreateDirectory(parent);
            using var source = entry.Open();
            using var output = new FileStream(destination, FileMode.CreateNew, FileAccess.Write, FileShare.None);
            source.CopyTo(output);
            ApplyArchiveMode(destination, entry.ExternalAttributes);
        }
        return Path.Combine(stageRoot, bundleName);
    }

    private static string ValidateArchivePath(string value)
    {
        var normalized = (value ?? string.Empty).Replace('\\', '/');
        var pathWithoutDirectoryMarker = normalized.TrimEnd('/');
        var segments = pathWithoutDirectoryMarker.Split('/');
        if (normalized.Length == 0
            || pathWithoutDirectoryMarker.Length == 0
            || normalized.StartsWith("/", StringComparison.Ordinal)
            || Path.IsPathRooted(normalized)
            || normalized.Contains(':')
            || segments.Any(segment => segment is "" or "." or ".."))
            throw new InvalidDataException("update_archive_path_invalid");
        return normalized;
    }

    private static bool IsSymlink(ZipArchiveEntry entry)
        => ((entry.ExternalAttributes >> 16) & 0xF000) == 0xA000;

    private static void ApplyArchiveMode(string path, int externalAttributes)
    {
        if (OperatingSystem.IsWindows())
            return;
        var raw = (externalAttributes >> 16) & 0x1FF;
        var mode = raw == 0 ? 0x1A4 : raw;
        File.SetUnixFileMode(path, (UnixFileMode)mode);
    }

    private static void MoveOrCopyDirectory(string source, string destination)
    {
        if (Directory.Exists(destination))
            throw new IOException("update_stage_exists");
        try
        {
            Directory.Move(source, destination);
        }
        catch (IOException)
        {
            CopyDirectory(source, destination);
        }
    }

    private static void CopyDirectory(string source, string destination)
    {
        Directory.CreateDirectory(destination);
        foreach (var directory in Directory.EnumerateDirectories(source, "*", SearchOption.AllDirectories))
            Directory.CreateDirectory(Path.Combine(destination, Path.GetRelativePath(source, directory)));
        foreach (var file in Directory.EnumerateFiles(source, "*", SearchOption.AllDirectories))
        {
            var target = Path.Combine(destination, Path.GetRelativePath(source, file));
            File.Copy(file, target, overwrite: false);
            if (!OperatingSystem.IsWindows())
                File.SetUnixFileMode(target, File.GetUnixFileMode(file));
        }
    }

    private Task<HttpResponseMessage> SendAsync(
        HttpRequestMessage request,
        CancellationToken ct,
        HttpCompletionOption completion = HttpCompletionOption.ResponseContentRead)
    {
        _onRequestForTest?.Invoke(request);
        var kind = LeikwanHttpClientFactory.SelectKind(
            request.RequestUri ?? throw new InvalidDataException("update_artifact_url_invalid"));
        return GetClient(kind).SendAsync(request, completion, ct);
    }

    private HttpClient GetClient(LeikwanHttpClientKind kind)
    {
        if (!_clients.TryGetValue(kind, out var client))
        {
            client = _httpClientFactory(kind);
            _clients[kind] = client;
        }
        return client;
    }

    private static async Task<byte[]> ReadBoundedAsync(Stream stream, int maximumBytes, CancellationToken ct)
    {
        using var output = new MemoryStream();
        var buffer = new byte[16 * 1024];
        try
        {
            while (true)
            {
                var read = await stream.ReadAsync(buffer, ct);
                if (read == 0)
                    break;
                if (output.Length + read > maximumBytes)
                    throw new InvalidDataException("update_manifest_too_large");
                output.Write(buffer, 0, read);
            }
            return output.ToArray();
        }
        finally
        {
            CryptographicOperations.ZeroMemory(buffer);
        }
    }

    private static void Report(IProgress<UpdateProgress>? progress, UpdateProgress update)
    {
        try
        {
            progress?.Report(update);
        }
        catch (Exception)
        {
            // A broken UI progress sink must never fail the update pipeline.
        }
    }

    private static bool SameOrigin(Uri left, Uri right)
        => string.Equals(left.Scheme, right.Scheme, StringComparison.OrdinalIgnoreCase)
            && string.Equals(left.Host, right.Host, StringComparison.OrdinalIgnoreCase)
            && left.Port == right.Port;

    private static bool VersionsEquivalent(string left, string right)
        => TryVersion(left, out var parsedLeft)
            && TryVersion(right, out var parsedRight)
            && parsedLeft == parsedRight;

    private static bool TryVersion(string value, out Version parsed)
    {
        parsed = new Version(0, 0, 0, 0);
        var normalized = (value ?? string.Empty).Trim();
        var metadata = normalized.IndexOfAny(['+', '-']);
        if (metadata >= 0)
            normalized = normalized[..metadata];
        if (!Version.TryParse(normalized, out var version))
            return false;
        parsed = new Version(
            version.Major,
            version.Minor,
            Math.Max(version.Build, 0),
            Math.Max(version.Revision, 0));
        return true;
    }

    private static bool IsDirectoryWritable(string directory)
    {
        try
        {
            var probe = Path.Combine(directory, ".leikwan-write-probe-" + Guid.NewGuid().ToString("N"));
            using (new FileStream(probe, FileMode.CreateNew, FileAccess.Write, FileShare.None, 1, FileOptions.DeleteOnClose))
            {
            }
            TryDeleteFile(probe);
            return true;
        }
        catch
        {
            return false;
        }
    }

    private static void EnsurePrivateDirectory(string path)
    {
        Directory.CreateDirectory(path);
        if (!OperatingSystem.IsWindows())
        {
            File.SetUnixFileMode(
                path,
                UnixFileMode.UserRead | UnixFileMode.UserWrite | UnixFileMode.UserExecute);
        }
    }

    private static void SetPrivateFile(string path)
    {
        if (!OperatingSystem.IsWindows())
            File.SetUnixFileMode(path, UnixFileMode.UserRead | UnixFileMode.UserWrite);
    }

    private static void TryDeleteFile(string path)
    {
        try
        {
            File.Delete(path);
        }
        catch (IOException)
        {
        }
        catch (UnauthorizedAccessException)
        {
        }
    }

    private static void TryDeleteDirectory(string path)
    {
        try
        {
            if (Directory.Exists(path))
                Directory.Delete(path, recursive: true);
        }
        catch (IOException)
        {
        }
        catch (UnauthorizedAccessException)
        {
        }
    }
}
