using System.IO;
using System.Threading;
using System.Windows;
using LeikwanBridge.Core.Models;
using LeikwanBridge.Core.Services;
using Serilog;

namespace LeikwanBridge.App;

public partial class App : System.Windows.Application
{
    private static Mutex? _mutex;
    private static WindowsProxyStartRequest? _startupProxyRequest;

    public static string ConfigPath { get; private set; } = "";
    public static AgentConfig Config { get; private set; } = new();
    private static string _logDirectory = "";

    public static WindowsProxyStartRequest? ConsumeStartupProxyRequest()
    {
        var request = _startupProxyRequest;
        _startupProxyRequest = null;
        return request;
    }

    protected override void OnStartup(StartupEventArgs e)
    {
        // A process-mode elevation starts a second copy with a short-lived
        // request.  Let the original UI release its mutex before deciding
        // that the elevated copy is a duplicate instance.
        _startupProxyRequest = WindowsProxyElevation.TryParseProxyStartRequest(
            e.Args,
            out var startupProxyRequest)
            ? startupProxyRequest
            : null;
        if (!TryAcquireSingleInstanceMutex(_startupProxyRequest is not null))
        {
            System.Windows.MessageBox.Show("Leikwan Bridge is already running. Look for it in the system tray.", "Leikwan Bridge",
                MessageBoxButton.OK, MessageBoxImage.Information);
            Shutdown();
            return;
        }

        // Setup logging
        var sharedLogDir = Path.Combine(
            Environment.GetFolderPath(Environment.SpecialFolder.CommonApplicationData),
            "Leikwan", "ClientAgent", "logs");
        var logDir = ResolveWritableLogDirectory(sharedLogDir);

        ConfigureLogging(logDir, DesktopLogLevel.Warning);

        Log.Information("Leikwan Bridge GUI starting...");
        if (!string.Equals(logDir, sharedLogDir, StringComparison.OrdinalIgnoreCase))
        {
            Log.Warning("shared log directory {SharedLogDir} is not writable; logging to {LogDir}", sharedLogDir, logDir);
        }

        // Panel/update traffic must egress through the physical NIC even
        // when a third-party TUN tool holds the default route.
        PanelDirectEgress.Initialize();

        // Load config
        var configDir = Path.Combine(
            Environment.GetFolderPath(Environment.SpecialFolder.CommonApplicationData),
            "Leikwan", "ClientAgent");
        Directory.CreateDirectory(configDir);
        ConfigPath = Path.Combine(configDir, "config.json");

        var stateManager = new StateManager(configDir);
        Config = stateManager.LoadConfig(ConfigPath);
        var normalizedLogLevel = DesktopLogLevel.Normalize(Config.LogLevel);
        var configChanged = !string.Equals(Config.LogLevel, normalizedLogLevel, StringComparison.Ordinal);
        Config.LogLevel = normalizedLogLevel;
        ConfigureLogging(logDir, Config.LogLevel);
        if (Config.MigrateLegacyUltraBin(AppDefaults.GetInstallRoot()))
            configChanged = true;
        if (configChanged)
            TrySaveConfig(stateManager, Config);
        var normalizedMasterUrl = AppDefaults.NormalizeMasterUrl(Config.MasterUrl);
        if (!string.Equals(normalizedMasterUrl, Config.MasterUrl, StringComparison.Ordinal))
        {
            Config.MasterUrl = normalizedMasterUrl;
            TrySaveConfig(stateManager, Config);
        }

        TryPruneInstallerCache();

        // Apply language
        SwitchLanguage(Config.Language);

        base.OnStartup(e);
    }

