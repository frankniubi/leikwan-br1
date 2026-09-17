using System.Diagnostics;
using System.Globalization;
using System.Runtime.InteropServices;
using System.Text;
using System.Text.Json;
using LeikwanBridge.Core.Services;
using Serilog;

namespace LeikwanBridge.Mac.Services;

// The headless loop: sign in with the client's agent token, start every
// selected forward, then keep the session alive until the supervisor (launchd)
// asks the process to stop. Every failure path is retried with backoff instead
// of exiting, because launchd would otherwise restart us in a hot loop.
public static class MacHeadlessHost
{
    private static readonly TimeSpan[] RetryDelays =
    [
        TimeSpan.FromSeconds(1),
        TimeSpan.FromSeconds(2),
        TimeSpan.FromSeconds(5),
        TimeSpan.FromSeconds(10),
        TimeSpan.FromSeconds(20),
        TimeSpan.FromSeconds(30),
    ];

    public static async Task<int> RunAsync(string[] args, MacHeadlessDependencies? dependencies = null)
    {
        var deps = dependencies ?? MacHeadlessDependencies.Default;
        if (!HeadlessClientOptions.TryParse(args, out var options, out var parseError))
        {
            deps.Error.WriteLine($"headless_invalid_arguments: {parseError}");
            deps.Error.WriteLine(HeadlessClientOptions.Usage);
            return 2;
        }

        using var shutdown = new CancellationTokenSource();
        using var signals = MacHeadlessSignals.Register(shutdown, deps);
        var paths = deps.PathsFactory();
        ConfigureLogging(paths, options, deps);

        Log.Information("headless_start version={Version} once={Once}", RuntimeVersion(), options.Once);
        IMacHeadlessClient? client = null;
        try
        {
            client = await deps.Bootstrap(paths, shutdown.Token).ConfigureAwait(false);
            // The bootstrap installs the windowed client's own file sink; in
            // headless mode the operator asked for --log-file, so re-establish
            // it last (Serilog keeps the final configuration).
            ConfigureLogging(paths, options, deps);
            await ApplyIdentityAsync(client, options, shutdown.Token).ConfigureAwait(false);
            var controller = client.Controller;
            var selection = HeadlessClientOptions.ParseForwardSelection(options.Forwards);

            var connected = await ConnectAsync(controller, options, deps, shutdown.Token).ConfigureAwait(false);
            if (!connected)
            {
                Log.Error("headless_startup_failed");
                return 1;
            }

            if (selection.Count > 0)
            {
                foreach (var forwardId in selection)
                {
                    try
                    {
                        await controller.StartForwardAsync(forwardId, shutdown.Token).ConfigureAwait(false);
                    }
                    catch (Exception exception)
                    {
                        Log.Warning("headless_forward_start_failed forward={ForwardId} type={ErrorType}", forwardId, exception.GetType().Name);
                    }
                }
            }
            else
            {
                await controller.StartAllAsync(shutdown.Token).ConfigureAwait(false);
            }

            if (options.Once)
                return await RunOnceAsync(controller, options, deps, shutdown.Token).ConfigureAwait(false);

            await SuperviseAsync(controller, options, deps, shutdown.Token).ConfigureAwait(false);
            await StopAsync(controller, selection).ConfigureAwait(false);
            Log.Information("headless_stopped");
            return 0;
        }
        catch (OperationCanceledException)
        {
            Log.Information("headless_cancelled");
            return 0;
        }
        catch (Exception exception)
        {
            Log.Error(exception, "headless_failed");
            deps.Error.WriteLine($"headless_failed: {exception.GetType().Name}: {exception.Message}");
            return 1;
        }
        finally
        {
            if (client is not null)
            {
                try
                {
                    await client.DisposeAsync().ConfigureAwait(false);
                }
                catch (Exception exception)
                {
                    Log.Warning("headless_dispose_failed type={ErrorType}", exception.GetType().Name);
                }
            }

            Log.CloseAndFlush();
        }
    }

    private static async Task ApplyIdentityAsync(
        IMacHeadlessClient client,
        HeadlessClientOptions options,
        CancellationToken ct)
    {
        var config = client.Config;
        if (!string.IsNullOrWhiteSpace(options.PanelUrl))
            config.MasterUrl = options.PanelUrl;
        config.MasterUrl = LeikwanBridge.Core.Models.PanelEndpoints.NormalizeKnownUrl(config.MasterUrl);
        // A launchd daemon may start before any interactive run has written a
        // config; fall back to the shipped panel exactly like the app does
        // instead of refusing to start.
        if (string.IsNullOrWhiteSpace(config.MasterUrl))
            config.MasterUrl = LeikwanBridge.Core.Models.PanelEndpoints.Default.Url;
        if (options.ClientId > 0)
        {
            config.ClientId = options.ClientId;
            config.ActiveClientId = options.ClientId;
        }

        if (string.IsNullOrWhiteSpace(options.AgentToken))
            return;
        if (options.ClientId <= 0 || string.IsNullOrWhiteSpace(config.MasterUrl))
            throw new InvalidOperationException("headless_identity_incomplete");
        await client.Controller
            .LoginWithAgentTokenAsync(config.MasterUrl, options.ClientId, options.AgentToken, rememberCredentials: true, ct)
            .ConfigureAwait(false);
    }

