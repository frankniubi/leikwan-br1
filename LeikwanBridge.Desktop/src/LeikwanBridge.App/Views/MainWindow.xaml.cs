using System.ComponentModel;
using System.Diagnostics;
using System.IO;
using System.Windows;
using System.Windows.Controls;
using Drawing = System.Drawing;
using Forms = System.Windows.Forms;
using LeikwanBridge.App.Services;
using LeikwanBridge.App.ViewModels;
using LeikwanBridge.Core.Models;
using LeikwanBridge.Core.Services;
using Serilog;

namespace LeikwanBridge.App.Views;

public partial class MainWindow : Window
{
    private readonly MainViewModel _vm;
    private readonly TrayWindowLifecycle _trayLifecycle = new();
    private Forms.NotifyIcon? _trayIcon;
    private bool _trayReady;

    public MainWindow()
    {
        InitializeComponent();
        _vm = (MainViewModel)DataContext;
        _vm.Initialize(App.Config);
        _vm.PropertyChanged += OnViewModelPropertyChanged;

        // Auto-scroll log
        _vm.LogEntries.CollectionChanged += (_, _) =>
        {
            if (LogListBox.Items.Count > 0)
                LogListBox.ScrollIntoView(LogListBox.Items[^1]);
        };

        // Populate settings fields
        PopulateSettings();
        UpdateVisiblePanel();
        TxtMasterUrl.Text = string.IsNullOrWhiteSpace(App.Config.MasterUrl)
            ? AppDefaults.DefaultMasterUrl
            : App.Config.MasterUrl;

        Loaded += (_, _) =>
        {
            EnsureTrayIcon();
            TryAutoConnect();
        };
        StateChanged += OnWindowStateChanged;
    }

    private void OnConnect_Click(object sender, RoutedEventArgs e)
    {
        var masterUrl = TxtMasterUrl.Text.Trim();
        if (!int.TryParse(TxtClientId.Text.Trim(), out int clientId) || clientId <= 0)
        {
            SetupStatus.Text = "Invalid Client ID.";
            return;
        }
        var agentToken = TxtAgentToken.Password.Trim();

        if (string.IsNullOrWhiteSpace(masterUrl) || string.IsNullOrWhiteSpace(agentToken))
        {
            SetupStatus.Text = "All fields are required.";
            return;
        }

        SetupStatus.Text = FindResource("SetupConnecting") as string ?? "Connecting...";

        App.Config.MasterUrl = masterUrl;
        App.Config.ClientId = clientId;
        App.Config.AgentToken = agentToken;

        // Save config
        var stateManager = new StateManager(Path.GetDirectoryName(App.ConfigPath)!);
        stateManager.SaveConfig(App.ConfigPath, App.Config);

        _vm.Initialize(App.Config);
        _vm.ConnectCommand.Execute(null);
    }

    private void OnForwardCardClick(object sender, System.Windows.Input.MouseButtonEventArgs e)
    {
        if (sender is FrameworkElement element && element.DataContext is ForwardViewModel forward)
        {
            _vm.SelectForwardCommand.Execute(forward);
        }
    }

    private void OnSaveSettings_Click(object sender, RoutedEventArgs e)
    {
        App.Config.MasterUrl = SettMasterUrl.Text.Trim();
        if (int.TryParse(SettClientId.Text.Trim(), out int cid))
            App.Config.ClientId = cid;
        App.Config.AgentToken = SettToken.Text.Trim();
        if (int.TryParse(SettPollInterval.Text.Trim(), out int poll) && poll > 0)
            App.Config.PollIntervalSeconds = poll;
        if (int.TryParse(SettHealthInterval.Text.Trim(), out int health) && health > 0)
            App.Config.HealthCheckIntervalSeconds = health;
        App.Config.AutoStart = SettAutoStart.IsChecked == true;
        App.Config.MinimizeToTray = SettMinimizeTray.IsChecked == true;

        if (SettLanguage.SelectedItem is ComboBoxItem lang)
        {
            var langTag = lang.Tag?.ToString() ?? "en-US";
            App.Config.Language = langTag;
            App.SwitchLanguage(langTag);
        }

        var stateManager = new StateManager(Path.GetDirectoryName(App.ConfigPath)!);
        stateManager.SaveConfig(App.ConfigPath, App.Config);

        // Handle auto-start
        SetAutoStart(App.Config.AutoStart);

        SettingsStatus.Text = FindResource("SettingsSaved") as string ?? "Settings saved.";
    }

