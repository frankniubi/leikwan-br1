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
        MacBundleValidator? validator = null)
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
    }

    public async Task<ClientAppArtifact?> CheckAsync(
        string panelUrl,
        string currentVersion,
        CancellationToken ct = default)
    {
        if (!Uri.TryCreate(panelUrl.TrimEnd('/'), UriKind.Absolute, out var panel)
            || panel.Scheme is not ("https" or "http"))
            throw new InvalidDataException("update_panel_url_invalid");
        _panelOrigin = panel;
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
            return null;
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

    public async Task<PreparedAppUpdate> PrepareAsync(
        ClientAppArtifact artifact,
        CancellationToken ct = default)
    {
        artifact = ValidateArtifact(artifact, "zip", _panelOrigin);
        EnsurePrivateDirectory(_updatesRoot);
        var token = Guid.NewGuid().ToString("N");
        var packagePath = Path.Combine(_updatesRoot, $"update-{artifact.Version}-{_architecture}-{token}.zip");
        var stageRoot = Path.Combine(_updatesRoot, "stage-" + token);
        try
        {
            await DownloadVerifiedAsync(artifact, packagePath, ct);
            var bundlePath = ExtractValidatedArchive(packagePath, stageRoot);
            _validator.Validate(bundlePath, artifact);
            var executable = MacBundleValidator.GetMainExecutablePath(bundlePath);
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

    public async Task<AppUpdateLaunchResult> ApplyAsync(
        PreparedAppUpdate update,
        bool deferUntilRestart,
        CancellationToken ct = default)
    {
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
            return await OpenManualInstallerAsync(update.Artifact, ct);

        var baseName = Path.GetFileNameWithoutExtension(_currentBundlePath);
        var siblingStage = Path.Combine(bundleParent, $".{baseName}.update-{Guid.NewGuid():N}.app");
        var backup = Path.Combine(bundleParent, $".{baseName}.backup-{Guid.NewGuid():N}.app");
        MoveOrCopyDirectory(update.StagedBundlePath, siblingStage);
        _validator.Validate(siblingStage, update.Artifact);
        var stagedExecutable = MacBundleValidator.GetMainExecutablePath(siblingStage);
        var request = new MacUpdateApplyRequest
        {
            CurrentProcessId = Environment.ProcessId,
            CurrentBundlePath = _currentBundlePath,
            StagedBundlePath = siblingStage,
            BackupBundlePath = backup,
            Version = update.Artifact.Version,
            PackageSizeBytes = update.Artifact.SizeBytes,
            PackageSha256 = update.Artifact.Sha256,
            MainExecutableSha256 = MacBundleValidator.ComputeSha256(stagedExecutable),
        };
        EnsurePrivateDirectory(_updatesRoot);
        var requestPath = Path.Combine(
            _updatesRoot,
            deferUntilRestart ? "pending-update.json" : $"apply-{Guid.NewGuid():N}.json");
        MacAppUpdateApplier.WriteRequest(requestPath, request);
        if (deferUntilRestart)
            return new AppUpdateLaunchResult(false, false, true, "update_deferred", requestPath);

        if (!_launcher.StartProcess(stagedExecutable, ["--apply-update", requestPath]))
        {
            TryDeleteFile(requestPath);
            TryDeleteDirectory(siblingStage);
            throw new IOException("update_helper_launch_failed");
        }
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
            var executable = MacBundleValidator.GetMainExecutablePath(request.StagedBundlePath);
            return (launcher ?? new MacUpdateLauncher()).StartProcess(
                executable,
                ["--apply-update", requestPath]);
        }
        catch
        {
            return false;
        }
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

    private async Task<AppUpdateLaunchResult> OpenManualInstallerAsync(
        ClientAppArtifact updateArtifact,
        CancellationToken ct)
    {
        var installer = updateArtifact.ManualInstaller
            ?? throw new InvalidDataException("update_manual_installer_missing");
        installer = ValidateArtifact(installer, "dmg", _panelOrigin);
        EnsurePrivateDirectory(_updatesRoot);
        var path = Path.Combine(_updatesRoot, $"LeiKwan-Bridge-{installer.Version}-{_architecture}.dmg");
        await DownloadVerifiedAsync(installer, path, ct);
        if (!_launcher.OpenFile(path))
            throw new IOException("update_manual_installer_open_failed");
        return new AppUpdateLaunchResult(false, true, false, "update_manual_install_required", path);
    }

    private ClientAppArtifact ValidateArtifact(ClientAppArtifact artifact, string format, Uri? panelOrigin)
    {
        if (!TryVersion(artifact.Version, out _)
            || artifact.SizeBytes is <= 0 or > MaximumPackageBytes
            || !Regex.IsMatch(artifact.Sha256 ?? string.Empty, "^[A-Fa-f0-9]{64}$", RegexOptions.CultureInvariant)
            || !string.Equals(artifact.Format, format, StringComparison.OrdinalIgnoreCase)
            || !string.Equals(
                MacBundleValidator.NormalizeArchitecture(artifact.Architecture),
                _architecture,
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
        if (resolved.Scheme == "http" && (panelOrigin is null || !SameOrigin(resolved, panelOrigin)))
            throw new InvalidDataException("update_artifact_url_invalid");
        return resolved;
    }

    private async Task DownloadVerifiedAsync(
        ClientAppArtifact artifact,
        string destination,
        CancellationToken ct)
    {
        var uri = ResolveAndValidateUrl(artifact.ArtifactUrl, _panelOrigin);
        using var request = new HttpRequestMessage(HttpMethod.Get, uri);
        using var response = await SendAsync(request, ct, HttpCompletionOption.ResponseHeadersRead);
        response.EnsureSuccessStatusCode();
        if (response.Content.Headers.ContentLength is long contentLength
            && contentLength != artifact.SizeBytes)
            throw new InvalidDataException("update_size_mismatch");
        var directory = Path.GetDirectoryName(destination)
            ?? throw new InvalidDataException("update_destination_invalid");
        EnsurePrivateDirectory(directory);
        var temporary = destination + "." + Guid.NewGuid().ToString("N") + ".tmp";
        try
        {
            await using var source = await response.Content.ReadAsStreamAsync(ct);
            await using var output = new FileStream(
                temporary,
                FileMode.CreateNew,
                FileAccess.Write,
                FileShare.None,
                128 * 1024,
                FileOptions.Asynchronous | FileOptions.SequentialScan);
            var buffer = new byte[128 * 1024];
            long total = 0;
            try
            {
                while (true)
                {
                    var read = await source.ReadAsync(buffer, ct);
                    if (read == 0)
                        break;
                    total += read;
                    if (total > artifact.SizeBytes)
                        throw new InvalidDataException("update_size_mismatch");
                    await output.WriteAsync(buffer.AsMemory(0, read), ct);
                }
            }
            finally
            {
                CryptographicOperations.ZeroMemory(buffer);
            }
            await output.FlushAsync(ct);
            output.Close();
            if (total != artifact.SizeBytes)
                throw new InvalidDataException("update_size_mismatch");
            if (!string.Equals(
                    MacBundleValidator.ComputeSha256(temporary),
                    artifact.Sha256,
                    StringComparison.OrdinalIgnoreCase))
                throw new InvalidDataException("update_hash_mismatch");
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