    private static async Task<bool> ConnectAsync(
        IDesktopClientController controller,
        HeadlessClientOptions options,
        MacHeadlessDependencies deps,
        CancellationToken ct)
    {
        var attempt = 0;
        // A smoke run (--once) still retries a bounded number of times: a cold
        // panel or a fresh TCP window is not a client failure, and the caller
        // wants one honest answer instead of one attempt.
        var maxAttempts = options.Once ? 3 : int.MaxValue;
        while (!ct.IsCancellationRequested && attempt < maxAttempts)
        {
            try
            {
                await controller.ConnectAsync(ct).ConfigureAwait(false);
                Log.Information("headless_connected forwards={ForwardCount}", controller.Snapshot.Forwards.Count);
                return true;
            }
            catch (OperationCanceledException) when (ct.IsCancellationRequested)
            {
                return false;
            }
            catch (Exception exception)
            {
                var delay = RetryDelays[Math.Min(attempt, RetryDelays.Length - 1)];
                attempt++;
                Log.Warning(
                    "headless_connect_failed attempt={Attempt} type={ErrorType} code={ErrorCode} retry_in={DelaySeconds}s",
                    attempt,
                    exception.GetType().Name,
                    BoundCode(exception),
                    delay.TotalSeconds);
                await deps.Delay(delay, ct).ConfigureAwait(false);
            }
        }

        return false;
    }

    private static async Task<int> RunOnceAsync(
        IDesktopClientController controller,
        HeadlessClientOptions options,
        MacHeadlessDependencies deps,
        CancellationToken ct)
    {
        using var window = CancellationTokenSource.CreateLinkedTokenSource(ct);
        window.CancelAfter(options.OnceWindow);
        try
        {
            await deps.Delay(options.OnceWindow, window.Token).ConfigureAwait(false);
        }
        catch (OperationCanceledException)
        {
        }

        var status = BuildStatus(controller);
        WriteStatus(options, deps, status);
        var running = status.Forwards.Count(item => item.Running);
        Log.Information("headless_once_complete forwards={Running}/{Total}", running, status.Forwards.Count);
        return running > 0 ? 0 : 1;
    }

    private static async Task SuperviseAsync(
        IDesktopClientController controller,
        HeadlessClientOptions options,
        MacHeadlessDependencies deps,
        CancellationToken ct)
    {
        var selection = HeadlessClientOptions.ParseForwardSelection(options.Forwards);
        var consecutiveFailures = 0;
        while (!ct.IsCancellationRequested)
        {
            try
            {
                await deps.Delay(options.StatusInterval, ct).ConfigureAwait(false);
            }
            catch (OperationCanceledException)
            {
                return;
            }

            var snapshot = controller.Snapshot;
            if (!snapshot.IsConnected)
            {
                var delay = RetryDelays[Math.Min(consecutiveFailures, RetryDelays.Length - 1)];
                consecutiveFailures++;
                Log.Warning(
                    "headless_reconnecting attempt={Attempt} status={Status} code={ErrorCode}",
                    consecutiveFailures,
                    snapshot.ConnectionStatus,
                    snapshot.LastErrorCode);
                try
                {
                    await controller.ConnectAsync(ct).ConfigureAwait(false);
                    consecutiveFailures = 0;
                    Log.Information("headless_reconnected");
                }
                catch (OperationCanceledException) when (ct.IsCancellationRequested)
                {
                    return;
                }
                catch (Exception exception)
                {
                    Log.Warning("headless_reconnect_failed type={ErrorType} code={ErrorCode}", exception.GetType().Name, BoundCode(exception));
                    try
                    {
                        await deps.Delay(delay, ct).ConfigureAwait(false);
                    }
                    catch (OperationCanceledException)
                    {
                        return;
                    }

                    continue;
                }
            }

            // The panel keeps the desired forward set in sync; restart anything
            // that dropped out so a crashed helper never leaves a dead route.
            foreach (var forward in controller.Snapshot.Forwards)
            {
                if (forward.IsRunning || forward.IsNativeOutboundRule)
                    continue;
                if (selection.Count > 0 && !selection.Contains(forward.ForwardId))
                    continue;
                try
                {
                    await controller.StartForwardAsync(forward.ForwardId, ct).ConfigureAwait(false);
                }
                catch (OperationCanceledException) when (ct.IsCancellationRequested)
                {
                    return;
                }
                catch (Exception exception)
                {
                    Log.Warning(
                        "headless_forward_ensure_failed forward={ForwardId} type={ErrorType}",
                        forward.ForwardId,
                        exception.GetType().Name);
                }
            }

            WriteStatus(options, deps, BuildStatus(controller));
        }
    }

