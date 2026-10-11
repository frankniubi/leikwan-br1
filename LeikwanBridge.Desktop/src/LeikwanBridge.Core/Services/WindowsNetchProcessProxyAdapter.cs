using System.Diagnostics;
using System.Globalization;
using System.Runtime.InteropServices;
using Microsoft.Win32;
using LeikwanBridge.Core.Models;

namespace LeikwanBridge.Core.Services;

public interface IWindowsProcessProxyAdapter : IAsyncDisposable
{
    bool IsAvailable { get; }
    string AvailabilityReason { get; }
    Task StartAsync(int socksPort, IEnumerable<WindowsProcessProxyRule> rules, CancellationToken ct = default);
    Task StopAsync(CancellationToken ct = default);
}

/// <summary>
/// One deterministic NetFilter2 setting.  The public representation keeps
/// the native dial plan testable without loading the optional driver DLL.
/// </summary>
public readonly record struct WindowsProcessProxyDial(int Name, string Value);

/// <summary>
/// Thin ABI adapter for Netch's NetFilter2 Redirector.bin.  Netch remains an
/// optional, separately licensed native payload; this project only calls its
/// documented exported ABI and never falls back to a fake "connected" state.
/// </summary>
public sealed class WindowsNetchProcessProxyAdapter : IWindowsProcessProxyAdapter
{
    private readonly string _redirectorPath;
    private readonly string _driverPath;
    private RedirectorApi? _api;
    private bool _initAttempted;
    private bool _driverServiceOwned;
    private Task<bool>? _freeTask;
    private readonly SemaphoreSlim _gate = new(1, 1);
    private readonly Func<bool> _stopDriverService;
    private readonly Func<bool> _isDriverServiceStopped;

    private static readonly TimeSpan NativeStopTimeout = TimeSpan.FromSeconds(5);

    public WindowsNetchProcessProxyAdapter(
        string redirectorPath,
        string driverPath,
        Func<bool>? stopDriverService = null,
        Func<bool>? isDriverServiceStopped = null)
    {
        _redirectorPath = redirectorPath ?? string.Empty;
        _driverPath = driverPath ?? string.Empty;
        _stopDriverService = stopDriverService ?? StopDriverServiceBestEffort;
        _isDriverServiceStopped = isDriverServiceStopped ?? IsDriverServiceStopped;
    }

    public bool IsAvailable => OperatingSystem.IsWindows()
        && IsElevated()
        && File.Exists(_redirectorPath)
        && File.Exists(_driverPath);

    public string AvailabilityReason
        => !OperatingSystem.IsWindows() ? "windows_required"
        : !IsElevated() ? "administrator_required"
        : !File.Exists(_redirectorPath) ? "process_redirector_missing"
        : !File.Exists(_driverPath) ? "netfilter_driver_missing"
        : "";

    public async Task StartAsync(
        int socksPort,
        IEnumerable<WindowsProcessProxyRule> rules,
        CancellationToken ct = default)
    {
        if (!IsAvailable)
            throw new InvalidOperationException(AvailabilityReason);
        if (socksPort is < 1 or > 65535)
            throw new ArgumentOutOfRangeException(nameof(socksPort));

        await _gate.WaitAsync(ct);
        try
        {
            await ConsumeCompletedFreeAsync();
            if (_initAttempted)
                throw new InvalidOperationException("process_proxy_already_running");
            _freeTask = null;
            _api ??= RedirectorApi.Load(_redirectorPath);
            var api = _api ?? throw new InvalidOperationException("process_redirector_load_failed");
            var copiedDriver = InstallDriverIfNeeded();

            // aio_init/aio_free start and stop a kernel-backed service.  Keep
            // those calls off the WPF dispatcher so a slow SCM/UAC operation
            // cannot freeze the window.
            await Task.Run(() =>
            {
                // Netch registers the driver only when it installs the
                // service.  Re-registering an existing service on every
                // connection can leave NetFilter2 in a half-started state.
                if (copiedDriver || !IsDriverServiceInstalled())
                {
                    _driverServiceOwned = true;
                    Call(api.Register("netfilter2"), "process_driver_register_failed");
                }
                Configure(api, socksPort, rules ?? []);
                // aio_free is valid only after aio_init has been entered.  A
                // rejected dial plan must be rolled back by stopping the
                // service, not by calling aio_free and reporting a second,
                // misleading native error.
                _initAttempted = true;
                Call(api.Init(), "process_redirector_init_failed");
            }, ct);
        }
        catch (Exception startException)
        {
            try
            {
                await StopCoreAsync();
            }
            catch (Exception cleanupException)
            {
                throw new InvalidOperationException(
                    "process_redirector_start_cleanup_failed",
                    new AggregateException(startException, cleanupException));
            }
            throw;
        }
        finally
        {
            _gate.Release();
        }
    }

