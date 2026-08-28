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

    public static string ConfigPath { get; private set; } = "";
    public static AgentConfig Config { get; private set; } = new();

    protected override void OnStartup(StartupEventArgs e)
    {
        // Single instance check
        _mutex = new Mutex(true, "LeikwanBridge_SingleInstance", out bool createdNew);
        if (!createdNew)
        {
            System.Windows.MessageBox.Show("Leikwan Bridge is already running. Look for it in the system tray.", "Leikwan Bridge",
                MessageBoxButton.OK, MessageBoxImage.Information);
            Shutdown();
            return;
        }

        // Setup logging
        var logDir = Path.Combine(
            Environment.GetFolderPath(Environment.SpecialFolder.CommonApplicationData),
            "Leikwan", "ClientAgent", "logs");
        Directory.CreateDirectory(logDir);

        Log.Logger = new LoggerConfiguration()
            .MinimumLevel.Debug()
            .WriteTo.File(Path.Combine(logDir, "leikwan-gui-.log"),
                rollingInterval: RollingInterval.Day,
                retainedFileCountLimit: 7)
            .CreateLogger();

        Log.Information("Leikwan Bridge GUI starting...");

        // Load config
        var configDir = Path.Combine(
            Environment.GetFolderPath(Environment.SpecialFolder.CommonApplicationData),
            "Leikwan", "ClientAgent");
        Directory.CreateDirectory(configDir);
        ConfigPath = Path.Combine(configDir, "config.json");

        var stateManager = new StateManager(configDir);
        Config = stateManager.LoadConfig(ConfigPath);
        if (Config.MigrateLegacyUltraBin(AppDefaults.GetInstallRoot()))
            stateManager.SaveConfig(ConfigPath, Config);
        var normalizedMasterUrl = AppDefaults.NormalizeMasterUrl(Config.MasterUrl);
        if (!string.Equals(normalizedMasterUrl, Config.MasterUrl, StringComparison.Ordinal))
        {
            Config.MasterUrl = normalizedMasterUrl;
            stateManager.SaveConfig(ConfigPath, Config);
        }

        // Apply language
        SwitchLanguage(Config.Language);

        base.OnStartup(e);
    }

    protected override void OnExit(ExitEventArgs e)
    {
        Log.Information("Leikwan Bridge GUI shutting down.");
        Log.CloseAndFlush();
        _mutex?.ReleaseMutex();
        _mutex?.Dispose();
        base.OnExit(e);
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
