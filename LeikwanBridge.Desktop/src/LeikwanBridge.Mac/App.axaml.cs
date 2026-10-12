using Avalonia;
using Avalonia.Controls;
using Avalonia.Controls.ApplicationLifetimes;
using Avalonia.Markup.Xaml;
using Avalonia.Platform;
using Avalonia.Threading;
using LeikwanBridge.Mac.Localization;
using LeikwanBridge.Mac.Services;
using LeikwanBridge.Mac.ViewModels;
using LeikwanBridge.Mac.Views;
using LeikwanBridge.Core.Models;
using LeikwanBridge.Core.Services;
using Serilog;

namespace LeikwanBridge.Mac;

public sealed partial class App : Application
{
    private MacAppServices? _services;
    private MainWindow? _window;
    private TrayIcon? _trayIcon;
    private bool _quitStarted;
    private ClientAppArtifact? _availableUpdate;
    private ConnectionRecoverySupervisor? _connectionRecovery;

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
        // Screenshot-only knobs, honoured only behind LEIKWAN_TEST_UI=1 (see Program).
        var theme = MacAppearance.Normalize(Environment.GetEnvironmentVariable("LEIKWAN_TEST_UI_THEME"));
        MacAppearance.Apply(theme);
        var language = L.Normalize(Environment.GetEnvironmentVariable("LEIKWAN_TEST_UI_LANG"));
        L.Apply(language);
        var controller = new MacUiFixtureController(UiFixtureState);
        var traffic = new MacUiFixtureTraffic();
        var viewModel = new MainWindowViewModel(
            controller,
            closeToTray: false,
            dispatch: action => action(),
            language: language,
            themeMode: theme,
            trafficCounters: string.Equals(UiFixtureState, "login", StringComparison.Ordinal) ? null : traffic.Next,
            nodeLibrary: new MacUiFixtureNodeLibrary());
        switch (UiFixtureState)
        {
            case "settings":
                viewModel.ShowSettingsCommand.Execute(null);
                break;
            case "services":
                viewModel.ShowConnectionsCommand.Execute(null);
                break;
            case "nodes":
                viewModel.ShowNodesCommand.Execute(null);
                break;
            case "pairing":
                viewModel.ShowPairingCommand.Execute(null);
                break;
        }
        // Warm the speed chart and running timer so a capture shows live-looking data.
        var start = DateTimeOffset.UtcNow.AddSeconds(-TrafficMonitor.HistoryLength - 1);
        for (var second = 0; second <= TrafficMonitor.HistoryLength; second++)
            viewModel.Tick(start.AddSeconds(second));
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
            using var startupCts = new CancellationTokenSource(TimeSpan.FromSeconds(20));
            var services = await MacAppBootstrap.CreateAsync(PlatformPaths, startupCts.Token).ConfigureAwait(false);
            await Dispatcher.UIThread.InvokeAsync(() => InitializeDesktopUi(desktop, services));