    public async Task StopAsync(CancellationToken ct = default)
    {
        await _gate.WaitAsync(ct);
        try { await StopCoreAsync(); }
        finally { _gate.Release(); }
    }

    public async ValueTask DisposeAsync()
    {
        try
        {
            await StopAsync(CancellationToken.None);
        }
        finally
        {
            // Never unload Redirector.bin while a timed-out native free call
            // is still executing.  The OS will release it when this process
            // exits; unloading it here would be a use-after-unload race.
            if (_freeTask is null || _freeTask.IsCompleted)
            {
                _api?.Dispose();
                _api = null;
            }
            _gate.Dispose();
        }
    }

    private async Task StopCoreAsync()
    {
        Exception? failure = null;
        var shouldStopDriverService = _driverServiceOwned || _initAttempted || _freeTask is not null;
        if (!shouldStopDriverService)
            return;

        if (_api is not null && _initAttempted)
        {
            var api = _api;
            _freeTask ??= Task.Run(() => api.Free());
            try
            {
                var freed = await _freeTask.WaitAsync(NativeStopTimeout, CancellationToken.None);
                _freeTask = null;
                _initAttempted = false;
                if (!freed)
                    failure = new InvalidOperationException("process_redirector_free_failed");
            }
            catch (TimeoutException exception)
            {
                failure = new InvalidOperationException("process_redirector_stop_timeout", exception);
            }
            catch (Exception exception)
            {
                _freeTask = null;
                _initAttempted = false;
                failure = exception;
            }
        }

        // aio_free normally stops this service. If the native call timed out
        // or an earlier app instance was killed, stop it directly so the
        // kernel filter cannot continue affecting unrelated processes.
        var serviceStopped = await StopDriverServiceAndConfirmAsync();
        if (!serviceStopped)
        {
            var serviceFailure = new InvalidOperationException("process_driver_stop_failed");
            failure = failure is null
                ? serviceFailure
                : new AggregateException(failure, serviceFailure);
        }
        else
        {
            // The SCM state is the authoritative safety boundary. Some
            // Redirector.bin builds leave aio_free blocked even after the
            // driver is stopped; retaining _freeTask keeps the DLL loaded,
            // while clearing _initAttempted lets an HTTP/TUN native start
            // proceed without inheriting a stale process filter.
            _initAttempted = false;
            failure = null;
        }
        if (serviceStopped)
            _driverServiceOwned = false;
        if (failure is not null)
            throw failure;
    }

    private async Task<bool> StopDriverServiceAndConfirmAsync()
    {
        var stopped = await Task.Run(_stopDriverService).ConfigureAwait(false);
        if (stopped)
            return true;

        // A stop request can return before SCM publishes STOPPED, or the
        // request can report a timeout even though the service has just
        // finished stopping. Give the state query a short bounded grace
        // window before treating cleanup as unsafe.
        for (var attempt = 0; attempt < 20; attempt++)
        {
            if (await Task.Run(_isDriverServiceStopped).ConfigureAwait(false))
                return true;
            await Task.Delay(TimeSpan.FromMilliseconds(100)).ConfigureAwait(false);
        }

        return false;
    }

