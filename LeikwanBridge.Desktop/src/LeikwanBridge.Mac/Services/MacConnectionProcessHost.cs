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
    Task StopAsync(CancellationToken ct);
}

public sealed class MacConnectionProcessHost : IMacConnectionProcessHost
{
    private readonly SemaphoreSlim _gate = new(1, 1);
    private readonly Func<ProcessStartInfo, Process> _processStarter;
    private readonly Func<string, MacConnectionMode, CancellationToken, Task<bool>>? _readinessProbe;
    private readonly TimeSpan _startupTimeout;
    private Run? _run;

    public MacConnectionProcessHost(
        Func<ProcessStartInfo, Process>? processStarter = null,
        Func<string, MacConnectionMode, CancellationToken, Task<bool>>? readinessProbe = null,
        TimeSpan? startupTimeout = null)
    {
        _processStarter = processStarter ?? (info => Process.Start(info)
            ?? throw new InvalidOperationException("connection_process_start_failed"));
        _readinessProbe = readinessProbe;
        _startupTimeout = startupTimeout ?? TimeSpan.FromSeconds(90);
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
                    if (await ProbePortAsync(port, ct).ConfigureAwait(false))
                        throw new InvalidOperationException("connection_port_in_use");
                }
                else previousInterfaces = GetTunInterfaces();
            }

            var privileged = mode == MacConnectionMode.Vpn && OperatingSystem.IsMacOS();
            var lifetime = privileged ? configPath + ".keep" : null;
            var errorPath = privileged ? configPath + ".stderr" : null;
            var dnsPath = privileged ? configPath + ".dns" : null;
            try
            {
                if (privileged)
                {
                    CreatePrivateFile(lifetime!);
                    CreatePrivateFile(errorPath!);
                    CreatePrivateFile(dnsPath!);
                }
                var info = privileged
                    ? BuildPrivilegedStartInfo(executablePath, configPath, lifetime!, errorPath!, dnsPath!)
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
            if (OperatingSystem.IsMacOS() && run.ManagedProxyPort > 0)
            {
                try { await ClearOwnedSystemProxyAsync(run.ManagedProxyPort, new MacCommandRunner()).ConfigureAwait(false); }
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

    private static ProcessStartInfo BuildPrivilegedStartInfo(string executablePath, string configPath, string lifetime, string errorPath, string dnsPath)
    {
        var dnsCommands = MacTunDnsSession.BuildCommands(Guid.NewGuid().ToString("N"));
        var command = $"umask 077; if [ ! -f {ShellQuote(lifetime)} ]; then exit 1; fi; "
            + $"{ShellQuote(executablePath)} run -c {ShellQuote(configPath)} >/dev/null 2>>{ShellQuote(errorPath)} & child=$!; "
            + "dns_pid=''; "
            + $"while kill -0 \"$child\" 2>/dev/null; do if [ ! -f {ShellQuote(lifetime)} ] || ! kill -0 {Environment.ProcessId} 2>/dev/null; then "
            + "kill -TERM \"$child\" 2>/dev/null; n=0; while kill -0 \"$child\" 2>/dev/null && [ \"$n\" -lt 30 ]; do sleep 0.1; n=$((n+1)); done; "
            + "kill -KILL \"$child\" 2>/dev/null; break; fi; "
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
