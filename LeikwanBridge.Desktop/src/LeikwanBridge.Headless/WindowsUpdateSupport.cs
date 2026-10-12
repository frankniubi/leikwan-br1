using System.Diagnostics;
using System.Globalization;
using System.Runtime.InteropServices;
using System.Runtime.Versioning;
using System.Security.AccessControl;
using System.Security.Principal;
using System.Text;
using LeikwanBridge.Core.Services;
using Serilog;

namespace LeikwanBridge.Headless;

/// <summary>Where the windowless client keeps its update bookkeeping.</summary>
internal static class HeadlessUpdatePaths
{
    public const string ManifestKey = "windows_headless_client";
    public const string ExecutableName = "leikwan-bridge-headless.exe";

    public static string StateDirectory => ClientDefaults.ResolveSharedStateDirectory();

    /// <summary>SYSTEM/Administrators-only: staging, extraction, state and lock.</summary>
    public static string UpdatesRoot => Path.Combine(StateDirectory, "updates");

    public static string StagingDirectory => Path.Combine(UpdatesRoot, "staging");

    public static string StatePath => Path.Combine(UpdatesRoot, "update-state.json");

    public static string LockPath => Path.Combine(UpdatesRoot, "apply.lock");

    /// <summary>SYSTEM/Administrators-only: install-&lt;version&gt;-&lt;timestamp&gt; copies.</summary>
    public static string BackupRoot => Path.Combine(StateDirectory, "backups");

    public static string InstallDirectory
        => AppContext.BaseDirectory.TrimEnd(Path.DirectorySeparatorChar, Path.AltDirectorySeparatorChar);

    public static string ProductVersion
        => typeof(HeadlessUpdatePaths).Assembly.GetName().Version?.ToString() ?? "0.0.0";

    public static string? ReadFileVersion(string executable)
    {
        try
        {
            return File.Exists(executable) ? FileVersionInfo.GetVersionInfo(executable).FileVersion : null;
        }
        catch (Exception exception) when (exception is IOException or UnauthorizedAccessException)
        {
            return null;
        }
    }
}

/// <summary>
/// Everything a LocalSystem service later executes or restores from must not be
/// writable by ordinary users (ProgramData subfolders are, by default).
/// </summary>
internal static class SecureDirectory
{
    public static void EnsureAdminOnly(string path)
    {
        if (!OperatingSystem.IsWindows())
        {
            Directory.CreateDirectory(path);
            return;
        }

        EnsureAdminOnlyWindows(path);
    }

    [SupportedOSPlatform("windows")]
    private static void EnsureAdminOnlyWindows(string path)
    {
        var system = new SecurityIdentifier(WellKnownSidType.LocalSystemSid, null);
        var administrators = new SecurityIdentifier(WellKnownSidType.BuiltinAdministratorsSid, null);
        var info = new DirectoryInfo(path);
        if (info.Exists)
        {
            // A directory someone else pre-created (its owner can always
            // rewrite the DACL) is discarded, never adopted.
            var owner = info.GetAccessControl(AccessControlSections.Owner).GetOwner(typeof(SecurityIdentifier));
            if (owner is not null && !owner.Equals(system) && !owner.Equals(administrators))
            {
                Log.Warning("update_dir_untrusted_owner dir={Dir}; recreating", path);
                info.Delete(recursive: true);
                info.Refresh();
            }
        }

        var security = new DirectorySecurity();
        security.SetAccessRuleProtection(isProtected: true, preserveInheritance: false);
        const InheritanceFlags inherit = InheritanceFlags.ContainerInherit | InheritanceFlags.ObjectInherit;
        security.AddAccessRule(new FileSystemAccessRule(system, FileSystemRights.FullControl, inherit, PropagationFlags.None, AccessControlType.Allow));
        security.AddAccessRule(new FileSystemAccessRule(administrators, FileSystemRights.FullControl, inherit, PropagationFlags.None, AccessControlType.Allow));
        if (!info.Exists)
        {
            info.Create(security);
            return;
        }

        info.SetAccessControl(security);
    }
}

/// <summary>Finds and stops every process whose image lives under a directory.</summary>
internal static class InstallDirectoryProcesses
{
    private const uint ProcessQueryLimitedInformation = 0x1000;

