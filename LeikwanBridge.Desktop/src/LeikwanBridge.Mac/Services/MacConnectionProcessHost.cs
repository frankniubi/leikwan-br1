using System.Diagnostics;

namespace LeikwanBridge.Mac.Services;

public interface IMacConnectionProcessHost : IAsyncDisposable
{
    Task StartAsync(string executablePath, string configPath, MacConnectionMode mode, CancellationToken ct);
    Task StopAsync(CancellationToken ct);
}

public sealed class MacConnectionProcessHost : IMacConnectionProcessHost
{
    private Process? _process;
    private readonly SemaphoreSlim _gate = new(1, 1);

    public async Task StartAsync(
        string executablePath,
        string configPath,
        MacConnectionMode mode,
        CancellationToken ct)
    {
        await _gate.WaitAsync(ct);
        try
        {
            if (_process is not null && !_process.HasExited)
                throw new InvalidOperationException("connection_already_running");

            var startInfo = mode == MacConnectionMode.Vpn
                ? BuildPrivilegedStartInfo(executablePath, configPath)
                : BuildDirectStartInfo(executablePath, configPath);
            var process = new Process { StartInfo = startInfo, EnableRaisingEvents = true };
            if (!process.Start())
                throw new InvalidOperationException("connection_process_start_failed");
            process.OutputDataReceived += (_, _) => { };
            process.ErrorDataReceived += (_, _) => { };
            process.BeginOutputReadLine();
            process.BeginErrorReadLine();
            _process = process;
            await Task.Delay(TimeSpan.FromMilliseconds(350), ct);
            if (process.HasExited)
                throw new InvalidOperationException(mode == MacConnectionMode.Vpn
                    ? "vpn_permission_required"
                    : "connection_process_exited");
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

    public async Task StopAsync(CancellationToken ct)
    {
        await _gate.WaitAsync(ct);
        try
        {
            await StopCoreAsync();
        }
        finally
        {
            _gate.Release();
        }
    }

    public async ValueTask DisposeAsync()
    {
        try
        {
            await StopAsync(CancellationToken.None);
        }
        finally
        {
            _gate.Dispose();
        }
    }

    private async Task StopCoreAsync()
    {
        var process = Interlocked.Exchange(ref _process, null);
        if (process is null)
            return;
        try
        {
            if (!process.HasExited)
                process.Kill(entireProcessTree: true);
            await process.WaitForExitAsync().WaitAsync(TimeSpan.FromSeconds(3));
        }
        catch (InvalidOperationException)
        {
        }
        catch (TimeoutException)
        {
        }
        catch (OperationCanceledException)
        {
        }
        finally
        {
            process.Dispose();
        }
    }

    private static ProcessStartInfo BuildDirectStartInfo(string executablePath, string configPath)
    {
        var info = NewStartInfo(executablePath);
        info.ArgumentList.Add("run");
        info.ArgumentList.Add("-c");
        info.ArgumentList.Add(configPath);
        return info;
    }

    private static ProcessStartInfo BuildPrivilegedStartInfo(string executablePath, string configPath)
    {
        if (!OperatingSystem.IsMacOS())
            return BuildDirectStartInfo(executablePath, configPath);
        var command = $"{ShellQuote(executablePath)} run -c {ShellQuote(configPath)}";
        var script = $"do shell script {AppleScriptQuote(command)} with administrator privileges";
        var info = NewStartInfo("/usr/bin/osascript");
        info.ArgumentList.Add("-e");
        info.ArgumentList.Add(script);
        return info;
    }

    private static ProcessStartInfo NewStartInfo(string fileName)
    {
        return new ProcessStartInfo
        {
            FileName = fileName,
            UseShellExecute = false,
            CreateNoWindow = true,
            RedirectStandardOutput = true,
            RedirectStandardError = true,
        };
    }

    private static string ShellQuote(string value)
        => "'" + value.Replace("'", "'\\''", StringComparison.Ordinal) + "'";

    private static string AppleScriptQuote(string value)
        => "\"" + value.Replace("\\", "\\\\", StringComparison.Ordinal)
            .Replace("\"", "\\\"", StringComparison.Ordinal) + "\"";
}
