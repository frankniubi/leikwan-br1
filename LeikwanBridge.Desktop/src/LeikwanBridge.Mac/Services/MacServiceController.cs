using System.Diagnostics;
using LeikwanBridge.Core.Models;
using LeikwanBridge.Core.Services;

namespace LeikwanBridge.Mac.Services;

/// <summary>launchd view of the windowless client, so `leikwan status` reports
/// the same state `launchctl print` does.</summary>
public sealed class MacServiceController(MacPlatformPaths paths, string label = "com.leikwan.bridge.headless")
    : IClientServiceController
{
    // The daemon runs as root, so its state lives in the system directories, not
    // in the invoking user's home (paths is kept for the bundle/root locations).
    private const string SystemStateDirectory = "/Library/Application Support/Leikwan/ClientAgent";

    public string PlatformName => string.IsNullOrWhiteSpace(paths.BundleDirectory) ? "macos" : "macos";

    public string ServiceLabel => label;

    public string StatusFilePath => SystemStateDirectory + "/headless-status.json";

    public string LogDirectory => "/Library/Logs/Leikwan/ClientAgent";

    public string LogFilePattern => "headless-*.log";

    public string Version => typeof(MacServiceController).Assembly.GetName().Version?.ToString(3) ?? "0.0.0";

    public async Task<ClientServiceState> QueryAsync(CancellationToken ct)
    {
        var result = await LaunchctlAsync(["print", $"system/{label}"], ct).ConfigureAwait(false);
        if (result.ExitCode != 0)
            return new ClientServiceState(label, false, false, result.Output.Trim());

        var running = result.Output.Contains("state = running", StringComparison.OrdinalIgnoreCase);
        var pidLine = result.Output
            .Split('\n')
            .FirstOrDefault(line => line.TrimStart().StartsWith("pid =", StringComparison.OrdinalIgnoreCase))
            ?.Trim();
        return new ClientServiceState(label, true, running, pidLine ?? (running ? "launchd state running" : "launchd state stopped"));
    }

    public async Task<bool> StartAsync(CancellationToken ct)
    {
        var result = await LaunchctlAsync(["kickstart", "-k", $"system/{label}"], ct).ConfigureAwait(false);
        return result.ExitCode == 0 && await WaitForRunningAsync(ct).ConfigureAwait(false);
    }

    public async Task<bool> StopAsync(CancellationToken ct)
    {
        // A LaunchDaemon with KeepAlive restarts after a plain kill, so the
        // service is booted out and bootstrapped again by the installer; here we
        // only ask launchd to stop it until the next start.
        var result = await LaunchctlAsync(["kill", "SIGTERM", $"system/{label}"], ct).ConfigureAwait(false);
        if (result.ExitCode != 0)
            return false;
        for (var attempt = 0; attempt < 20; attempt++)
        {
            var state = await QueryAsync(ct).ConfigureAwait(false);
            if (!state.Running)
                return true;
            await Task.Delay(TimeSpan.FromSeconds(1), ct).ConfigureAwait(false);
        }

        return false;
    }

    public async Task<bool> RestartAsync(CancellationToken ct)
    {
        var result = await LaunchctlAsync(["kickstart", "-k", $"system/{label}"], ct).ConfigureAwait(false);
        return result.ExitCode == 0 && await WaitForRunningAsync(ct).ConfigureAwait(false);
    }

    private async Task<bool> WaitForRunningAsync(CancellationToken ct)
    {
        for (var attempt = 0; attempt < 30; attempt++)
        {
            var state = await QueryAsync(ct).ConfigureAwait(false);
            if (state.Installed && state.Running)
                return true;
            await Task.Delay(TimeSpan.FromSeconds(1), ct).ConfigureAwait(false);
        }

        return false;
    }

    internal static async Task<(int ExitCode, string Output)> LaunchctlAsync(
        IReadOnlyList<string> arguments,
        CancellationToken ct)
    {
        var startInfo = new ProcessStartInfo("/bin/launchctl")
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
            return (1, "launchctl could not be started");
        var output = await process.StandardOutput.ReadToEndAsync(ct).ConfigureAwait(false)
            + await process.StandardError.ReadToEndAsync(ct).ConfigureAwait(false);
        await process.WaitForExitAsync(ct).ConfigureAwait(false);
        return (process.ExitCode, output);
    }
}

/// <summary>
/// `leikwan update` on macOS reuses the windowed client's update path (verified
/// zip, bundle replacement, relaunch) and then kicks the daemon so the new
/// bundle is the one running.
/// </summary>
public sealed class MacClientUpdateService : IClientUpdateService
{
    private readonly MacAppUpdateService updater;
    private readonly MacServiceController controller;
    private readonly string panelUrl;
    private readonly TimeSpan applyVerificationTimeout;
    private ClientAppArtifact? _available;

