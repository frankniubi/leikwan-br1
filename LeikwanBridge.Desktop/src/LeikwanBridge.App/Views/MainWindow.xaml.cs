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
    private readonly WindowsAppUpdateService _updateService = new();

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

        Loaded += async (_, _) =>
        {
            EnsureTrayIcon();
            if (await TryApplyPendingUpdateAsync())
                return;
            TryAutoConnect();
            if (WindowsAppUpdateService.ShouldCheckForUpdatesOnStartup(App.Config))
                await CheckForUpdatesAsync();
        };
        StateChanged += OnWindowStateChanged;
    }

    private async void OnConnect_Click(object sender, RoutedEventArgs e)
    {
        var masterUrl = TxtMasterUrl.Text.Trim();
        var email = TxtEmail.Text.Trim();
        var password = TxtPassword.Password.Trim();

        if (string.IsNullOrWhiteSpace(masterUrl) || string.IsNullOrWhiteSpace(email) || string.IsNullOrWhiteSpace(password))
        {
            SetupStatus.Text = "All fields are required.";
            return;
        }

        SetupStatus.Text = FindResource("SetupConnecting") as string ?? "Connecting...";

        try
        {
            using var api = new MasterApiClient(masterUrl, string.Empty);
            var login = await api.DesktopLoginAsync(email, password);
            _vm.Config.MasterUrl = masterUrl;
            _vm.CompleteDesktopLogin(login);

            var stateManager = new StateManager(Path.GetDirectoryName(App.ConfigPath)!);
            stateManager.SaveConfig(App.ConfigPath, _vm.Config);

            if (!_vm.ShowClientPicker && _vm.PendingClientSelectionId > 0)
                await ActivateSelectedClientAsync(_vm.PendingClientSelectionId);
        }
        catch (Exception ex)
        {
            SetupStatus.Text = ex.Message;
        }
    }

    private void OnForwardCardClick(object sender, System.Windows.Input.MouseButtonEventArgs e)
    {
        if (sender is FrameworkElement element && element.DataContext is ForwardViewModel forward)
        {
            _vm.SelectForwardCommand.Execute(forward);
        }
    }


    private async void OnSelectClient_Click(object sender, RoutedEventArgs e)
    {
        if (ClientPickerList.SelectedItem is not DesktopClientChoice client)
            return;

        await ActivateSelectedClientAsync(client.Id);
    }

    private void OnSaveSettings_Click(object sender, RoutedEventArgs e)
    {
        App.Config.MasterUrl = SettMasterUrl.Text.Trim();
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
        SettSignedInEmail.Text = App.Config.SignedInEmail;
        SettActiveClientName.Text = App.Config.ActiveClientName;
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

    private async Task ActivateSelectedClientAsync(int clientId)
    {
        var masterUrl = _vm.Config.MasterUrl;
        var sessionToken = _vm.Config.DesktopSessionToken;
        if (string.IsNullOrWhiteSpace(masterUrl) || string.IsNullOrWhiteSpace(sessionToken) || clientId <= 0)
            return;

        try
        {
            _vm.DisconnectCommand.Execute(null);

            using var api = new MasterApiClient(masterUrl, string.Empty);
            var selection = await api.SelectClientAsync(sessionToken, clientId);
            _vm.CompleteClientSelection(selection);
            ClientPickerList.SelectedItem = null;

            var stateManager = new StateManager(Path.GetDirectoryName(App.ConfigPath)!);
            stateManager.SaveConfig(App.ConfigPath, _vm.Config);

            _vm.Initialize(_vm.Config);
            PopulateSettings();
            _vm.ConnectCommand.Execute(null);
        }
        catch (Exception ex)
        {
            SetupStatus.Text = ex.Message;
        }
    }

    private void OnShowClientPicker_Click(object sender, RoutedEventArgs e)
    {
        if (_vm.Config.AvailableClients.Count <= 1)
            return;

        ClientPickerList.ItemsSource = _vm.AvailableClientChoices;
        _vm.ShowClientPicker = true;
    }

    private void OnSignOut_Click(object sender, RoutedEventArgs e)
    {
        _vm.DisconnectCommand.Execute(null);
        _vm.SignOutForTests();
        var stateManager = new StateManager(Path.GetDirectoryName(App.ConfigPath)!);
        stateManager.SaveConfig(App.ConfigPath, _vm.Config);
        TxtPassword.Password = string.Empty;
        TxtEmail.Text = string.Empty;
        SetupStatus.Text = string.Empty;
        PopulateSettings();
    }


    private async void OnCheckForUpdates_Click(object sender, RoutedEventArgs e)
    {
        await CheckForUpdatesAsync();
    }

    private async void OnInstallUpdateNow_Click(object sender, RoutedEventArgs e)
    {
        if (_vm.AvailableUpdateArtifact == null)
            return;

        try
        {
            var installerPath = await PrepareCachedInstallerAsync(_vm.AvailableUpdateArtifact);
            var installerArguments = BuildSilentUpdateArguments(installerPath);
            ClearPendingUpdateState();
            LaunchInstallerAndExit(installerPath, installerArguments);
        }
        catch (Exception ex)
        {
            SetupStatus.Text = ex.Message;
            Log.Warning(ex, "install-now update flow failed");
        }
    }

    private async void OnDeferUpdateUntilRestart_Click(object sender, RoutedEventArgs e)
    {
        if (_vm.AvailableUpdateArtifact == null)
            return;

        try
        {
            var artifact = _vm.AvailableUpdateArtifact;
            var installerPath = await PrepareCachedInstallerAsync(artifact);
            var sha = WindowsAppUpdateService.ComputeSha256(installerPath);
            _vm.DeferUpdateForRestartForTests(artifact.Version, installerPath, sha);
            SaveCurrentConfig();
        }
        catch (Exception ex)
        {
            SetupStatus.Text = ex.Message;
            Log.Warning(ex, "deferred update flow failed");
        }
    }

    private async Task CheckForUpdatesAsync()
    {
        try
        {
            if (string.IsNullOrWhiteSpace(App.Config.MasterUrl))
                return;

            var artifact = await _updateService.FetchWindowsClientArtifactAsync(App.Config.MasterUrl);
            var localVersion = GetCurrentAppVersion();
            App.Config.LastUpdateCheckAt = DateTimeOffset.UtcNow.ToString("O");
            SaveCurrentConfig();
            if (WindowsAppUpdateService.IsRemoteVersionNewer(artifact.Version, localVersion))
            {
                _vm.PresentUpdatePrompt(artifact);
            }
            else
            {
                _vm.ReportNoUpdateAvailableForTests(localVersion);
            }
        }
        catch (Exception ex)
        {
            _vm.ReportUpdateCheckFailedForTests(ex.Message);
            Log.Warning(ex, "update check failed");
        }
    }

    private Task<bool> TryApplyPendingUpdateAsync()
    {
        if (!WindowsAppUpdateService.HasPendingUpdate(App.Config))
            return Task.FromResult(false);

        if (!Version.TryParse(App.Config.PendingUpdateVersion, out var pendingVersion))
        {
            ClearPendingUpdateState();
            return Task.FromResult(false);
        }

        if (Version.TryParse(GetCurrentAppVersion(), out var currentVersion) && currentVersion >= pendingVersion)
        {
            ClearPendingUpdateState();
            return Task.FromResult(false);
        }

        var path = App.Config.PendingUpdateInstallerPath;
        if (string.IsNullOrWhiteSpace(path) || !File.Exists(path))
        {
            ClearPendingUpdateState();
            return Task.FromResult(false);
        }

        try
        {
            var actualSha = WindowsAppUpdateService.ComputeSha256(path);
            if (!string.IsNullOrWhiteSpace(App.Config.PendingUpdateSha256)
                && !string.Equals(actualSha, App.Config.PendingUpdateSha256, StringComparison.OrdinalIgnoreCase))
            {
                ClearPendingUpdateState();
                return Task.FromResult(false);
            }

            var installerArguments = BuildSilentUpdateArguments(path);
            ClearPendingUpdateState();
            LaunchInstallerAndExit(path, installerArguments);
            return Task.FromResult(true);
        }
        catch (Exception ex)
        {
            ClearPendingUpdateState();
            Log.Warning(ex, "pending update could not be applied");
            return Task.FromResult(false);
        }
    }

    private async Task<string> PrepareCachedInstallerAsync(WindowsClientAppArtifact artifact)
    {
        var installRoot = Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData), "LeiKwan Bridge");
        var installerPath = WindowsAppUpdateService.BuildCachedInstallerPath(installRoot, artifact.Version);

        if (File.Exists(installerPath))
        {
            var cachedSha = WindowsAppUpdateService.ComputeSha256(installerPath);
            if (string.Equals(cachedSha, artifact.Sha256, StringComparison.OrdinalIgnoreCase))
                return installerPath;
        }

        await _updateService.DownloadInstallerAsync(
            WindowsAppUpdateService.ResolveArtifactUrl(App.Config.MasterUrl, artifact.ArtifactUrl),
            installerPath);
        var downloadedSha = WindowsAppUpdateService.ComputeSha256(installerPath);
        if (!string.Equals(downloadedSha, artifact.Sha256, StringComparison.OrdinalIgnoreCase))
            throw new InvalidOperationException("downloaded installer hash mismatch");

        return installerPath;
    }

    private void LaunchInstallerAndExit(string installerPath, IReadOnlyList<string>? installerArguments = null)
    {
        _vm.DisconnectCommand.Execute(null);
        _trayLifecycle.RequestExit();
        DisposeTrayIcon();

        var startInfo = new ProcessStartInfo
        {
            FileName = installerPath,
            UseShellExecute = true,
            WorkingDirectory = Path.GetDirectoryName(installerPath) ?? string.Empty,
        };
        if (installerArguments is not null)
        {
            foreach (var installerArgument in installerArguments)
                startInfo.ArgumentList.Add(installerArgument);
        }

        var process = Process.Start(startInfo);
        if (process == null)
            throw new InvalidOperationException("failed to launch installer");
        System.Windows.Application.Current.Shutdown();
    }

    private static IReadOnlyList<string> BuildSilentUpdateArguments(string installerPath)
    {
        var installRoot = WindowsAppUpdateService.NormalizeInstallRoot(AppContext.BaseDirectory);
        var state = WindowsAppUpdateService.LoadInstallState(installRoot)
            ?? throw new InvalidOperationException("install-state missing for silent update");
        return WindowsAppUpdateService.BuildSilentUpdateArguments(installerPath, installRoot, state);
    }

    private void ClearPendingUpdateState()
    {
        App.Config.PendingUpdateVersion = string.Empty;
        App.Config.PendingUpdateInstallerPath = string.Empty;
        App.Config.PendingUpdateSha256 = string.Empty;
        App.Config.PendingUpdateDownloadedAt = string.Empty;
        SaveCurrentConfig();
    }

    private void SaveCurrentConfig()
    {
        var stateManager = new StateManager(Path.GetDirectoryName(App.ConfigPath)!);
        stateManager.SaveConfig(App.ConfigPath, App.Config);
    }

    private static string GetCurrentAppVersion()
    {
        var exePath = Process.GetCurrentProcess().MainModule?.FileName;
        if (!string.IsNullOrWhiteSpace(exePath))
        {
            var fileVersion = FileVersionInfo.GetVersionInfo(exePath).FileVersion;
            if (!string.IsNullOrWhiteSpace(fileVersion))
                return fileVersion;
        }

        return typeof(App).Assembly.GetName().Version?.ToString() ?? "0.0.0.0";
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
        _updateService.Dispose();
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
