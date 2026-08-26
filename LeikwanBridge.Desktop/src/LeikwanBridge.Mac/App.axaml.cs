using Avalonia;
using Avalonia.Controls;
using Avalonia.Controls.ApplicationLifetimes;
using Avalonia.Markup.Xaml;
using Avalonia.Platform;
using Avalonia.Threading;
using LeikwanBridge.Mac.Services;
using LeikwanBridge.Mac.ViewModels;
using LeikwanBridge.Mac.Views;
using LeikwanBridge.Core.Models;
using Serilog;

namespace LeikwanBridge.Mac;

public sealed partial class App : Application
{
    private MacAppServices? _services;
    private MainWindow? _window;
    private TrayIcon? _trayIcon;
    private bool _quitStarted;
    private ClientAppArtifact? _availableUpdate;

    public static MacPlatformPaths PlatformPaths { get; set; } = null!;
    public static string UiFixtureState { get; set; } = string.Empty;
    public static int UiFixtureWidth { get; set; } = 1100;
    public static int UiFixtureHeight { get; set; } = 720;

    public override void Initialize() => AvaloniaXamlLoader.Load(this);

    public override void OnFrameworkInitializationCompleted()
    {
        if (ApplicationLifetime is IClassicDesktopStyleApplicationLifetime desktop)
        {
            if (!string.IsNullOrEmpty(UiFixtureState))
            {
                InitializeUiFixture(desktop);
                base.OnFrameworkInitializationCompleted();
                return;
            }
            desktop.ShutdownMode = ShutdownMode.OnExplicitShutdown;
            _ = InitializeDesktopAsync(desktop);
        }
        base.OnFrameworkInitializationCompleted();
    }

    private static void InitializeUiFixture(IClassicDesktopStyleApplicationLifetime desktop)
    {
        desktop.ShutdownMode = ShutdownMode.OnMainWindowClose;
        var controller = new MacUiFixtureController(UiFixtureState);
        var viewModel = new MainWindowViewModel(
            controller,
            closeToTray: false,
            dispatch: action => action());
        if (string.Equals(UiFixtureState, "settings", StringComparison.Ordinal))
            viewModel.ShowSettingsCommand.Execute(null);
        var window = new MainWindow
        {
            DataContext = viewModel,
            Width = UiFixtureWidth,
            Height = UiFixtureHeight,
            AllowClose = true,
        };
        desktop.MainWindow = window;
        window.Show();
    }

    private async Task InitializeDesktopAsync(IClassicDesktopStyleApplicationLifetime desktop)
    {
        try
        {
            _services = await MacAppBootstrap.CreateAsync(PlatformPaths);
            var config = _services.Config;
            var viewModel = new MainWindowViewModel(
                _services.Controller,
                _services.Autostart,
                config.MinimizeToTray,
                config.Language,
                async (panelUrl, language, closeToTray, ct) =>
                {
                    config.MasterUrl = panelUrl.TrimEnd('/');
                    config.Language = language;
                    config.MinimizeToTray = closeToTray;
                    await _services.ConfigStore.SaveAsync(config, ct);
                },
                ct => CheckForUpdatesAsync(ct, notifyWhenCurrent: true),
                ct => InstallUpdateAsync(desktop, ct));
            _window = new MainWindow { DataContext = viewModel };
            _window.ExitRequested += (_, _) => _ = QuitAsync(desktop);
            desktop.MainWindow = _window;

            viewModel.TrayAvailable = TryCreateTray(desktop, viewModel);
            var background = desktop.Args?.Any(arg => string.Equals(arg, "--background", StringComparison.Ordinal)) == true;
            if (!background || !viewModel.TrayAvailable)
                _window.Show();

            if (_services.Controller.Snapshot.IsSignedIn
                && !_services.Controller.Snapshot.RequiresClientSelection
                && !string.IsNullOrWhiteSpace(config.AgentToken))
            {
                try
                {
                    await _services.Controller.ConnectAsync();
                }
                catch
                {
                    Log.Warning("startup_connect_failed");
                }
            }
            if (!config.DisableAutoUpdates && !string.IsNullOrWhiteSpace(config.MasterUrl))
                _ = CheckForUpdatesAsync(CancellationToken.None, notifyWhenCurrent: false);
        }
        catch
        {
            Log.Error("mac_app_initialization_failed");
            var errorWindow = BuildStartupErrorWindow();
            desktop.MainWindow = errorWindow;
            errorWindow.Closed += (_, _) => desktop.Shutdown(1);
            errorWindow.Show();
        }
    }

