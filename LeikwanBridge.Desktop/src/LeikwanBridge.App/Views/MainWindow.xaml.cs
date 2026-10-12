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
    // True until the constructor finishes so XAML-time SelectedIndex changes
    // never persist an endpoint the user did not choose.
    private bool _suppressEndpointChangeHandling = true;
    private bool _updateFailureWasInstall;
    private readonly WindowsAppUpdateService _updateService = new();
    private readonly CancellationTokenSource _lifecycleCts = new();
    private readonly ConnectionRecoverySupervisor _connectionRecovery;
    private Task? _startupTask;
    private bool _shutdownCloseRequested;
    private bool _shutdownCloseFinalizing;
    private bool _saveLoginInfo;
    private bool _suppressEgressSelectionHandling;

    public MainWindow()
    {
        InitializeComponent();
        _vm = (MainViewModel)DataContext;
        _connectionRecovery = new ConnectionRecoverySupervisor(
            async _ =>
            {
                await Dispatcher.InvokeAsync(async () =>
                {
                    await _vm.ConnectCommand.ExecuteAsync(null);
                    if (!_vm.IsConnected)
                        throw new InvalidOperationException("connection_not_ready");
                }).Task.Unwrap();
            });
        if (!App.Config.RememberLoginInfo)
        {
            App.Config.AgentToken = string.Empty;
            App.Config.ClientId = 0;
            App.Config.ActiveClientId = 0;
            App.Config.ActiveClientName = string.Empty;
            App.Config.DesktopSessionToken = string.Empty;
            App.Config.SignedInUserId = 0;
            App.Config.SignedInUsername = string.Empty;
            App.Config.SignedInEmail = string.Empty;
            App.Config.AvailableClients = [];
        }
        _vm.Initialize(App.Config);
        _saveLoginInfo = App.Config.RememberLoginInfo;
        if (!_saveLoginInfo)
            new StateManager(Path.GetDirectoryName(App.ConfigPath)!).SaveConfig(App.ConfigPath, App.Config);
        _vm.PropertyChanged += OnViewModelPropertyChanged;
        _vm.RetryUpdateHandler = RetryFailedUpdateAsync;
        _vm.SpeedHistoryChanged += () => DashSpeedChart.SetSamples(_vm.SpeedInHistory, _vm.SpeedOutHistory);
        _vm.CloudPairingsApplied += () =>
        {
            SaveCurrentConfig();
            ForwardPairingProfileList.SelectedValue = _vm.SelectedProxyProfileId;
        };
        SourceInitialized += (_, _) => ThemeManager.ApplyTitleBar(this);
        ThemeManager.ThemeChanged += OnThemeChanged;

        // Auto-scroll log
        _vm.LogEntries.CollectionChanged += (_, _) =>
        {
            if (LogListBox.Items.Count > 0)
                LogListBox.ScrollIntoView(LogListBox.Items[^1]);
        };

        // Populate settings fields
        PopulateSettings();
        UpdateVisiblePanel();
        _suppressEndpointChangeHandling = true;
        try { SelectEndpoint(SetupEndpointCombo, App.Config.MasterUrl); }
        finally { _suppressEndpointChangeHandling = false; }
        TxtMasterUrl.Text = string.IsNullOrWhiteSpace(App.Config.MasterUrl)
            ? AppDefaults.DefaultMasterUrl
            : App.Config.MasterUrl;
        SetupSaveInfoCheckBox.IsChecked = _saveLoginInfo;
        RestoreSetupLoginInfo();
        PopulateSetupEgressInterfaces();
        _suppressEndpointChangeHandling = false;

        UpdateCoreToggle();
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
            await TryAutoConnectAsync(ct);
            ct.ThrowIfCancellationRequested();
            await TryStartRequestedProxyAsync(ct);
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
        TxtMasterUrl.Text = ResolveEndpointUrl(SetupEndpointCombo);
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
        // The adapter choice must be in force before the first sign-in request.
        EnsureSetupEgressApplied();

        try
        {
            using var api = new MasterApiClient(
                masterUrl,
                agentToken,
                MasterApiClient.BuildWindowsDeviceId(clientId, Environment.MachineName),
                $"Leikwan Bridge on {Environment.MachineName}",
                "windows-desktop");
            var deviceLimit = default(DeviceLimitReachedException);
            if (!await api.TestConnectionAsync())
            {
                deviceLimit = api.LastDeviceLimit;
                if (deviceLimit is null)
                {
                    SetupStatus.Text = string.IsNullOrWhiteSpace(api.LastErrorMessage)
                        ? (FindResource("SetupFailed") as string ?? "Connection failed.")
                        : api.LastErrorMessage;
                    return;
                }
                // The panel accepted the token and only refused this device
                // because the online device limit is full. Save the profile and
                // continue: the main window keeps the explanation visible and
                // rejoins automatically once a slot frees.
                SetupStatus.Text = MainViewModel.FormatDeviceLimitMessage(deviceLimit);
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
            _saveLoginInfo = SetupSaveInfoCheckBox.IsChecked == true;

            var stateManager = new StateManager(Path.GetDirectoryName(App.ConfigPath)!);
            _vm.Config.RememberLoginInfo = _saveLoginInfo;
            stateManager.SaveConfig(App.ConfigPath, BuildPersistedLoginConfig());
            if (deviceLimit is null)
                SetupStatus.Text = FindResource("SetupSuccess") as string ?? "Connection successful!";
            await _vm.ConnectCommand.ExecuteAsync(null);
            if (!_saveLoginInfo)
                ClearSetupLoginInfo();
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
        SettMasterUrl.Text = ResolveEndpointUrl(SettEndpointCombo);
        App.Config.MasterUrl = AppDefaults.NormalizeMasterUrl(SettMasterUrl.Text);
        SettMasterUrl.Text = App.Config.MasterUrl;
        if (int.TryParse(SettClientId.Text.Trim(), out var clientId) && clientId > 0)
        {
            App.Config.ClientId = clientId;
            App.Config.ActiveClientId = clientId;
        }
        if (!string.IsNullOrWhiteSpace(SettAgentToken.Password))
            App.Config.AgentToken = SettAgentToken.Password.Trim();
        _saveLoginInfo = SettSaveInfoCheckBox.IsChecked == true;
        if (int.TryParse(SettPollInterval.Text.Trim(), out int poll) && poll > 0)
            App.Config.PollIntervalSeconds = poll;
        if (int.TryParse(SettHealthInterval.Text.Trim(), out int health) && health > 0)
            App.Config.HealthCheckIntervalSeconds = health;
        if (SettEgressInterface.SelectedValue is string egressInterface)
            ApplyEgressInterface(egressInterface, persist: false);
        if (SettLogLevel.SelectedItem is ComboBoxItem logLevel)
            App.Config.LogLevel = DesktopLogLevel.Normalize(logLevel.Tag?.ToString());
        App.Config.AutoStart = SettAutoStart.IsChecked == true;
        App.Config.MinimizeToTray = SettMinimizeTray.IsChecked == true;

        if (SettLanguage.SelectedItem is ComboBoxItem lang)
        {
            var langTag = lang.Tag?.ToString() ?? "en-US";
            App.Config.Language = langTag;
            App.SwitchLanguage(langTag);
            ApplyProxyVisualState();
        }

        App.Config.RememberLoginInfo = _saveLoginInfo;
        var stateManager = new StateManager(Path.GetDirectoryName(App.ConfigPath)!);
        stateManager.SaveConfig(App.ConfigPath, BuildPersistedLoginConfig());
        App.ApplyLogLevel(App.Config.LogLevel);

        // Handle auto-start
        SetAutoStart(App.Config.AutoStart);

        SettingsStatus.Text = FindResource("SettingsSaved") as string ?? "Settings saved.";
        if (!_saveLoginInfo)
            ClearSettingsLoginInfo();
    }

    private void OnEndpointSelectionChanged(object sender, SelectionChangedEventArgs e)
    {
        if (sender is not System.Windows.Controls.ComboBox combo)
            return;

        var url = ResolveEndpointUrl(combo);
        if (ReferenceEquals(combo, SetupEndpointCombo) && TxtMasterUrl is not null)
            TxtMasterUrl.Text = url;
        else if (ReferenceEquals(combo, SettEndpointCombo) && SettMasterUrl is not null)
            SettMasterUrl.Text = url;

        if (_suppressEndpointChangeHandling)
            return;

        // Persist the chosen endpoint immediately so update downloads and
        // future sessions follow the selection without waiting for a Save.
        var normalized = AppDefaults.NormalizeMasterUrl(url);
        if (!string.Equals(normalized, App.Config.MasterUrl, StringComparison.Ordinal))
        {
            App.Config.MasterUrl = normalized;
            SaveCurrentConfig();
            Log.Information("update download endpoint changed to {Endpoint}", normalized);
        }
    }

    private static string ResolveEndpointUrl(System.Windows.Controls.ComboBox combo)
    {
        var key = (combo.SelectedItem as ComboBoxItem)?.Tag?.ToString();
        return AppDefaults.ResolveConnectionEndpoint(key);
    }

    private static void SelectEndpoint(System.Windows.Controls.ComboBox combo, string? url)
    {
        var key = AppDefaults.GetConnectionEndpointKey(url);
        var selected = combo.Items
            .OfType<ComboBoxItem>()
            .FirstOrDefault(item => string.Equals(item.Tag?.ToString(), key, StringComparison.OrdinalIgnoreCase));
        if (selected is not null)
            combo.SelectedItem = selected;
    }

    private void RestoreSetupLoginInfo()
    {
        if (!_saveLoginInfo)
        {
            ClearSetupLoginInfo();
            return;
        }

        TxtClientId.Text = (App.Config.ClientId > 0 ? App.Config.ClientId : App.Config.ActiveClientId)
            .ToString(CultureInfo.InvariantCulture);
        TxtAgentToken.Password = App.Config.AgentToken;
    }

    private void ClearSetupLoginInfo()
    {
        TxtClientId.Text = string.Empty;
        TxtAgentToken.Password = string.Empty;
    }

    private void ClearSettingsLoginInfo()
    {
        SettClientId.Text = string.Empty;
        SettAgentToken.Password = string.Empty;
    }

    // The sign-in selector and the settings page share one option list (with a
    // localized Automatic entry) and one field: App.Config.PreferredEgressInterface.
    private IReadOnlyList<EgressInterfaceOption> BuildEgressOptions()
        => EgressInterfaceCatalog.Enumerate(TryFindResource("SetupEgressAutomatic") as string);

    private void PopulateSetupEgressInterfaces()
    {
        var options = BuildEgressOptions();
        var choice = EgressInterfaceSelection.Reconcile(options, App.Config.PreferredEgressInterface);
        _suppressEgressSelectionHandling = true;
        try
        {
            SetupEgressInterface.ItemsSource = options;
            SetupEgressInterface.SelectedValue = choice.SelectedId;
        }
        finally
        {
            _suppressEgressSelectionHandling = false;
        }
        ShowSetupEgressNotice(choice);
    }

    private void ShowSetupEgressNotice(EgressInterfaceChoice choice)
    {
        if (!choice.FellBackToAutomatic)
        {
            SetupEgressNotice.Text = string.Empty;
            SetupEgressNotice.Visibility = Visibility.Collapsed;
            return;
        }

        var template = TryFindResource("SetupEgressMissing") as string
            ?? "The saved adapter \"{0}\" is not available. Automatic is used instead.";
        SetupEgressNotice.Text = string.Format(CultureInfo.CurrentCulture, template, choice.MissingInterface);
        SetupEgressNotice.Visibility = Visibility.Visible;
    }

    private void OnSetupEgressInterfaceChanged(object sender, SelectionChangedEventArgs e)
    {
        if (_suppressEgressSelectionHandling || SetupEgressInterface.SelectedValue is not string selected)
            return;
        ShowSetupEgressNotice(new EgressInterfaceChoice(selected, string.Empty));
        ApplyEgressInterface(selected);
    }

    private void OnSetupEgressRefresh_Click(object sender, RoutedEventArgs e)
        => PopulateSetupEgressInterfaces();

    /// <summary>
    /// Re-validates the sign-in selection against the current adapters (one
    /// may have been unplugged since the list was drawn) and applies it, so a
    /// vanished adapter falls back to Automatic before the request is sent.
    /// </summary>
    private void EnsureSetupEgressApplied()
    {
        var options = BuildEgressOptions();
        var requested = SetupEgressInterface.SelectedValue as string ?? App.Config.PreferredEgressInterface;
        var choice = EgressInterfaceSelection.Reconcile(options, requested);
        if (choice.FellBackToAutomatic)
        {
            _suppressEgressSelectionHandling = true;
            try
            {
                SetupEgressInterface.ItemsSource = options;
                SetupEgressInterface.SelectedValue = choice.SelectedId;
            }
            finally
            {
                _suppressEgressSelectionHandling = false;
            }
            ShowSetupEgressNotice(choice);
        }
        ApplyEgressInterface(choice.SelectedId);
    }

    /// <summary>
    /// Puts an adapter choice into force for panel direct egress and Ultra,
    /// keeps both selectors in sync, and (from the sign-in page) persists it.
    /// </summary>
    private void ApplyEgressInterface(string? interfaceName, bool persist = true)
    {
        App.Config.PreferredEgressInterface = interfaceName?.Trim() ?? string.Empty;
        PanelDirectEgress.SetPreferredInterface(App.Config.PreferredEgressInterface);
        _vm.SetPreferredEgressInterface(App.Config.PreferredEgressInterface);
        _suppressEgressSelectionHandling = true;
        try
        {
            SetupEgressInterface.SelectedValue = App.Config.PreferredEgressInterface;
            SettEgressInterface.SelectedValue = App.Config.PreferredEgressInterface;
        }
        finally
        {
            _suppressEgressSelectionHandling = false;
        }

        if (!persist)
            return;
        try
        {
            var stateManager = new StateManager(Path.GetDirectoryName(App.ConfigPath)!);
            stateManager.SaveConfig(App.ConfigPath, BuildPersistedLoginConfig());
        }
        catch (Exception ex)
        {
            Log.Warning(ex, "egress interface preference could not be persisted");
        }
    }

    private AgentConfig BuildPersistedLoginConfig()
    {
        var persisted = _vm.Config.Clone();
        persisted.RememberLoginInfo = _saveLoginInfo;
        if (!_saveLoginInfo)
        {
            persisted.AgentToken = string.Empty;
            persisted.ClientId = 0;
            persisted.ActiveClientId = 0;
            persisted.ActiveClientName = string.Empty;
            persisted.DesktopSessionToken = string.Empty;
            persisted.SignedInUserId = 0;
            persisted.SignedInUsername = string.Empty;
            persisted.SignedInEmail = string.Empty;
            persisted.AvailableClients = [];
        }
        return persisted;
    }

    private void PopulateProxyUi()
    {
        if (!_proxyUiReady)
        {
            _proxyUiReady = true;
        }

        var selected = _vm.ProxyProfiles.FirstOrDefault(profile => profile.Id == _vm.SelectedProxyProfileId);
        ProxyProfileList.SelectedItem = selected;
        ForwardPairingProfileList.SelectedItem = selected;
        _vm.ProxyEditor.Load(selected);
        ProxyPassword.Password = _vm.ProxyEditor.Password;
        PairingProxyPassword.Password = _vm.ProxyEditor.Password;
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
            ? parsed : (WindowsProxyMode?)null;
        ProxyModeCombo.SelectedIndex = !App.Config.ProxyModeChosen ? 0 : mode switch
        {
            WindowsProxyMode.Http => 1,
            WindowsProxyMode.Tun => 2,
            WindowsProxyMode.Process => 3,
            _ => 0,
        };
        _vm.IsProxyModeSelected = App.Config.ProxyModeChosen && mode is not null;
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
    }

    private void OnProxyForwardSelectionChanged(object sender, SelectionChangedEventArgs e)
    {
        if (!_proxyUiReady || ProxyForwardCombo.SelectedValue is not int forwardId || forwardId <= 0)
            return;
        _vm.SelectedProxyForwardId = forwardId;
        ProxyProfileList.SelectedValue = _vm.SelectedProxyProfileId;
        ProxyPassword.Password = _vm.ProxyEditor.Password;
        SaveCurrentConfig();
    }

    private void OnProxyPairedForwardSelectionChanged(object sender, SelectionChangedEventArgs e)
    {
        if (!_proxyUiReady || ProxyPairedForwardCombo.SelectedValue is not int forwardId || forwardId <= 0)
            return;
        _vm.SelectedProxyForwardId = forwardId;
        _vm.SelectedProxyProfileId = _vm.GetProxyProfileIdForForward(forwardId);
        SaveCurrentConfig();
    }

    private void OnProxyPanelSizeChanged(object sender, SizeChangedEventArgs e)
    {
        if (ProxySidebarColumn is not null)
            ProxySidebarColumn.Width = new GridLength(0);
    }

    private void OnProxyRowProfileSelectionChanged(object sender, SelectionChangedEventArgs e)
    {
        if (!_proxyUiReady || e.AddedItems.Count == 0)
            return;
        if (sender is System.Windows.Controls.ComboBox { DataContext: ProxyForwardRowViewModel row }
            && row.SelectedProfileId >= 0)
            SaveCurrentConfig();
    }

    private void OnPairingProfileSelectionChanged(object sender, SelectionChangedEventArgs e)
    {
        if (!_proxyUiReady || ForwardPairingProfileList.SelectedItem is not WindowsProxyProfile profile)
            return;
        _vm.ProxyEditor.Load(profile);
        PairingProxyPassword.Password = profile.Password;
    }

    private void OnPairingNew_Click(object sender, RoutedEventArgs e)
    {
        ForwardPairingProfileList.SelectedItem = null;
        _vm.ProxyEditor.Load(null);
        PairingProxyPassword.Password = string.Empty;
        ForwardPairingNodeEditorExpander.IsExpanded = true;
        ForwardPairingNodeEditorExpander.BringIntoView();
    }

    private void OnPairingEdit_Click(object sender, RoutedEventArgs e)
    {
        if (ForwardPairingProfileList.SelectedItem is not WindowsProxyProfile profile)
            return;
        _vm.ProxyEditor.Load(profile);
        PairingProxyPassword.Password = profile.Password;
        ForwardPairingNodeEditorExpander.IsExpanded = true;
        ForwardPairingNodeEditorExpander.BringIntoView();
    }

    private async void OnPairingImport_Click(object sender, RoutedEventArgs e)
    {
        try
        {
            var result = await _vm.ImportNodesAsync(PairingImportUri.Text);
            SaveCurrentConfig();
            PopulateProxyUi();
            PairingImportUri.Clear();
            ForwardPairingProfileList.SelectedValue = _vm.SelectedProxyProfileId;
            PairingProxyPassword.Password = _vm.ProxyEditor.Password;
            ForwardPairingStatus.Text = FindResource("ForwardPairingSaved") as string ?? "Node saved";
            SetNodesStatus(DescribeImport(result), error: false);
        }
        catch (Exception exception)
        {
            SetNodesStatus(DescribeNodeError(exception), error: true);
            SetProxyStatusError(exception.Message);
        }
    }

    private async void OnPairingSave_Click(object sender, RoutedEventArgs e)
    {
        _vm.ProxyEditor.Password = PairingProxyPassword.Password;
        try
        {
            await _vm.SaveProxyProfileAsync();
            SaveCurrentConfig();
            PopulateProxyUi();
            ForwardPairingProfileList.SelectedValue = _vm.SelectedProxyProfileId;
            ForwardPairingStatus.Text = FindResource("ForwardPairingSaved") as string ?? "Node saved";
            SetNodesStatus(FindResource("ForwardPairingSaved") as string ?? "Node saved", error: false);
        }
        catch (Exception ex)
        {
            SetNodesStatus(DescribeNodeError(ex), error: true);
            SetProxyStatusError(ex.Message);
            Log.Warning("failed to save pairing node: {Code}", LeikwanBridge.Core.Nodes.HostedNodeSyncService.ErrorCode(ex));
        }
    }

    private async void OnPairingDelete_Click(object sender, RoutedEventArgs e)
    {
        if (ForwardPairingProfileList.SelectedItem is not WindowsProxyProfile profile)
            return;
        if (profile.IsHosted && System.Windows.MessageBox.Show(
                this,
                string.Format(FindResource("NodesDeleteHostedConfirm") as string ?? "Delete {0} from the cloud and all devices?", profile.Name),
                FindResource("AppTitle") as string ?? "Leikwan Bridge",
                MessageBoxButton.OKCancel,
                MessageBoxImage.Warning) != MessageBoxResult.OK)
            return;
        try
        {
            await _vm.DeleteProxyProfileAsync(profile.Id);
            SaveCurrentConfig();
            PopulateProxyUi();
            ForwardPairingStatus.Text = FindResource("ForwardPairingSaved") as string ?? "Node list updated";
            SetNodesStatus(FindResource("NodesDeleted") as string ?? "Node deleted", error: false);
        }
        catch (Exception ex)
        {
            SetNodesStatus(DescribeNodeError(ex), error: true);
            SetProxyStatusError(ex.Message);
            Log.Warning("failed to delete pairing node: {Code}", LeikwanBridge.Core.Nodes.HostedNodeSyncService.ErrorCode(ex));
        }
    }

    private void OnSaveForwardPairings_Click(object sender, RoutedEventArgs e)
    {
        SaveCurrentConfig();
        ForwardPairingStatus.Text = FindResource("ForwardPairingSaved") as string ?? "Pairings saved";
    }

    private async void OnProxyRowConnect_Click(object sender, RoutedEventArgs e)
    {
        if (sender is not FrameworkElement { DataContext: ProxyForwardRowViewModel row })
            return;
        if (!row.CanConnect)
            return;
        _vm.SelectedProxyForwardId = row.ForwardId;
        _vm.SelectedProxyProfileId = row.SelectedProfileId;
        await StartSelectedProxyAsync();
    }

    private async void OnProxyRowDisconnect_Click(object sender, RoutedEventArgs e)
    {
        if (sender is not FrameworkElement { DataContext: ProxyForwardRowViewModel row })
            return;
        try
        {
            await row.DisconnectAsync();
            SaveCurrentConfig();
        }
        catch (Exception ex)
        {
            SetProxyStatusError(ex.Message);
            Log.Warning(ex, "failed to stop local proxy for forward row");
        }
    }

    private async void OnProxySelectedConnect_Click(object sender, RoutedEventArgs e)
    {
        var row = _vm.PairedProxyForwardRows.FirstOrDefault(item => item.ForwardId == _vm.SelectedProxyForwardId);
        if (row is null || !row.CanConnect)
            return;
        try
        {
            await row.ConnectAsync();
            SaveCurrentConfig();
        }
        catch (Exception ex)
        {
            if (ex is OperationCanceledException)
            {
                SetProxyReady();
                Log.Debug(ex, "paired proxy start cancelled");
                return;
            }
            SetProxyStatusError(ex.Message);
            Log.Warning(ex, "failed to start local proxy for selected paired forward");
        }
    }

    private async void OnProxySelectedDisconnect_Click(object sender, RoutedEventArgs e)
    {
        var row = _vm.PairedProxyForwardRows.FirstOrDefault(item => item.ForwardId == _vm.SelectedProxyForwardId);
        if (row is null)
            return;
        try
        {
            await row.DisconnectAsync();
            SaveCurrentConfig();
        }
        catch (Exception ex)
        {
            SetProxyStatusError(ex.Message);
            Log.Warning(ex, "failed to stop local proxy for selected paired forward");
        }
    }

    private void OnProxyNew_Click(object sender, RoutedEventArgs e)
    {
        ProxyProfileList.SelectedItem = null;
        _vm.ProxyEditor.Load(null);
        ProxyPassword.Password = string.Empty;
        ProxyNodeEditorExpander.IsExpanded = true;
        ProxyNodeEditorExpander.BringIntoView();
        SetProxyReady();
    }

    private void OnProxyEdit_Click(object sender, RoutedEventArgs e)
    {
        if (ProxyProfileList.SelectedItem is not WindowsProxyProfile profile)
            return;
        _vm.ProxyEditor.Load(profile);
        ProxyPassword.Password = profile.Password;
        ProxyNodeEditorExpander.IsExpanded = true;
        ProxyNodeEditorExpander.BringIntoView();
    }

    private async void OnProxySave_Click(object sender, RoutedEventArgs e)
    {
        _vm.ProxyEditor.Password = ProxyPassword.Password;
        try
        {
            await _vm.SaveProxyProfileAsync();
            SaveCurrentConfig();
            PopulateProxyUi();
            ProxyNodeEditorExpander.IsExpanded = false;
            ProxyPairedForwardCombo.BringIntoView();
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

    private async Task StartSelectedProxyAsync()
    {
        if (!_vm.IsProxyModeSelected)
        {
            SetProxyStatusError("proxy_mode_required");
            return;
        }
        var profileId = _vm.SelectedProxyProfileId;
        if (profileId <= 0)
        {
            SetProxyStatusError("proxy_saved_profile_required");
            return;
        }
        if (!_vm.IsExternalProxySelected && !CaptureProxyLocalListenPort())
            return;
        var gameId = GetSelectedProxyGameId();
        if (!string.IsNullOrWhiteSpace(gameId))
        {
            _vm.SelectedProxyGameId = gameId;
            App.Config.ProxyActiveGameId = gameId;
        }
        CaptureProxyDnsSettings();
        var mode = GetSelectedProxyMode();
        SetProxyStarting();
        try
        {
            await _vm.StartProxyAsync(mode, profileId, gameId);
            RestoreProxyGameSelection(mode == WindowsProxyMode.Process ? _vm.SelectedProxyGameId : gameId);
            SaveCurrentConfig();
        }
        catch (Exception ex)
        {
            if (ex is OperationCanceledException)
            {
                SetProxyReady();
                Log.Debug(ex, "proxy start cancelled");
                return;
            }
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
            await _vm.WaitForProxyForwardSelectionAsync(ct);
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
        var tag = item.Tag?.ToString() ?? string.Empty;
        _vm.IsProxyModeSelected = Enum.TryParse<WindowsProxyMode>(tag, true, out var selectedMode);
        if (!_vm.IsProxyModeSelected)
        {
            App.Config.ProxyActiveMode = string.Empty;
            App.Config.ProxyModeChosen = false;
            UpdateProxyModeVisibility();
            return;
        }
        App.Config.ProxyActiveMode = selectedMode.ToString();
        App.Config.ProxyModeChosen = true;
        UpdateProxyModeVisibility();
        SyncDashboardModeButtons();
    }

    private void UpdateProxyModeVisibility()
    {
        if (ProxyGameCard == null || ProxyModeCombo == null)
            return;
        // The mode combo legitimately has no selection until the saved config has
        // been applied, and this runs while the window is still being built. That
        // is a normal state, not a fault: asking for the selected mode here used
        // to throw proxy_mode_required out of PopulateProxyUi, which abandoned the
        // rest of the local-proxy setup and logged "local proxy initialization
        // failed" on every single start.
        ProxyGameCard.Visibility = TryGetSelectedProxyMode(out var mode) && mode == WindowsProxyMode.Process
            ? Visibility.Visible
            : Visibility.Collapsed;
        SyncDashboardModeButtons();
    }

    private bool TryGetSelectedProxyMode(out WindowsProxyMode mode)
    {
        if (ProxyModeCombo.SelectedItem is ComboBoxItem item
            && Enum.TryParse(item.Tag?.ToString(), true, out mode))
            return true;
        mode = default;
        return false;
    }

    private void UpdateNativeOutboundUi()
    {
        if (NativeOutboundCard is null)
            return;
        var visible = _vm.CurrentView == "Proxy"
            && _vm.IsNativeOutboundVisible
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
            NativeOutboundStopButton.Visibility = _vm.ProxySnapshot.State is WindowsProxyConnectionState.Connected or WindowsProxyConnectionState.Starting
                && !_vm.ProxyForwardRows.Any(row => row.IsActive) ? Visibility.Visible : Visibility.Collapsed;
        }
        finally
        {
            _updatingNativeUi = false;
        }
    }

    private WindowsProxyMode GetSelectedProxyMode()
    {
        if (TryGetSelectedProxyMode(out var mode))
            return mode;
        throw new InvalidOperationException("proxy_mode_required");
    }

    private void OnViewModelPropertyChanged(object? sender, PropertyChangedEventArgs e)
    {
        if (e.PropertyName == nameof(MainViewModel.CurrentView))
        {
            UpdateVisiblePanel();
        }
        if (e.PropertyName == nameof(MainViewModel.ActiveForwardCount)
            || e.PropertyName == nameof(MainViewModel.ConnectionStatus)
            || e.PropertyName == nameof(MainViewModel.IsDeviceLimitReached))
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
        {
            ApplyProxyVisualState();
            UpdateCoreToggle();
        }
        if (e.PropertyName == nameof(MainViewModel.RunningTimeText))
            UpdateCoreToggle();
        if (e.PropertyName == nameof(MainViewModel.SelectedProxyProfileId))
        {
            ProxyProfileList.SelectedValue = _vm.SelectedProxyProfileId;
            ProxyPassword.Password = _vm.ProxyEditor.Password;
        }
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
        if (ProxyStatusBanner is null || ProxyStatusText is null
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
                _vm.IsExternalProxyActive
                    ? $"{snapshot.ActiveProfileName}  |  {FindResource("ProxyRouteListenPort")}: 127.0.0.1:{snapshot.ProxyPort}"
                    : $"{snapshot.ActiveProfileName}  |  {FindResource("ProxyLocalListenPort")}: 127.0.0.1:{snapshot.ProxyPort}",
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
        ProxyStatusTitle.SetResourceReference(TextBlock.ForegroundProperty, titleBrushKey);
        ProxyStatusText.SetResourceReference(TextBlock.ForegroundProperty,
            snapshot.State == WindowsProxyConnectionState.Error ? "DangerBrush" : "MutedTextBrush");
        ProxyStatusBanner.SetResourceReference(Border.BorderBrushProperty, borderBrushKey);
        ProxyStatusBanner.SetResourceReference(Border.BackgroundProperty, snapshot.State switch
        {
            WindowsProxyConnectionState.Connected => "SuccessContainerBrush",
            WindowsProxyConnectionState.Starting => "WarningContainerBrush",
            WindowsProxyConnectionState.Error => "DangerContainerBrush",
            _ => "InfoContainerBrush",
        });

        ProxyLocalListenPortCombo.IsEnabled = snapshot.State is not WindowsProxyConnectionState.Connected
            and not WindowsProxyConnectionState.Starting;
        ProxyForwardCombo.IsEnabled = ProxyLocalListenPortCombo.IsEnabled;
        ProxyPairedForwardCombo.IsEnabled = ProxyLocalListenPortCombo.IsEnabled;
        ProxyProfileList.IsEnabled = ProxyLocalListenPortCombo.IsEnabled;
        ProxyTestUdpButton.IsEnabled = snapshot.State == WindowsProxyConnectionState.Connected
            && snapshot.Mode != WindowsProxyMode.Tun && !_vm.IsExternalProxyActive;
        UpdateNativeOutboundUi();
    }

    private void SetProxyStarting()
    {
        ProxyStatusTitle.Text = FindResource("ProxyStarting") as string ?? "Starting proxy";
        ProxyStatusText.Text = FindResource("ProxyStarting") as string ?? "Starting proxy";
        ProxyStatusTitle.SetResourceReference(TextBlock.ForegroundProperty, "WarningBrush");
        ProxyStatusText.SetResourceReference(TextBlock.ForegroundProperty, "WarningBrush");
        ProxyStatusBanner.SetResourceReference(Border.BorderBrushProperty, "WarningBrush");
        ProxyStatusBanner.SetResourceReference(Border.BackgroundProperty, "WarningContainerBrush");
        ProxyLocalListenPortCombo.IsEnabled = false;
        ProxyForwardCombo.IsEnabled = false;
        ProxyPairedForwardCombo.IsEnabled = false;
        ProxyProfileList.IsEnabled = false;
    }

    private void SetProxyReady()
    {
        ApplyProxyVisualState();
        if (_vm.ProxySnapshot.State == WindowsProxyConnectionState.Disconnected)
            ProxyStatusText.Text = FindResource("ProxyReady") as string ?? "Ready";
    }

    private void SetProxyStatusError(string message)
    {
        message = message switch
        {
            "proxy_saved_profile_required" => FindResource("ProxySavedProfileRequired") as string ?? message,
            "proxy_forward_required" => FindResource("ProxyForwardRequired") as string ?? message,
            "proxy_forward_unavailable" => FindResource("ProxyForwardUnavailable") as string ?? message,
            "proxy_forward_runtime_unavailable" => FindResource("ProxyForwardRuntimeUnavailable") as string ?? message,
            "proxy_forward_policy_unavailable" => FindResource("ProxyForwardPolicyUnavailable") as string ?? message,
            "proxy_forward_readiness_timeout" => FindResource("ProxyForwardReadinessTimeout") as string ?? message,
            "proxy_forward_tcp_required" => FindResource("ProxyForwardTcpRequired") as string ?? message,
            "proxy_forward_transport_invalid" => FindResource("ProxyForwardTransportInvalid") as string ?? message,
            "proxy_external_endpoint_invalid" => FindResource("ProxyExternalEndpointInvalid") as string ?? message,
            "proxy_mode_required" => FindResource("ProxyModeSelect") as string ?? message,
            _ => message,
        };
        ProxyStatusTitle.Text = FindResource("ProxyError") as string ?? "Proxy failed";
        ProxyStatusText.Text = string.IsNullOrWhiteSpace(message) ? "Unknown error" : message;
        ProxyStatusTitle.SetResourceReference(TextBlock.ForegroundProperty, "DangerBrush");
        ProxyStatusText.SetResourceReference(TextBlock.ForegroundProperty, "DangerBrush");
        ProxyStatusBanner.SetResourceReference(Border.BorderBrushProperty, "DangerBrush");
        ProxyStatusBanner.SetResourceReference(Border.BackgroundProperty, "DangerContainerBrush");
        ProxyLocalListenPortCombo.IsEnabled = true;
        ProxyForwardCombo.IsEnabled = true;
        ProxyPairedForwardCombo.IsEnabled = true;
        ProxyProfileList.IsEnabled = true;
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
        var pairing = _vm.CurrentView == "Forwards";
        DashboardPanel.Visibility = _vm.CurrentView == "Dashboard" ? Visibility.Visible : Visibility.Collapsed;
        ForwardPairingPanel.Visibility = pairing ? Visibility.Visible : Visibility.Collapsed;
        NodesPanel.Visibility = _vm.CurrentView == "Nodes" ? Visibility.Visible : Visibility.Collapsed;
        PageTitleText.SetResourceReference(TextBlock.TextProperty, _vm.CurrentView switch
        {
            "Forwards" => "NavForwards",
            "Nodes" => "NavNodes",
            "Proxy" => "ProxyTitle",
            "Log" => "NavLog",
            "Settings" => "NavSettings",
            _ => "NavDashboard",
        });
        // The start / stop button belongs to the overview pages; the proxy page has its own controls.
        CoreToggleButton.Visibility = _vm.CurrentView is "Dashboard" or "Forwards" or "Nodes"
            ? Visibility.Visible
            : Visibility.Collapsed;
        ProxyPanel.Visibility = _vm.CurrentView == "Proxy" ? Visibility.Visible : Visibility.Collapsed;
        LogPanel.Visibility = _vm.CurrentView == "Log" ? Visibility.Visible : Visibility.Collapsed;
        SettingsPanel.Visibility = _vm.CurrentView == "Settings" ? Visibility.Visible : Visibility.Collapsed;
        SidebarStartAllButton.Visibility = pairing ? Visibility.Collapsed : Visibility.Visible;
        SidebarStopAllButton.Visibility = pairing ? Visibility.Collapsed : Visibility.Visible;
        ProxySavedNodesSidebar.Visibility = Visibility.Collapsed;
        if (ProxySidebarColumn is not null)
            ProxySidebarColumn.Width = new GridLength(0);
        UpdateNativeOutboundUi();
    }

    private void PopulateSettings()
    {
        PopulateThemeSelection();
        var normalizedMasterUrl = AppDefaults.NormalizeMasterUrl(App.Config.MasterUrl);
        SettMasterUrl.Text = string.IsNullOrWhiteSpace(normalizedMasterUrl)
            ? AppDefaults.DefaultMasterUrl
            : normalizedMasterUrl;
        _suppressEndpointChangeHandling = true;
        try { SelectEndpoint(SettEndpointCombo, SettMasterUrl.Text); }
        finally { _suppressEndpointChangeHandling = false; }
        _saveLoginInfo = App.Config.RememberLoginInfo;
        SettClientId.Text = (App.Config.ClientId > 0 ? App.Config.ClientId : App.Config.ActiveClientId).ToString();
        SettAgentToken.Password = App.Config.AgentToken;
        SettSaveInfoCheckBox.IsChecked = _saveLoginInfo;
        if (!_saveLoginInfo)
            ClearSettingsLoginInfo();
        SettPollInterval.Text = App.Config.PollIntervalSeconds.ToString();
        SettHealthInterval.Text = App.Config.HealthCheckIntervalSeconds.ToString();
        SettEgressInterface.ItemsSource = BuildEgressOptions();
        SettEgressInterface.SelectedValue = App.Config.PreferredEgressInterface ?? string.Empty;
        SelectLogLevel(App.Config.LogLevel);
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

    private void SelectLogLevel(string? value)
    {
        var normalized = DesktopLogLevel.Normalize(value);
        var selected = SettLogLevel.Items
            .OfType<ComboBoxItem>()
            .FirstOrDefault(item => string.Equals(item.Tag?.ToString(), normalized, StringComparison.Ordinal));
        if (selected is not null)
            SettLogLevel.SelectedItem = selected;
    }

    private void OnSignOut_Click(object sender, RoutedEventArgs e)
    {
        _vm.StopProxyForShutdown();
        _vm.DisconnectCommand.Execute(null);
        _vm.SignOutForTests();
        var stateManager = new StateManager(Path.GetDirectoryName(App.ConfigPath)!);
        stateManager.SaveConfig(App.ConfigPath, BuildPersistedLoginConfig());
        TxtAgentToken.Password = string.Empty;
        TxtClientId.Text = string.Empty;
        SetupSaveInfoCheckBox.IsChecked = _saveLoginInfo;
        SettSaveInfoCheckBox.IsChecked = _saveLoginInfo;
        SetupStatus.Text = string.Empty;
        PopulateSettings();
        PopulateSetupEgressInterfaces();
    }


    private async void OnCheckForUpdates_Click(object sender, RoutedEventArgs e)
    {
        await CheckForUpdatesAsync(_lifecycleCts.Token);
    }

    private async void OnInstallUpdateNow_Click(object sender, RoutedEventArgs e)
    {
        if (_vm.AvailableUpdateArtifact == null)
            return;

        _updateFailureWasInstall = true;
        try
        {
            var installerPath = await PrepareCachedInstallerAsync(_vm.AvailableUpdateArtifact, CreateUpdateProgress());
            var installerArguments = BuildSilentUpdateArguments(installerPath);
            LaunchInstallerAndExit(installerPath, installerArguments);
            ClearPendingUpdateState();
        }
        catch (Exception ex)
        {
            _vm.ReportUpdateFailed(ex.Message);
            Log.Warning(ex, "install-now update flow failed");
        }
    }

    private async void OnDeferUpdateUntilRestart_Click(object sender, RoutedEventArgs e)
    {
        if (_vm.AvailableUpdateArtifact == null)
            return;

        _updateFailureWasInstall = true;
        try
        {
            var artifact = _vm.AvailableUpdateArtifact;
            var installerPath = await PrepareCachedInstallerAsync(artifact, CreateUpdateProgress());
            var sha = WindowsAppUpdateService.ComputeSha256(installerPath);
            _vm.DeferUpdateForRestartForTests(artifact.Version, installerPath, sha);
            SaveCurrentConfig();
        }
        catch (Exception ex)
        {
            _vm.ReportUpdateFailed(ex.Message);
            Log.Warning(ex, "deferred update flow failed");
        }
    }

    private async Task CheckForUpdatesAsync(CancellationToken ct = default)
    {
        _updateFailureWasInstall = false;
        try
        {
            if (string.IsNullOrWhiteSpace(App.Config.MasterUrl))
                return;

            ct.ThrowIfCancellationRequested();
            var artifact = await _updateService.FetchWindowsClientArtifactAsync(
                App.Config.MasterUrl,
                CreateUpdateProgress(),
                ct);
            ct.ThrowIfCancellationRequested();
            var localVersion = GetCurrentAppVersion();
            App.Config.LastUpdateCheckAt = DateTimeOffset.UtcNow.ToString("O");
            SaveCurrentConfig();
            if (WindowsAppUpdateService.IsRemoteVersionNewer(artifact.Version, localVersion)
                || WindowsAppUpdateService.HasRuntimeDrift(App.Config, artifact))
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

    private async Task RetryFailedUpdateAsync()
    {
        try
        {
            if (_updateFailureWasInstall && _vm.AvailableUpdateArtifact is { } artifact)
            {
                var installerPath = await PrepareCachedInstallerAsync(artifact, CreateUpdateProgress());
                var installerArguments = BuildSilentUpdateArguments(installerPath);
                LaunchInstallerAndExit(installerPath, installerArguments);
                ClearPendingUpdateState();
                return;
            }

            await CheckForUpdatesAsync(_lifecycleCts.Token);
        }
        catch (Exception ex)
        {
            _vm.ReportUpdateFailed(ex.Message);
            Log.Warning(ex, "update retry failed");
        }
    }

    private Progress<UpdateProgress> CreateUpdateProgress()
        => new(update => _vm.ReportUpdateProgress(update));

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
            LaunchInstallerAndExit(path, installerArguments);
            ClearPendingUpdateState();
            return Task.FromResult(true);
        }
        catch (Exception ex)
        {
            ClearPendingUpdateState();
            Log.Warning(ex, "pending update could not be applied");
            return Task.FromResult(false);
        }
    }

    private async Task<string> PrepareCachedInstallerAsync(
        WindowsClientAppArtifact artifact,
        IProgress<UpdateProgress>? progress = null)
    {
        var installRoot = Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData), "LeiKwan Bridge");
        var installerPath = WindowsAppUpdateService.BuildCachedInstallerPath(installRoot, artifact.Version);

        if (File.Exists(installerPath))
        {
            var cachedSha = WindowsAppUpdateService.ComputeSha256(installerPath);
            if (string.Equals(cachedSha, artifact.Sha256, StringComparison.OrdinalIgnoreCase))
            {
                progress?.Report(UpdateProgress.Verifying(100, "Cached installer verified."));
                return installerPath;
            }
        }

        // ResolveArtifactUrl rebinds the selected endpoint onto the artifact
        // URL, so manifest, installer and runtime-drift downloads all follow
        // the user's chosen download source.
        var artifactUrl = WindowsAppUpdateService.ResolveArtifactUrl(App.Config.MasterUrl, artifact.ArtifactUrl);
        artifactUrl = WindowsAppUpdateService.BuildArtifactDownloadUrl(artifactUrl, artifact.Version, artifact.Sha256);
        await _updateService.DownloadInstallerAsync(artifactUrl, installerPath, progress, artifact.Sha256, artifact.SizeBytes);
        return installerPath;
    }

    // ERROR_CANCELLED: the user dismissed the UAC prompt raised for the
    // installer, which runs elevated because the client is an all-users install.
    private const int ErrorCancelledByUser = 1223;

    private void LaunchInstallerAndExit(string installerPath, IReadOnlyList<string>? installerArguments = null)
    {
        // Without this the panel just sat at "download finished / 100%" while
        // the elevation prompt was on screen, which reads as the update having
        // silently stopped.
        _vm.ReportUpdateProgress(UpdateProgress.Applying(100, "Waiting for administrator approval to install..."));

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

        // Start the installer before tearing the client down. Installing an
        // all-users copy needs elevation, so a declined prompt throws right
        // here; disconnecting and requesting exit first left the user with no
        // client, no tunnel and no installer running - which reads as "the app
        // broke and will not start any more".
        Process? process;
        try
        {
            process = Process.Start(startInfo);
        }
        catch (System.ComponentModel.Win32Exception ex) when (ex.NativeErrorCode == ErrorCancelledByUser)
        {
            Log.Information("update installer launch cancelled at the elevation prompt");
            throw new InvalidOperationException(
                "Update cancelled: installing LeiKwan Bridge needs administrator approval. " +
                "The client keeps running; approve the prompt or install again as administrator.",
                ex);
        }

        if (process == null)
            throw new InvalidOperationException("failed to launch installer");

        _vm.DisconnectCommand.Execute(null);
        _trayLifecycle.RequestExit();
        DisposeTrayIcon();
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
        try
        {
            var stateManager = new StateManager(Path.GetDirectoryName(App.ConfigPath)!);
            stateManager.SaveConfig(App.ConfigPath, App.Config);
        }
        catch (Exception ex)
        {
            // See App.TrySaveConfig: a shared config that cannot be written must
            // degrade to "this change is not persisted", never to a crash.
            Log.Warning(ex, "config could not be persisted");
        }
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
        _ = _connectionRecovery.StopAsync();
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
            await _connectionRecovery.StopAsync().ConfigureAwait(true);
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
            _suppressEndpointChangeHandling = true;
            try
            {
                SelectEndpoint(SetupEndpointCombo, App.Config.MasterUrl);
                SelectEndpoint(SettEndpointCombo, App.Config.MasterUrl);
            }
            finally { _suppressEndpointChangeHandling = false; }
        }

        if (!_vm.ShouldAutoConnectOnStartup)
            return;

        try
        {
            await _vm.ConnectCommand.ExecuteAsync(null);
            if (!_vm.IsConnected)
            {
                if (!ct.IsCancellationRequested && !_vm.IsShuttingDown)
                {
                    Log.Warning("automatic connection returned disconnected; scheduling background recovery");
                    _ = _connectionRecovery.StartAsync(ct);
                }
            }
        }
        catch (OperationCanceledException) when (ct.IsCancellationRequested || _vm.IsShuttingDown)
        {
        }
        catch (Exception ex)
        {
            Log.Warning(ex, "automatic connection failed");
            _ = _connectionRecovery.StartAsync(ct);
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
        if (_vm.IsDeviceLimitReached)
        {
            var limited = $"{baseText} - {GetString("DeviceLimitStatus")}";
            return limited.Length > 63 ? limited[..63] : limited;
        }
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