    private async Task ConsumeCompletedFreeAsync()
    {
        var freeTask = _freeTask;
        if (freeTask is null)
            return;
        if (!freeTask.IsCompleted)
            throw new InvalidOperationException("process_redirector_stop_in_progress");

        _freeTask = null;
        _initAttempted = false;
        bool freed;
        try
        {
            freed = await freeTask.ConfigureAwait(false);
        }
        catch (Exception exception)
        {
            throw new InvalidOperationException("process_redirector_free_failed", exception);
        }

        var serviceStopped = freed || await Task.Run(_isDriverServiceStopped).ConfigureAwait(false);
        if (!serviceStopped)
            throw new InvalidOperationException("process_redirector_free_failed");

        if (serviceStopped)
            _driverServiceOwned = false;
    }

    private void Configure(RedirectorApi api, int socksPort, IEnumerable<WindowsProcessProxyRule> rules)
    {
        foreach (var dial in BuildDialPlan(socksPort, rules, AppContext.BaseDirectory))
            Dial(api, (RedirectorName)dial.Name, dial.Value);
    }

    /// <summary>
    /// Builds the same ordered settings Netch sends to NetFilter2.
    /// Process-mode DNS is deliberately left to Windows' normal resolver:
    /// enabling a global DNS hijack while the local target is unavailable can
    /// black-hole every process on the machine.
    /// </summary>
    public static IReadOnlyList<WindowsProcessProxyDial> BuildDialPlan(
        int socksPort,
        IEnumerable<WindowsProcessProxyRule>? rules,
        string? selfDirectory = null)
    {
        if (socksPort is < 1 or > 65535)
            throw new ArgumentOutOfRangeException(nameof(socksPort));

        var plan = new List<WindowsProcessProxyDial>
        {
            new((int)RedirectorName.FilterLoopback, "false"),
            // Keep LAN/gateway traffic direct.  A process accelerator should
            // not make the host's local network unreachable.
            new((int)RedirectorName.FilterIntranet, "false"),
            new((int)RedirectorName.FilterParent, "false"),
            new((int)RedirectorName.FilterIcmp, "false"),
            new((int)RedirectorName.FilterTcp, "true"),
            new((int)RedirectorName.FilterUdp, "true"),
            new((int)RedirectorName.FilterDns, "false"),
            new((int)RedirectorName.DnsOnly, "false"),
            new((int)RedirectorName.DnsProxy, "false"),
            new((int)RedirectorName.TargetHost, "127.0.0.1"),
            new((int)RedirectorName.TargetPort, socksPort.ToString(CultureInfo.InvariantCulture)),
            new((int)RedirectorName.TargetUser, ""),
            new((int)RedirectorName.TargetPass, ""),
            new((int)RedirectorName.ClearNames, ""),
        };

        foreach (var directory in GetSelfDirectories(selfDirectory))
            plan.Add(new((int)RedirectorName.BypassName, "^" + EscapeCppRegex(directory)));

        foreach (var rule in (rules ?? []).Take(64))
        {
            var pattern = ValidatePattern(rule.Pattern);
            plan.Add(new(
                (int)(rule.Bypass ? RedirectorName.BypassName : RedirectorName.AddName),
                pattern));
        }

        return plan;
    }

    private static IEnumerable<string> GetSelfDirectories(string? selfDirectory)
    {
        var candidates = new[] { selfDirectory ?? AppContext.BaseDirectory };
        return candidates
            .Select(path => path?.Trim() ?? string.Empty)
            .Where(path => path.Length > 0)
            .Select(path => Path.GetFullPath(path).TrimEnd(Path.DirectorySeparatorChar, Path.AltDirectorySeparatorChar))
            .Distinct(StringComparer.OrdinalIgnoreCase);
    }