    private static async Task StopAsync(IDesktopClientController controller, IReadOnlyList<int> selection)
    {
        try
        {
            if (selection.Count > 0)
            {
                foreach (var forwardId in selection)
                {
                    try
                    {
                        await controller.StopForwardAsync(forwardId).ConfigureAwait(false);
                    }
                    catch (Exception exception)
                    {
                        Log.Warning("headless_forward_stop_failed forward={ForwardId} type={ErrorType}", forwardId, exception.GetType().Name);
                    }
                }
            }
            else
            {
                await controller.StopAllAsync().ConfigureAwait(false);
            }
        }
        catch (Exception exception)
        {
            Log.Warning("headless_stop_failed type={ErrorType}", exception.GetType().Name);
        }

        try
        {
            await controller.DisconnectAsync().ConfigureAwait(false);
        }
        catch (Exception exception)
        {
            Log.Warning("headless_disconnect_failed type={ErrorType}", exception.GetType().Name);
        }
    }

    private static HeadlessClientStatus BuildStatus(IDesktopClientController controller)
    {
        var snapshot = controller.Snapshot;
        var forwards = snapshot.Forwards
            .Select(forward => new HeadlessClientForward(
                forward.ForwardId,
                forward.DisplayName,
                forward.Mode,
                forward.ListenPort,
                forward.IsRunning,
                forward.Status))
            .ToList();
        return new HeadlessClientStatus(
            RuntimeVersion(),
            snapshot.IsConnected,
            snapshot.ConnectionStatus,
            snapshot.LastErrorCode,
            snapshot.ActiveClientName,
            forwards,
            DateTimeOffset.UtcNow);
    }

    private static void WriteStatus(
        HeadlessClientOptions options,
        MacHeadlessDependencies deps,
        HeadlessClientStatus status)
    {
        var json = JsonSerializer.Serialize(status, HeadlessClientStatusContext.Default.HeadlessClientStatus);
        if (!string.IsNullOrWhiteSpace(options.StatusFile))
        {
            try
            {
                var directory = Path.GetDirectoryName(options.StatusFile);
                if (!string.IsNullOrWhiteSpace(directory))
                    Directory.CreateDirectory(directory);
                var temporary = options.StatusFile + ".tmp";
                File.WriteAllText(temporary, json + Environment.NewLine, Encoding.UTF8);
                File.Move(temporary, options.StatusFile, overwrite: true);
            }
            catch (Exception exception) when (exception is IOException or UnauthorizedAccessException)
            {
                Log.Warning("headless_status_write_failed type={ErrorType}", exception.GetType().Name);
            }
        }

        if (options.PrintStatus)
        {
            deps.Output.WriteLine(json);
            deps.Output.Flush();
        }
    }

    private static void ConfigureLogging(
        MacPlatformPaths paths,
        HeadlessClientOptions options,
        MacHeadlessDependencies deps)
    {
        // Reconfiguration: release the previous sink first, otherwise the file
        // sink cannot reopen the same path (and a rolling restart would fail the
        // same way).
        Log.CloseAndFlush();
        var defaultLogFile = paths.LogDirectory.TrimEnd(Path.DirectorySeparatorChar, Path.AltDirectorySeparatorChar)
            + Path.DirectorySeparatorChar + "headless-.log";
        var logDirectory = Path.GetDirectoryName(
            string.IsNullOrWhiteSpace(options.LogFile) ? defaultLogFile : options.LogFile);
        if (!string.IsNullOrWhiteSpace(logDirectory))
            Directory.CreateDirectory(logDirectory);
        var configuration = new LoggerConfiguration()
            .MinimumLevel.Information()
            .WriteTo.File(
                new RedactingTextFormatter("{Timestamp:yyyy-MM-dd HH:mm:ss} [{Level:u3}] {Message:lj}{NewLine}{Exception}"),
                string.IsNullOrWhiteSpace(options.LogFile) ? defaultLogFile : options.LogFile,
                rollingInterval: RollingInterval.Day,
                retainedFileCountLimit: 7,
                fileSizeLimitBytes: 5 * 1024 * 1024,
                rollOnFileSizeLimit: true);
        Log.Logger = configuration.CreateLogger();
    }