    public static List<Process> FindUnder(string directory, int excludePid)
    {
        var root = Path.GetFullPath(directory).TrimEnd('\\') + "\\";
        var matches = new List<Process>();
        foreach (var process in Process.GetProcesses())
        {
            if (process.Id == excludePid || process.Id == 0 || process.Id == 4)
            {
                process.Dispose();
                continue;
            }

            var image = TryGetImagePath(process.Id);
            if (image is not null && image.StartsWith(root, StringComparison.OrdinalIgnoreCase))
                matches.Add(process);
            else
                process.Dispose();
        }

        return matches;
    }

    public static async Task<bool> DrainAsync(string directory, TimeSpan timeout, int extraPid, CancellationToken ct)
    {
        var deadline = DateTimeOffset.UtcNow + timeout;
        while (true)
        {
            var alive = FindUnder(directory, Environment.ProcessId);
            // The process that launched this step (CLI or service host) is
            // waited for but, unless it runs from the install directory, never
            // killed: its PID could have been reused by then.
            var waitingForLauncher = extraPid > 0 && extraPid != Environment.ProcessId && IsAlive(extraPid)
                && alive.All(item => item.Id != extraPid);

            if (alive.Count == 0 && !waitingForLauncher)
                return true;
            if (DateTimeOffset.UtcNow >= deadline)
            {
                foreach (var process in alive)
                {
                    using (process)
                    {
                        try
                        {
                            Log.Warning("update_apply_killing_straggler pid={Pid} name={Name}", process.Id, process.ProcessName);
                            // Not the tree: the detached apply step is itself a
                            // descendant of the service host it is replacing.
                            process.Kill(entireProcessTree: false);
                            process.WaitForExit(10000);
                        }
                        catch (Exception exception) when (exception is InvalidOperationException or System.ComponentModel.Win32Exception or NotSupportedException)
                        {
                            Log.Warning("update_apply_kill_failed pid={Pid} type={ErrorType}", process.Id, exception.GetType().Name);
                        }
                    }
                }

                var survivors = FindUnder(directory, Environment.ProcessId);
                var ok = survivors.Count == 0;
                foreach (var survivor in survivors)
                {
                    Log.Warning("update_apply_process_survived pid={Pid} name={Name}", survivor.Id, survivor.ProcessName);
                    survivor.Dispose();
                }

                return ok;
            }

            foreach (var process in alive)
                process.Dispose();
            await Task.Delay(TimeSpan.FromSeconds(1), ct).ConfigureAwait(false);
        }
    }

    private static bool IsAlive(int pid)
    {
        try
        {
            using var process = Process.GetProcessById(pid);
            return !process.HasExited;
        }
        catch (Exception exception) when (exception is ArgumentException or InvalidOperationException or System.ComponentModel.Win32Exception)
        {
            return false;
        }
    }

    private static string? TryGetImagePath(int pid)
    {
        var handle = OpenProcess(ProcessQueryLimitedInformation, false, pid);
        if (handle == IntPtr.Zero)
            return null;
        try
        {
            var buffer = new StringBuilder(1024);
            var size = buffer.Capacity;
            return QueryFullProcessImageNameW(handle, 0, buffer, ref size) ? buffer.ToString(0, size) : null;
        }
        finally
        {
            CloseHandle(handle);
        }
    }

    [DllImport("kernel32.dll", SetLastError = true)]
    private static extern IntPtr OpenProcess(uint access, bool inheritHandle, int processId);

    [DllImport("kernel32.dll", SetLastError = true, CharSet = CharSet.Unicode)]
    private static extern bool QueryFullProcessImageNameW(IntPtr process, uint flags, StringBuilder buffer, ref int size);

    [DllImport("kernel32.dll", SetLastError = true)]
    private static extern bool CloseHandle(IntPtr handle);
}

/// <summary>SCM + process + status-file access for <see cref="UpdateApplyOrchestrator"/>.</summary>
internal sealed class WindowsUpdateApplyEnvironment(string serviceName, string statusFilePath, int waitPid) : IUpdateApplyEnvironment
{
    private readonly WindowsServiceController _controller = new(serviceName);

    public DateTimeOffset UtcNow => DateTimeOffset.UtcNow;

    public Task DelayAsync(TimeSpan delay, CancellationToken ct) => Task.Delay(delay, ct);