            var config = services.Config;
            if (services.Controller.Snapshot.IsSignedIn
                && !services.Controller.Snapshot.RequiresClientSelection
                && !string.IsNullOrWhiteSpace(config.AgentToken))
            {
                try
                {
                    await services.Controller.ConnectAsync().ConfigureAwait(false);
                }
                catch (Exception exception)
                {
                    Log.Warning(
                        "startup_connect_failed type={ErrorType} code={ErrorCode}",
                        exception.GetType().Name,
                        BoundErrorCode(exception));
                    if (!_quitStarted)
                    {
                        _connectionRecovery = new ConnectionRecoverySupervisor(
                            ct => services.Controller.ConnectAsync(ct));
                        _ = _connectionRecovery.StartAsync();
                    }
                }
            }
            if (!config.DisableAutoUpdates && !string.IsNullOrWhiteSpace(config.MasterUrl))
                _ = CheckForUpdatesAsync(CancellationToken.None, notifyWhenCurrent: false);
        }
        catch (Exception exception)
        {
            Log.Error(exception, "mac_app_initialization_failed");
            await Dispatcher.UIThread.InvokeAsync(() => ShowStartupError(desktop));
        }
    }

    private void InitializeDesktopUi(
        IClassicDesktopStyleApplicationLifetime desktop,
        MacAppServices services)
    {
        _services = services;
        L.Apply(services.Config.Language);
        var uiPreferences = new MacUiPreferencesStore(PlatformPaths.Root.TrimEnd('/', '\\') + "/ui-preferences.json");
        var themeMode = uiPreferences.LoadThemeMode();
        MacAppearance.Apply(themeMode);
        var config = services.Config;
        var viewModel = new MainWindowViewModel(
            services.Controller,
            services.Connections,
            () => services.Controller.RuntimeForwards,
            services.Autostart,
            config.MinimizeToTray,
            config.Language,
            async (panelUrl, language, closeToTray, ct) =>
            {
                config.MasterUrl = panelUrl.TrimEnd('/');
                config.Language = language;
                config.MinimizeToTray = closeToTray;
                await services.ConfigStore.SaveAsync(config, ct).ConfigureAwait(false);
            },
            ct => CheckForUpdatesAsync(ct, notifyWhenCurrent: true),
            ct => InstallUpdateAsync(desktop, ct),
            rememberLoginInfo: config.RememberLoginInfo,
            savedClientId: config.ActiveClientId > 0 ? config.ActiveClientId : config.ClientId,
            savedAgentToken: config.AgentToken,
            rememberLoginInfoChanged: value => config.RememberLoginInfo = value,
            checkForUpdatesWithProgress: (progress, ct) => CheckForUpdatesAsync(ct, notifyWhenCurrent: true, progress),
            installUpdateWithProgress: (progress, ct) => InstallUpdateAsync(desktop, ct, progress),
            logLevel: config.LogLevel,
            saveLogLevel: async (level, ct) =>
            {
                config.LogLevel = DesktopLogLevel.Normalize(level);
                await services.ConfigStore.SaveAsync(config, ct).ConfigureAwait(false);
                MacAppBootstrap.ReconfigureLogging(PlatformPaths, config.LogLevel);
            },
            preferredEgressInterface: config.PreferredEgressInterface,
            saveEgressInterface: async (interfaceName, ct) =>
            {
                config.PreferredEgressInterface = interfaceName?.Trim() ?? string.Empty;
                PanelDirectEgress.SetPreferredInterface(config.PreferredEgressInterface);
                services.Connections.SetPreferredEgressInterface(config.PreferredEgressInterface);
                await services.ConfigStore.SaveAsync(config, ct).ConfigureAwait(false);
            },
            trafficCounters: new MacTrafficCounterReader(
                () => config.GetEffectiveStateDir(),
                PlatformPaths.RuntimeDirectory).Read,
            nodeLibrary: services.NodeLibrary,
            themeMode: themeMode,
            saveThemeMode: (mode, ct) => uiPreferences.SaveThemeModeAsync(mode, ct));
        _window = new MainWindow { DataContext = viewModel };
        _window.ExitRequested += (_, _) => _ = QuitAsync(desktop);
        desktop.MainWindow = _window;

        viewModel.TrayAvailable = TryCreateTray(desktop, viewModel);
        var background = desktop.Args?.Any(arg => string.Equals(arg, "--background", StringComparison.Ordinal)) == true;
        if (!background || !viewModel.TrayAvailable)
            _window.Show();
    }

    private static void ShowStartupError(IClassicDesktopStyleApplicationLifetime desktop)
    {
        var errorWindow = BuildStartupErrorWindow();
        desktop.MainWindow = errorWindow;
        errorWindow.Closed += (_, _) => desktop.Shutdown(1);
        errorWindow.Show();
    }

    private bool TryCreateTray(
        IClassicDesktopStyleApplicationLifetime desktop,
        MainWindowViewModel viewModel)
    {
        try
        {
            using var stream = AssetLoader.Open(new Uri("avares://LeikwanBridge.Mac/Assets/app-icon.png"));
            var show = new NativeMenuItem(L.T("TrayShow", "Show LeiKwan Bridge"));
            show.Click += (_, _) => RestoreWindow();
            var startAll = new NativeMenuItem(L.T("StartAllLabel", "Start all")) { Command = viewModel.StartAllCommand };
            var stopAll = new NativeMenuItem(L.T("StopAllLabel", "Stop all")) { Command = viewModel.StopAllCommand };
            var quit = new NativeMenuItem(L.T("TrayQuit", "Quit"));
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

    private MainWindowViewModel? UpdateViewModel
        => _window?.DataContext as MainWindowViewModel;

    private async Task CheckForUpdatesAsync(
        CancellationToken ct,
        bool notifyWhenCurrent,
        IProgress<UpdateProgress>? progress = null)
    {
        if (_services is null)
            return;
        try
        {
            // Prefer the live view-model selection so an endpoint change made
            // moments ago applies even before the config write settles.
            // Avalonia controls are thread-affine. Startup calls this method
            // after the bootstrap continuation has deliberately left the UI
            // thread, so reading DataContext here used to throw
            // `Call from invalid thread` and abort every automatic update check.
            var panelUrl = await Dispatcher.UIThread.InvokeAsync(() =>
            {
                var selected = UpdateViewModel?.PanelUrl;
                return string.IsNullOrWhiteSpace(selected)
                    ? _services.Config.MasterUrl
                    : selected!;
            });
            var currentVersion = typeof(App).Assembly.GetName().Version?.ToString(3) ?? "1.0.0";
            _availableUpdate = await _services.Updater.CheckAsync(
                panelUrl,
                currentVersion,
                progress,
                ct);
            await Dispatcher.UIThread.InvokeAsync(() =>
            {
                if (_availableUpdate is null)
                {
                    if (notifyWhenCurrent)
                        _services.Controller.PublishNotice(L.T("NoticeUpToDate", "LeiKwan Bridge is up to date."));
                    UpdateViewModel?.ReportUpdateFinished();
                    return;
                }

                _services.Controller.PublishUpdateAvailable(_availableUpdate.Version);
                UpdateViewModel?.ReportUpdateFinished();
            });
        }
        catch (Exception exception)
        {
            Log.Warning(exception, "update_check_failed");
            await Dispatcher.UIThread.InvokeAsync(() =>
            {
                _services.Controller.PublishNotice(L.T("NoticeUpdateCheckFailed", "The update check could not be completed."));
                UpdateViewModel?.ReportUpdateFailure(BoundErrorCode(exception), "check");
            });
        }
    }

    private static string BoundErrorCode(Exception exception)
    {
        var message = exception.Message.Replace('\r', ' ').Replace('\n', ' ').Trim();
        if (message.Length > 120)
            message = message[..120];
        return string.IsNullOrWhiteSpace(message) ? "unknown" : message;
    }

    private async Task InstallUpdateAsync(
        IClassicDesktopStyleApplicationLifetime desktop,
        CancellationToken ct,
        IProgress<UpdateProgress>? progress = null)
    {
        if (_services is null || _availableUpdate is null)
            return;
        try
        {
            var prepared = await _services.Updater.PrepareAsync(_availableUpdate, progress, ct);
            var result = await _services.Updater.ApplyAsync(prepared, deferUntilRestart: false, progress, ct);
            if (result.RequiresManualInstall)
            {
                await Dispatcher.UIThread.InvokeAsync(() =>
                {
                    _services.Controller.PublishNotice(L.T("NoticeManualInstall", "A verified disk image was opened for manual replacement."));
                    UpdateViewModel?.ReportUpdateFinished();
                });
                return;
            }
            if (result.Started)
                await QuitAsync(desktop);
        }
        catch (Exception exception)
        {
            Log.Warning(exception, "update_install_failed");
            await Dispatcher.UIThread.InvokeAsync(() =>
            {
                _services.Controller.PublishNotice(L.T("NoticeUpdateInstallFailed", "The update could not be installed."));
                UpdateViewModel?.ReportUpdateFailure(BoundErrorCode(exception), "install");
            });
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
            if (_connectionRecovery is not null)
                await _connectionRecovery.StopAsync();
            await _services.Connections.DisposeAsync();
            await _services.Controller.DisposeAsync();
            _services.Updater.Dispose();
        }
        PanelDirectEgress.Shutdown();
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
