using System.Diagnostics;
using System.Security.Cryptography;
using System.Text.Json;
using System.Text.Json.Serialization;
using System.Text.RegularExpressions;

namespace LeikwanBridge.Mac.Services;

public interface IMacUpdateLauncher
{
    bool StartProcess(string executable, IReadOnlyList<string> arguments);
    bool OpenApplication(string bundlePath);
    bool OpenFile(string path);
}

public sealed class MacUpdateLauncher : IMacUpdateLauncher
{
    public bool StartProcess(string executable, IReadOnlyList<string> arguments)
    {
        try
        {
            var startInfo = new ProcessStartInfo
            {
                FileName = executable,
                UseShellExecute = false,
                CreateNoWindow = true,
            };
            foreach (var argument in arguments)
                startInfo.ArgumentList.Add(argument);
            using var process = Process.Start(startInfo);
            return process is not null;
        }
        catch
        {
            return false;
        }
    }

    public bool OpenApplication(string bundlePath)
        => StartProcess("/usr/bin/open", ["-n", bundlePath]);

    public bool OpenFile(string path)
        => StartProcess("/usr/bin/open", [path]);
}

public sealed record MacUpdateApplyRequest
{
    [JsonPropertyName("current_process_id")]
    public int CurrentProcessId { get; init; }

    [JsonPropertyName("current_bundle_path")]
    public string CurrentBundlePath { get; init; } = string.Empty;

    [JsonPropertyName("staged_bundle_path")]
    public string StagedBundlePath { get; init; } = string.Empty;

    [JsonPropertyName("backup_bundle_path")]
    public string BackupBundlePath { get; init; } = string.Empty;

    [JsonPropertyName("version")]
    public string Version { get; init; } = string.Empty;

    [JsonPropertyName("package_size_bytes")]
    public long PackageSizeBytes { get; init; }

    [JsonPropertyName("package_sha256")]
    public string PackageSha256 { get; init; } = string.Empty;

    [JsonPropertyName("main_executable_sha256")]
    public string MainExecutableSha256 { get; init; } = string.Empty;
}

public static class MacAppUpdateApplier
{
    private static readonly JsonSerializerOptions JsonOptions = new() { WriteIndented = true };

    public static void WriteRequest(string path, MacUpdateApplyRequest request)
    {
        ValidateRequest(request, requireFiles: false);
        var directory = Path.GetDirectoryName(path)
            ?? throw new InvalidDataException("update_request_path_invalid");
        Directory.CreateDirectory(directory);
        var bytes = JsonSerializer.SerializeToUtf8Bytes(request, JsonOptions);
        var temporary = path + "." + Guid.NewGuid().ToString("N") + ".tmp";
        try
        {
            File.WriteAllBytes(temporary, bytes);
            SetPrivateFile(temporary);
            File.Move(temporary, path, overwrite: true);
            SetPrivateFile(path);
        }
        finally
        {
            CryptographicOperations.ZeroMemory(bytes);
            TryDeleteFile(temporary);
        }
    }

    public static MacUpdateApplyRequest ReadRequest(string path)
    {
        var info = new FileInfo(path);
        if (!info.Exists || info.Length is <= 0 or > 32 * 1024)
            throw new InvalidDataException("update_request_invalid");
        var bytes = File.ReadAllBytes(path);
        try
        {
            var request = JsonSerializer.Deserialize<MacUpdateApplyRequest>(bytes, JsonOptions)
                ?? throw new InvalidDataException("update_request_invalid");
            ValidateRequest(request, requireFiles: false);
            return request;
        }
        catch (JsonException)
        {
            throw new InvalidDataException("update_request_invalid");
        }
        finally
        {
            CryptographicOperations.ZeroMemory(bytes);
        }
    }