    public async Task<bool> StopServiceAsync(TimeSpan timeout, CancellationToken ct)
    {
        var state = await _controller.QueryAsync(ct).ConfigureAwait(false);
        if (!state.Installed)
            return true;
        await WindowsServiceController.ScAsync(["stop", serviceName], ct).ConfigureAwait(false);
        var deadline = DateTimeOffset.UtcNow + timeout;
        while (DateTimeOffset.UtcNow < deadline)
        {
            if (await IsStoppedAsync(ct).ConfigureAwait(false))
                return true;
            await Task.Delay(TimeSpan.FromSeconds(1), ct).ConfigureAwait(false);
        }

        var pid = await QueryServicePidAsync(ct).ConfigureAwait(false);
        if (pid > 0)
        {
            Log.Warning("update_apply_service_stop_timeout service={Service} pid={Pid}; killing", serviceName, pid);
            try
            {
                using var process = Process.GetProcessById(pid);
                // Not the tree: this apply step may be the service's child.
                // Child runtimes die with the host's kill-on-close job and
                // anything left under the install dir is drained next.
                process.Kill(entireProcessTree: false);
                process.WaitForExit(15000);
            }
            catch (Exception exception) when (exception is ArgumentException or InvalidOperationException or System.ComponentModel.Win32Exception)
            {
                Log.Warning("update_apply_service_kill_failed pid={Pid} type={ErrorType}", pid, exception.GetType().Name);
            }
        }

        for (var attempt = 0; attempt < 15; attempt++)
        {
            if (await IsStoppedAsync(ct).ConfigureAwait(false))
                return true;
            await Task.Delay(TimeSpan.FromSeconds(1), ct).ConfigureAwait(false);
        }

        return false;
    }

    public Task<bool> WaitForInstallProcessesExitAsync(string installDirectory, TimeSpan timeout, CancellationToken ct)
        => InstallDirectoryProcesses.DrainAsync(installDirectory, timeout, waitPid, ct);

    public Task<bool> StartServiceAsync(CancellationToken ct) => _controller.StartAsync(ct);

    public async Task<bool> IsServiceRunningAsync(CancellationToken ct)
    {
        var state = await _controller.QueryAsync(ct).ConfigureAwait(false);
        return state.Installed && state.Running;
    }

    public ClientStatusDocument? ReadStatus() => HeadlessCli.ReadStatusFile(statusFilePath);

    private async Task<bool> IsStoppedAsync(CancellationToken ct)
    {
        var result = await WindowsServiceController.ScAsync(["query", serviceName], ct).ConfigureAwait(false);
        return result.ExitCode != 0 || result.Output.Contains("STOPPED", StringComparison.OrdinalIgnoreCase);
    }

    private async Task<int> QueryServicePidAsync(CancellationToken ct)
    {
        var result = await WindowsServiceController.ScAsync(["queryex", serviceName], ct).ConfigureAwait(false);
        foreach (var line in result.Output.Split('\n'))
        {
            var trimmed = line.Trim();
            if (!trimmed.StartsWith("PID", StringComparison.OrdinalIgnoreCase))
                continue;
            var colon = trimmed.IndexOf(':');
            if (colon > 0 && int.TryParse(trimmed[(colon + 1)..].Trim(), NumberStyles.Integer, CultureInfo.InvariantCulture, out var pid))
                return pid;
        }

        return 0;
    }
}

/// <summary>Routes an applier's progress lines into the service log.</summary>
internal sealed class SerilogLineWriter(string prefix) : TextWriter
{
    private readonly StringBuilder _line = new();

    public override Encoding Encoding => Encoding.UTF8;

    public override void Write(char value)
    {
        if (value == '\n')
        {
            Flush();
            return;
        }

        if (value != '\r')
            _line.Append(value);
    }

    public override void Flush()
    {
        if (_line.Length == 0)
            return;
        Log.Information("{Prefix} {Line}", prefix, _line.ToString());
        _line.Clear();
    }
}

