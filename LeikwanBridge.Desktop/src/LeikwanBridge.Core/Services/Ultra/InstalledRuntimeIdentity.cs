using System.ComponentModel;
using System.Diagnostics;
using System.Security.Cryptography;
using LeikwanBridge.Core.Models;
using Serilog;

namespace LeikwanBridge.Core.Services.Ultra;

/// <summary>
/// Identity of the Ultra runtime binary the host launches next, read without a
/// lease: the build stamp the binary prints for <c>--version</c> (the value it
/// attests about itself while running) and the SHA-256 of the file, so the panel
/// can compare it exactly like a running runtime's own attestation.
/// </summary>
/// <remarks>
/// The panel's runtime version cutoff judges the identity it stored last. After an
/// app update nothing is running yet, so that stored identity still belongs to the
/// replaced runtime, and the lease the new runtime needs before it can report
/// itself was refused indefinitely (field 2026-11-18: macOS 1.0.68 refused as its
/// 2026.11.10.1 predecessor until the stored identity was cleared by hand).
/// </remarks>
public sealed class InstalledRuntimeIdentity
{
    private static readonly TimeSpan VersionTimeout = TimeSpan.FromSeconds(5);

    // A binary that cannot report its version is retried at a slow cadence, not
    // on every heartbeat.
    private static readonly TimeSpan FailureRetryInterval = TimeSpan.FromMinutes(10);

    private const int MaximumVersionOutputChars = 256;

    private readonly Func<string?> _binaryPath;
    private readonly Func<string, TimeSpan, string?> _readVersion;
    private readonly Func<DateTimeOffset> _now;
    private readonly object _gate = new();
    private CachedIdentity? _cached;

    public InstalledRuntimeIdentity(
        Func<string?> binaryPath,
        Func<string, TimeSpan, string?>? readVersion = null,
        Func<DateTimeOffset>? now = null)
    {
        _binaryPath = binaryPath;
        _readVersion = readVersion ?? ReadVersionOutput;
        _now = now ?? (() => DateTimeOffset.UtcNow);
    }

    /// <summary>
    /// The installed runtime's identity, or null when the binary is missing or
    /// does not report a usable build stamp. Never throws.
    /// </summary>
    public RuntimeAttestationSnapshot? Read()
    {
        try
        {
            var path = _binaryPath();
            if (string.IsNullOrWhiteSpace(path))
                return null;
            var info = new FileInfo(path);
            if (!info.Exists || info.Length <= 0)
                return null;
            var stamp = new FileStamp(info.FullName, info.Length, info.LastWriteTimeUtc);
            lock (_gate)
            {
                if (_cached is { } cached
                    && cached.Stamp == stamp
                    && (cached.Identity is not null || _now() - cached.ReadAt < FailureRetryInterval))
                    return Copy(cached.Identity);
                var identity = ReadIdentity(stamp.Path);
                _cached = new CachedIdentity(stamp, identity, _now());
                return Copy(identity);
            }
        }
        catch (Exception exception) when (exception is IOException
            or UnauthorizedAccessException
            or InvalidOperationException
            or Win32Exception
            or ArgumentException
            or NotSupportedException)
        {
            Log.Debug("installed_runtime_identity_unavailable type={ErrorType}", exception.GetType().Name);
            return null;
        }
    }

    private RuntimeAttestationSnapshot? ReadIdentity(string path)
    {
        var fingerprint = (_readVersion(path, VersionTimeout) ?? string.Empty).Trim();
        if (!UltraRuntimeStatus.IsRuntimeFingerprint(fingerprint))
        {
            Log.Warning("installed_runtime_identity_unavailable reason=version_unreadable");
            return null;
        }
        string selfSha256;
        using (var stream = new FileStream(path, FileMode.Open, FileAccess.Read, FileShare.ReadWrite | FileShare.Delete))
            selfSha256 = Convert.ToHexString(SHA256.HashData(stream)).ToLowerInvariant();
        return new RuntimeAttestationSnapshot { Fingerprint = fingerprint, SelfSha256 = selfSha256 };
    }

    private static string? ReadVersionOutput(string path, TimeSpan timeout)
    {
        var startInfo = new ProcessStartInfo(path)
        {
            UseShellExecute = false,
            CreateNoWindow = true,
            RedirectStandardOutput = true,
            RedirectStandardError = true,
        };
        startInfo.ArgumentList.Add("--version");
        using var process = Process.Start(startInfo);
        if (process is null)
            return null;
        var output = process.StandardOutput.ReadToEndAsync();
        _ = process.StandardError.ReadToEndAsync();
        if (!process.WaitForExit((int)timeout.TotalMilliseconds))
        {
            try
            {
                process.Kill(entireProcessTree: true);
            }
            catch (Exception exception) when (exception is InvalidOperationException or Win32Exception)
            {
            }
            return null;
        }
        if (process.ExitCode != 0 || !output.Wait(timeout))
            return null;
        var text = output.Result;
        return text.Length <= MaximumVersionOutputChars ? text : null;
    }

    private static RuntimeAttestationSnapshot? Copy(RuntimeAttestationSnapshot? identity)
        => identity is null
            ? null
            : new RuntimeAttestationSnapshot { Fingerprint = identity.Fingerprint, SelfSha256 = identity.SelfSha256 };

    private readonly record struct FileStamp(string Path, long Length, DateTime LastWriteUtc);

    private sealed record CachedIdentity(FileStamp Stamp, RuntimeAttestationSnapshot? Identity, DateTimeOffset ReadAt);
}