    public MacClientUpdateService(
        MacAppUpdateService updater,
        MacServiceController controller,
        string panelUrl,
        TimeSpan? applyVerificationTimeout = null)
    {
        this.updater = updater;
        this.controller = controller;
        this.panelUrl = panelUrl;
        // The helper swaps the bundle after this process is told to keep running and
        // then publishes its outcome; allow for a slow disk before giving up.
        this.applyVerificationTimeout = applyVerificationTimeout ?? TimeSpan.FromSeconds(120);
    }

    public async Task<ClientUpdateCheck> CheckAsync(CancellationToken ct)
    {
        _available = await updater.CheckAsync(panelUrl, controller.Version, progress: null, ct).ConfigureAwait(false);
        return new ClientUpdateCheck(
            controller.Version,
            _available?.Version,
            _available is not null,
            _available?.ArtifactUrl ?? string.Empty);
    }

    public async Task<string> DescribeLatestAsync(CancellationToken ct)
    {
        try
        {
            var artifact = await updater.CheckAsync(panelUrl, controller.Version, progress: null, ct).ConfigureAwait(false);
            return artifact is null ? $"{controller.Version} (current)" : artifact.Version ?? "unknown";
        }
        catch (Exception exception) when (exception is HttpRequestException or TaskCanceledException or InvalidDataException)
        {
            return "manifest unavailable: " + exception.GetType().Name;
        }
    }

    public async Task<bool> ApplyAsync(
        ClientUpdateCheck check,
        IClientServiceController service,
        TextWriter output,
        CancellationToken ct)
    {
        var artifact = _available;
        if (artifact is null)
        {
            output.WriteLine("no prepared update; run \"leikwan update --check\" first");
            return false;
        }

        var prepared = await updater.PrepareAsync(artifact, progress: null, ct).ConfigureAwait(false);
        var result = await updater.ApplyAsync(prepared, deferUntilRestart: false, progress: null, ct).ConfigureAwait(false);
        if (result.RequiresManualInstall)
        {
            output.WriteLine("this update has to be installed manually from the mounted disk image");
            return false;
        }

        if (result.Started)
        {
            output.WriteLine("applied    : the update launcher is replacing the bundle");
            // "the helper started" is not "the update landed": verify the installed
            // bundle really is the published version before reporting success. The
            // field failure this closes reported "applied 1.0.37", exit 0, while the
            // installed bundle stayed 1.0.36.
            return await VerifyAppliedAsync(check.PublishedVersion, output, ct).ConfigureAwait(false);
        }

        var restarted = await controller.RestartAsync(ct).ConfigureAwait(false);
        output.WriteLine(restarted
            ? "apply      : daemon restarted on the new build"
            : "apply      : daemon did not report running; run \"leikwan start\"");
        return restarted;
    }

    private async Task<bool> VerifyAppliedAsync(string? expected, TextWriter output, CancellationToken ct)
    {
        if (string.IsNullOrWhiteSpace(expected))
            return true;
        var logPath = Path.Combine(updater.UpdatesRoot, MacAppUpdateApplier.ApplyLogFileName);
        var deadline = DateTime.UtcNow + applyVerificationTimeout;
        MacUpdateApplyOutcome? outcome = null;
        while (DateTime.UtcNow < deadline)
        {
            outcome = MacAppUpdateApplier.ReadLastResult(updater.UpdatesRoot);
            var installed = updater.ReadInstalledVersion();
            if (string.Equals(installed, expected, StringComparison.OrdinalIgnoreCase))
            {
                output.WriteLine($"verify     : installed bundle is now {installed}");
                return true;
            }
            if (outcome is { Success: false })
            {
                output.WriteLine($"verify     : the update helper rolled back ({outcome.Code})");
                if (!string.IsNullOrWhiteSpace(outcome.Detail))
                    output.WriteLine($"verify     : reason {outcome.Detail}");
                output.WriteLine($"verify     : installed bundle is still {installed ?? "unreadable"} (expected {expected})");
                output.WriteLine($"verify     : helper log {logPath}");
                return false;
            }
            await Task.Delay(TimeSpan.FromMilliseconds(250), ct).ConfigureAwait(false);
        }

        var currentVersion = updater.ReadInstalledVersion();
        output.WriteLine($"verify     : timed out waiting for {expected}; installed bundle is still {currentVersion ?? "unreadable"}");
        output.WriteLine($"verify     : helper log {logPath}");
        return false;
    }
}