    private static string EscapeCppRegex(string value)
    {
        const string escapedCharacters = "\\*+?|{}[]()^$.";
        var builder = new System.Text.StringBuilder(value.Length + 8);
        foreach (var character in value)
        {
            if (escapedCharacters.IndexOf(character) >= 0)
                builder.Append('\\');
            builder.Append(character);
        }
        return builder.ToString();
    }

    private static string ValidatePattern(string pattern)
    {
        var value = pattern?.Trim() ?? string.Empty;
        if (value.Length == 0 || value.Length > 512 || value.Any(char.IsControl))
            throw new ArgumentException("process_rule_invalid", nameof(pattern));
        return value;
    }

    private static void Dial(RedirectorApi api, RedirectorName name, string value)
    {
        if (!api.Dial((int)name, value))
            throw new InvalidOperationException($"process_redirector_config_failed_{name}");
    }

    private bool InstallDriverIfNeeded()
    {
        var target = Path.Combine(Environment.SystemDirectory, "drivers", "netfilter2.sys");
        if (File.Exists(target))
            return false;
        Directory.CreateDirectory(Path.GetDirectoryName(target)!);
        File.Copy(_driverPath, target, overwrite: false);
        return true;
    }

    private static bool IsDriverServiceInstalled()
    {
        if (!OperatingSystem.IsWindows())
            return false;
        try
        {
            using var key = Registry.LocalMachine.OpenSubKey(
                @"SYSTEM\CurrentControlSet\Services\netfilter2",
                writable: false);
            return key is not null;
        }
        catch (UnauthorizedAccessException)
        {
            // A process-mode start is elevated, but an access-denied query is
            // safer to treat as installed than to re-register the service.
            return true;
        }
    }

    private static bool StopDriverServiceBestEffort()
    {
        if (!OperatingSystem.IsWindows())
            return true;
        try
        {
            var state = QueryDriverServiceState();
            if (state is DriverServiceState.Missing or DriverServiceState.Stopped)
                return true;

            using var process = Process.Start(new ProcessStartInfo
            {
                FileName = Path.Combine(Environment.SystemDirectory, "sc.exe"),
                UseShellExecute = false,
                CreateNoWindow = true,
                RedirectStandardOutput = true,
                RedirectStandardError = true,
                Arguments = "stop netfilter2",
            });
            if (process is null)
                return false;
            process.WaitForExit((int)NativeStopTimeout.TotalMilliseconds);
            return WaitForDriverServiceStopped(NativeStopTimeout);
        }
        catch
        {
            return false;
        }
    }

    private static bool IsDriverServiceStopped()
        => QueryDriverServiceState() is DriverServiceState.Missing or DriverServiceState.Stopped;

    private static bool WaitForDriverServiceStopped(TimeSpan timeout)
    {
        var deadline = DateTime.UtcNow + timeout;
        while (DateTime.UtcNow < deadline)
        {
            if (IsDriverServiceStopped())
                return true;
            Thread.Sleep(TimeSpan.FromMilliseconds(100));
        }
        return IsDriverServiceStopped();
    }

    private static DriverServiceState QueryDriverServiceState()
    {
        if (!OperatingSystem.IsWindows())
            return DriverServiceState.Stopped;
        try
        {
            using var process = Process.Start(new ProcessStartInfo
            {
                FileName = Path.Combine(Environment.SystemDirectory, "sc.exe"),
                UseShellExecute = false,
                CreateNoWindow = true,
                RedirectStandardOutput = true,
                RedirectStandardError = false,
                Arguments = "query netfilter2",
            });
            if (process is null)
                return DriverServiceState.Unknown;
            var output = process.StandardOutput.ReadToEnd();
            process.WaitForExit(2000);
            if (output.Contains("1060", StringComparison.Ordinal)
                || output.Contains("does not exist", StringComparison.OrdinalIgnoreCase))
                return DriverServiceState.Missing;

            var marker = output.IndexOf("STATE", StringComparison.OrdinalIgnoreCase);
            if (marker < 0)
                return DriverServiceState.Unknown;
            var state = output[marker..];
            if (state.Contains("STOP_PENDING", StringComparison.OrdinalIgnoreCase))
                return DriverServiceState.StopPending;
            if (state.Contains("RUNNING", StringComparison.OrdinalIgnoreCase))
                return DriverServiceState.Running;
            if (state.Contains("STOPPED", StringComparison.OrdinalIgnoreCase))
                return DriverServiceState.Stopped;
            return DriverServiceState.Unknown;
        }
        catch
        {
            return DriverServiceState.Unknown;
        }
    }

