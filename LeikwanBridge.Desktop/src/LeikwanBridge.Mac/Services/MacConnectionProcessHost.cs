using System.Diagnostics;
using System.Net;
using System.Net.NetworkInformation;
using System.Net.Sockets;
using System.Runtime.InteropServices;
using System.Text;
using System.Text.Json;
using Serilog;

namespace LeikwanBridge.Mac.Services;

public sealed record MacConnectionProcessExit(string ConfigPath, string ErrorCode);

public interface IMacConnectionProcessHost : IAsyncDisposable
{
    bool IsRunning { get; }
    event Action<MacConnectionProcessExit>? UnexpectedExit;
    Task StartAsync(string executablePath, string configPath, MacConnectionMode mode, CancellationToken ct);

    /// <summary>
    /// Ask the live helper to rebuild itself from the config file at its startup path
    /// (sing-box reloads on SIGHUP) and, for TUN modes, confirm the interface came back
    /// with the MTU that config asks for. False means the running service was not proven
    /// to have picked the rewritten config up.
    /// </summary>
    Task<bool> ReloadAsync(CancellationToken ct);

    Task StopAsync(CancellationToken ct);
}

public sealed class MacConnectionProcessHost : IMacConnectionProcessHost
{
    private const int Sighup = 1;

    private readonly SemaphoreSlim _gate = new(1, 1);
    private readonly Func<ProcessStartInfo, Process> _processStarter;
    private readonly Func<string, MacConnectionMode, CancellationToken, Task<bool>>? _readinessProbe;
    private readonly Func<IMacCommandRunner> _commandRunnerFactory;
    private readonly Func<bool> _isMacOs;
    private readonly TimeSpan _startupTimeout;
    private readonly Func<int, bool> _signalReload;
    private readonly Func<CancellationToken, Task<int>> _tunMtuProbe;
    private readonly TimeSpan _reloadTimeout;
    private Run? _run;

    public MacConnectionProcessHost(
        Func<ProcessStartInfo, Process>? processStarter = null,
        Func<string, MacConnectionMode, CancellationToken, Task<bool>>? readinessProbe = null,
        TimeSpan? startupTimeout = null,
        Func<IMacCommandRunner>? commandRunnerFactory = null,
        Func<bool>? isMacOs = null,
        Func<int, bool>? signalReload = null,
        Func<CancellationToken, Task<int>>? tunMtuProbe = null,
        TimeSpan? reloadTimeout = null)
    {
        _processStarter = processStarter ?? (info => Process.Start(info)
            ?? throw new InvalidOperationException("connection_process_start_failed"));
        _readinessProbe = readinessProbe;
        _commandRunnerFactory = commandRunnerFactory ?? (() => new MacCommandRunner());
        _isMacOs = isMacOs ?? OperatingSystem.IsMacOS;
        _startupTimeout = startupTimeout ?? TimeSpan.FromSeconds(90);
        _signalReload = signalReload ?? (pid => OperatingSystem.IsMacOS() && kill(pid, Sighup) == 0);
        _tunMtuProbe = tunMtuProbe ?? CreateTunMtuProbe(_commandRunnerFactory);
        _reloadTimeout = reloadTimeout ?? TimeSpan.FromSeconds(12);
    }

    public bool IsRunning => _run is { } run && IsAlive(run.Process);
    public event Action<MacConnectionProcessExit>? UnexpectedExit;

