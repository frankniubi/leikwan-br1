using LeikwanBridge.Core.Models;
using LeikwanBridge.Core.Services;
using Serilog;

namespace LeikwanBridge.Headless;

internal static class Program
{
    private const string ServiceFlag = "--service";
    private const string InstallServiceFlag = "--install-service";
    private const string UninstallServiceFlag = "--uninstall-service";
    private const string ServiceNameFlag = "--service-name";
    private const string CliFlag = "--cli";
    private const string ApplyUpdateCommand = "apply-update";
    private const string NoStartFlag = "--no-start";

    private static async Task<int> Main(string[] args)
    {
        var mode = ServiceMode.Console;
        var serviceName = WindowsServiceInstaller.DefaultServiceName;
        var clientArguments = new List<string>();
        var startAfterInstall = true;
        for (var index = 0; index < args.Length; index++)
        {
            switch (args[index])
            {
                case ServiceFlag:
                    mode = ServiceMode.Service;
                    break;
                case InstallServiceFlag:
                    mode = ServiceMode.Install;
                    break;
                case UninstallServiceFlag:
                    mode = ServiceMode.Uninstall;
                    break;
                case NoStartFlag:
                    // Installer-driven: register/update only; the installer
                    // starts the service itself and verifies it.
                    startAfterInstall = false;
                    break;
                case ServiceNameFlag:
                    if (index + 1 >= args.Length)
                    {
                        Console.Error.WriteLine("--service-name requires a value");
                        return 2;
                    }

                    serviceName = args[++index];
                    break;
                default:
                    clientArguments.Add(args[index]);
                    break;
            }
        }

        if (mode == ServiceMode.Install)
            return WindowsServiceInstaller.Install(serviceName, clientArguments.ToArray(), startAfterInstall);

        if (mode == ServiceMode.Uninstall)
            return WindowsServiceInstaller.Uninstall(serviceName);

        // `leikwan <command>` — the operator surface for the windowless client.
        if (clientArguments.Count > 0
            && string.Equals(clientArguments[0], CliFlag, StringComparison.Ordinal))
        {
            var cliArguments = clientArguments.Skip(1).ToList();
            if (cliArguments.Count > 0
                && string.Equals(cliArguments[0], ApplyUpdateCommand, StringComparison.Ordinal))
            {
                return await ApplyUpdateAsync(cliArguments.Skip(1).ToList(), serviceName).ConfigureAwait(false);
            }

            return await RunCliAsync(cliArguments, serviceName).ConfigureAwait(false);
        }

        if (!HeadlessClientOptions.TryParse(clientArguments, out var options, out var parseError))
        {
            Console.Error.WriteLine($"headless_invalid_arguments: {parseError}");
            Console.Error.WriteLine(HeadlessClientOptions.Usage);
            return 2;
        }

        ConfigureLogging(options);

        if (mode == ServiceMode.Service)
            return WindowsServiceHost.Run(serviceName, ct => RunServiceAsync(options, serviceName, ct));

        using var shutdown = new CancellationTokenSource();
        Console.CancelKeyPress += (_, eventArgs) =>
        {
            eventArgs.Cancel = true;
            if (!shutdown.IsCancellationRequested)
            {
                Console.Out.WriteLine("headless_signal: SIGINT");
                shutdown.Cancel();
            }
        };
        // A late ProcessExit must never crash the shutdown path: the CTS is
        // already disposed by the time the runtime tears the process down.
        AppDomain.CurrentDomain.ProcessExit += (_, _) =>
        {
            try
            {
                shutdown.Cancel();
            }
            catch (ObjectDisposedException)
            {
            }
        };
        return await RunClientAsync(options, shutdown.Token).ConfigureAwait(false);
    }

    private static async Task<int> RunCliAsync(IReadOnlyList<string> arguments, string serviceName)
    {
        var controller = new WindowsServiceController(serviceName);
        var config = TryLoadPanelConfig();
        // `leikwan update|doctor` talk to the panel as well: same proxy-free,
        // physical-NIC egress (and pinned adapter) as the service itself.
        PanelDirectEgress.Initialize();
        if (!string.IsNullOrWhiteSpace(config.PreferredEgressInterface))
            PanelDirectEgress.SetPreferredInterface(config.PreferredEgressInterface);
        var installDirectory = HeadlessUpdatePaths.InstallDirectory;
        var updater = new PanelClientUpdateService(
            config.MasterUrl,
            HeadlessUpdatePaths.ManifestKey,
            controller.Version,
            Path.Combine(Path.GetTempPath(), "leikwan-update"),
            new WindowsHeadlessUpdateApplier(installDirectory, serviceName, HeadlessUpdatePaths.UpdatesRoot));
        using var shutdown = new CancellationTokenSource();
        Console.CancelKeyPress += (_, eventArgs) =>
        {
            eventArgs.Cancel = true;
            shutdown.Cancel();
        };
        return await HeadlessCli.RunAsync(arguments, controller, updater, Console.Out, Console.Error, shutdown.Token)
            .ConfigureAwait(false);
    }

