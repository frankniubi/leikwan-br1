using System.Diagnostics;
using System.Globalization;
using LeikwanBridge.Core.Services;

namespace LeikwanBridge.Headless;

/// <summary>Service control through sc.exe, so what the CLI reports is exactly
/// what services.msc shows.</summary>
internal sealed class WindowsServiceController(string serviceName) : IClientServiceController
{
    public string PlatformName => "windows";

    public string ServiceLabel => serviceName;

    public string StatusFilePath => Path.Combine(ClientDefaults.ResolveSharedStateDirectory(), "headless-status.json");

    public string LogDirectory => Path.Combine(ClientDefaults.ResolveSharedStateDirectory(), "logs");

    public string LogFilePattern => "headless-*.log";

    // The panel manifest publishes the four-part product version (0.1.20.48),
    // so the CLI must compare the same shape or every update would look new.
    public string Version => typeof(WindowsServiceController).Assembly.GetName().Version?.ToString() ?? "0.0.0";

    public async Task<ClientServiceState> QueryAsync(CancellationToken ct)
    {
        var result = await ScAsync(["query", serviceName], ct).ConfigureAwait(false);
        if (result.ExitCode != 0)
            return new ClientServiceState(serviceName, false, false, result.Output.Trim());

        var running = result.Output.Contains("RUNNING", StringComparison.OrdinalIgnoreCase);
        var stopped = result.Output.Contains("STOPPED", StringComparison.OrdinalIgnoreCase);
        var pending = result.Output.Contains("PENDING", StringComparison.OrdinalIgnoreCase);
        var detail = running ? "SCM state RUNNING" : stopped ? "SCM state STOPPED" : pending ? "SCM state PENDING" : string.Empty;
        return new ClientServiceState(serviceName, true, running, detail);
    }

    public async Task<bool> StartAsync(CancellationToken ct)
    {
        var result = await ScAsync(["start", serviceName], ct).ConfigureAwait(false);
        if (result.ExitCode == 0 || result.Output.Contains("already been started", StringComparison.OrdinalIgnoreCase))
            return await WaitForStateAsync(running: true, ct).ConfigureAwait(false);
        return false;
    }

    public async Task<bool> StopAsync(CancellationToken ct)
    {
        await ScAsync(["stop", serviceName], ct).ConfigureAwait(false);
        return await WaitForStateAsync(running: false, ct).ConfigureAwait(false);
    }

    public async Task<bool> RestartAsync(CancellationToken ct)
    {
        await StopAsync(ct).ConfigureAwait(false);
        return await StartAsync(ct).ConfigureAwait(false);
    }

    private async Task<bool> WaitForStateAsync(bool running, CancellationToken ct)
    {
        for (var attempt = 0; attempt < 30; attempt++)
        {
            ct.ThrowIfCancellationRequested();
            var state = await QueryAsync(ct).ConfigureAwait(false);
            if (state.Installed && state.Running == running)
                return true;
            await Task.Delay(TimeSpan.FromSeconds(1), ct).ConfigureAwait(false);
        }

        return false;
    }

    internal static async Task<(int ExitCode, string Output)> ScAsync(IReadOnlyList<string> arguments, CancellationToken ct)
    {
        var startInfo = new ProcessStartInfo("sc.exe")
        {
            UseShellExecute = false,
            RedirectStandardOutput = true,
            RedirectStandardError = true,
            CreateNoWindow = true,
        };
        foreach (var argument in arguments)
            startInfo.ArgumentList.Add(argument);
        using var process = Process.Start(startInfo);
        if (process is null)
            return (1, "sc.exe could not be started");
        var output = await process.StandardOutput.ReadToEndAsync(ct).ConfigureAwait(false)
            + await process.StandardError.ReadToEndAsync(ct).ConfigureAwait(false);
        await process.WaitForExitAsync(ct).ConfigureAwait(false);
        return (process.ExitCode, output);
    }
}

/// <summary>Windows applier: extract the verified zip, then hand the swap to a
/// detached copy of the new executable (a running service cannot overwrite its
/// own install directory).</summary>
internal sealed class WindowsHeadlessUpdateApplier(string installDirectory, string serviceName) : IClientUpdateApplier
{
    public async Task<bool> ApplyAsync(
        string stagedFile,
        string version,
        IClientServiceController service,
        TextWriter output,
        CancellationToken ct)
    {
        var stagingRoot = Path.Combine(Path.GetTempPath(), "leikwan-update-" + version);
        if (Directory.Exists(stagingRoot))
            Directory.Delete(stagingRoot, recursive: true);
        Directory.CreateDirectory(stagingRoot);
        System.IO.Compression.ZipFile.ExtractToDirectory(stagedFile, stagingRoot, overwriteFiles: true);

        // The published zip wraps the payload in one folder.
        var payloadRoot = Directory.EnumerateDirectories(stagingRoot).FirstOrDefault() ?? stagingRoot;
        var newExecutable = Directory.EnumerateFiles(payloadRoot, "leikwan-bridge-headless.exe", SearchOption.AllDirectories)
            .FirstOrDefault();
        if (newExecutable is null)
        {
            output.WriteLine("staged package does not contain leikwan-bridge-headless.exe");
            return false;
        }

        output.WriteLine($"apply     : staging in {Path.GetDirectoryName(newExecutable)}");
        var startInfo = new ProcessStartInfo(newExecutable)
        {
            UseShellExecute = false,
            CreateNoWindow = true,
            WorkingDirectory = Path.GetDirectoryName(newExecutable)!,
        };
        foreach (var argument in new[]
                 {
                     "--cli", "apply-update",
                     "--staged", Path.GetDirectoryName(newExecutable)!,
                     "--install-dir", installDirectory,
                     "--service", serviceName,
                     "--wait-pid", Environment.ProcessId.ToString(CultureInfo.InvariantCulture),
                 })
        {
            startInfo.ArgumentList.Add(argument);
        }

        using var applier = Process.Start(startInfo);
        if (applier is null)
        {
            output.WriteLine("could not start the detached updater");
            return false;
        }

        output.WriteLine("apply     : service will restart with the new build in a few seconds");
        await Task.CompletedTask.ConfigureAwait(false);
        return true;
    }
}
