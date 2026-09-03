using System.ComponentModel;
using System.Diagnostics;
using System.Globalization;
using System.IO;
using System.Windows;
using System.Windows.Controls;
using System.Windows.Media;
using Drawing = System.Drawing;
using Forms = System.Windows.Forms;
using Brush = System.Windows.Media.Brush;
using Brushes = System.Windows.Media.Brushes;
using Color = System.Windows.Media.Color;
using LeikwanBridge.App.Services;
using LeikwanBridge.App.ViewModels;
using LeikwanBridge.Core.Models;
using LeikwanBridge.Core.Services;
using Microsoft.Win32;
using Serilog;

namespace LeikwanBridge.App.Views;

public partial class MainWindow : Window
{
    private readonly MainViewModel _vm;
    private readonly TrayWindowLifecycle _trayLifecycle = new();
    private Forms.NotifyIcon? _trayIcon;
    private bool _trayReady;
    private bool _proxyUiReady;
    private bool _updatingNativeUi;
    private readonly WindowsAppUpdateService _updateService = new();
    private readonly CancellationTokenSource _lifecycleCts = new();
    private Task? _startupTask;
    private bool _shutdownCloseRequested;
    private bool _shutdownCloseFinalizing;

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
            _startupTask = InitializeWindowAsync(_lifecycleCts.Token);
        };
        StateChanged += OnWindowStateChanged;
    }

    private async Task InitializeWindowAsync(CancellationToken ct)
    {
        try
        {
            EnsureTrayIcon();
            ct.ThrowIfCancellationRequested();
            if (await TryApplyPendingUpdateAsync().ConfigureAwait(true))
                return;

            ct.ThrowIfCancellationRequested();
            try
            {
                await _vm.InitializeProxyAsync(ct);
                ct.ThrowIfCancellationRequested();
                PopulateProxyUi();
                _proxyUiReady = true;
            }
            catch (OperationCanceledException) when (ct.IsCancellationRequested)
            {
                return;
            }
            catch (Exception ex)
            {
                SetProxyStatusError(ex.Message);
                Log.Warning(ex, "local proxy initialization failed");
            }

            ct.ThrowIfCancellationRequested();
            await TryStartRequestedProxyAsync(ct);
            ct.ThrowIfCancellationRequested();
            await TryAutoConnectAsync(ct);
            ct.ThrowIfCancellationRequested();
            if (WindowsAppUpdateService.ShouldCheckForUpdatesOnStartup(App.Config))
                await CheckForUpdatesAsync(ct);
        }
        catch (OperationCanceledException) when (ct.IsCancellationRequested)
        {
        }
        catch (Exception ex)
        {
            Log.Warning(ex, "window startup task failed");
        }
    }

    private async void OnConnect_Click(object sender, RoutedEventArgs e)
    {
        var masterUrl = AppDefaults.NormalizeMasterUrl(TxtMasterUrl.Text);
        TxtMasterUrl.Text = masterUrl;
        var clientIdText = TxtClientId.Text.Trim();
        var agentToken = TxtAgentToken.Password.Trim();

        if (string.IsNullOrWhiteSpace(masterUrl)
            || !int.TryParse(clientIdText, out var clientId)
            || clientId <= 0
            || string.IsNullOrWhiteSpace(agentToken))
        {
            SetupStatus.Text = FindResource("SetupFieldsRequired") as string ?? "All fields are required.";
            return;
        }

        SetupStatus.Text = FindResource("SetupConnecting") as string ?? "Connecting...";

        try
        {
            using var api = new MasterApiClient(
                masterUrl,
                agentToken,
                MasterApiClient.BuildWindowsDeviceId(clientId, Environment.MachineName),
                $"Leikwan Bridge on {Environment.MachineName}",
                "windows-desktop");
            if (!await api.TestConnectionAsync())
            {
                SetupStatus.Text = string.IsNullOrWhiteSpace(api.LastErrorMessage)
                    ? (FindResource("SetupFailed") as string ?? "Connection failed.")
                    : api.LastErrorMessage;
                return;
            }

            _vm.Config.MasterUrl = masterUrl;
            _vm.Config.ClientId = clientId;
            _vm.Config.ActiveClientId = clientId;
            _vm.Config.AgentToken = agentToken;
            _vm.Config.DesktopSessionToken = string.Empty;
            _vm.Config.SignedInUserId = 0;
            _vm.Config.SignedInUsername = string.Empty;
            _vm.Config.SignedInEmail = string.Empty;
            _vm.Config.ActiveClientName = string.Empty;
            _vm.Config.AvailableClients = [];
            _vm.Initialize(_vm.Config);

            var stateManager = new StateManager(Path.GetDirectoryName(App.ConfigPath)!);
            stateManager.SaveConfig(App.ConfigPath, _vm.Config);
            SetupStatus.Text = FindResource("SetupSuccess") as string ?? "Connection successful!";
            await _vm.ConnectCommand.ExecuteAsync(null);
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


    private void OnSaveSettings_Click(object sender, RoutedEventArgs e)
    {
        App.Config.MasterUrl = AppDefaults.NormalizeMasterUrl(SettMasterUrl.Text);
        SettMasterUrl.Text = App.Config.MasterUrl;
        if (int.TryParse(SettClientId.Text.Trim(), out var clientId) && clientId > 0)
        {
            App.Config.ClientId = clientId;
            App.Config.ActiveClientId = clientId;
        }
        if (!string.IsNullOrWhiteSpace(SettAgentToken.Password))
            App.Config.AgentToken = SettAgentToken.Password.Trim();
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
            ApplyProxyVisualState();
        }

        var stateManager = new StateManager(Path.GetDirectoryName(App.ConfigPath)!);
        stateManager.SaveConfig(App.ConfigPath, App.Config);

        // Handle auto-start
        SetAutoStart(App.Config.AutoStart);

        SettingsStatus.Text = FindResource("SettingsSaved") as string ?? "Settings saved.";
    }

    private void PopulateProxyUi()
    {
        if (!_proxyUiReady)
        {
            _proxyUiReady = true;
        }

        var selected = _vm.ProxyProfiles.FirstOrDefault(profile => profile.Id == App.Config.ProxyActiveProfileId)
            ?? _vm.ProxyProfiles.FirstOrDefault();
        ProxyProfileList.SelectedItem = selected;
        _vm.ProxyEditor.Load(selected);
        ProxyPassword.Password = _vm.ProxyEditor.Password;
        ProxyBypassChinaCheckBox.IsChecked = App.Config.ProxyBypassChinaMainland;
        ProxyDnsModeCombo.SelectedIndex = App.Config.GetEffectiveProxyDnsMode() == WindowsProxyDnsMode.Custom ? 1 : 0;
        ProxyDnsServersText.Text = string.IsNullOrWhiteSpace(App.Config.ProxyDnsServers)
            ? WindowsProxyDns.DefaultCustomServer
            : App.Config.ProxyDnsServers;
        ProxyLocalListenPortCombo.Text = App.Config.GetEffectiveProxyPort()
            .ToString(CultureInfo.InvariantCulture);
        RestoreProxyGameSelection(_vm.SelectedProxyGameId);
        UpdateProxyDnsVisibility();

        var mode = Enum.TryParse<WindowsProxyMode>(App.Config.ProxyActiveMode, ignoreCase: true, out var parsed)
            ? parsed
            : WindowsProxyMode.Http;
        ProxyModeCombo.SelectedIndex = mode switch
        {
            WindowsProxyMode.Tun => 1,
            WindowsProxyMode.Process => 2,
            _ => 0,
        };
        UpdateProxyModeVisibility();
        UpdateNativeOutboundUi();
        ApplyProxyVisualState();
    }

    private void OnProxyProfileSelectionChanged(object sender, SelectionChangedEventArgs e)
    {
        if (!_proxyUiReady || ProxyProfileList.SelectedItem is not WindowsProxyProfile profile)
            return;
        _vm.ProxyEditor.Load(profile);
        ProxyPassword.Password = profile.Password;
        App.Config.ProxyActiveProfileId = profile.Id;
    }

    private void OnProxyNew_Click(object sender, RoutedEventArgs e)
    {
        ProxyProfileList.SelectedItem = null;
        _vm.ProxyEditor.Load(null);
        ProxyPassword.Password = string.Empty;
        SetProxyReady();
    }

    private void OnProxyImport_Click(object sender, RoutedEventArgs e)
    {
        if (_vm.ProxyEditor.TryImport(ProxyImportUri.Text, out var error))
        {
            ProxyPassword.Password = _vm.ProxyEditor.Password;
            ProxyProfileList.SelectedItem = null;
            SetProxyReady();
        }
        else
        {
            SetProxyStatusError(error);
        }
    }

    private async void OnProxySave_Click(object sender, RoutedEventArgs e)
    {
        _vm.ProxyEditor.Password = ProxyPassword.Password;
        if (!CaptureProxyLocalListenPort())
            return;
        CaptureProxyDnsSettings();
        try
        {
            await _vm.SaveProxyProfileAsync();
            SaveCurrentConfig();
            PopulateProxyUi();
            SetProxyReady();
        }
        catch (Exception ex)
        {
            SetProxyStatusError(ex.Message);
            Log.Warning(ex, "failed to save local proxy profile");
        }
    }

    private async void OnProxyDelete_Click(object sender, RoutedEventArgs e)
    {
        if (ProxyProfileList.SelectedItem is not WindowsProxyProfile profile)
            return;
        try
        {
            await _vm.DeleteProxyProfileAsync(profile.Id);
            SaveCurrentConfig();
            PopulateProxyUi();
        }
        catch (Exception ex)
        {
            SetProxyStatusError(ex.Message);
            Log.Warning(ex, "failed to delete local proxy profile");
        }
    }

    private async void OnProxyStart_Click(object sender, RoutedEventArgs e)
    {
        _vm.ProxyEditor.Password = ProxyPassword.Password;
        if (!CaptureProxyLocalListenPort())
            return;
        // Capture the game before saving the profile. Saving refreshes the
        // profile list and can transiently clear a ComboBox selection.
        var gameId = GetSelectedProxyGameId();
        if (!string.IsNullOrWhiteSpace(gameId))
        {
            _vm.SelectedProxyGameId = gameId;
            App.Config.ProxyActiveGameId = gameId;
        }
        CaptureProxyDnsSettings();
        var mode = GetSelectedProxyMode();
        var profileId = 0;
        SetProxyStarting();
        try
        {
            // Persist the current editor values before starting so a mode
            // switch never runs an older password/cipher by accident.
            await _vm.SaveProxyProfileAsync();
            PopulateProxyUi();

            profileId = _vm.ProxyEditor.Id;
            if (profileId <= 0)
                throw new InvalidOperationException("proxy_profile_required");
            await _vm.StartProxyAsync(mode, profileId, gameId);
            RestoreProxyGameSelection(mode == WindowsProxyMode.Process ? _vm.SelectedProxyGameId : gameId);
            SaveCurrentConfig();
        }
        catch (Exception ex)
        {
            if (mode == WindowsProxyMode.Process
                && profileId > 0
                && string.Equals(ex.Message, "administrator_required", StringComparison.OrdinalIgnoreCase))
            {
                App.Config.ProxyActiveMode = mode.ToString();
                App.Config.ProxyActiveProfileId = profileId;
                App.Config.ProxyActiveGameId = gameId?.Trim() ?? string.Empty;
                SaveCurrentConfig();
                if (await TryLaunchElevatedProxyAsync(profileId, gameId))
                    return;
            }

            SetProxyStatusError(ex.Message);
            Log.Warning(ex, "failed to start local proxy");
        }
    }

    private void OnNativeForwardSelectionChanged(object sender, SelectionChangedEventArgs e)
    {
        if (!_proxyUiReady || _updatingNativeUi)
            return;
        if (NativeForwardCombo.SelectedValue is int id && id > 0)
            _vm.SelectedNativeForwardId = id;
        UpdateNativeOutboundUi();
    }

    private void OnNativeRouteScopeSelectionChanged(object sender, SelectionChangedEventArgs e)
    {
        if (!_proxyUiReady || _updatingNativeUi)
            return;
        if (NativeRouteScopeCombo.SelectedItem is ComboBoxItem item
            && item.Tag is string scope)
            _vm.SelectedNativeRouteScope = scope;
        UpdateNativeOutboundUi();
    }

    private async void OnNativeOutboundStart_Click(object sender, RoutedEventArgs e)
    {
        if (!_vm.IsNativeOutboundVisible || _vm.SelectedNativeForwardId <= 0)
            return;
        var mode = NativeModeCombo.SelectedItem is ComboBoxItem item
            && Enum.TryParse<WindowsProxyMode>(item.Tag?.ToString(), true, out var parsed)
            ? parsed
            : WindowsProxyMode.Tun;
        SetProxyStarting();
        try
        {
            await _vm.StartNativeOutboundAsync(
                mode,
                _vm.SelectedNativeForwardId,
                _vm.SelectedNativeRouteScope,
                selectedGameId: null,
                bypassChinaMainland: _vm.NativeBypassChinaMainland,
                unrestrictedRoute: _vm.NativeUnrestrictedRoute);
            UpdateNativeOutboundUi();
            ApplyProxyVisualState();
        }
        catch (Exception ex)
        {
            SetProxyStatusError(ex.Message);
            Log.Warning(ex, "failed to start native outbound");
        }
    }

    private async Task TryStartRequestedProxyAsync(CancellationToken ct = default)
    {
        ct.ThrowIfCancellationRequested();
        var request = App.ConsumeStartupProxyRequest();
        if (request is null)
            return;
        if (!_proxyUiReady)
        {
            SetProxyStatusError("proxy_initialization_failed");
            return;
        }

        App.Config.ProxyActiveMode = request.Mode.ToString();
        App.Config.ProxyActiveProfileId = request.ProfileId;
        App.Config.ProxyActiveGameId = request.GameId;
        _vm.SelectedProxyGameId = request.GameId;
        PopulateProxyUi();
        SetProxyStarting();
        try
        {
            await _vm.StartProxyAsync(request.Mode, request.ProfileId, request.GameId, ct);
            SaveCurrentConfig();
        }
        catch (OperationCanceledException) when (ct.IsCancellationRequested || _vm.IsShuttingDown)
        {
        }
        catch (Exception ex)
        {
            SetProxyStatusError(ex.Message);
            Log.Warning(ex, "failed to start elevated local process proxy");
        }
    }

    private async Task<bool> TryLaunchElevatedProxyAsync(int profileId, string? gameId)
    {
        if (!OperatingSystem.IsWindows())
            return false;

        var executablePath = Process.GetCurrentProcess().MainModule?.FileName;
        if (string.IsNullOrWhiteSpace(executablePath)
            || string.Equals(
                Path.GetFileNameWithoutExtension(executablePath),
                "dotnet",
                StringComparison.OrdinalIgnoreCase))
        {
            return false;
        }

        try
        {
            using var elevated = await WindowsProxyElevation.StartElevatedAsync(
                WindowsProxyElevation.BuildRelaunchStartInfo(
                    executablePath,
                    new WindowsProxyStartRequest(
                        WindowsProxyMode.Process,
                        profileId,
                        gameId?.Trim() ?? string.Empty)));
            if (elevated is null)
                return false;

            // Stop/Disconnect cleanup runs from the normal close path.  Doing
            // it here as well can hold the single-instance mutex while the
            // elevated child is starting and makes the handoff look hung.
            _trayLifecycle.RequestExit();
            DisposeTrayIcon();
            System.Windows.Application.Current.Shutdown();
            return true;
        }
        catch (Exception exception) when (WindowsProxyElevation.IsElevationCancelled(exception))
        {
            Log.Information("process proxy elevation was cancelled by the user");
            return false;
        }
        catch (Exception exception)
        {
            Log.Warning(exception, "failed to launch elevated process proxy");
            return false;
        }
    }

    private async void OnProxyStop_Click(object sender, RoutedEventArgs e)
    {
        try { await _vm.StopProxyAsync(); }
        catch (Exception ex)
        {
            SetProxyStatusError(ex.Message);
            Log.Warning(ex, "failed to stop local proxy");
        }
    }

    private async void OnProxyTestUdp_Click(object sender, RoutedEventArgs e)
    {
        try
        {
            var result = await _vm.TestProxyUdpAsync();
            SetProxyTransientStatus(result.Succeeded
                ? $"{FindResource("ProxyTestUdp") as string ?? "Test UDP"}: {result.ResponseBytes} bytes"
                : result.ErrorCode, !result.Succeeded, preserveTitle: result.Succeeded);
        }
        catch (Exception ex)
        {
            SetProxyStatusError(ex.Message);
            Log.Warning(ex, "proxy UDP probe failed");
        }
    }

    private void OnProxyRefreshGames_Click(object sender, RoutedEventArgs e)
    {
        _vm.RefreshProxyGameOptions();
        RestoreProxyGameSelection(_vm.SelectedProxyGameId);
    }

    private async void OnProxyAddGame_Click(object sender, RoutedEventArgs e)
    {
        var dialog = new ProxyGameAddDialog { Owner = this };
        if (dialog.ShowDialog() != true)
            return;

        try
        {
            var profile = dialog.ScanFolder
                ? await _vm.AddProxyGameFolderAsync(dialog.SelectedPath, dialog.DisplayName)
                : await _vm.AddProxyGameAsync(dialog.SelectedPath, dialog.DisplayName);
            _vm.SelectedProxyGameId = profile.Id;
            App.Config.ProxyActiveGameId = profile.Id;
            SaveCurrentConfig();
            RestoreProxyGameSelection(profile.Id);
            SetProxyTransientStatus(string.Format(
                FindResource("ProxyGameAdded") as string ?? "Process added: {0}", profile.DisplayName), false, true);
        }
        catch (Exception ex)
        {
            SetProxyStatusError(ex.Message);
            Log.Warning(ex, "failed to add proxy game process");
        }
    }

    private async void OnProxyImportNetchModes_Click(object sender, RoutedEventArgs e)
    {
        var modeDirectory = _vm.Config.GetEffectiveProxyNetchModePath();
        if (!Directory.Exists(modeDirectory))
        {
            using var dialog = new Forms.FolderBrowserDialog
            {
                Description = FindResource("ProxyNetchFolderRequired") as string ?? "Select the Netch mode folder.",
                UseDescriptionForTitle = true,
                ShowNewFolderButton = false,
            };
            if (dialog.ShowDialog() != Forms.DialogResult.OK)
                return;
            modeDirectory = ResolveNetchModeDirectory(dialog.SelectedPath);
        }

        try
        {
            var count = await _vm.ImportNetchModesAsync(modeDirectory);
            SaveCurrentConfig();
            RestoreProxyGameSelection(_vm.SelectedProxyGameId);
            SetProxyTransientStatus(string.Format(
                FindResource("ProxyNetchImported") as string ?? "Imported {0} Netch game modes.", count), false, true);
        }
        catch (Exception ex)
        {
            SetProxyStatusError(ex.Message);
            Log.Warning(ex, "failed to import Netch mode profiles");
        }
    }

    private void OnProxyGameSelectionChanged(object sender, SelectionChangedEventArgs e)
    {
        if (!_proxyUiReady)
            return;
        var id = GetSelectedProxyGameId();
        // ItemsSource refreshes emit a null selection while the collection is
        // being rebuilt. Do not erase the user's last valid game in that gap.
        if (!string.IsNullOrWhiteSpace(id))
        {
            _vm.SelectedProxyGameId = id;
            App.Config.ProxyActiveGameId = id;
        }
    }

    private string? GetSelectedProxyGameId()
    {
        var optionId = (ProxyGameCombo.SelectedItem as WindowsProxyGameOption)?.Id;
        if (!string.IsNullOrWhiteSpace(optionId))
            return optionId;

        var selectedValue = ProxyGameCombo.SelectedValue?.ToString();
        if (!string.IsNullOrWhiteSpace(selectedValue))
            return selectedValue;

        if (!string.IsNullOrWhiteSpace(_vm.SelectedProxyGameId))
            return _vm.SelectedProxyGameId;

        return string.IsNullOrWhiteSpace(App.Config.ProxyActiveGameId)
            ? null
            : App.Config.ProxyActiveGameId;
    }

    private void OnProxyDnsModeSelectionChanged(object sender, SelectionChangedEventArgs e)
    {
        if (!_proxyUiReady || ProxyDnsModeCombo.SelectedItem is not ComboBoxItem item)
            return;
        App.Config.ProxyDnsMode = item.Tag?.ToString() ?? nameof(WindowsProxyDnsMode.Remote);
        UpdateProxyDnsVisibility();
    }

    private void OnProxyDnsServersTextChanged(object sender, TextChangedEventArgs e)
    {
        if (!_proxyUiReady || ProxyDnsServersText is null)
            return;
        App.Config.ProxyDnsServers = ProxyDnsServersText.Text;
    }

    private bool CaptureProxyLocalListenPort()
    {
        if (ProxyLocalListenPortCombo is null
            || !TryParseProxyPort(ProxyLocalListenPortCombo.Text, out var port))
        {
            SetProxyStatusError("proxy_port_invalid");
            return false;
        }

        try
        {
            _vm.SetProxyPort(port);
            App.Config.ProxyPort = port;
            return true;
        }
        catch (Exception exception) when (exception is InvalidOperationException or ArgumentOutOfRangeException)
        {
            SetProxyStatusError(exception.Message);
            return false;
        }
    }

    private static bool TryParseProxyPort(string? text, out int port)
        => int.TryParse(text?.Trim(), NumberStyles.Integer, CultureInfo.InvariantCulture, out port)
            && port is >= 1 and <= 65535;

    private void UpdateProxyDnsVisibility()
    {
        if (ProxyDnsServersPanel is null || ProxyDnsModeCombo is null)
            return;
        var custom = ProxyDnsModeCombo.SelectedItem is ComboBoxItem item
            && string.Equals(item.Tag?.ToString(), nameof(WindowsProxyDnsMode.Custom), StringComparison.OrdinalIgnoreCase);
        ProxyDnsServersPanel.Visibility = custom ? Visibility.Visible : Visibility.Collapsed;
    }

    private void CaptureProxyDnsSettings()
    {
        if (ProxyDnsModeCombo.SelectedItem is ComboBoxItem mode)
            App.Config.ProxyDnsMode = mode.Tag?.ToString() ?? nameof(WindowsProxyDnsMode.Remote);
        if (ProxyDnsServersText is not null && !string.IsNullOrWhiteSpace(ProxyDnsServersText.Text))
            App.Config.ProxyDnsServers = ProxyDnsServersText.Text.Trim();
        if (string.IsNullOrWhiteSpace(App.Config.ProxyDnsServers))
            App.Config.ProxyDnsServers = WindowsProxyDns.DefaultCustomServer;
    }

    private void OnProxyModeSelectionChanged(object sender, SelectionChangedEventArgs e)
    {
        if (!_proxyUiReady || ProxyModeCombo.SelectedItem is not ComboBoxItem item)
            return;
        App.Config.ProxyActiveMode = item.Tag?.ToString() ?? nameof(WindowsProxyMode.Http);
        UpdateProxyModeVisibility();
    }

    private void UpdateProxyModeVisibility()
    {
        if (ProxyGameCard == null)
            return;
        ProxyGameCard.Visibility = GetSelectedProxyMode() == WindowsProxyMode.Process
            ? Visibility.Visible
            : Visibility.Collapsed;
    }

    private void UpdateNativeOutboundUi()
    {
        if (NativeOutboundCard is null)
            return;
        var visible = _vm.IsNativeOutboundVisible
            && _vm.NativeOutboundForwards.Count > 0;
        NativeOutboundCard.Visibility = visible ? Visibility.Visible : Visibility.Collapsed;
        if (!visible)
            return;

        _updatingNativeUi = true;
        try
        {
            NativeForwardCombo.SelectedValue = _vm.SelectedNativeForwardId;
            var carrier = _vm.NativeOutboundCapability?.FindForward(_vm.SelectedNativeForwardId);
            NativeDirectionText.Text = carrier?.Direction ?? string.Empty;
            NativeRouteScopePanel.Visibility = _vm.IsNativeRouteScopeSelectable
                ? Visibility.Visible
                : Visibility.Collapsed;

            NativeRouteScopeCombo.Items.Clear();
            foreach (var scope in carrier?.RouteScopes ?? [])
            {
                var label = scope.ToLowerInvariant() switch
                {
                    "cn_only" => FindResource("ProxyNativeOutboundCnOnly") as string ?? "Mainland China only",
                    "intl_only" => FindResource("ProxyNativeOutboundIntlOnly") as string ?? "International only",
                    _ => FindResource("ProxyNativeOutboundGlobal") as string ?? "Global",
                };
                NativeRouteScopeCombo.Items.Add(new ComboBoxItem { Content = label, Tag = scope });
            }
            var selectedScope = _vm.SelectedNativeRouteScope;
            var selectedItem = NativeRouteScopeCombo.Items
                .OfType<ComboBoxItem>()
                .FirstOrDefault(item => string.Equals(item.Tag?.ToString(), selectedScope, StringComparison.OrdinalIgnoreCase));
            if (selectedItem is null && NativeRouteScopeCombo.Items.Count > 0)
            {
                selectedItem = NativeRouteScopeCombo.Items[0] as ComboBoxItem;
                if (selectedItem?.Tag is string fallbackScope)
                    _vm.SelectedNativeRouteScope = fallbackScope;
            }
            NativeRouteScopeCombo.SelectedItem = selectedItem;
            NativeBypassChinaCheckBox.Visibility = _vm.IsNativeRouteGlobal
                ? Visibility.Visible
                : Visibility.Collapsed;
            NativeBypassChinaCheckBox.IsEnabled = _vm.IsNativeBypassChinaEnabled
                && _vm.ProxySnapshot.State is not (WindowsProxyConnectionState.Connected or WindowsProxyConnectionState.Starting);
            NativeUnrestrictedRouteCheckBox.Visibility = _vm.IsNativeUnrestrictedRouteVisible
                ? Visibility.Visible
                : Visibility.Collapsed;
            NativeUnrestrictedRouteCheckBox.IsEnabled = _vm.IsNativeUnrestrictedRouteEnabled;
            NativeUnrestrictedRouteCheckBox.IsChecked = _vm.NativeUnrestrictedRoute;
            NativeOutboundStartButton.IsEnabled = _vm.ProxySnapshot.State is not (WindowsProxyConnectionState.Connected or WindowsProxyConnectionState.Starting);
        }
        finally
        {
            _updatingNativeUi = false;
        }
    }

    private WindowsProxyMode GetSelectedProxyMode()
    {
        if (ProxyModeCombo.SelectedItem is ComboBoxItem item
            && Enum.TryParse<WindowsProxyMode>(item.Tag?.ToString(), true, out var mode))
            return mode;
        return WindowsProxyMode.Http;
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
        if (e.PropertyName == nameof(MainViewModel.ShowUpdatePrompt)
            || e.PropertyName == nameof(MainViewModel.AvailableUpdateVersion))
        {
            UpdateUpdatePromptText();
        }
        if (e.PropertyName == nameof(MainViewModel.ProxySnapshot))
            ApplyProxyVisualState();
        if (e.PropertyName == nameof(MainViewModel.SelectedProxyGameId)
            || e.PropertyName == nameof(MainViewModel.SelectedProxyGameDisplayName))
        {
            RestoreProxyGameSelection(_vm.SelectedProxyGameId);
        }
        if (e.PropertyName == nameof(MainViewModel.NativeOutboundCapability)
            || e.PropertyName == nameof(MainViewModel.SelectedNativeForwardId)
            || e.PropertyName == nameof(MainViewModel.SelectedNativeRouteScope)
            || e.PropertyName == nameof(MainViewModel.NativeBypassChinaMainland))
        {
            UpdateNativeOutboundUi();
        }
    }

    private void RestoreProxyGameSelection(string? gameId)
    {
        var id = gameId?.Trim() ?? string.Empty;
        if (id.Length == 0 || ProxyGameCombo is null)
            return;
        var option = _vm.ProxyGameOptions.FirstOrDefault(item =>
            string.Equals(item.Id, id, StringComparison.OrdinalIgnoreCase));
        if (option is not null)
            ProxyGameCombo.SelectedItem = option;
        else
            ProxyGameCombo.SelectedValue = id;
    }

    private void ApplyProxyVisualState()
    {
        if (ProxyStatusBanner is null || ProxyStartButton is null || ProxyStopButton is null
            || ProxyLocalListenPortCombo is null)
            return;

        var snapshot = _vm.ProxySnapshot;
        var titleKey = snapshot.State switch
        {
            WindowsProxyConnectionState.Connected => "ProxyConnected",
            WindowsProxyConnectionState.Starting => "ProxyStarting",
            WindowsProxyConnectionState.Error => "ProxyError",
            _ => "ProxyDisconnected",
        };
        ProxyStatusTitle.Text = FindResource(titleKey) as string ?? titleKey;
        ProxyStatusText.Text = snapshot.State switch
        {
            WindowsProxyConnectionState.Connected =>
                $"{snapshot.Mode}  |  {snapshot.ActiveProfileName}  |  127.0.0.1:{snapshot.ProxyPort}",
            WindowsProxyConnectionState.Starting =>
                FindResource("ProxyStarting") as string ?? "Starting proxy",
            WindowsProxyConnectionState.Error =>
                string.IsNullOrWhiteSpace(snapshot.ErrorCode)
                    ? (FindResource("ProxyError") as string ?? "Proxy failed")
                    : snapshot.ErrorCode,
            _ => FindResource("ProxyReady") as string ?? "Ready",
        };

        var titleBrushKey = snapshot.State switch
        {
            WindowsProxyConnectionState.Connected => "SuccessBrush",
            WindowsProxyConnectionState.Starting => "WarningBrush",
            WindowsProxyConnectionState.Error => "DangerBrush",
            _ => "TextBrush",
        };
        var borderBrushKey = snapshot.State switch
        {
            WindowsProxyConnectionState.Connected => "SuccessBrush",
            WindowsProxyConnectionState.Starting => "WarningBrush",
            WindowsProxyConnectionState.Error => "DangerBrush",
            _ => "PrimaryBrush",
        };
        ProxyStatusTitle.Foreground = FindResource(titleBrushKey) as Brush ?? Brushes.Black;
        ProxyStatusText.Foreground = snapshot.State == WindowsProxyConnectionState.Error
            ? (FindResource("DangerBrush") as Brush ?? Brushes.DarkRed)
            : (FindResource("MutedTextBrush") as Brush ?? Brushes.Gray);
        ProxyStatusBanner.BorderBrush = FindResource(borderBrushKey) as Brush ?? Brushes.Gray;
        ProxyStatusBanner.Background = new SolidColorBrush(snapshot.State switch
        {
            WindowsProxyConnectionState.Connected => Color.FromRgb(236, 253, 245),
            WindowsProxyConnectionState.Starting => Color.FromRgb(255, 251, 235),
            WindowsProxyConnectionState.Error => Color.FromRgb(254, 242, 242),
            _ => Color.FromRgb(239, 246, 255),
        });

        ProxyStartButton.IsEnabled = snapshot.State is not WindowsProxyConnectionState.Connected
            and not WindowsProxyConnectionState.Starting;
        ProxyStopButton.IsEnabled = snapshot.State is WindowsProxyConnectionState.Connected
            or WindowsProxyConnectionState.Starting;
        ProxyLocalListenPortCombo.IsEnabled = snapshot.State is not WindowsProxyConnectionState.Connected
            and not WindowsProxyConnectionState.Starting;
        ProxyStartButton.Content = FindResource(snapshot.State switch
        {
            WindowsProxyConnectionState.Connected => "ProxyRunning",
            WindowsProxyConnectionState.Starting => "ProxyStarting",
            _ => "ProxyStart",
        }) as string ?? "Start proxy";
        ProxyStartButton.Background = FindResource(snapshot.State switch
        {
            WindowsProxyConnectionState.Connected => "SuccessBrush",
            WindowsProxyConnectionState.Starting => "WarningBrush",
            _ => "PrimaryBrush",
        }) as Brush ?? Brushes.DodgerBlue;
        ProxyTestUdpButton.IsEnabled = snapshot.State == WindowsProxyConnectionState.Connected
            && snapshot.Mode != WindowsProxyMode.Tun;
        UpdateNativeOutboundUi();
    }

    private void SetProxyStarting()
    {
        ProxyStatusTitle.Text = FindResource("ProxyStarting") as string ?? "Starting proxy";
        ProxyStatusText.Text = FindResource("ProxyStarting") as string ?? "Starting proxy";
        ProxyStatusTitle.Foreground = FindResource("WarningBrush") as Brush ?? Brushes.DarkOrange;
        ProxyStatusText.Foreground = FindResource("WarningBrush") as Brush ?? Brushes.DarkOrange;
        ProxyStatusBanner.BorderBrush = FindResource("WarningBrush") as Brush ?? Brushes.DarkOrange;
        ProxyStatusBanner.Background = new SolidColorBrush(Color.FromRgb(255, 251, 235));
        ProxyStartButton.IsEnabled = false;
        ProxyStopButton.IsEnabled = true;
        ProxyLocalListenPortCombo.IsEnabled = false;
    }

    private void SetProxyReady()
    {
        ApplyProxyVisualState();
        if (_vm.ProxySnapshot.State == WindowsProxyConnectionState.Disconnected)
            ProxyStatusText.Text = FindResource("ProxyReady") as string ?? "Ready";
    }

    private void SetProxyStatusError(string message)
    {
        ProxyStatusTitle.Text = FindResource("ProxyError") as string ?? "Proxy failed";
        ProxyStatusText.Text = string.IsNullOrWhiteSpace(message) ? "Unknown error" : message;
        ProxyStatusTitle.Foreground = FindResource("DangerBrush") as Brush ?? Brushes.DarkRed;
        ProxyStatusText.Foreground = FindResource("DangerBrush") as Brush ?? Brushes.DarkRed;
        ProxyStatusBanner.BorderBrush = FindResource("DangerBrush") as Brush ?? Brushes.DarkRed;
        ProxyStatusBanner.Background = new SolidColorBrush(Color.FromRgb(254, 242, 242));
        ProxyStartButton.IsEnabled = true;
        ProxyStopButton.IsEnabled = false;
        ProxyLocalListenPortCombo.IsEnabled = true;
    }

    private void SetProxyTransientStatus(string message, bool error, bool preserveTitle)
    {
        if (error)
        {
            SetProxyStatusError(message);
            return;
        }
        if (!preserveTitle)
            SetProxyReady();
        ProxyStatusText.Text = message;
    }

    private static string ResolveNetchModeDirectory(string selectedPath)
    {
        var path = selectedPath?.Trim() ?? string.Empty;
        if (Directory.Exists(Path.Combine(path, "mode")))
            return Path.Combine(path, "mode");
        return path;
    }

    private void UpdateUpdatePromptText()
    {
        var template = FindResource("UpdateVersionReady") as string ?? "Version {0} is ready to install.";
        var version = string.IsNullOrWhiteSpace(_vm.AvailableUpdateVersion) ? "—" : _vm.AvailableUpdateVersion;
        UpdateVersionText.Text = string.Format(template, version);
    }

    private void UpdateVisiblePanel()
    {
        DashboardPanel.Visibility = _vm.CurrentView == "Dashboard" ? Visibility.Visible : Visibility.Collapsed;
        ProxyPanel.Visibility = _vm.CurrentView == "Proxy" ? Visibility.Visible : Visibility.Collapsed;
        LogPanel.Visibility = _vm.CurrentView == "Log" ? Visibility.Visible : Visibility.Collapsed;
        SettingsPanel.Visibility = _vm.CurrentView == "Settings" ? Visibility.Visible : Visibility.Collapsed;

        // Forwards view uses the same dashboard panel (forwards section)
        if (_vm.CurrentView == "Forwards")
            DashboardPanel.Visibility = Visibility.Visible;
    }

    private void PopulateSettings()
    {
        var normalizedMasterUrl = AppDefaults.NormalizeMasterUrl(App.Config.MasterUrl);
        SettMasterUrl.Text = string.IsNullOrWhiteSpace(normalizedMasterUrl)
            ? AppDefaults.DefaultMasterUrl
            : normalizedMasterUrl;
        SettClientId.Text = (App.Config.ClientId > 0 ? App.Config.ClientId : App.Config.ActiveClientId).ToString();
        SettAgentToken.Password = App.Config.AgentToken;
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

    private void OnSignOut_Click(object sender, RoutedEventArgs e)
    {
        _vm.StopProxyForShutdown();
        _vm.DisconnectCommand.Execute(null);
        _vm.SignOutForTests();
        var stateManager = new StateManager(Path.GetDirectoryName(App.ConfigPath)!);
        stateManager.SaveConfig(App.ConfigPath, _vm.Config);
        TxtAgentToken.Password = string.Empty;
        TxtClientId.Text = string.Empty;
        SetupStatus.Text = string.Empty;
        PopulateSettings();
    }


    private async void OnCheckForUpdates_Click(object sender, RoutedEventArgs e)
    {
        await CheckForUpdatesAsync(_lifecycleCts.Token);
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

    private async Task CheckForUpdatesAsync(CancellationToken ct = default)
    {
        try
        {
            if (string.IsNullOrWhiteSpace(App.Config.MasterUrl))
                return;

            ct.ThrowIfCancellationRequested();
            var artifact = await _updateService.FetchWindowsClientArtifactAsync(App.Config.MasterUrl, ct);
            ct.ThrowIfCancellationRequested();
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
            if (ct.IsCancellationRequested || _vm.IsShuttingDown)
                return;
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

        var artifactUrl = WindowsAppUpdateService.ResolveArtifactUrl(App.Config.MasterUrl, artifact.ArtifactUrl);
        artifactUrl = WindowsAppUpdateService.BuildArtifactDownloadUrl(artifactUrl, artifact.Version, artifact.Sha256);
        await _updateService.DownloadInstallerAsync(artifactUrl, installerPath);
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
            base.OnClosing(e);
            return;
        }

        // Closing can be raised more than once (for example when an explicit
        // Application.Shutdown follows a tray exit). Keep the first event
        // cancellable while asynchronous cleanup runs, then allow the final
        // close through after all managed work has been stopped.
        if (_shutdownCloseFinalizing)
        {
            base.OnClosing(e);
            return;
        }

        if (_shutdownCloseRequested)
        {
            e.Cancel = true;
            base.OnClosing(e);
            return;
        }

        e.Cancel = true;
        _shutdownCloseRequested = true;
        _trayLifecycle.MarkClosing();
        try { _lifecycleCts.Cancel(); }
        catch (ObjectDisposedException) { }
        _vm.RequestShutdown();
        DisposeTrayIcon();
        Hide();
        _ = CompleteShutdownAndCloseAsync();
        base.OnClosing(e);
    }

    private async Task CompleteShutdownAndCloseAsync()
    {
        Task? shutdownTask = null;
        try
        {
            // Signal VM shutdown immediately, then let the startup state
            // machine observe cancellation in parallel. Waiting for startup
            // first can add a full network timeout to every user exit.
            shutdownTask = _vm.ShutdownForExitAsync();
            try
            {
                await WaitForTaskAsync(_startupTask, TimeSpan.FromSeconds(8)).ConfigureAwait(true);
            }
            catch (Exception ex)
            {
                Log.Warning(ex, "window startup task did not finish cleanly during shutdown");
            }

            await shutdownTask.WaitAsync(TimeSpan.FromSeconds(45)).ConfigureAwait(true);
        }
        catch (TimeoutException)
        {
            Log.Warning("desktop shutdown exceeded its wait budget; forcing application exit");
        }
        catch (Exception ex)
        {
            Log.Warning(ex, "desktop shutdown cleanup failed; forcing application exit");
        }
        finally
        {
            _shutdownCloseFinalizing = true;
            RequestApplicationShutdown();
        }
    }

    protected override void OnClosed(EventArgs e)
    {
        DisposeTrayIcon();
        _vm.DisposeProxyForShutdown();
        _updateService.Dispose();
        RequestApplicationShutdown();
        base.OnClosed(e);
    }

    private static async Task WaitForTaskAsync(Task? task, TimeSpan timeout)
    {
        if (task is null)
            return;
        try { await task.WaitAsync(timeout).ConfigureAwait(true); }
        catch (OperationCanceledException) { }
    }

    private static void RequestApplicationShutdown()
    {
        var app = System.Windows.Application.Current;
        if (app?.Dispatcher is null || app.Dispatcher.HasShutdownStarted || app.Dispatcher.HasShutdownFinished)
            return;
        try { app.Shutdown(0); }
        catch (InvalidOperationException) { }
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

    private async Task TryAutoConnectAsync(CancellationToken ct)
    {
        ct.ThrowIfCancellationRequested();
        var normalizedMasterUrl = AppDefaults.NormalizeMasterUrl(App.Config.MasterUrl);
        if (!string.Equals(App.Config.MasterUrl, normalizedMasterUrl, StringComparison.Ordinal))
        {
            App.Config.MasterUrl = normalizedMasterUrl;
            var stateManager = new StateManager(Path.GetDirectoryName(App.ConfigPath)!);
            stateManager.SaveConfig(App.ConfigPath, App.Config);
            TxtMasterUrl.Text = App.Config.MasterUrl;
            SettMasterUrl.Text = App.Config.MasterUrl;
        }

        if (!_vm.ShouldAutoConnectOnStartup)
            return;

        try
        {
            await _vm.ConnectCommand.ExecuteAsync(null);
        }
        catch (OperationCanceledException) when (ct.IsCancellationRequested || _vm.IsShuttingDown)
        {
        }
        catch (Exception ex)
        {
            Log.Warning(ex, "automatic connection failed");
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
        if (!Dispatcher.CheckAccess())
        {
            Dispatcher.BeginInvoke(new Action(ExitFromTray));
            return;
        }
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
