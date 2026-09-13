using Avalonia;
using Serilog;
using System.Diagnostics;
using System.Runtime.InteropServices;

namespace LeikwanBridge.Mac;

internal static class Program
{
    private static MacSingleInstance? _singleInstance;

    [STAThread]
    public static int Main(string[] args)
    {
        MacProcessSecurity.DisableCoreDumps();
        if (OperatingSystem.IsMacOS() && !OperatingSystem.IsMacOSVersionAtLeast(12))
        {
            ShowUnsupportedOsNotice();
            return 1;
        }
        // `leikwan <command>` — the operator surface for the windowless client.
        if (args.Length > 0 && string.Equals(args[0], "--cli", StringComparison.Ordinal))
        {
            var cliHome = Environment.GetFolderPath(Environment.SpecialFolder.UserProfile);
            var cliPaths = new Services.MacPlatformPaths(cliHome, ResolveBundleDirectory());
            var controller = new Services.MacServiceController(cliPaths);
            var updater = new Services.MacClientUpdateService(
                new Services.MacAppUpdateService(
                    cliPaths.Root.TrimEnd('/', '\\') + "/updates",
                    cliPaths.BundleDirectory,
                    RuntimeInformation.ProcessArchitecture == Architecture.Arm64 ? "arm64" : "x64"),
                controller,
                Services.MacHeadlessCli.PanelUrl(cliPaths));
            using var cliCts = new CancellationTokenSource();
            Console.CancelKeyPress += (_, eventArgs) =>
            {
                eventArgs.Cancel = true;
                cliCts.Cancel();
            };
            return LeikwanBridge.Core.Services.HeadlessCli
                .RunAsync(args[1..], controller, updater, Console.Out, Console.Error, cliCts.Token)
                .GetAwaiter().GetResult();
        }
        // The windowless client is a lifecycle variant of the same payload: it
        // never initialises Avalonia, so launchd can run it without a GUI
        // session while the Core services, lease handling and update pinning
        // stay byte-identical to the windowed application.
        if (args.Any(argument => string.Equals(argument, LeikwanBridge.Core.Services.HeadlessClientOptions.Flag, StringComparison.Ordinal)))
            return Services.MacHeadlessHost.RunAsync(args).GetAwaiter().GetResult();
        if (args.Length > 0 && string.Equals(args[0], "--ultra-leak-fixture", StringComparison.Ordinal))
            return Services.MacUltraLeakFixture.RunAsync(args[1..]).GetAwaiter().GetResult();
        if (TryConfigureUiFixture(args))
        {
            BuildAvaloniaApp().StartWithClassicDesktopLifetime([]);
            return 0;
        }
        var home = Environment.GetFolderPath(Environment.SpecialFolder.UserProfile);
        var paths = new Services.MacPlatformPaths(home, ResolveBundleDirectory());
        if (args.Length == 2 && string.Equals(args[0], "--apply-update", StringComparison.Ordinal))
        {
            return Services.MacAppUpdateApplier.ApplyFromRequestAsync(args[1]).GetAwaiter().GetResult()
                ? 0
                : 1;
        }
        if (Services.MacAppUpdateService.TryLaunchPendingUpdate(
                paths.Root.TrimEnd('/', '\\') + "/updates",
                Environment.ProcessId))
            return 0;
        _singleInstance = MacSingleInstance.TryAcquire(paths.Root);
        if (_singleInstance is null)
            return 0;
        App.PlatformPaths = paths;
        try
        {
            BuildAvaloniaApp().StartWithClassicDesktopLifetime(args);
            return 0;
        }
        catch (Exception exception)
        {
            // Logging may not be configured when Avalonia fails before the app
            // bootstrap. Preserve a local diagnostic for launchd and support.
            Console.Error.WriteLine($"mac_app_start_failed:{exception}");
            Log.Error("mac_app_start_failed:{ErrorType}", exception.GetType().Name);
            return 1;
        }
        finally
        {
            _singleInstance.Dispose();
            Log.CloseAndFlush();
        }
    }

    public static AppBuilder BuildAvaloniaApp()
        => AppBuilder.Configure<App>()
            .UsePlatformDetect()
            .WithInterFont()
            .LogToTrace();

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

    private static void ShowUnsupportedOsNotice()
    {
        try
        {
            var startInfo = new ProcessStartInfo
            {
                FileName = "/usr/bin/osascript",
                UseShellExecute = false,
                CreateNoWindow = true,
            };
            startInfo.ArgumentList.Add("-e");
            startInfo.ArgumentList.Add("display alert \"LeiKwan Bridge requires macOS 12 or later.\"");
            using var process = Process.Start(startInfo);
            process?.WaitForExit(10_000);
        }
        catch
        {
            Console.Error.WriteLine("LeiKwan Bridge requires macOS 12 or later.");
        }
    }

    private static bool TryConfigureUiFixture(string[] args)
    {
        if (args.Length == 0 || !string.Equals(args[0], "--ui-fixture", StringComparison.Ordinal))
            return false;
        if (!string.Equals(Environment.GetEnvironmentVariable("LEIKWAN_TEST_UI"), "1", StringComparison.Ordinal)
            || args.Length != 6
            || !int.TryParse(args[3], out var width)
            || !int.TryParse(args[5], out var height)
            || args[2] != "--window-width"
            || args[4] != "--window-height"
            || width is < 900 or > 1600
            || height is < 620 or > 1000
            || args[1] is not ("login" or "dashboard" or "forwards" or "settings"))
            throw new InvalidOperationException("ui_fixture_invalid");
        App.UiFixtureState = args[1];
        App.UiFixtureWidth = width;
        App.UiFixtureHeight = height;
        return true;
    }
}