    /// <summary>
    /// Detached swap step of `leikwan update` and of the service's auto-update:
    /// this process runs from the staged copy, so it can stop the service and
    /// replace the install directory the old build is still occupying. It
    /// backs the install up first, holds the new build to a health gate, and
    /// restores the backup (recording the version as rejected) when the new
    /// build does not come up connected.
    /// </summary>
    private static async Task<int> ApplyUpdateAsync(IReadOnlyList<string> arguments, string defaultServiceName)
    {
        ConfigureUpdateLogging();
        try
        {
            var staged = (ReadOption(arguments, "--staged") ?? AppContext.BaseDirectory)
                .TrimEnd(Path.DirectorySeparatorChar, Path.AltDirectorySeparatorChar);
            var installDirectory = ReadOption(arguments, "--install-dir");
            // The service name arrives as the global --service-name (parsed by
            // Main into defaultServiceName); "--service" is kept for old launchers.
            var serviceName = ReadOption(arguments, "--service") ?? defaultServiceName;
            var waitPid = int.TryParse(ReadOption(arguments, "--wait-pid"), out var parsedPid) ? parsedPid : 0;
            if (string.IsNullOrWhiteSpace(installDirectory) || !Directory.Exists(installDirectory))
            {
                Console.Error.WriteLine("apply-update: --install-dir is required and must exist");
                Log.Warning("update_apply_invalid reason=install_dir_missing");
                return 2;
            }

            installDirectory = installDirectory.TrimEnd(Path.DirectorySeparatorChar, Path.AltDirectorySeparatorChar);
            // Older launchers do not pass versions: read them from the binaries.
            var newVersion = ReadOption(arguments, "--version")
                ?? HeadlessUpdatePaths.ReadFileVersion(Path.Combine(staged, HeadlessUpdatePaths.ExecutableName))
                ?? HeadlessUpdatePaths.ProductVersion;
            var previousVersion = ReadOption(arguments, "--previous-version")
                ?? HeadlessUpdatePaths.ReadFileVersion(Path.Combine(installDirectory, HeadlessUpdatePaths.ExecutableName))
                ?? "unknown";

            SecureDirectory.EnsureAdminOnly(HeadlessUpdatePaths.UpdatesRoot);
            SecureDirectory.EnsureAdminOnly(HeadlessUpdatePaths.BackupRoot);
            using var applyLock = await UpdateApplyLock.AcquireAsync(HeadlessUpdatePaths.LockPath, TimeSpan.FromSeconds(120)).ConfigureAwait(false);
            if (applyLock is null)
            {
                Console.Error.WriteLine("apply-update: another update is being applied");
                Log.Warning("update_apply_locked from={OldVersion} to={NewVersion}", previousVersion, newVersion);
                return 3;
            }

            var statusFile = new WindowsServiceController(serviceName).StatusFilePath;
            var orchestrator = new UpdateApplyOrchestrator(
                new WindowsUpdateApplyEnvironment(serviceName, statusFile, waitPid),
                new UpdateApplyOptions
                {
                    StagedDirectory = staged,
                    InstallDirectory = installDirectory,
                    BackupRoot = HeadlessUpdatePaths.BackupRoot,
                    StatePath = HeadlessUpdatePaths.StatePath,
                    PreviousVersion = previousVersion,
                    NewVersion = newVersion,
                    SentinelFileName = HeadlessUpdatePaths.ExecutableName,
                });
            var outcome = await orchestrator.RunAsync(CancellationToken.None).ConfigureAwait(false);
            Log.Information("update_apply_finished outcome={Outcome} from={OldVersion} to={NewVersion}", outcome, previousVersion, newVersion);
            Console.WriteLine($"apply-update: {outcome} ({previousVersion} -> {newVersion})");
            return outcome switch
            {
                UpdateApplyOutcome.Applied => 0,
                UpdateApplyOutcome.InvalidRequest => 2,
                _ => 1,
            };
        }
        catch (Exception exception)
        {
            Log.Error("update_apply_crashed type={ErrorType}", exception.GetType().Name);
            Console.Error.WriteLine($"apply-update: failed ({exception.GetType().Name})");
            return 1;
        }
        finally
        {
            Log.CloseAndFlush();
        }
    }