    public async Task StartAsync(string executablePath, string configPath, MacConnectionMode mode, CancellationToken ct)
    {
        await _gate.WaitAsync(ct).ConfigureAwait(false);
        try
        {
            if (IsRunning) throw new InvalidOperationException("connection_already_running");
            await StopCoreAsync().ConfigureAwait(false);
            var port = 0;
            var managesSystemProxy = false;
            var previousInterfaces = new HashSet<string>(StringComparer.Ordinal);
            if (_readinessProbe is null)
            {
                if (mode == MacConnectionMode.Proxy)
                {
                    using var config = JsonDocument.Parse(await File.ReadAllTextAsync(configPath, ct).ConfigureAwait(false));
                    port = config.RootElement.GetProperty("inbounds")[0].GetProperty("listen_port").GetInt32();
                    managesSystemProxy = config.RootElement.GetProperty("inbounds")[0]
                        .TryGetProperty("set_system_proxy", out var enabled) && enabled.GetBoolean();
                    if (managesSystemProxy && _isMacOs())
                    {
                        // A crash or forced quit can leave WinINet-equivalent
                        // macOS proxy flags behind after the local listener is
                        // gone. Clear only the exact loopback endpoint owned by
                        // this connection before testing port readiness.
                        await ClearOwnedSystemProxyAsync(port, _commandRunnerFactory()).ConfigureAwait(false);
                        Log.Information("mac stale system proxy cleanup complete port={Port}", port);
                    }
                    if (await ProbePortAsync(port, ct).ConfigureAwait(false))
                        throw new InvalidOperationException("connection_port_in_use");
                }
                else previousInterfaces = GetTunInterfaces();
            }

            var privileged = mode == MacConnectionMode.Vpn && OperatingSystem.IsMacOS();
            var lifetime = privileged ? configPath + ".keep" : null;
            var errorPath = privileged ? configPath + ".stderr" : null;
            var dnsPath = privileged ? configPath + ".dns" : null;
            var reloadPath = MacConnectionReload.MarkerPath(configPath);
            try
            {
                if (privileged)
                {
                    CreatePrivateFile(lifetime!);
                    CreatePrivateFile(errorPath!);
                    CreatePrivateFile(dnsPath!);
                }
                // A request left behind by a previous session must not reload this one.
                DeleteFile(reloadPath);
                var info = privileged
                    ? BuildPrivilegedStartInfo(executablePath, configPath, lifetime!, errorPath!, dnsPath!, reloadPath)
                    : BuildDirectStartInfo(executablePath, configPath);
                var process = _processStarter(info);
                var run = new Run(process, configPath, mode, lifetime, errorPath, managesSystemProxy ? port : 0, dnsPath);
                _run = run;
                process.OutputDataReceived += (_, _) => { };
                process.ErrorDataReceived += (_, args) => run.AppendError(args.Data);
                process.BeginOutputReadLine();
                process.BeginErrorReadLine();
                using var timeout = CancellationTokenSource.CreateLinkedTokenSource(ct);
                timeout.CancelAfter(_startupTimeout);
                try
                {
                    while (true)
                    {
                        timeout.Token.ThrowIfCancellationRequested();
                        run.ReadErrorFile();
                        if (!IsAlive(process))
                        {
                            await process.WaitForExitAsync(timeout.Token).ConfigureAwait(false);
                            run.ReadErrorFile();
                            throw new InvalidOperationException(run.ErrorCode);
                        }
                        var ready = _readinessProbe is not null
                            ? await _readinessProbe(configPath, mode, timeout.Token).ConfigureAwait(false)
                            : mode == MacConnectionMode.Proxy
                                ? await ProbePortAsync(port, timeout.Token).ConfigureAwait(false)
                                : GetTunInterfaces().Except(previousInterfaces).Any() && run.DnsReady;
                        if (ready && IsAlive(process)) break;
                        await Task.Delay(100, timeout.Token).ConfigureAwait(false);
                    }
                }
                catch (OperationCanceledException) when (!ct.IsCancellationRequested)
                {
                    throw new InvalidOperationException("connection_readiness_timeout");
                }
                Log.Information("mac connection data plane ready mode={Mode} pid={ProcessId}", mode, process.Id);
                run.Monitor = MonitorAsync(run);
            }
            catch
            {
                await StopCoreAsync().ConfigureAwait(false);
                DeleteFile(lifetime);
                DeleteFile(errorPath);
                DeleteFile(dnsPath);
                DeleteFile(reloadPath);
                throw;
            }
        }
        finally { _gate.Release(); }
    }

