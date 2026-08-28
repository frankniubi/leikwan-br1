using System.Diagnostics;

namespace LeikwanBridge.Mac.Services;

public sealed record MacCommandResult(int ExitCode, string StandardOutput, string StandardError);

public interface IMacCommandRunner
{
    Task<MacCommandResult> RunAsync(ProcessStartInfo startInfo, CancellationToken ct);
}

public sealed class MacCommandRunner : IMacCommandRunner
{
    private static readonly TimeSpan CommandTimeout = TimeSpan.FromSeconds(5);

    public Task<MacCommandResult> RunAsync(ProcessStartInfo startInfo, CancellationToken ct)
        => Task.Run(() => RunBlocking(startInfo, ct), CancellationToken.None);

    private static MacCommandResult RunBlocking(ProcessStartInfo startInfo, CancellationToken ct)
    {
        startInfo.UseShellExecute = false;
        startInfo.CreateNoWindow = true;
        startInfo.RedirectStandardOutput = true;
        startInfo.RedirectStandardError = true;
        using var process = new Process { StartInfo = startInfo };
        try
        {
            ct.ThrowIfCancellationRequested();
            if (!process.Start())
                throw new InvalidOperationException("mac_command_start_failed");

            var deadline = Stopwatch.GetTimestamp() + (long)(CommandTimeout.TotalSeconds * Stopwatch.Frequency);
            while (!process.HasExited)
            {
                ct.ThrowIfCancellationRequested();
                if (Stopwatch.GetTimestamp() >= deadline)
                    throw new TimeoutException("mac_command_timeout");
                Thread.Sleep(25);
            }

            // WaitForExit() is used only after HasExited so fast-exit commands
            // cannot lose the process exit notification. Reading after exit
            // also prevents a child pipe from keeping the caller blocked.
            process.WaitForExit();
            var output = process.StandardOutput.ReadToEnd();
            var error = process.StandardError.ReadToEnd();
            return new MacCommandResult(
                process.ExitCode,
                Bound(output),
                Bound(error));
        }
        catch
        {
            StopProcess(process);
            throw;
        }
    }

    private static void StopProcess(Process process)
    {
        try
        {
            if (!process.HasExited)
                process.Kill(entireProcessTree: true);
        }
        catch
        {
        }
    }

    private static string Bound(string value)
    {
        const int maxChars = 1024 * 1024;
        return value.Length <= maxChars ? value : value[..maxChars];
    }
}