    // Installer downloads accumulate one ~70 MB setup per release in the per-user
    // cache; keep only what the client can still act on (the running build and a
    // deferred update) and drop the rest.
    private static void TryPruneInstallerCache()
    {
        try
        {
            var installRoot = Path.Combine(
                Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData),
                "LeiKwan Bridge");
            var removed = WindowsAppUpdateService.PruneCachedInstallers(
                installRoot,
                GetCurrentAppVersion(),
                Config.PendingUpdateInstallerPath);
            if (removed.Count > 0)
            {
                Log.Information("pruned {Count} stale cached installer(s)", removed.Count);
            }
        }
        catch (Exception ex)
        {
            Log.Warning(ex, "could not prune the installer cache");
        }
    }

    private static string GetCurrentAppVersion()
    {
        var exePath = Environment.ProcessPath;
        if (!string.IsNullOrWhiteSpace(exePath))
        {
            var fileVersion = System.Diagnostics.FileVersionInfo.GetVersionInfo(exePath).FileVersion;
            if (!string.IsNullOrWhiteSpace(fileVersion))
                return fileVersion;
        }

        return typeof(App).Assembly.GetName().Version?.ToString() ?? "0.0.0.0";
    }

    // A log file written by an elevated run is owned by Administrators and stays
    // read-only for the interactive user, so the next non-elevated run cannot
    // open it. Serilog fails silently in that case and the client runs with no
    // log at all, which is the worst possible state to debug a startup problem
    // from. Probe the exact file the sink will open and fall back to a per-user
    // directory the client always owns.
    private static string ResolveWritableLogDirectory(string preferredDirectory)
    {
        try
        {
            Directory.CreateDirectory(preferredDirectory);
            var dayFile = Path.Combine(preferredDirectory, $"leikwan-gui-{DateTime.Now:yyyyMMdd}.log");
            using (new FileStream(dayFile, FileMode.Append, FileAccess.Write, FileShare.ReadWrite))
            {
            }

            return preferredDirectory;
        }
        catch (Exception)
        {
            var fallback = Path.Combine(
                Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData),
                "LeiKwan Bridge", "logs");
            try
            {
                Directory.CreateDirectory(fallback);
                return fallback;
            }
            catch (Exception)
            {
                return preferredDirectory;
            }
        }
    }

    // The shared config lives under %ProgramData%\Leikwan\ClientAgent, so a
    // single elevated run is enough to leave config.json owned by
    // BUILTIN\Administrators with the interactive user read-only. An
    // unhandled failure here killed the process before it wrote a single log
    // line, which is indistinguishable from "the app will not start any more"
    // and only "fixed" by running the client as administrator. The tunnel only
    // needs the in-memory copy, so a read-only config must never be fatal.
    private static void TrySaveConfig(StateManager stateManager, AgentConfig config)
    {
        try
        {
            stateManager.SaveConfig(ConfigPath, config);
        }
        catch (Exception ex)
        {
            Log.Warning(ex, "config could not be persisted; continuing with the in-memory config");
        }
    }

    protected override void OnExit(ExitEventArgs e)
    {
        Log.Information("Leikwan Bridge GUI shutting down.");
        PanelDirectEgress.Shutdown();
        Log.CloseAndFlush();
        try { _mutex?.ReleaseMutex(); }
        catch (ApplicationException) { }
        _mutex?.Dispose();
        base.OnExit(e);
    }

    internal static void ApplyLogLevel(string? level)
    {
        Config.LogLevel = DesktopLogLevel.Normalize(level);
        if (!string.IsNullOrWhiteSpace(_logDirectory))
            ConfigureLogging(_logDirectory, Config.LogLevel);
    }

    private static void ConfigureLogging(string logDirectory, string? level)
    {
        Log.CloseAndFlush();
        _logDirectory = logDirectory;
        Log.Logger = new LoggerConfiguration()
            .MinimumLevel.Is(DesktopLogLevel.ToSerilog(level))
            .WriteTo.File(
                new RedactingTextFormatter("{Timestamp:yyyy-MM-dd HH:mm:ss} [{Level:u3}] {Message:lj}{NewLine}{Exception}"),
                Path.Combine(logDirectory, "leikwan-gui-.log"),
                rollingInterval: RollingInterval.Day,
                retainedFileCountLimit: 7)
            .CreateLogger();
    }

    private static bool TryAcquireSingleInstanceMutex(bool waitForExisting)
    {
        const int waitAttempts = 100;
        var attempts = waitForExisting ? waitAttempts : 1;
        for (var attempt = 0; attempt < attempts; attempt++)
        {
            var candidate = new Mutex(true, "LeikwanBridge_SingleInstance", out var createdNew);
            if (createdNew)
            {
                _mutex = candidate;
                return true;
            }

            candidate.Dispose();
            if (attempt + 1 < attempts)
                Thread.Sleep(TimeSpan.FromMilliseconds(100));
        }

        return false;
    }

    public static void SwitchLanguage(string lang)
    {
        var dict = new ResourceDictionary();
        try
        {
            dict.Source = lang switch
            {
                "zh-CN" => new Uri("Resources/Strings/zh-CN.xaml", UriKind.Relative),
                _ => new Uri("Resources/Strings/en-US.xaml", UriKind.Relative),
            };
        }
        catch
        {
            dict.Source = new Uri("Resources/Strings/en-US.xaml", UriKind.Relative);
        }

        // Remove existing string dictionary and add new one
        var merged = Current.Resources.MergedDictionaries;
        for (int i = merged.Count - 1; i >= 0; i--)
        {
            var src = merged[i].Source?.ToString() ?? "";
            if (src.Contains("Strings/"))
                merged.RemoveAt(i);
        }
        merged.Add(dict);
    }
}