    private void OnViewModelPropertyChanged(object? sender, PropertyChangedEventArgs e)
    {
        if (e.PropertyName == nameof(MainViewModel.CurrentView))
        {
            UpdateVisiblePanel();
        }
        if (e.PropertyName == nameof(MainViewModel.ActiveForwardCount)
            || e.PropertyName == nameof(MainViewModel.ConnectionStatus))
        {
            UpdateTrayText();
        }
        if (e.PropertyName == nameof(MainViewModel.ShowSetupWizard))
        {
            if (!_vm.ShowSetupWizard)
                PopulateSettings();
        }
    }

    private void UpdateVisiblePanel()
    {
        DashboardPanel.Visibility = _vm.CurrentView == "Dashboard" ? Visibility.Visible : Visibility.Collapsed;
        LogPanel.Visibility = _vm.CurrentView == "Log" ? Visibility.Visible : Visibility.Collapsed;
        SettingsPanel.Visibility = _vm.CurrentView == "Settings" ? Visibility.Visible : Visibility.Collapsed;

        // Forwards view uses the same dashboard panel (forwards section)
        if (_vm.CurrentView == "Forwards")
            DashboardPanel.Visibility = Visibility.Visible;
    }

    private void PopulateSettings()
    {
        SettMasterUrl.Text = string.IsNullOrWhiteSpace(App.Config.MasterUrl)
            ? AppDefaults.DefaultMasterUrl
            : App.Config.MasterUrl;
        SettClientId.Text = App.Config.ClientId.ToString();
        SettToken.Text = App.Config.AgentToken;
        SettPollInterval.Text = App.Config.PollIntervalSeconds.ToString();
        SettHealthInterval.Text = App.Config.HealthCheckIntervalSeconds.ToString();
        SettAutoStart.IsChecked = App.Config.AutoStart;
        SettMinimizeTray.IsChecked = App.Config.MinimizeToTray;

        for (int i = 0; i < SettLanguage.Items.Count; i++)
        {
            if (SettLanguage.Items[i] is ComboBoxItem item &&
                item.Tag?.ToString() == App.Config.Language)
            {
                SettLanguage.SelectedIndex = i;
                break;
            }
        }
    }

    private static void SetAutoStart(bool enable)
    {
        try
        {
            using var key = Microsoft.Win32.Registry.CurrentUser.OpenSubKey(
                @"SOFTWARE\Microsoft\Windows\CurrentVersion\Run", true);
            if (key == null) return;

            if (enable)
            {
                var exePath = System.Diagnostics.Process.GetCurrentProcess().MainModule?.FileName;
                if (!string.IsNullOrEmpty(exePath))
                    key.SetValue("LeikwanBridge", $"\"{exePath}\"");
            }
            else
            {
                key.DeleteValue("LeikwanBridge", false);
            }
        }
        catch (Exception ex)
        {
            Log.Warning(ex, "failed to set auto-start registry key");
        }
    }

    protected override void OnClosing(CancelEventArgs e)
    {
        if (_trayLifecycle.ShouldHideOnClose(App.Config.MinimizeToTray, _trayReady))
        {
            e.Cancel = true;
            HideToTray();
        }
        else
        {
            _trayLifecycle.MarkClosing();
            _vm.DisconnectCommand.Execute(null);
            DisposeTrayIcon();
        }
        base.OnClosing(e);
    }