    private async Task MonitorAsync(Run run)
    {
        while (ReferenceEquals(_run, run) && IsAlive(run.Process))
        {
            run.ReadErrorFile();
            await Task.Delay(200).ConfigureAwait(false);
        }
        if (!ReferenceEquals(_run, run)) return;
        await run.Process.WaitForExitAsync().ConfigureAwait(false);
        run.ReadErrorFile();
        Log.Warning("mac connection data plane exited mode={Mode} exit={ExitCode} error={ErrorCode}",
            run.Mode, run.Process.ExitCode, run.ErrorCode);
        UnexpectedExit?.Invoke(new MacConnectionProcessExit(run.ConfigPath, run.ErrorCode));
    }

    public async Task<bool> ReloadAsync(CancellationToken ct)
    {
        Run? run;
        await _gate.WaitAsync(ct).ConfigureAwait(false);
        try { run = _run is { } current && IsAlive(current.Process) ? current : null; }
        finally { _gate.Release(); }
        if (run is null)
            return false;
        // A TUN helper only proves the reload once the live interface reports the MTU the
        // rewritten config asks for; a proxy helper has no interface to wait for.
        var expectedMtu = run.Mode == MacConnectionMode.Vpn ? ReadConfiguredTunMtu(run.ConfigPath) : 0;
        var requested = run.Lifetime is null
            ? _signalReload(run.Process.Id)
            : await MacConnectionReload.RequestAsync(
                MacConnectionReload.MarkerPath(run.ConfigPath),
                _reloadTimeout,
                ct).ConfigureAwait(false);
        if (!requested || !IsAlive(run.Process))
            return false;
        return expectedMtu <= 0 || await WaitForTunMtuAsync(run, expectedMtu, ct).ConfigureAwait(false);
    }

    private async Task<bool> WaitForTunMtuAsync(Run run, int expectedMtu, CancellationToken ct)
    {
        using var timeout = CancellationTokenSource.CreateLinkedTokenSource(ct);
        timeout.CancelAfter(_reloadTimeout);
        try
        {
            while (true)
            {
                timeout.Token.ThrowIfCancellationRequested();
                if (!IsAlive(run.Process))
                    return false;
                if (await _tunMtuProbe(timeout.Token).ConfigureAwait(false) == expectedMtu)
                    return true;
                await Task.Delay(200, timeout.Token).ConfigureAwait(false);
            }
        }
        catch (OperationCanceledException) when (!ct.IsCancellationRequested)
        {
            // The reload was requested but the interface never reported the new MTU:
            // report failure so the caller keeps the MTU pending and retries.
            return false;
        }
    }

    private static Func<CancellationToken, Task<int>> CreateTunMtuProbe(Func<IMacCommandRunner> commandRunnerFactory)
        => async ct =>
        {
            // Reading the interface MTU needs no privilege and only ever looks at our TUN.
            var info = NewStartInfo("/sbin/ifconfig");
            var result = await commandRunnerFactory().RunAsync(info, ct).ConfigureAwait(false);
            return result.ExitCode == 0
                ? MacTunMtuProbe.Parse(result.StandardOutput, MacConnectionConfigBuilder.TunAddress.Split('/')[0])
                : 0;
        };

    private static int ReadConfiguredTunMtu(string configPath)
    {
        try
        {
            using var document = JsonDocument.Parse(File.ReadAllBytes(configPath));
            if (document.RootElement.TryGetProperty("inbounds", out var inbounds)
                && inbounds.ValueKind == JsonValueKind.Array
                && inbounds.GetArrayLength() > 0)
            {
                var inbound = inbounds[0];
                if (inbound.TryGetProperty("type", out var type)
                    && string.Equals(type.GetString(), "tun", StringComparison.Ordinal)
                    && inbound.TryGetProperty("mtu", out var mtu)
                    && mtu.TryGetInt32(out var value))
                    return value;
            }
        }
        catch (Exception exception) when (exception is IOException or UnauthorizedAccessException or JsonException)
        {
        }
        return 0;
    }

    public async Task StopAsync(CancellationToken ct)
    {
        await _gate.WaitAsync(ct).ConfigureAwait(false);
        try { await StopCoreAsync().ConfigureAwait(false); }
        finally { _gate.Release(); }
    }

