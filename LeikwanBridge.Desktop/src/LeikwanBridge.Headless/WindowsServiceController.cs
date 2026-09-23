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
/// own install directory). The detached step (Program "apply-update") stops the
/// service and everything under the install directory, backs it up, swaps,
/// health-checks the new build and rolls back when it is unhealthy.</summary>
internal sealed class WindowsHeadlessUpdateApplier(string installDirectory, string serviceName, string updatesRoot) : IClientUpdateApplier
{
    public async Task<bool> ApplyAsync(
        string stagedFile,
        string version,
        IClientServiceController service,
        TextWriter output,
        CancellationToken ct)
    {
        // Extract under the SYSTEM/Administrators-only updates root: the
        // extracted executable is what LocalSystem runs next.
        var extractName = "extract-" + string.Concat(version.Select(character => char.IsLetterOrDigit(character) || character == '.' ? character : '_'));
        var stagingRoot = Path.Combine(updatesRoot, extractName);
        try
        {
            SecureDirectory.EnsureAdminOnly(updatesRoot);
            PruneOldExtracts(updatesRoot, extractName, stagedFile, output);
            if (Directory.Exists(stagingRoot))
                Directory.Delete(stagingRoot, recursive: true);
            Directory.CreateDirectory(stagingRoot);
            System.IO.Compression.ZipFile.ExtractToDirectory(stagedFile, stagingRoot, overwriteFiles: true);
        }
        catch (UnauthorizedAccessException)
        {
            output.WriteLine($"apply     : cannot write {updatesRoot}; run the update from an elevated shell");
            return false;
        }
        catch (Exception exception) when (exception is IOException or InvalidDataException)
        {
            output.WriteLine($"apply     : could not extract the package ({exception.GetType().Name})");
            return false;
        }

        // The published zip wraps the payload in one folder.
        var payloadRoot = Directory.EnumerateDirectories(stagingRoot).FirstOrDefault() ?? stagingRoot;
        var newExecutable = Directory.EnumerateFiles(payloadRoot, HeadlessUpdatePaths.ExecutableName, SearchOption.AllDirectories)
            .FirstOrDefault();
        if (newExecutable is null)
        {
            output.WriteLine($"staged package does not contain {HeadlessUpdatePaths.ExecutableName}");
            return false;
        }

        // The manifest version must be the payload's own version; otherwise a
        // mislabelled publish would be "applied" every cycle without ever
        // becoming the installed version.
        var payloadVersion = HeadlessUpdatePaths.ReadFileVersion(newExecutable) ?? string.Empty;
        if (!HeadlessUpdatePolicy.IsSameVersion(payloadVersion, version))
        {
            output.WriteLine($"apply     : refused, payload is {payloadVersion} but the manifest says {version}");
            return false;
        }

        var previousVersion = HeadlessUpdatePaths.ReadFileVersion(Path.Combine(installDirectory, HeadlessUpdatePaths.ExecutableName)) ?? service.Version;
        output.WriteLine($"apply     : {previousVersion} -> {version}, staging in {Path.GetDirectoryName(newExecutable)}");
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
                     // --service-name, not --service: Main treats a bare
                     // --service as "run as the SCM service" and eats it.
                     "--service-name", serviceName,
                     "--wait-pid", Environment.ProcessId.ToString(CultureInfo.InvariantCulture),
                     "--version", version,
                     "--previous-version", previousVersion,
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

        output.WriteLine($"apply     : detached updater pid {applier.Id}; the service restarts on {version} and is rolled back to {previousVersion} if it does not come up healthy");
        await Task.CompletedTask.ConfigureAwait(false);
        return true;
    }

    private static void PruneOldExtracts(string updatesRoot, string keep, string currentPackage, TextWriter output)
    {
        foreach (var directory in Directory.EnumerateDirectories(updatesRoot, "extract-*"))
        {
            if (string.Equals(Path.GetFileName(directory), keep, StringComparison.OrdinalIgnoreCase))
                continue;
            try
            {
                Directory.Delete(directory, recursive: true);
            }
            catch (Exception exception) when (exception is IOException or UnauthorizedAccessException)
            {
                output.WriteLine($"apply     : could not remove old staging {Path.GetFileName(directory)} ({exception.GetType().Name})");
            }
        }

        var staging = Path.Combine(updatesRoot, "staging");
        if (!Directory.Exists(staging))
            return;
        var current = Path.GetFullPath(currentPackage);
        foreach (var file in Directory.EnumerateFiles(staging).OrderByDescending(File.GetLastWriteTimeUtc).Skip(2))
        {
            if (string.Equals(Path.GetFullPath(file), current, StringComparison.OrdinalIgnoreCase))
                continue;
            try
            {
                File.Delete(file);
            }
            catch (Exception exception) when (exception is IOException or UnauthorizedAccessException)
            {
            }
        }
    }
}