    private bool TryCreateTray(
        IClassicDesktopStyleApplicationLifetime desktop,
        MainWindowViewModel viewModel)
    {
        try
        {
            using var stream = AssetLoader.Open(new Uri("avares://LeikwanBridge.Mac/Assets/app-icon.png"));
            var show = new NativeMenuItem("Show LeiKwan Bridge");
            show.Click += (_, _) => RestoreWindow();
            var startAll = new NativeMenuItem("Start All") { Command = viewModel.StartAllCommand };
            var stopAll = new NativeMenuItem("Stop All") { Command = viewModel.StopAllCommand };
            var quit = new NativeMenuItem("Quit");
            quit.Click += (_, _) => _ = QuitAsync(desktop);
            var menu = new NativeMenu();
            menu.Items.Add(show);
            menu.Items.Add(new NativeMenuItemSeparator());
            menu.Items.Add(startAll);
            menu.Items.Add(stopAll);
            menu.Items.Add(new NativeMenuItemSeparator());
            menu.Items.Add(quit);
            _trayIcon = new TrayIcon
            {
                Icon = new WindowIcon(stream),
                ToolTipText = "LeiKwan Bridge",
                Menu = menu,
                IsVisible = true,
            };
            _trayIcon.Clicked += (_, _) => RestoreWindow();
            return true;
        }
        catch
        {
            Log.Warning("tray_unavailable");
            _trayIcon?.Dispose();
            _trayIcon = null;
            return false;
        }
    }

    private void RestoreWindow()
    {
        if (_window is null)
            return;
        _window.ShowInTaskbar = true;
        _window.Show();
        if (_window.WindowState == WindowState.Minimized)
            _window.WindowState = WindowState.Normal;
        _window.Activate();
    }

    private async Task CheckForUpdatesAsync(CancellationToken ct, bool notifyWhenCurrent)
    {
        if (_services is null)
            return;
        try
        {
            var currentVersion = typeof(App).Assembly.GetName().Version?.ToString(3) ?? "1.0.0";
            _availableUpdate = await _services.Updater.CheckAsync(
                _services.Config.MasterUrl,
                currentVersion,
                ct);
            if (_availableUpdate is null)
            {
                if (notifyWhenCurrent)
                    _services.Controller.PublishNotice("LeiKwan Bridge is up to date.");
                return;
            }
            _services.Controller.PublishUpdateAvailable(_availableUpdate.Version);
        }
        catch
        {
            Log.Warning("update_check_failed");
            _services.Controller.PublishNotice("The update check could not be completed.");
        }
    }

    private async Task InstallUpdateAsync(
        IClassicDesktopStyleApplicationLifetime desktop,
        CancellationToken ct)
    {
        if (_services is null || _availableUpdate is null)
            return;
        try
        {
            var prepared = await _services.Updater.PrepareAsync(_availableUpdate, ct);
            var result = await _services.Updater.ApplyAsync(prepared, deferUntilRestart: false, ct);
            if (result.RequiresManualInstall)
            {
                _services.Controller.PublishNotice("A verified disk image was opened for manual replacement.");
                return;
            }
            if (result.Started)
                await QuitAsync(desktop);
        }
        catch
        {
            Log.Warning("update_install_failed");
            _services.Controller.PublishNotice("The update could not be installed.");
        }
    }

    private async Task QuitAsync(IClassicDesktopStyleApplicationLifetime desktop)
    {
        if (_quitStarted)
            return;
        _quitStarted = true;
        if (_window is not null)
            _window.AllowClose = true;
        _trayIcon?.Dispose();
        _trayIcon = null;
        if (_services is not null)
        {
            await _services.Controller.DisposeAsync();
            _services.Updater.Dispose();
        }
        await Dispatcher.UIThread.InvokeAsync(() => desktop.Shutdown());
    }

    private static Window BuildStartupErrorWindow()
    {
        return new Window
        {
            Title = "LeiKwan Bridge",
            Width = 520,
            Height = 220,
            CanResize = false,
            WindowStartupLocation = WindowStartupLocation.CenterScreen,
            Content = new StackPanel
            {
                Margin = new Thickness(32),
                Spacing = 12,
                Children =
                {
                    new TextBlock { Text = "LeiKwan Bridge could not start.", FontSize = 20, FontWeight = Avalonia.Media.FontWeight.SemiBold },
                    new TextBlock { Text = "Check the application log and restart the app.", TextWrapping = Avalonia.Media.TextWrapping.Wrap },
                },
            },
        };
    }
}