/// <summary>
/// Service-mode update wiring: the coordinator reads the same panel manifest
/// the CLI uses, and launches the same detached apply step, but only for an
/// update requested from the panel console. The request token arrives with the
/// client's own config pulls in this process (<see cref="HeadlessUpdateRequestSignal"/>).
/// </summary>
internal static class HeadlessAutoUpdateHost
{
    public static Task StartLoop(HeadlessClientOptions options, string serviceName, CancellationToken ct)
        => Task.Run(async () =>
        {
            try
            {
                var coordinator = Create(options, serviceName);
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
        }, CancellationToken.None);

    public static HeadlessAutoUpdateCoordinator Create(HeadlessClientOptions options, string serviceName)
    {
        var installDirectory = HeadlessUpdatePaths.InstallDirectory;
        var applier = new WindowsHeadlessUpdateApplier(installDirectory, serviceName, HeadlessUpdatePaths.UpdatesRoot);
        var source = new LazyPanelUpdateSource(() => ResolvePanelUrl(options), applier);
        return new HeadlessAutoUpdateCoordinator(
            HeadlessUpdatePaths.ProductVersion,
            HeadlessUpdatePaths.StatePath,
            HeadlessUpdatePaths.LockPath,
            IsEnabled,
            () => HeadlessUpdateRequestSignal.Shared.Current,
            source,
            new Launcher(applier, serviceName));
    }

    /// <summary>Re-read on every cycle so an operator can set
    /// "disable_auto_updates": true in config.json without a restart; it also
    /// refuses updates requested from the console.</summary>
    private static bool IsEnabled()
    {
        var directory = HeadlessUpdatePaths.StateDirectory;
        var path = Path.Combine(directory, "config.json");
        if (!File.Exists(path))
            return true;
        var config = new StateManager(directory).LoadConfig(path);
        return HeadlessUpdatePolicy.IsAutoUpdateEnabled(config);
    }

    private static string ResolvePanelUrl(HeadlessClientOptions options)
    {
        if (!string.IsNullOrWhiteSpace(options.PanelUrl))
            return ClientDefaults.NormalizeMasterUrl(options.PanelUrl);
        try
        {
            var directory = HeadlessUpdatePaths.StateDirectory;
            var path = Path.Combine(directory, "config.json");
            if (File.Exists(path))
            {
                var url = ClientDefaults.NormalizeMasterUrl(new StateManager(directory).LoadConfig(path).MasterUrl);
                if (!string.IsNullOrWhiteSpace(url))
                    return url;
            }
        }
        catch (Exception exception) when (exception is IOException or UnauthorizedAccessException)
        {
        }

        return ClientDefaults.DefaultMasterUrl;
    }

    private sealed class Launcher(WindowsHeadlessUpdateApplier applier, string serviceName) : IHeadlessUpdateLauncher
    {
        public async Task<bool> LaunchAsync(string stagedFile, string version, CancellationToken ct)
        {
            using var writer = new SerilogLineWriter("headless_update_apply");
            var launched = await applier.ApplyAsync(stagedFile, version, new WindowsServiceController(serviceName), writer, ct).ConfigureAwait(false);
            writer.Flush();
            return launched;
        }
    }

    /// <summary>Builds the HTTP client on first use, after the runner has
    /// initialised the panel egress/DoH plumbing it depends on.</summary>
    private sealed class LazyPanelUpdateSource(Func<string> panelUrl, IClientUpdateApplier applier) : IClientUpdateSource
    {
        private PanelClientUpdateService? _inner;

        public Task<PublishedClientUpdate?> ReadPublishedAsync(CancellationToken ct)
            => Inner().ReadPublishedAsync(ct);

        public Task<string> DownloadVerifiedAsync(PublishedClientUpdate update, CancellationToken ct)
            => Inner().DownloadVerifiedAsync(update, ct);

        private PanelClientUpdateService Inner()
        {
            if (_inner is not null)
                return _inner;
            SecureDirectory.EnsureAdminOnly(HeadlessUpdatePaths.UpdatesRoot);
            var url = panelUrl();
            var http = LeikwanHttpClientFactory.CreateFor(new Uri(url));
            http.Timeout = TimeSpan.FromMinutes(10);
            _inner = new PanelClientUpdateService(
                url,
                HeadlessUpdatePaths.ManifestKey,
                HeadlessUpdatePaths.ProductVersion,
                HeadlessUpdatePaths.StagingDirectory,
                applier,
                http);
            return _inner;
        }
    }
}
