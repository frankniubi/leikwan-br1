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

    /// <summary>
    /// Bundle the helper is launched from. It must NOT be the staged bundle, because
    /// the swap renames that bundle while the helper is still running from it; the
    /// helper resolves its managed payload relative to its own bundle, so renaming it
    /// makes the post-swap verification throw and silently roll the update back.
    /// </summary>
    [JsonPropertyName("applier_bundle_path")]
    public string ApplierBundlePath { get; init; } = string.Empty;
}

/// <summary>Outcome of one apply attempt, including the reason when nothing was applied.</summary>
public sealed record MacUpdateApplyOutcome(bool Success, string Code, string Detail);

public static class MacAppUpdateApplier
{
    /// <summary>Step log written next to the apply request.</summary>
    public const string ApplyLogFileName = "update-apply.log";

    /// <summary>Machine-readable outcome of the most recent apply.</summary>
    public const string LastApplyResultFileName = "last-apply-result.json";

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
        => (await ApplyFromRequestOutcomeAsync(requestPath, launcher, ct).ConfigureAwait(false)).Success;

    /// <summary>
    /// Applies a staged bundle and reports WHY it did or did not happen. The
    /// previous implementation swallowed every failure into a bare <c>false</c>,
    /// which made a rolled-back update indistinguishable from one that never
    /// started - the caller then reported success because the helper process had
    /// launched. Every step, including the rollback reason, is appended to
    /// <see cref="ApplyLogFileName"/> next to the request, and the last outcome is
    /// published to <see cref="LastApplyResultFileName"/> for a waiting caller.
    /// </summary>
    public static async Task<MacUpdateApplyOutcome> ApplyFromRequestOutcomeAsync(
        string requestPath,
        IMacUpdateLauncher? launcher = null,
        CancellationToken ct = default)
    {
        launcher ??= new MacUpdateLauncher();
        var logPath = ApplyLogPath(requestPath);
        MacUpdateApplyRequest request;
        try
        {
            request = ReadRequest(requestPath);
            ValidateRequest(request, requireFiles: true);
        }
        catch (Exception exception)
        {
            var rejected = new MacUpdateApplyOutcome(false, ErrorCode(exception), exception.Message);
            AppendLog(logPath, $"rejected reason={Describe(exception)}");
            WriteResult(requestPath, rejected);
            TryDeleteFile(requestPath);
            return rejected;
        }

        AppendLog(
            logPath,
            $"begin version={request.Version} current={request.CurrentBundlePath} staged={request.StagedBundlePath} helper={request.ApplierBundlePath}");
        // A caller that is itself being replaced (the running app) must exit before the
        // swap; the command-line updater passes 0 and waits for this helper instead, so
        // it can verify the result instead of trusting that the helper started.
        if (request.CurrentProcessId > 0)
        {
            AppendLog(logPath, $"waiting for caller pid={request.CurrentProcessId}");
            await WaitForProcessExitAsync(request.CurrentProcessId, ct).ConfigureAwait(false);
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
            AppendLog(logPath, "moved current -> backup");
            Directory.Move(request.StagedBundlePath, request.CurrentBundlePath);
            movedStaged = true;
            AppendLog(logPath, "moved staged -> current");

            var installedExecutable = Path.Combine(
                request.CurrentBundlePath,
                "Contents",
                "MacOS",
                "LeikwanBridge.Mac");
            if (!HashMatches(installedExecutable, request.MainExecutableSha256))
                throw new InvalidDataException("update_staged_hash_mismatch");
            if (!launcher.OpenApplication(request.CurrentBundlePath))
                throw new IOException("update_relaunch_failed");
            AppendLog(logPath, $"verified installed executable and requested relaunch version={request.Version}");

            // Publish before the backup is removed: when this helper ran from the
            // installed app, the backup IS the caller's own bundle.
            var applied = new MacUpdateApplyOutcome(true, "ok", "installed " + request.Version);
            WriteResult(requestPath, applied);
            Directory.Delete(request.BackupBundlePath, recursive: true);
            TryDeleteFile(requestPath);
            AppendLog(logPath, "success");
            return applied;
        }
        catch (Exception exception)
        {
            var failed = new MacUpdateApplyOutcome(false, ErrorCode(exception), exception.Message);
            AppendLog(logPath, $"rollback reason={Describe(exception)}");
            try
            {
                if (movedStaged && Directory.Exists(request.CurrentBundlePath))
                    Directory.Delete(request.CurrentBundlePath, recursive: true);
                if (movedCurrent && Directory.Exists(request.BackupBundlePath))
                    Directory.Move(request.BackupBundlePath, request.CurrentBundlePath);
                if (Directory.Exists(request.CurrentBundlePath))
                    _ = launcher.OpenApplication(request.CurrentBundlePath);
                AppendLog(logPath, "rollback complete; installed bundle restored");
            }
            catch (Exception rollbackException)
            {
                AppendLog(logPath, $"ROLLBACK FAILED reason={Describe(rollbackException)}");
            }
            WriteResult(requestPath, failed);
            TryDeleteFile(requestPath);
            return failed;
        }
    }

