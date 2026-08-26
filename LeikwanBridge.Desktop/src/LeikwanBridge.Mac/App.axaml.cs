using Avalonia;
using Avalonia.Controls;
using Avalonia.Controls.ApplicationLifetimes;
using Avalonia.Markup.Xaml;
using Avalonia.Platform;
using Avalonia.Threading;
using LeikwanBridge.Mac.Services;
using LeikwanBridge.Mac.ViewModels;
using LeikwanBridge.Mac.Views;
using Serilog;

namespace LeikwanBridge.Mac;

public sealed partial class App : Application
{
    private MacAppServices? _services;
    private MainWindow? _window;
    private TrayIcon? _trayIcon;
    private bool _quitStarted;

    public static MacPlatformPaths PlatformPaths { get; set; } = null!;

    public override void Initialize() => AvaloniaXamlLoader.Load(this);

    public override void OnFrameworkInitializationCompleted()
    {
        if (ApplicationLifetime is IClassicDesktopStyleApplicationLifetime desktop)
        {
            desktop.ShutdownMode = ShutdownMode.OnExplicitShutdown;
            _ = InitializeDesktopAsync(desktop);
        }
        base.OnFrameworkInitializationCompleted();
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
                CheckForUpdatesAsync);
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

    private Task CheckForUpdatesAsync(CancellationToken ct)
    {
        ct.ThrowIfCancellationRequested();
        Log.Information("manual_update_check_requested");
        return Task.CompletedTask;
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
            await _services.Controller.DisposeAsync();
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
