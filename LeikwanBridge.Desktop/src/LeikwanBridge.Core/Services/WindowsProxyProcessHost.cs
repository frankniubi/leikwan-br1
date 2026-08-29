using System.Diagnostics;
using System.Net;
using System.Net.Sockets;
using System.Text;
using LeikwanBridge.Core.Models;

namespace LeikwanBridge.Core.Services;

public interface IWindowsProxyProcessHost : IAsyncDisposable
{
    bool IsRunning { get; }
    Task StartAsync(string executablePath, string configPath, WindowsProxyMode mode, CancellationToken ct = default);
    Task StopAsync(CancellationToken ct = default);
}

public sealed class WindowsProxyProcessExitException : InvalidOperationException
{
    public WindowsProxyProcessExitException(int exitCode, string? diagnostics)
        : base(BuildMessage(exitCode, diagnostics))
    {
        ExitCode = exitCode;
        Diagnostics = diagnostics?.Trim() ?? string.Empty;
    }

    public int ExitCode { get; }
    public string Diagnostics { get; }

    private static string BuildMessage(int exitCode, string? diagnostics)
    {
        var detail = diagnostics?.Trim() ?? string.Empty;
        return detail.Length == 0
            ? $"proxy_process_exited (exit code {exitCode})"
            : $"proxy_process_exited (exit code {exitCode}): {detail}";
    }
}

public sealed class WindowsProxyProcessHost : IWindowsProxyProcessHost
{
    private readonly Action<string>? _onOutput;
    private readonly SemaphoreSlim _gate = new(1, 1);
    private Process? _process;

    public WindowsProxyProcessHost(Action<string>? onOutput = null)
    {
        _onOutput = onOutput;
    }

    public bool IsRunning => _process is { HasExited: false };

    public async Task<bool> WaitForListenerAsync(
        int port,
        CancellationToken ct = default)
    {
        if (port is < 1 or > 65535)
            return false;

        var deadline = DateTime.UtcNow + TimeSpan.FromSeconds(3);
        while (DateTime.UtcNow < deadline)
        {
            ct.ThrowIfCancellationRequested();
            if (!IsRunning)
                return false;

            try
            {
                using var client = new TcpClient(AddressFamily.InterNetwork);
                await client.ConnectAsync(IPAddress.Loopback, port, ct);
                return true;
            }
            catch (SocketException) { }
            catch (OperationCanceledException) { throw; }
            catch (ObjectDisposedException) { }

            await Task.Delay(TimeSpan.FromMilliseconds(75), ct);
        }

        return false;
    }

    public async Task StartAsync(
        string executablePath,
        string configPath,
        WindowsProxyMode mode,
        CancellationToken ct = default)
    {
        if (string.IsNullOrWhiteSpace(executablePath) || !File.Exists(executablePath))
            throw new FileNotFoundException("sing_box_missing", executablePath);
        if (string.IsNullOrWhiteSpace(configPath) || !File.Exists(configPath))
            throw new FileNotFoundException("proxy_config_missing", configPath);

        await _gate.WaitAsync(ct);
        try
        {
            if (IsRunning)
                throw new InvalidOperationException("proxy_process_already_running");

            var info = BuildStartInfo(executablePath, configPath, mode);
            var diagnostics = new StringBuilder();
            var process = new Process { StartInfo = info, EnableRaisingEvents = true };
            if (!process.Start())
                throw new InvalidOperationException("proxy_process_start_failed");
            if (info.RedirectStandardOutput)
            {
                process.OutputDataReceived += (_, e) =>
                {
                    if (!string.IsNullOrWhiteSpace(e.Data))
                    {
                        lock (diagnostics)
                            diagnostics.AppendLine(e.Data);
                        _onOutput?.Invoke(e.Data);
                    }
                };
                process.ErrorDataReceived += (_, e) =>
                {
                    if (!string.IsNullOrWhiteSpace(e.Data))
                    {
                        lock (diagnostics)
                            diagnostics.AppendLine(e.Data);
                        _onOutput?.Invoke(e.Data);
                    }
                };
                process.BeginOutputReadLine();
                process.BeginErrorReadLine();
            }
            _process = process;
            await Task.Delay(TimeSpan.FromMilliseconds(350), ct);
            if (process.HasExited)
            {
                try { process.WaitForExit(); } catch (InvalidOperationException) { }
                string detail;
                lock (diagnostics)
                    detail = NormalizeDiagnostics(diagnostics.ToString());
                throw new WindowsProxyProcessExitException(process.ExitCode, detail);
            }
        }
        catch
        {
            await StopCoreAsync();
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
        try { await StopAsync(CancellationToken.None); }
        finally { _gate.Dispose(); }
    }

    public static ProcessStartInfo BuildStartInfo(
        string executablePath,
        string configPath,
        WindowsProxyMode mode)
    {
        // Secrets are in the ephemeral JSON only.  ArgumentList avoids a
        // shell-expanded command line and makes accidental password logging
        // impossible here.
        var needsElevation = OperatingSystem.IsWindows()
            && (mode is WindowsProxyMode.Tun or WindowsProxyMode.Process)
            && !WindowsProxyElevation.IsElevated();
        var info = new ProcessStartInfo
        {
            FileName = executablePath,
            UseShellExecute = needsElevation,
            CreateNoWindow = true,
            RedirectStandardOutput = !needsElevation,
            RedirectStandardError = !needsElevation,
            WorkingDirectory = Path.GetDirectoryName(executablePath) ?? AppContext.BaseDirectory,
        };
        if (needsElevation)
            info.Verb = "runas";
        info.ArgumentList.Add("run");
        info.ArgumentList.Add("-c");
        info.ArgumentList.Add(configPath);
        return info;
    }

    private async Task StopCoreAsync()
    {
        var process = Interlocked.Exchange(ref _process, null);
        if (process is null)
            return;
        try
        {
            if (!process.HasExited)
            {
                try { process.Kill(entireProcessTree: true); }
                catch (InvalidOperationException) { }
                catch (System.ComponentModel.Win32Exception) { }
            }

            try
            {
                await process.WaitForExitAsync().WaitAsync(TimeSpan.FromSeconds(3));
            }
            catch (TimeoutException)
            {
                // A stale elevated child must not keep the listener alive after
                // the UI has reported stop.  taskkill is scoped to this PID and
                // its descendants, then we give the handle one final wait.
                ForceKillTree(process.Id);
                await process.WaitForExitAsync().WaitAsync(TimeSpan.FromSeconds(2));
            }
        }
        catch (InvalidOperationException) { }
        catch (OperationCanceledException) { }
        catch (TimeoutException) { }
        finally { process.Dispose(); }
    }

    private static void ForceKillTree(int processId)
    {
        if (!OperatingSystem.IsWindows() || processId <= 0)
            return;
        try
        {
            using var taskkill = Process.Start(new ProcessStartInfo
            {
                FileName = Path.Combine(Environment.SystemDirectory, "taskkill.exe"),
                UseShellExecute = false,
                CreateNoWindow = true,
                RedirectStandardOutput = true,
                RedirectStandardError = true,
                Arguments = $"/PID {processId} /T /F",
            });
            taskkill?.WaitForExit(2000);
        }
        catch
        {
            // The normal Kill path already ran; this is only a last-resort
            // cleanup and must not mask the caller's stop result.
        }
    }

    private static string NormalizeDiagnostics(string value)
    {
        var cleaned = new string(value
            .Where(character => !char.IsControl(character)
                || character is '\r' or '\n' or '\t')
            .ToArray())
            .Trim();
        return cleaned.Length <= 1024 ? cleaned : cleaned[..1024];
    }
}