    public async ValueTask DisposeAsync()
    {
        await StopAsync(CancellationToken.None).ConfigureAwait(false);
        _gate.Dispose();
    }

    private async Task StopCoreAsync()
    {
        var run = Interlocked.Exchange(ref _run, null);
        if (run is null) return;
        // The privileged shell owns its child. Removing this marker requests
        // graceful shutdown even though the GUI cannot signal a root process.
        DeleteFile(run.Lifetime);
        try
        {
            if (IsAlive(run.Process) && run.Lifetime is null)
            {
                if (OperatingSystem.IsMacOS()) kill(run.Process.Id, 15);
                else run.Process.Kill(entireProcessTree: true);
            }
            try { await run.Process.WaitForExitAsync().WaitAsync(TimeSpan.FromSeconds(5)).ConfigureAwait(false); }
            catch (TimeoutException)
            {
                if (IsAlive(run.Process)) run.Process.Kill(entireProcessTree: true);
                await run.Process.WaitForExitAsync().WaitAsync(TimeSpan.FromSeconds(3)).ConfigureAwait(false);
            }
            await run.Monitor.ConfigureAwait(false);
        }
        finally
        {
            run.Process.Dispose();
            DeleteFile(run.ErrorPath);
            DeleteFile(run.DnsPath);
            DeleteFile(MacConnectionReload.MarkerPath(run.ConfigPath));
            if (_isMacOs() && run.ManagedProxyPort > 0)
            {
                try { await ClearOwnedSystemProxyAsync(run.ManagedProxyPort, _commandRunnerFactory()).ConfigureAwait(false); }
                catch (Exception) { Log.Warning("mac system proxy cleanup failed"); }
            }
        }
    }

    public static async Task ClearOwnedSystemProxyAsync(int port, IMacCommandRunner runner)
    {
        async Task<MacCommandResult> RunCommand(params string[] arguments)
        {
            var info = NewStartInfo("/usr/sbin/networksetup");
            foreach (var argument in arguments) info.ArgumentList.Add(argument);
            return await runner.RunAsync(info, CancellationToken.None).ConfigureAwait(false);
        }
        var services = await RunCommand("-listallnetworkservices").ConfigureAwait(false);
        if (services.ExitCode != 0) throw new InvalidOperationException("system_proxy_cleanup_failed");
        foreach (var service in services.StandardOutput.Split('\n', StringSplitOptions.TrimEntries | StringSplitOptions.RemoveEmptyEntries).Skip(1))
        {
            if (service.StartsWith('*')) continue;
            foreach (var kind in new[] { "webproxy", "securewebproxy", "socksfirewallproxy" })
            {
                var state = await RunCommand("-get" + kind, service).ConfigureAwait(false);
                var lines = state.StandardOutput.Split('\n', StringSplitOptions.TrimEntries);
                if (state.ExitCode != 0 || !lines.Contains("Enabled: Yes")
                    || !lines.Contains("Server: 127.0.0.1") || !lines.Contains($"Port: {port}")) continue;
                var result = await RunCommand("-set" + kind + "state", service, "off").ConfigureAwait(false);
                if (result.ExitCode != 0) throw new InvalidOperationException("system_proxy_cleanup_failed");
            }
        }
    }

    private static async Task<bool> ProbePortAsync(int port, CancellationToken ct)
    {
        if (port is < 1 or > 65535) throw new InvalidOperationException("connection_port_invalid");
        using var socket = new TcpClient();
        try
        {
            await socket.ConnectAsync(IPAddress.Loopback, port, ct).AsTask()
                .WaitAsync(TimeSpan.FromMilliseconds(300), ct).ConfigureAwait(false);
            return true;
        }
        catch (SocketException) { return false; }
        catch (TimeoutException) { return false; }
    }

    private static HashSet<string> GetTunInterfaces()
    {
        var address = IPAddress.Parse(MacConnectionConfigBuilder.TunAddress.Split('/')[0]);
        return NetworkInterface.GetAllNetworkInterfaces()
            .Where(network => network.Name.StartsWith("utun", StringComparison.Ordinal)
                && network.GetIPProperties().UnicastAddresses.Any(item => item.Address.Equals(address)))
            .Select(network => network.Id).ToHashSet(StringComparer.Ordinal);
    }