    public static async Task<bool> ApplyFromRequestAsync(
        string requestPath,
        IMacUpdateLauncher? launcher = null,
        CancellationToken ct = default)
    {
        launcher ??= new MacUpdateLauncher();
        MacUpdateApplyRequest request;
        try
        {
            request = ReadRequest(requestPath);
            ValidateRequest(request, requireFiles: true);
            await WaitForProcessExitAsync(request.CurrentProcessId, ct);
        }
        catch
        {
            TryDeleteFile(requestPath);
            return false;
        }

        var movedCurrent = false;
        var movedStaged = false;
        try
        {
            var stagedExecutable = Path.Combine(
                request.StagedBundlePath,
                "Contents",
                "MacOS",
                "LeikwanBridge.Mac");
            if (!HashMatches(stagedExecutable, request.MainExecutableSha256))
                throw new InvalidDataException("update_staged_hash_mismatch");
            if (Directory.Exists(request.BackupBundlePath))
                throw new IOException("update_backup_exists");

            Directory.Move(request.CurrentBundlePath, request.BackupBundlePath);
            movedCurrent = true;
            Directory.Move(request.StagedBundlePath, request.CurrentBundlePath);
            movedStaged = true;

            var installedExecutable = Path.Combine(
                request.CurrentBundlePath,
                "Contents",
                "MacOS",
                "LeikwanBridge.Mac");
            if (!HashMatches(installedExecutable, request.MainExecutableSha256)
                || !launcher.OpenApplication(request.CurrentBundlePath))
                throw new IOException("update_relaunch_failed");

            Directory.Delete(request.BackupBundlePath, recursive: true);
            TryDeleteFile(requestPath);
            return true;
        }
        catch
        {
            try
            {
                if (movedStaged && Directory.Exists(request.CurrentBundlePath))
                    Directory.Delete(request.CurrentBundlePath, recursive: true);
                if (movedCurrent && Directory.Exists(request.BackupBundlePath))
                    Directory.Move(request.BackupBundlePath, request.CurrentBundlePath);
                if (Directory.Exists(request.CurrentBundlePath))
                    _ = launcher.OpenApplication(request.CurrentBundlePath);
            }
            catch
            {
            }
            TryDeleteFile(requestPath);
            return false;
        }
    }

    private static void ValidateRequest(MacUpdateApplyRequest request, bool requireFiles)
    {
        if (request.CurrentProcessId < 0
            || request.PackageSizeBytes <= 0
            || !Version.TryParse(request.Version, out _)
            || !IsSha(request.PackageSha256)
            || !IsSha(request.MainExecutableSha256))
            throw new InvalidDataException("update_request_invalid");

        var current = RequireBundlePath(request.CurrentBundlePath);
        var staged = RequireBundlePath(request.StagedBundlePath);
        var backup = RequireBundlePath(request.BackupBundlePath);
        var parent = Path.GetDirectoryName(current);
        if (!string.Equals(parent, Path.GetDirectoryName(staged), StringComparison.Ordinal)
            || !string.Equals(parent, Path.GetDirectoryName(backup), StringComparison.Ordinal)
            || string.Equals(current, staged, StringComparison.Ordinal)
            || string.Equals(current, backup, StringComparison.Ordinal)
            || string.Equals(staged, backup, StringComparison.Ordinal))
            throw new InvalidDataException("update_request_path_invalid");
        if (requireFiles && (!Directory.Exists(current) || !Directory.Exists(staged)))
            throw new InvalidDataException("update_bundle_missing");
    }

    private static string RequireBundlePath(string path)
    {
        if (string.IsNullOrWhiteSpace(path) || !Path.IsPathFullyQualified(path))
            throw new InvalidDataException("update_request_path_invalid");
        var full = Path.GetFullPath(path);
        if (!full.EndsWith(".app", StringComparison.OrdinalIgnoreCase))
            throw new InvalidDataException("update_request_path_invalid");
        return full.TrimEnd(Path.DirectorySeparatorChar, Path.AltDirectorySeparatorChar);
    }

    private static bool IsSha(string value)
        => Regex.IsMatch(value ?? string.Empty, "^[A-Fa-f0-9]{64}$", RegexOptions.CultureInvariant);

    private static bool HashMatches(string path, string expected)
        => File.Exists(path)
            && string.Equals(MacBundleValidator.ComputeSha256(path), expected, StringComparison.OrdinalIgnoreCase);

    private static async Task WaitForProcessExitAsync(int processId, CancellationToken ct)
    {
        if (processId <= 0 || processId == Environment.ProcessId)
            return;
        try
        {
            using var process = Process.GetProcessById(processId);
            using var timeout = CancellationTokenSource.CreateLinkedTokenSource(ct);
            timeout.CancelAfter(TimeSpan.FromSeconds(90));
            await process.WaitForExitAsync(timeout.Token);
        }
        catch (ArgumentException)
        {
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
}