    private static string ErrorCode(Exception exception)
        => exception is InvalidDataException or IOException && !string.IsNullOrWhiteSpace(exception.Message)
            ? exception.Message
            : exception.GetType().Name;

    private static string Describe(Exception exception)
        => exception.GetType().Name + ": " + exception.Message;

    private static string ApplyLogPath(string requestPath)
        => Path.Combine(Path.GetDirectoryName(Path.GetFullPath(requestPath)) ?? ".", ApplyLogFileName);

    /// <summary>Appends one line to the apply log; diagnostics must never throw.</summary>
    private static void AppendLog(string path, string message)
    {
        try
        {
            var directory = Path.GetDirectoryName(path);
            if (!string.IsNullOrEmpty(directory))
                Directory.CreateDirectory(directory);
            File.AppendAllText(path, $"{DateTimeOffset.UtcNow:O} {message}{Environment.NewLine}");
        }
        catch (Exception)
        {
        }
    }

    private static void WriteResult(string requestPath, MacUpdateApplyOutcome outcome)
    {
        try
        {
            var directory = Path.GetDirectoryName(Path.GetFullPath(requestPath));
            if (string.IsNullOrEmpty(directory))
                return;
            Directory.CreateDirectory(directory);
            var bytes = JsonSerializer.SerializeToUtf8Bytes(new
            {
                success = outcome.Success,
                code = outcome.Code,
                detail = outcome.Detail,
                request = Path.GetFileName(requestPath),
                at = DateTimeOffset.UtcNow.ToString("O"),
            }, JsonOptions);
            var path = Path.Combine(directory, LastApplyResultFileName);
            var temporary = path + "." + Guid.NewGuid().ToString("N") + ".tmp";
            File.WriteAllBytes(temporary, bytes);
            File.Move(temporary, path, overwrite: true);
        }
        catch (Exception)
        {
        }
    }

    /// <summary>
    /// Reads the outcome of the most recent apply under <paramref name="updatesRoot"/>,
    /// or null when no apply has reported yet. The command-line updater uses this to
    /// distinguish "applied" from "the helper started".
    /// </summary>
    public static MacUpdateApplyOutcome? ReadLastResult(string updatesRoot)
    {
        try
        {
            var path = Path.Combine(updatesRoot, LastApplyResultFileName);
            if (!File.Exists(path))
                return null;
            using var document = JsonDocument.Parse(File.ReadAllBytes(path));
            var root = document.RootElement;
            var success = root.TryGetProperty("success", out var successValue)
                && successValue.ValueKind == JsonValueKind.True;
            var code = root.TryGetProperty("code", out var codeValue) ? codeValue.GetString() ?? string.Empty : string.Empty;
            var detail = root.TryGetProperty("detail", out var detailValue) ? detailValue.GetString() ?? string.Empty : string.Empty;
            return new MacUpdateApplyOutcome(success, code, detail);
        }
        catch (Exception)
        {
            return null;
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
        // Deliberately allowed to live outside the target parent: the helper copy is
        // kept in a stable directory so the swap cannot rename it out from under itself.
        if (!string.IsNullOrWhiteSpace(request.ApplierBundlePath))
            _ = RequireBundlePath(request.ApplierBundlePath);
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