    public static string ClassifyError(string? raw, MacConnectionMode mode = MacConnectionMode.Vpn)
    {
        var text = raw ?? string.Empty;
        if (text.Contains("bad tun name", StringComparison.OrdinalIgnoreCase)) return "vpn_interface_name_invalid";
        if (text.Contains("no such file", StringComparison.OrdinalIgnoreCase)) return "connection_config_missing";
        if (text.Contains("(-128)", StringComparison.Ordinal)
            || text.Contains("canceled", StringComparison.OrdinalIgnoreCase)
            || text.Contains("cancelled", StringComparison.OrdinalIgnoreCase)) return "vpn_permission_cancelled";
        if (text.Contains("-60007", StringComparison.Ordinal)
            || text.Contains("authorization", StringComparison.OrdinalIgnoreCase)
            || text.Contains("operation not permitted", StringComparison.OrdinalIgnoreCase)
            || text.Contains("permission denied", StringComparison.OrdinalIgnoreCase)) return "vpn_permission_required";
        if (text.Contains("address already in use", StringComparison.OrdinalIgnoreCase)) return "connection_port_in_use";
        if (text.Contains("decode config", StringComparison.OrdinalIgnoreCase)) return "connection_config_invalid";
        if (text.Contains("rule-set", StringComparison.OrdinalIgnoreCase)) return "connection_rule_set_unavailable";
        if (text.Contains("vpn_dns_unavailable", StringComparison.Ordinal)) return "vpn_dns_unavailable";
        return mode == MacConnectionMode.Vpn ? "vpn_process_exited" : "connection_process_exited";
    }

    private static ProcessStartInfo BuildDirectStartInfo(string executablePath, string configPath)
    {
        var info = NewStartInfo(executablePath);
        info.ArgumentList.Add("run");
        info.ArgumentList.Add("-c");
        info.ArgumentList.Add(configPath);
        return info;
    }

    private static ProcessStartInfo BuildPrivilegedStartInfo(string executablePath, string configPath, string lifetime, string errorPath, string dnsPath, string reloadPath)
    {
        var dnsCommands = MacTunDnsSession.BuildCommands(Guid.NewGuid().ToString("N"));
        var command = $"umask 077; if [ ! -f {ShellQuote(lifetime)} ]; then exit 1; fi; "
            + $"{ShellQuote(executablePath)} run -c {ShellQuote(configPath)} >/dev/null 2>>{ShellQuote(errorPath)} & child=$!; "
            + "dns_pid=''; "
            + $"while kill -0 \"$child\" 2>/dev/null; do if [ ! -f {ShellQuote(lifetime)} ] || ! kill -0 {Environment.ProcessId} 2>/dev/null; then "
            + "kill -TERM \"$child\" 2>/dev/null; n=0; while kill -0 \"$child\" 2>/dev/null && [ \"$n\" -lt 30 ]; do sleep 0.1; n=$((n+1)); done; "
            + "kill -KILL \"$child\" 2>/dev/null; break; fi; "
            // The GUI cannot signal a root child, so it drops a marker here and the
            // privileged shell (which already owns and polls the lifetime marker) asks
            // sing-box to rebuild itself from the rewritten config in place.
            + MacConnectionReload.ShellProbe(reloadPath)
            + $"if [ -z \"$dns_pid\" ] && /sbin/ifconfig -a | /usr/bin/grep -q 'inet {MacConnectionConfigBuilder.TunAddress.Split('/')[0]} '; then "
            + $"( {{ printf '%s' {ShellQuote(dnsCommands)}; while [ -f {ShellQuote(lifetime)} ] && kill -0 \"$child\" 2>/dev/null && kill -0 {Environment.ProcessId} 2>/dev/null; do sleep 0.2; done; printf 'quit\\n'; }} | /usr/sbin/scutil >{ShellQuote(dnsPath)} 2>>{ShellQuote(errorPath)} ) & dns_pid=$!; /usr/bin/dscacheutil -flushcache >/dev/null 2>&1; fi; "
            + $"if [ -n \"$dns_pid\" ] && ! kill -0 \"$dns_pid\" 2>/dev/null; then printf 'vpn_dns_unavailable\\n' >>{ShellQuote(errorPath)}; kill -TERM \"$child\" 2>/dev/null; fi; "
            + "sleep 0.1; done; wait \"$child\"; rc=$?; if [ -n \"$dns_pid\" ]; then wait \"$dns_pid\"; /usr/bin/dscacheutil -flushcache >/dev/null 2>&1; fi; exit \"$rc\"";
        var info = NewStartInfo("/usr/bin/osascript");
        info.ArgumentList.Add("-e");
        info.ArgumentList.Add($"do shell script {AppleScriptQuote(command)} with administrator privileges");
        return info;
    }

