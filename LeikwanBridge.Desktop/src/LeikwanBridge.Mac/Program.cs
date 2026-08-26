using Avalonia;
using Serilog;

namespace LeikwanBridge.Mac;

internal static class Program
{
    private static MacSingleInstance? _singleInstance;

    [STAThread]
    public static int Main(string[] args)
    {
        MacProcessSecurity.DisableCoreDumps();
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
}
