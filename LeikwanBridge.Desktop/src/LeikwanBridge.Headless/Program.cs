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

    private static async Task<int> Main(string[] args)
    {
        var mode = ServiceMode.Console;
        var serviceName = WindowsServiceInstaller.DefaultServiceName;
        var clientArguments = new List<string>();
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
            return WindowsServiceInstaller.Install(serviceName, clientArguments.ToArray());

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
            return WindowsServiceHost.Run(serviceName, ct => RunClientAsync(options, ct));

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
        var installDirectory = AppContext.BaseDirectory.TrimEnd(Path.DirectorySeparatorChar, Path.AltDirectorySeparatorChar);
        var updater = new PanelClientUpdateService(
            config.MasterUrl,
            "windows_headless_client",
            controller.Version,
            Path.Combine(Path.GetTempPath(), "leikwan-update"),
            new WindowsHeadlessUpdateApplier(installDirectory, serviceName));
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
    /// Detached swap step of `leikwan update`: this process runs from the staged
    /// copy, so it can stop the service and replace the install directory that
    /// the old build is still occupying.
    /// </summary>
    private static async Task<int> ApplyUpdateAsync(IReadOnlyList<string> arguments, string defaultServiceName)
    {
        var staged = ReadOption(arguments, "--staged") ?? AppContext.BaseDirectory;
        var installDirectory = ReadOption(arguments, "--install-dir");
        var serviceName = ReadOption(arguments, "--service") ?? defaultServiceName;
        var waitPid = int.TryParse(ReadOption(arguments, "--wait-pid"), out var parsedPid) ? parsedPid : 0;
        if (string.IsNullOrWhiteSpace(installDirectory) || !Directory.Exists(installDirectory))
        {
            Console.Error.WriteLine("apply-update: --install-dir is required and must exist");
            return 2;
        }

        var controller = new WindowsServiceController(serviceName);
        await controller.StopAsync(CancellationToken.None).ConfigureAwait(false);
        if (waitPid > 0)
        {
            for (var attempt = 0; attempt < 60; attempt++)
            {
                try
                {
                    using var parent = System.Diagnostics.Process.GetProcessById(waitPid);
                    if (parent.HasExited)
                        break;
                }
                catch (ArgumentException)
                {
                    break;
                }

                await Task.Delay(TimeSpan.FromSeconds(1)).ConfigureAwait(false);
            }
        }

        var copied = 0;
        foreach (var source in Directory.EnumerateFiles(staged, "*", SearchOption.AllDirectories))
        {
            var relative = Path.GetRelativePath(staged, source);
            var target = Path.Combine(installDirectory, relative);
            Directory.CreateDirectory(Path.GetDirectoryName(target)!);
            File.Copy(source, target, overwrite: true);
            copied++;
        }

        Console.WriteLine($"apply-update: replaced {copied} file(s) in {installDirectory}");
        var started = await controller.StartAsync(CancellationToken.None).ConfigureAwait(false);
        Console.WriteLine(started
            ? $"apply-update: {serviceName} restarted on the new build"
            : $"apply-update: {serviceName} did not report running; start it with \"leikwan start\"");
        return started ? 0 : 1;
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

    private enum ServiceMode
    {
        Console,
        Service,
        Install,
        Uninstall,
    }
}
