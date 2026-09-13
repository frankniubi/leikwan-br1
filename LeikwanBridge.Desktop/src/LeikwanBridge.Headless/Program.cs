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