    /// <summary>
    /// Service mode: the client plus, next to it, the auto-update loop. The loop
    /// is cancelled with the service and can never take the client down.
    /// </summary>
    private static async Task<int> RunServiceAsync(HeadlessClientOptions options, string serviceName, CancellationToken ct)
    {
        using var updateStop = CancellationTokenSource.CreateLinkedTokenSource(ct);
        var updateLoop = HeadlessAutoUpdateHost.StartLoop(options, serviceName, updateStop.Token);
        try
        {
            return await RunClientAsync(options, ct).ConfigureAwait(false);
        }
        finally
        {
            updateStop.Cancel();
            try
            {
                await updateLoop.WaitAsync(TimeSpan.FromSeconds(5)).ConfigureAwait(false);
            }
            catch (Exception exception) when (exception is TimeoutException or OperationCanceledException)
            {
            }
        }
    }

    private static string? ReadOption(IReadOnlyList<string> arguments, string name)
    {
        for (var index = 0; index < arguments.Count - 1; index++)
        {
            if (string.Equals(arguments[index], name, StringComparison.Ordinal))
                return arguments[index + 1];
        }

        return null;
    }

    private static LeikwanBridge.Core.Models.AgentConfig TryLoadPanelConfig()
    {
        try
        {
            var directory = ClientDefaults.ResolveSharedStateDirectory();
            var path = Path.Combine(directory, "config.json");
            if (File.Exists(path))
            {
                var state = new StateManager(directory);
                var config = state.LoadConfig(path);
                if (!string.IsNullOrWhiteSpace(config.MasterUrl))
                    return config;
            }
        }
        catch (Exception exception) when (exception is IOException or UnauthorizedAccessException)
        {
        }

        return new LeikwanBridge.Core.Models.AgentConfig { MasterUrl = ClientDefaults.DefaultMasterUrl };
    }

    private static Task<int> RunClientAsync(HeadlessClientOptions options, CancellationToken ct)
        => HeadlessRunner.RunAsync(
            options,
            ct,
            static async (config, clientOptions, token) => (IHeadlessClientStack)await WindowsClientStack
                .ConnectAsync(config, message => Log.Information("{Message}", message), token)
                .ConfigureAwait(false));

    private static void ConfigureLogging(HeadlessClientOptions options)
    {
        var defaultLogFile = Path.Combine(ClientDefaults.ResolveSharedStateDirectory(), "logs", "headless-.log");
        var logFile = string.IsNullOrWhiteSpace(options.LogFile) ? defaultLogFile : options.LogFile;
        var directory = Path.GetDirectoryName(logFile);
        if (!string.IsNullOrWhiteSpace(directory))
            Directory.CreateDirectory(directory);
        Log.Logger = new LoggerConfiguration()
            .MinimumLevel.Information()
            .WriteTo.File(
                logFile,
                rollingInterval: RollingInterval.Day,
                retainedFileCountLimit: 7,
                fileSizeLimitBytes: 5 * 1024 * 1024,
                rollOnFileSizeLimit: true,
                outputTemplate: "{Timestamp:yyyy-MM-dd HH:mm:ss} [{Level:u3}] {Message:lj}{NewLine}{Exception}")
            .CreateLogger();
    }

    private static void ConfigureUpdateLogging()
    {
        try
        {
            var directory = Path.Combine(ClientDefaults.ResolveSharedStateDirectory(), "logs");
            Directory.CreateDirectory(directory);
            Log.Logger = new LoggerConfiguration()
                .MinimumLevel.Information()
                .WriteTo.File(
                    Path.Combine(directory, "update-.log"),
                    rollingInterval: RollingInterval.Day,
                    retainedFileCountLimit: 14,
                    fileSizeLimitBytes: 5 * 1024 * 1024,
                    rollOnFileSizeLimit: true,
                    outputTemplate: "{Timestamp:yyyy-MM-dd HH:mm:ss} [{Level:u3}] {Message:lj}{NewLine}{Exception}")
                .CreateLogger();
        }
        catch (Exception exception) when (exception is IOException or UnauthorizedAccessException)
        {
            // Logging is best effort; the swap itself must still run.
        }
    }

    private enum ServiceMode
    {
        Console,
        Service,
        Install,
        Uninstall,
    }
}