    protected override void OnClosed(EventArgs e)
    {
        DisposeTrayIcon();
        base.OnClosed(e);
    }

    private void OnWindowStateChanged(object? sender, EventArgs e)
    {
        if (_trayLifecycle.IsClosing || !_trayReady || !App.Config.MinimizeToTray)
            return;

        if (WindowState == WindowState.Minimized)
            HideToTray();
    }

    private void EnsureTrayIcon()
    {
        if (_trayReady || _trayIcon != null)
            return;

        try
        {
            var menu = new Forms.ContextMenuStrip();
            menu.Items.Add(GetString("TrayShow"), null, (_, _) => RestoreFromTray());
            menu.Items.Add(GetString("TrayExit"), null, (_, _) => ExitFromTray());

            var executablePath = Process.GetCurrentProcess().MainModule?.FileName ?? string.Empty;
            var iconSource = BrandingAssets.ResolveTrayIconSource(AppContext.BaseDirectory, executablePath);
            var trayIcon = Drawing.Icon.ExtractAssociatedIcon(iconSource) ?? Drawing.SystemIcons.Application;

            _trayIcon = new Forms.NotifyIcon
            {
                Icon = trayIcon,
                Visible = true,
                Text = BuildTrayText(),
                ContextMenuStrip = menu,
            };
            _trayIcon.DoubleClick += (_, _) => RestoreFromTray();
            _trayReady = true;
        }
        catch (Exception ex)
        {
            _trayReady = false;
            Log.Warning(ex, "failed to initialize tray icon");
        }
    }

    private void TryAutoConnect()
    {
        if (string.Equals(App.Config.MasterUrl, AppDefaults.LegacyMasterUrl, StringComparison.OrdinalIgnoreCase))
        {
            App.Config.MasterUrl = AppDefaults.DefaultMasterUrl;
            var stateManager = new StateManager(Path.GetDirectoryName(App.ConfigPath)!);
            stateManager.SaveConfig(App.ConfigPath, App.Config);
            TxtMasterUrl.Text = App.Config.MasterUrl;
            SettMasterUrl.Text = App.Config.MasterUrl;
        }

        if (_vm.ShouldAutoConnectOnStartup)
        {
            _vm.ConnectCommand.Execute(null);
        }
    }

    private void DisposeTrayIcon()
    {
        if (_trayIcon == null)
            return;

        try
        {
            _trayIcon.Visible = false;
            _trayIcon.Dispose();
        }
        catch (Exception ex)
        {
            Log.Warning(ex, "failed to dispose tray icon");
        }
        finally
        {
            _trayIcon = null;
            _trayReady = false;
        }
    }

    private void HideToTray()
    {
        if (_trayLifecycle.IsClosing)
            return;

        ShowInTaskbar = false;
        Hide();
        UpdateTrayText();
    }

    private void RestoreFromTray()
    {
        if (!_trayLifecycle.CanRestore)
            return;

        ShowInTaskbar = true;
        if (!IsVisible)
            Show();
        if (WindowState == WindowState.Minimized)
            WindowState = WindowState.Normal;

        Activate();
        Focus();
    }

    private void ExitFromTray()
    {
        if (_trayLifecycle.IsClosing)
            return;

        _trayLifecycle.RequestExit();
        ShowInTaskbar = true;
        DisposeTrayIcon();
        Close();
    }

    private void UpdateTrayText()
    {
        if (_trayIcon == null)
            return;

        _trayIcon.Text = BuildTrayText();
    }

    private string BuildTrayText()
    {
        var baseText = "Leikwan Bridge";
        if (_vm.ActiveForwardCount <= 0)
            return baseText;

        var template = TryFindResource("TrayForwardsActive") as string ?? "{0} forwards active";
        var suffix = string.Format(template, _vm.ActiveForwardCount);
        var text = $"{baseText} - {suffix}";
        return text.Length > 63 ? text[..63] : text;
    }

    private string GetString(string key)
    {
        return TryFindResource(key) as string ?? key;
    }
}