    private static void Call(bool ok, string code)
    {
        if (!ok)
            throw new InvalidOperationException(code);
    }

    private static bool IsElevated()
    {
        if (!OperatingSystem.IsWindows())
            return true;
        try
        {
            using var identity = System.Security.Principal.WindowsIdentity.GetCurrent();
            var principal = new System.Security.Principal.WindowsPrincipal(identity);
            return principal.IsInRole(System.Security.Principal.WindowsBuiltInRole.Administrator);
        }
        catch
        {
            return false;
        }
    }

    private enum RedirectorName
    {
        FilterLoopback,
        FilterIntranet,
        FilterParent,
        FilterIcmp,
        FilterTcp,
        FilterUdp,
        FilterDns,
        IcmpPing,
        DnsOnly,
        DnsProxy,
        DnsHost,
        DnsPort,
        TargetHost,
        TargetPort,
        TargetUser,
        TargetPass,
        ClearNames = 16,
        AddName = 17,
        BypassName = 18,
    }

    private enum DriverServiceState
    {
        Unknown,
        Missing,
        Stopped,
        Running,
        StopPending,
    }

    private sealed class RedirectorApi
    {
        private readonly nint _library;
        public readonly AioRegister Register;
        public readonly AioDial Dial;
        public readonly AioInit Init;
        public readonly AioFree Free;

        private RedirectorApi(nint library)
        {
            _library = library;
            Register = Get<AioRegister>("aio_register");
            Dial = Get<AioDial>("aio_dial");
            Init = Get<AioInit>("aio_init");
            Free = Get<AioFree>("aio_free");

            T Get<T>(string name) where T : Delegate
                => Marshal.GetDelegateForFunctionPointer<T>(NativeLibrary.GetExport(_library, name));
        }

        public static RedirectorApi Load(string path)
        {
            nint library = nint.Zero;
            try
            {
                library = NativeLibrary.Load(path);
                return new RedirectorApi(library);
            }
            catch (Exception exception) when (exception is DllNotFoundException
                or EntryPointNotFoundException
                or BadImageFormatException
                or FileLoadException
                or TypeLoadException
                or ArgumentException)
            {
                if (library != nint.Zero)
                    NativeLibrary.Free(library);
                throw new InvalidOperationException("process_redirector_load_failed", exception);
            }
        }

        public void Dispose()
        {
            if (_library != nint.Zero)
                NativeLibrary.Free(_library);
        }

        [UnmanagedFunctionPointer(CallingConvention.Cdecl)]
        [return: MarshalAs(UnmanagedType.Bool)]
        public delegate bool AioRegister([MarshalAs(UnmanagedType.LPWStr)] string value);

        [UnmanagedFunctionPointer(CallingConvention.Cdecl)]
        [return: MarshalAs(UnmanagedType.Bool)]
        public delegate bool AioDial(int name, [MarshalAs(UnmanagedType.LPWStr)] string value);

        [UnmanagedFunctionPointer(CallingConvention.Cdecl)]
        [return: MarshalAs(UnmanagedType.Bool)]
        public delegate bool AioInit();

        [UnmanagedFunctionPointer(CallingConvention.Cdecl)]
        [return: MarshalAs(UnmanagedType.Bool)]
        public delegate bool AioFree();
    }
}