    private static ProcessStartInfo NewStartInfo(string fileName) => new()
    {
        FileName = fileName, UseShellExecute = false, CreateNoWindow = true,
        RedirectStandardOutput = true, RedirectStandardError = true,
    };

    private static bool IsAlive(Process process)
    {
        try { return !process.HasExited; }
        catch (InvalidOperationException) { return false; }
    }

    private static void CreatePrivateFile(string path)
    {
        if (OperatingSystem.IsWindows()) throw new PlatformNotSupportedException();
        using var file = new FileStream(path, new FileStreamOptions
        {
            Mode = FileMode.CreateNew, Access = FileAccess.Write,
            UnixCreateMode = UnixFileMode.UserRead | UnixFileMode.UserWrite,
        });
    }

    private static void DeleteFile(string? path)
    {
        if (path is not null) File.Delete(path);
    }

    private static string ShellQuote(string value) => "'" + value.Replace("'", "'\\''", StringComparison.Ordinal) + "'";
    private static string AppleScriptQuote(string value) => "\"" + value.Replace("\\", "\\\\", StringComparison.Ordinal).Replace("\"", "\\\"", StringComparison.Ordinal) + "\"";

    [DllImport("libSystem.B.dylib", SetLastError = true)]
    private static extern int kill(int pid, int signal);

    private sealed class Run(Process process, string configPath, MacConnectionMode mode, string? lifetime, string? errorPath, int managedProxyPort, string? dnsPath)
    {
        private readonly StringBuilder _error = new();
        public Process Process { get; } = process;
        public string ConfigPath { get; } = configPath;
        public MacConnectionMode Mode { get; } = mode;
        public string? Lifetime { get; } = lifetime;
        public string? ErrorPath { get; } = errorPath;
        public string? DnsPath { get; } = dnsPath;
        public bool DnsReady
        {
            get
            {
                try { return DnsPath is not null && MacTunDnsSession.IsReady(File.ReadAllText(DnsPath)); }
                catch (IOException) { return false; }
                catch (UnauthorizedAccessException) { return false; }
            }
        }
        public int ManagedProxyPort { get; } = managedProxyPort;
        public Task Monitor { get; set; } = Task.CompletedTask;
        public string ErrorCode { get { lock (_error) return ClassifyError(_error.ToString(), Mode); } }

        public void AppendError(string? value)
        {
            if (string.IsNullOrEmpty(value)) return;
            lock (_error)
            {
                _error.AppendLine(value.Length > 8192 ? value[^8192..] : value);
                if (_error.Length > 16384) _error.Remove(0, _error.Length - 16384);
            }
        }

        public void ReadErrorFile()
        {
            if (ErrorPath is null) return;
            try
            {
                using var file = new FileStream(ErrorPath, FileMode.Open, FileAccess.ReadWrite, FileShare.ReadWrite);
                if (file.Length == 0) return;
                file.Seek(Math.Max(0, file.Length - 16384), SeekOrigin.Begin);
                using var reader = new StreamReader(file, leaveOpen: true);
                AppendError(reader.ReadToEnd());
                file.SetLength(0);
            }
            catch (IOException) { }
            catch (UnauthorizedAccessException) { }
        }
    }
}
