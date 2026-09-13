namespace LeikwanBridge.Mac.Services;

/// <summary>
/// In-place reload handshake for the bundled sing-box helper (see
/// scripts/build-macos-client.sh, SING_BOX_VERSION v1.13.12).
///
/// sing-box only reads the TUN MTU while it builds the service, but
/// `sing-box run` re-reads its <c>-c</c> config and rebuilds the service on
/// SIGHUP (cmd/sing-box/cmd_run.go, `run()`), which is the same mechanism its
/// packaged systemd unit uses for <c>ExecReload</c>. The Clash API is not an
/// option: <c>PUT /configs</c> is a no-op stub in this pin
/// (experimental/clashapi/configs.go, `updateConfigs` renders 204 and returns).
///
/// The GUI owns the sing-box process in proxy mode; in VPN mode the helper is a
/// child of the privileged `osascript` shell and cannot be signalled directly,
/// so the GUI drops a marker file the shell already loops over. The marker lives
/// in the same per-user runtime directory as the lifetime marker that shell
/// already trusts, so it grants no new capability.
/// </summary>
public static class MacConnectionReload
{
    /// <summary>Marker the privileged wrapper consumes to reload its sing-box child.</summary>
    public const string MarkerSuffix = ".reload";

    private static readonly TimeSpan PollInterval = TimeSpan.FromMilliseconds(50);

    public static string MarkerPath(string configPath) => configPath + MarkerSuffix;

    /// <summary>
    /// Shell snippet the privileged wrapper runs inside its supervision loop: consume the
    /// marker, then ask the child to rebuild itself from the rewritten config.
    /// </summary>
    public static string ShellProbe(string markerPath)
    {
        var quoted = ShellQuote(markerPath);
        return $"if [ -f {quoted} ]; then rm -f {quoted}; kill -HUP \"$child\" 2>/dev/null; fi; ";
    }

    /// <summary>
    /// Create the reload marker as a private file and wait for the privileged wrapper to
    /// consume it. The marker only disappears once the root shell has handled it.
    /// </summary>
    public static async Task<bool> RequestAsync(string markerPath, TimeSpan timeout, CancellationToken ct)
    {
        try
        {
            CreateMarker(markerPath);
        }
        catch (Exception exception) when (exception is IOException or UnauthorizedAccessException)
        {
            return false;
        }

        var deadline = DateTimeOffset.UtcNow + timeout;
        try
        {
            while (true)
            {
                ct.ThrowIfCancellationRequested();
                if (!File.Exists(markerPath))
                    return true;
                if (DateTimeOffset.UtcNow >= deadline)
                    return false;
                await Task.Delay(PollInterval, ct).ConfigureAwait(false);
            }
        }
        finally
        {
            // A marker nobody consumed must not reload a later session.
            try { File.Delete(markerPath); } catch (IOException) { } catch (UnauthorizedAccessException) { }
        }
    }

    public static void CreateMarker(string markerPath)
    {
        if (OperatingSystem.IsWindows())
        {
            using var plain = new FileStream(markerPath, FileMode.Create, FileAccess.Write, FileShare.Read);
            return;
        }
        using var file = new FileStream(markerPath, new FileStreamOptions
        {
            Mode = FileMode.Create,
            Access = FileAccess.Write,
            UnixCreateMode = UnixFileMode.UserRead | UnixFileMode.UserWrite,
        });
    }

    private static string ShellQuote(string value) => "'" + value.Replace("'", "'\\''", StringComparison.Ordinal) + "'";
}