    private static string RuntimeVersion()
        => typeof(MacHeadlessHost).Assembly.GetName().Version?.ToString(3) ?? "0.0.0";

    private static string BoundCode(Exception exception)
    {
        var message = exception.Message.Replace('\r', ' ').Replace('\n', ' ').Trim();
        return string.IsNullOrWhiteSpace(message)
            ? "unknown"
            : message.Length > 120 ? message[..120] : message;
    }
}

// launchd stops a daemon with SIGTERM; an interactive run stops with Ctrl+C.
// Both must reach the same graceful teardown so forwards are stopped and the
// lease is released instead of leaving a half-open route behind.
internal static class MacHeadlessSignals
{
    public static IDisposable Register(CancellationTokenSource shutdown, MacHeadlessDependencies deps)
    {
        var registrations = new List<IDisposable>();
        void Handle(string signal)
        {
            if (shutdown.IsCancellationRequested)
                return;
            deps.Output.WriteLine($"headless_signal: {signal}");
            deps.Output.Flush();
            try
            {
                shutdown.Cancel();
            }
            catch (ObjectDisposedException)
            {
                // The run already finished and disposed its token source; a
                // signal that late must not escalate into a crash.
            }
        }

        if (!OperatingSystem.IsWindows())
        {
            registrations.Add(PosixSignalRegistration.Create(PosixSignal.SIGTERM, _ => Handle("SIGTERM")));
            registrations.Add(PosixSignalRegistration.Create(PosixSignal.SIGINT, _ => Handle("SIGINT")));
            registrations.Add(PosixSignalRegistration.Create(PosixSignal.SIGQUIT, _ => Handle("SIGQUIT")));
        }
        else
        {
            Console.CancelKeyPress += (_, eventArgs) =>
            {
                eventArgs.Cancel = true;
                Handle("SIGINT");
            };
        }

        return new Registration(registrations);
    }

    private sealed class Registration(IReadOnlyList<IDisposable> registrations) : IDisposable
    {
        public void Dispose()
        {
            foreach (var registration in registrations)
            {
                try
                {
                    registration.Dispose();
                }
                catch (Exception)
                {
                }
            }
        }
    }
}

// The windowless client only needs the controller, the persisted config and a
// way to release the platform resources; everything else stays private to the
// bootstrap. Keeping the surface this small is what makes the lifecycle
// testable off-macOS.
public interface IMacHeadlessClient : IAsyncDisposable
{
    IDesktopClientController Controller { get; }

    LeikwanBridge.Core.Models.AgentConfig Config { get; }
}

public sealed class MacHeadlessClient(MacAppServices services) : IMacHeadlessClient
{
    public IDesktopClientController Controller => services.Controller;

    public LeikwanBridge.Core.Models.AgentConfig Config => services.Config;

    public async ValueTask DisposeAsync()
    {
        await services.Connections.DisposeAsync().ConfigureAwait(false);
        services.Updater.Dispose();
        await services.Controller.DisposeAsync().ConfigureAwait(false);
    }
}

// Seams for tests: the host never touches launchd, Keychain or the network
// directly, so the whole lifecycle can be exercised on any platform.
public sealed record MacHeadlessDependencies(
    Func<MacPlatformPaths, CancellationToken, Task<IMacHeadlessClient>> Bootstrap,
    Func<MacPlatformPaths> PathsFactory,
    TextWriter Output,
    TextWriter Error,
    Func<TimeSpan, CancellationToken, Task> Delay)
{
    public static MacHeadlessDependencies Default { get; } = new(
        async (paths, ct) => new MacHeadlessClient(await MacAppBootstrap.CreateAsync(paths, ct).ConfigureAwait(false)),
        CreateDefaultPaths,
        Console.Out,
        Console.Error,
        (delay, ct) => Task.Delay(delay, ct));

    private static MacPlatformPaths CreateDefaultPaths()
    {
        var home = Environment.GetFolderPath(Environment.SpecialFolder.UserProfile);
        return new MacPlatformPaths(home, ResolveBundleDirectory());
    }

    // A launchd daemon starts in /; the bundle is found by walking up from the
    // executable, exactly like the windowed entry point does.
    private static string ResolveBundleDirectory()
    {
        var current = new DirectoryInfo(AppContext.BaseDirectory);
        while (current is not null)
        {
            if (current.Name.EndsWith(".app", StringComparison.OrdinalIgnoreCase))
                return current.FullName;
            current = current.Parent;
        }

        return AppContext.BaseDirectory.TrimEnd(Path.DirectorySeparatorChar, Path.AltDirectorySeparatorChar);
    }
}
