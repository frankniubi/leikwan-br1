using System.Diagnostics;

namespace LeikwanBridge.Mac.Services;

public sealed record MacCommandResult(int ExitCode, string StandardOutput, string StandardError);

public interface IMacCommandRunner
{
    Task<MacCommandResult> RunAsync(ProcessStartInfo startInfo, CancellationToken ct);
}

public sealed class MacCommandRunner : IMacCommandRunner
{
    public async Task<MacCommandResult> RunAsync(ProcessStartInfo startInfo, CancellationToken ct)
    {
        startInfo.UseShellExecute = false;
        startInfo.CreateNoWindow = true;
        startInfo.RedirectStandardOutput = true;
        startInfo.RedirectStandardError = true;
        using var process = new Process { StartInfo = startInfo };
        if (!process.Start())
            throw new InvalidOperationException("mac_command_start_failed");
        var outputTask = process.StandardOutput.ReadToEndAsync(ct);
        var errorTask = process.StandardError.ReadToEndAsync(ct);
        try
        {
            await process.WaitForExitAsync(ct);
        }
        catch (OperationCanceledException)
        {
            try
            {
                if (!process.HasExited)
                    process.Kill(entireProcessTree: true);
            }
            catch
            {
            }
            throw;
        }
        return new MacCommandResult(
            process.ExitCode,
            Bound(await outputTask),
            Bound(await errorTask));
    }

    private static string Bound(string value)
    {
        const int maxChars = 1024 * 1024;
        return value.Length <= maxChars ? value : value[..maxChars];
    }
}
