using System.Text;
using System.Text.Json;
using LeikwanBridge.Core.Models;
using LeikwanBridge.Core.Services;
using Serilog;

namespace LeikwanBridge.Headless;

// Lifecycle shared by the console run and the Windows service run: sign in with
// the client's agent token, bring the routes up, then keep them up until the
// supervisor asks the process to stop. Failures are retried with backoff rather
// than exiting, because the SCM/console host would otherwise restart us in a
// hot loop.
public static class HeadlessRunner
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

    public static async Task<int> RunAsync(
        HeadlessClientOptions options,
        CancellationToken ct,
        Func<AgentConfig, HeadlessClientOptions, CancellationToken, Task<IHeadlessClientStack>> factory,
        TextWriter? output = null,
        Func<TimeSpan, CancellationToken, Task>? delay = null,
        Func<HeadlessClientOptions, AgentConfig>? configFactory = null)
    {
        var writer = output ?? Console.Out;
        var wait = delay ?? ((span, token) => Task.Delay(span, token));
        var config = (configFactory ?? LoadConfig)(options);
        ApplyPinnedEgress(config, options);
        PanelDirectEgress.Initialize();
        if (!string.IsNullOrWhiteSpace(config.PreferredEgressInterface))
            PanelDirectEgress.SetPreferredInterface(config.PreferredEgressInterface);
        IHeadlessClientStack? stack = null;
        try
        {
            stack = await ConnectWithRetryAsync(config, options, factory, wait, writer, ct).ConfigureAwait(false);
            if (stack is null)
            {
                Log.Error("headless_startup_failed");
                return 1;
            }

            var selection = HeadlessClientOptions.ParseForwardSelection(options.Forwards);
            await StartForwardsAsync(stack.Session, selection, ct).ConfigureAwait(false);

            if (options.Once)
                return await RunOnceAsync(stack.Session, options, wait, writer).ConfigureAwait(false);

            await SuperviseAsync(stack.Session, selection, options, wait, writer, ct).ConfigureAwait(false);
            await StopAsync(stack.Session, selection).ConfigureAwait(false);
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
            Console.Error.WriteLine($"headless_failed: {exception.GetType().Name}: {exception.Message}");
            return 1;
        }
        finally
        {
            if (stack is not null)
            {
                try
                {
                    // Explicit teardown order: the session stops polling and the
                    // managed runtimes before the stack releases its client.
                    await stack.Session.DisposeAsync().ConfigureAwait(false);
                }
                catch (Exception exception)
                {
                    Log.Warning("headless_session_stop_failed type={ErrorType}", exception.GetType().Name);
                }

                try
                {
                    await stack.DisposeAsync().ConfigureAwait(false);
                }
                catch (Exception exception)
                {
                    Log.Warning("headless_dispose_failed type={ErrorType}", exception.GetType().Name);
                }
            }

            Log.CloseAndFlush();
        }
    }

    // The windowless client shares %ProgramData%\Leikwan\ClientAgent with the
    // GUI client: one machine, one client identity, one runtime tree.
    public static AgentConfig LoadConfig(HeadlessClientOptions options)
    {
        var configDirectory = Path.Combine(
            Environment.GetFolderPath(Environment.SpecialFolder.CommonApplicationData),
            "Leikwan", "ClientAgent");
        Directory.CreateDirectory(configDirectory);
        var configPath = Path.Combine(configDirectory, "config.json");
        var stateManager = new StateManager(configDirectory);
        var config = ApplyIdentity(stateManager.LoadConfig(configPath), options);
        ApplyPinnedEgress(config, options);

        if (options.ClientId > 0
            || !string.IsNullOrWhiteSpace(options.AgentToken)
            || !string.IsNullOrWhiteSpace(options.PreferredEgressInterface))
        {
            try
            {
                stateManager.SaveConfig(configPath, config);
            }
            catch (Exception exception) when (exception is IOException or UnauthorizedAccessException)
            {
                Log.Warning("headless_config_not_persisted type={ErrorType}", exception.GetType().Name);
            }
        }

        return config;
    }

    private static void ApplyPinnedEgress(AgentConfig config, HeadlessClientOptions options)
    {
        if (!string.IsNullOrWhiteSpace(options.PreferredEgressInterface))
            config.PreferredEgressInterface = options.PreferredEgressInterface.Trim();

        if (string.IsNullOrWhiteSpace(config.PreferredEgressInterface))
            return;

        // A requested pin is fail-closed. Falling back to Automatic here would
        // let another TUN/VPN change the default route underneath the headless
        // client, which is precisely the drift the pin is meant to prevent.
        _ = EgressInterfaceConfig.ResolveOrThrow(config.PreferredEgressInterface);
    }

    /// <summary>
    /// Command line identity wins over whatever the machine already had: the
    /// service registration carries the panel/client id, and the token file is
    /// the only secret involved.
    /// </summary>
    public static AgentConfig ApplyIdentity(AgentConfig config, HeadlessClientOptions options)
    {
        if (!string.IsNullOrWhiteSpace(options.PanelUrl))
            config.MasterUrl = options.PanelUrl;
        config.MasterUrl = ClientDefaults.NormalizeMasterUrl(config.MasterUrl);
        if (string.IsNullOrWhiteSpace(config.MasterUrl))
            config.MasterUrl = ClientDefaults.DefaultMasterUrl;
        if (options.ClientId > 0)
        {
            config.ClientId = options.ClientId;
            config.ActiveClientId = options.ClientId;
        }

        if (!string.IsNullOrWhiteSpace(options.AgentToken))
            config.AgentToken = options.AgentToken;

        if (string.IsNullOrWhiteSpace(config.AgentToken) || config.ActiveClientId <= 0)
            throw new InvalidOperationException("headless_identity_incomplete");
        return config;
    }

    private static async Task<IHeadlessClientStack?> ConnectWithRetryAsync(
        AgentConfig config,
        HeadlessClientOptions options,
        Func<AgentConfig, HeadlessClientOptions, CancellationToken, Task<IHeadlessClientStack>> factory,
        Func<TimeSpan, CancellationToken, Task> wait,
        TextWriter writer,
        CancellationToken ct)
    {
        var attempt = 0;
        var maxAttempts = options.Once ? 3 : int.MaxValue;
        while (!ct.IsCancellationRequested && attempt < maxAttempts)
        {
            try
            {
                var stack = await factory(config, options, ct).ConfigureAwait(false);
                Log.Information("headless_connected forwards={ForwardCount}", stack.Session.Forwards.Count);
                return stack;
            }
            catch (OperationCanceledException) when (ct.IsCancellationRequested)
            {
                return null;
            }
            catch (Exception exception)
            {
                var backoff = RetryDelays[Math.Min(attempt, RetryDelays.Length - 1)];
                attempt++;
                Log.Warning(
                    "headless_connect_failed attempt={Attempt} type={ErrorType} code={ErrorCode} retry_in={DelaySeconds}s",
                    attempt,
                    exception.GetType().Name,
                    BoundCode(exception),
                    backoff.TotalSeconds);
                if (options.PrintStatus)
                {
                    writer.WriteLine($"headless_connect_failed attempt={attempt} {BoundCode(exception)}");
                    writer.Flush();
                }

                await wait(backoff, ct).ConfigureAwait(false);
            }
        }

        return null;
    }

    private static async Task StartForwardsAsync(
        IDesktopRuntimeSession session,
        IReadOnlyList<int> selection,
        CancellationToken ct)
    {
        if (selection.Count > 0)
        {
            foreach (var forwardId in selection)
            {
                try
                {
                    await session.StartForwardAsync(forwardId, ct).ConfigureAwait(false);
                }
                catch (Exception exception)
                {
                    Log.Warning("headless_forward_start_failed forward={ForwardId} type={ErrorType}", forwardId, exception.GetType().Name);
                }
            }

            return;
        }

        await session.StartAllAsync(ct).ConfigureAwait(false);
    }

    private static async Task<int> RunOnceAsync(
        IDesktopRuntimeSession session,
        HeadlessClientOptions options,
        Func<TimeSpan, CancellationToken, Task> wait,
        TextWriter writer)
    {
        try
        {
            await wait(options.OnceWindow, CancellationToken.None).ConfigureAwait(false);
        }
        catch (OperationCanceledException)
        {
        }

        var status = BuildStatus(session);
        WriteStatus(options, writer, status);
        var running = status.Forwards.Count(item => item.Running);
        Log.Information("headless_once_complete forwards={Running}/{Total}", running, status.Forwards.Count);
        return running > 0 ? 0 : 1;
    }

    private static async Task SuperviseAsync(
        IDesktopRuntimeSession session,
        IReadOnlyList<int> selection,
        HeadlessClientOptions options,
        Func<TimeSpan, CancellationToken, Task> wait,
        TextWriter writer,
        CancellationToken ct)
    {
        while (!ct.IsCancellationRequested)
        {
            try
            {
                await wait(options.StatusInterval, ct).ConfigureAwait(false);
            }
            catch (OperationCanceledException)
            {
                return;
            }

            // The panel owns the desired forward set; restart anything that
            // dropped out so a crashed helper never leaves a dead route.
            foreach (var forward in session.Forwards)
            {
                if (selection.Count > 0 && !selection.Contains(forward.ForwardId))
                    continue;
                if (session.IsForwardRunning(forward.ForwardId))
                    continue;
                try
                {
                    await session.StartForwardAsync(forward.ForwardId, ct).ConfigureAwait(false);
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

            WriteStatus(options, writer, BuildStatus(session));
        }
    }

    private static async Task StopAsync(IDesktopRuntimeSession session, IReadOnlyList<int> selection)
    {
        try
        {
            if (selection.Count > 0)
            {
                foreach (var forwardId in selection)
                {
                    try
                    {
                        await session.StopForwardAsync(forwardId, CancellationToken.None).ConfigureAwait(false);
                    }
                    catch (Exception exception)
                    {
                        Log.Warning("headless_forward_stop_failed forward={ForwardId} type={ErrorType}", forwardId, exception.GetType().Name);
                    }
                }
            }
            else
            {
                await session.StopAllAsync(CancellationToken.None).ConfigureAwait(false);
            }
        }
        catch (Exception exception)
        {
            Log.Warning("headless_stop_failed type={ErrorType}", exception.GetType().Name);
        }
    }

    private static HeadlessClientStatus BuildStatus(IDesktopRuntimeSession session)
    {
        var forwards = session.Forwards
            .Select(forward => new HeadlessClientForward(
                forward.ForwardId,
                string.IsNullOrWhiteSpace(forward.Target) ? $"Forward #{forward.ForwardId}" : forward.Target,
                string.IsNullOrWhiteSpace(forward.ClientMode) ? forward.Mode : forward.ClientMode,
                forward.ListenPort,
                session.IsForwardRunning(forward.ForwardId),
                session.IsForwardRunning(forward.ForwardId) ? "running" : "stopped"))
            .ToList();
        return new HeadlessClientStatus(
            typeof(HeadlessRunner).Assembly.GetName().Version?.ToString() ?? "0.0.0", // four-part: the update health gate matches it
            true,
            "connected",
            string.Empty,
            Environment.MachineName,
            forwards,
            DateTimeOffset.UtcNow);
    }

    private static void WriteStatus(HeadlessClientOptions options, TextWriter writer, HeadlessClientStatus status)
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
            writer.WriteLine(json);
            writer.Flush();
        }
    }

    private static string BoundCode(Exception exception)
    {
        var message = exception.Message.Replace('\r', ' ').Replace('\n', ' ').Trim();
        if (string.IsNullOrWhiteSpace(message))
            return "unknown";
        return message.Length > 120 ? message[..120] : message;
    }
}
