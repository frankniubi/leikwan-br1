using System.Collections.ObjectModel;
using Avalonia.Threading;
using CommunityToolkit.Mvvm.ComponentModel;
using CommunityToolkit.Mvvm.Input;
using LeikwanBridge.Core.Models;
using LeikwanBridge.Core.Services;
using LeikwanBridge.Core.Services.Platform;
using LeikwanBridge.Mac.Localization;
using LeikwanBridge.Mac.Services;
using Serilog;

namespace LeikwanBridge.Mac.ViewModels;

public sealed partial class MainWindowViewModel : ObservableObject
{
    public const string EmailPasswordAuthentication = "Email + Password";
    public const string ClientIdTokenAuthentication = "Client ID + Token";

    /// <summary>
    /// How often a live native session re-reads the relay's measured payload ceiling. The
    /// runtime republishes its status file while the tunnel runs and nothing here is
    /// event-driven, so the MTU watcher polls instead of waiting for the next refresh.
    /// </summary>
    public static readonly TimeSpan DefaultNativeTunMtuWatchInterval = TimeSpan.FromSeconds(5);
    public IReadOnlyList<PanelEndpointOption> PanelEndpointOptions => PanelEndpoints.Options;

    private readonly IDesktopClientController _controller;
    private readonly IMacConnectionService? _connections;
    private readonly Func<IReadOnlyList<ForwardConfig>> _forwardsProvider;
    private readonly IAutostartService? _autostart;
    private readonly Func<string, string, bool, CancellationToken, Task>? _savePreferences;
    private readonly Func<CancellationToken, Task>? _checkForUpdates;
    private readonly Func<CancellationToken, Task>? _installUpdate;
    private readonly Func<IProgress<UpdateProgress>?, CancellationToken, Task>? _checkForUpdatesWithProgress;
    private readonly Func<IProgress<UpdateProgress>?, CancellationToken, Task>? _installUpdateWithProgress;
    private readonly Action<bool>? _rememberLoginInfoChanged;
    private readonly Func<string, CancellationToken, Task>? _saveLogLevel;
    private readonly Func<string, CancellationToken, Task>? _saveEgressInterface;
    private readonly Func<string?, IReadOnlyList<EgressInterfaceOption>> _egressInterfaceCatalog;
    private bool _egressApplyReady;
    private bool _suppressEgressApply;
    private string _updateFailureStage = "check";
    // Last minimum-version policy seen in a pull (memory only, never persisted).
    private readonly ClientUpdateRequirement _updateRequirement;
    private bool _endpointPersistenceReady;
    private readonly Action<Action> _dispatch;
    private readonly TimeSpan _nativeTunMtuWatchInterval;
    private int _nativeRefreshInProgress;
    private int _nativeTunMtuWatchRunning;
    private MacConnectionMode _nativeActiveMode = MacConnectionMode.Vpn;
    private int _nativeActiveForwardId;
    private string _nativeActiveRouteScope = "global";
    private bool _nativeActiveBypassChina;
    private bool _nativeActiveUnrestricted;
    private string _preparationProgress = string.Empty;
    private readonly SemaphoreSlim _connectionGate = new(1, 1);
    private ProxyForwardLease? _connectionForwardLease;
    private readonly object _connectionForwardReleaseLock = new();
    private Task _connectionForwardRelease = Task.CompletedTask;
    private CancellationTokenSource? _connectionStartCancellation;
    private int _pendingConnectionForwardId;
    private bool _pendingConnectionForwardWasRunning;
    private string _connectionStartErrorCode = string.Empty;
    private MacConnectionSelection _connectionSelection = MacConnectionSelection.Empty;
    private bool _loadingConnectionSelection = true;

    public MainWindowViewModel(
        IDesktopClientController controller,
        IMacConnectionService? connections = null,
        Func<IReadOnlyList<ForwardConfig>>? forwardsProvider = null,
        IAutostartService? autostart = null,
        bool closeToTray = true,
        string language = "en-US",
        Func<string, string, bool, CancellationToken, Task>? savePreferences = null,
        Func<CancellationToken, Task>? checkForUpdates = null,
        Func<CancellationToken, Task>? installUpdate = null,
        Action<Action>? dispatch = null,
        bool rememberLoginInfo = true,
        int savedClientId = 0,
        string? savedAgentToken = null,
        Action<bool>? rememberLoginInfoChanged = null,
        Func<IProgress<UpdateProgress>?, CancellationToken, Task>? checkForUpdatesWithProgress = null,
        Func<IProgress<UpdateProgress>?, CancellationToken, Task>? installUpdateWithProgress = null,
        TimeSpan? nativeTunMtuWatchInterval = null,
        string logLevel = DesktopLogLevel.Warning,
        Func<string, CancellationToken, Task>? saveLogLevel = null,
        string? preferredEgressInterface = null,
        Func<string, CancellationToken, Task>? saveEgressInterface = null,
        Func<string?, IReadOnlyList<EgressInterfaceOption>>? egressInterfaceCatalog = null,
        Func<IReadOnlyDictionary<string, TrafficCounter>>? trafficCounters = null,
        INodeLibrary? nodeLibrary = null,
        string themeMode = MacAppearance.System,
        Func<string, CancellationToken, Task>? saveThemeMode = null,
        string? currentAppVersion = null)
    {
        _controller = controller;
        _connections = connections;
        _forwardsProvider = forwardsProvider ?? (() => []);
        _autostart = autostart;
        _savePreferences = savePreferences;
        _checkForUpdates = checkForUpdates;
        _installUpdate = installUpdate;
        _checkForUpdatesWithProgress = checkForUpdatesWithProgress;
        _installUpdateWithProgress = installUpdateWithProgress;
        _rememberLoginInfoChanged = rememberLoginInfoChanged;
        _saveLogLevel = saveLogLevel;
        _saveEgressInterface = saveEgressInterface;
        _egressInterfaceCatalog = egressInterfaceCatalog ?? (label => EgressInterfaceCatalog.Enumerate(label));
        _dispatch = dispatch ?? Dispatch;
        _nativeTunMtuWatchInterval = nativeTunMtuWatchInterval ?? DefaultNativeTunMtuWatchInterval;
        _updateRequirement = new ClientUpdateRequirement(
            currentAppVersion ?? typeof(MainWindowViewModel).Assembly.GetName().Version?.ToString(3) ?? "1.0.0");
        CloseToTray = closeToTray;
        Language = L.Normalize(language);
        LogLevel = DesktopLogLevel.Normalize(logLevel);
        PreferredEgressInterface = preferredEgressInterface?.Trim() ?? string.Empty;
        InitializeShell(trafficCounters, nodeLibrary, themeMode, saveThemeMode);
        ReloadEgressInterfaces();
        PanelUrl = controller.Snapshot.PanelUrl;
        SelectedPanelEndpoint = PanelEndpoints.ForUrl(PanelUrl);
        RememberLoginInfo = rememberLoginInfo;
        if (rememberLoginInfo)
        {
            if (savedClientId > 0)
                ClientIdText = savedClientId.ToString(System.Globalization.CultureInfo.InvariantCulture);
            AgentToken = savedAgentToken ?? string.Empty;
        }
        ApplySnapshot(controller.Snapshot);
        _controller.SnapshotChanged += snapshot => _dispatch(() => ApplySnapshot(snapshot));
        _controller.ForwardsChanged += forwards => _dispatch(() => ApplyForwards(forwards));
        _controller.NativeOutboundChanged += capability => _dispatch(() => ApplyNativeOutbound(capability));
        _controller.NoticeRaised += notice => _dispatch(() => ShowNotice(notice));
        _controller.LogRaised += message => _dispatch(() =>
        {
            if (message.StartsWith("preparing_routes:", StringComparison.Ordinal)
                && System.Text.RegularExpressions.Regex.IsMatch(message, @"^preparing_routes:\d{1,5}/\d{1,5}$"))
            {
                _preparationProgress = message["preparing_routes:".Length..];
                OnPropertyChanged(nameof(BusyMessage));
                // Route preparation is transient UI progress, not an activity
                // log entry. Keeping it out of Logs prevents one entry per
                // forward on every reconciliation pass.
                return;
            }
            else if (message is "preparing_config" or "connected" or "connection_failed")
            {
                if (message is "preparing_config")
                    _preparationProgress = string.Empty;
                OnPropertyChanged(nameof(BusyMessage));
            }
            AddLog(message);
        });
        _controller.UpdateAvailable += version => _dispatch(() => ShowUpdate(version));
        _controller.ClientUpdatePolicyReceived += policy => _dispatch(() => ApplyClientUpdatePolicy(policy));
        if (_connections is not null)
        {
            _connections.SnapshotChanged += snapshot => _dispatch(() => ApplyConnectionSnapshot(snapshot));
            _ = LoadConnectionsAsync();
        }
        _ = LoadAutostartAsync();
        _endpointPersistenceReady = true;
        _egressApplyReady = true;
    }

    public ObservableCollection<DesktopClientChoice> Clients { get; } = [];
    public ObservableCollection<ForwardRowViewModel> Forwards { get; } = [];
    public ObservableCollection<MacConnectionProfile> ConnectionProfiles { get; } = [];
    public ObservableCollection<string> Logs { get; } = [];
    public IReadOnlyList<string> Languages { get; } = [L.English, L.SimplifiedChinese];
    public IReadOnlyList<string> LogLevels { get; } = DesktopLogLevel.Options;
    public IReadOnlyList<string> AuthenticationModes { get; } =
        [ClientIdTokenAuthentication];
    public IReadOnlyList<MacConnectionMode> ConnectionModes { get; } =
        [MacConnectionMode.Proxy, MacConnectionMode.Vpn];
    public MacConnectionEditorViewModel NodeEditor { get; } = new();

    [ObservableProperty] private string _panelUrl = string.Empty;
    [ObservableProperty] private PanelEndpointOption _selectedPanelEndpoint = PanelEndpoints.Default;
    [ObservableProperty] private string _email = string.Empty;
    [ObservableProperty] private string _password = string.Empty;
    [ObservableProperty] private string _selectedAuthenticationMode = ClientIdTokenAuthentication;
    [ObservableProperty] private string _clientIdText = string.Empty;
    [ObservableProperty] private string _agentToken = string.Empty;
    [ObservableProperty] private bool _rememberLoginInfo;
    [ObservableProperty] private string _validationMessage = string.Empty;
    [ObservableProperty] private string _connectionStatus = "disconnected";
    [ObservableProperty] private string _signedInUsername = string.Empty;
    [ObservableProperty] private string _activeClientName = string.Empty;
    [ObservableProperty] private bool _isBusy;
    [ObservableProperty] private bool _isLoginVisible = true;
    [ObservableProperty] private bool _isClientPickerVisible;
    [ObservableProperty] private bool _isDashboardVisible;
    [ObservableProperty] private bool _isLogsVisible;
    [ObservableProperty] private bool _isSettingsVisible;
    [ObservableProperty, NotifyPropertyChangedFor(nameof(IsConnectionWorkspaceVisible))]
    private bool _isConnectionsVisible;
    [ObservableProperty, NotifyPropertyChangedFor(nameof(IsConnectionWorkspaceVisible), nameof(ConnectionWorkspaceTitle))]
    private bool _isPairingVisible;
    [ObservableProperty] private bool _isNoticeVisible;
    [ObservableProperty] private string _noticeText = string.Empty;
    [ObservableProperty] private bool _isDeviceLimitVisible;
    [ObservableProperty] private string _deviceLimitMessage = string.Empty;
    [ObservableProperty] private bool _isUpdateVisible;
    [ObservableProperty] private string _updateVersion = string.Empty;
    [ObservableProperty] private bool _isUpdateInstalling;
    [ObservableProperty] private string _updatePhaseText = string.Empty;
    [ObservableProperty] private double _updatePercent;
    [ObservableProperty] private string _updateProgressMessage = string.Empty;
    [ObservableProperty] private string _updateErrorMessage = string.Empty;
    [ObservableProperty] private bool _isUpdateInProgress;
    [ObservableProperty] private bool _showUpdateError;
    [ObservableProperty] private bool _isUpdateRequired;
    [ObservableProperty] private string _requiredUpdateMinimumVersion = string.Empty;
    [ObservableProperty] private bool _closeToTray;
    [ObservableProperty] private bool _autoStartEnabled;
    [ObservableProperty] private string _language = "en-US";
    [ObservableProperty] private string _logLevel = DesktopLogLevel.Warning;
    [ObservableProperty] private string _preferredEgressInterface = string.Empty;
    [ObservableProperty] private IReadOnlyList<EgressInterfaceOption> _egressInterfaceOptions = [];
    [ObservableProperty, NotifyPropertyChangedFor(nameof(IsEgressInterfaceNoticeVisible))]
    private string _egressInterfaceNotice = string.Empty;
    [ObservableProperty] private bool _trayAvailable;
    [ObservableProperty] private string _selectedSection = "dashboard";
    [ObservableProperty] private MacConnectionProfile? _selectedConnectionProfile;
    [ObservableProperty] private ForwardRowViewModel? _selectedConnectionForward;
    [ObservableProperty] private bool _isConnectionStarting;
    [ObservableProperty] private MacConnectionMode? _selectedConnectionMode;
    [ObservableProperty] private string _localConnectionStatus = "Disconnected";
    [ObservableProperty] private string _localConnectionError = string.Empty;
    [ObservableProperty] private int _bypassAddressCount;
    [ObservableProperty] private NativeOutboundCapability? _nativeOutboundCapability;
    [ObservableProperty] private int _selectedNativeForwardId;
    [ObservableProperty] private NativeOutboundForward? _selectedNativeForward;
    [ObservableProperty] private string _selectedNativeRouteScope = "global";
    [ObservableProperty] private NativeRouteScopeOption? _selectedNativeRouteScopeOption;
    [ObservableProperty] private MacConnectionMode _selectedNativeConnectionMode = MacConnectionMode.Vpn;
    [ObservableProperty] private bool _nativeBypassChinaMainland;
    [ObservableProperty] private bool _nativeUnrestrictedRoute;
    [ObservableProperty] private bool _isNativeOutboundExpanded;
    [ObservableProperty] private bool _isProxyEditorExpanded;
    [ObservableProperty] private string _proxyImportUri = string.Empty;
    [ObservableProperty] private string _proxyImportError = string.Empty;

    public bool HasForwards => Forwards.Count > 0;
    /// <summary>The dismissible top banner; the required overlay replaces it while gated.</summary>
    public bool IsUpdateBannerVisible => (IsUpdateVisible || ShowUpdateError) && !IsUpdateRequired;

    /// <summary>Required overlay: Install, once the manifest offered a newer build and nothing failed.</summary>
    public bool IsRequiredUpdateInstallVisible => IsUpdateRequired && IsUpdateVisible && !ShowUpdateError;

    /// <summary>Required overlay: Retry after a failure, or a re-check while no update is known.</summary>
    public bool IsRequiredUpdateRetryVisible
        => IsUpdateRequired && !IsUpdateInProgress && (ShowUpdateError || !IsUpdateVisible);

    /// <summary>Required overlay: no installable update known yet (manifest missing or not newer).</summary>
    public bool IsRequiredUpdateUnavailable
        => IsUpdateRequired && !IsUpdateVisible && !ShowUpdateError && !IsUpdateInProgress;
    public bool IsConnectionWorkspaceVisible => IsConnectionsVisible || IsPairingVisible;
    public string ConnectionWorkspaceTitle => IsPairingVisible
        ? L.T("PairingTitle", "Route pairing")
        : L.T("ServicesTitle", "Services");
    public IReadOnlyList<ForwardRowViewModel> ConnectionRoutes => Forwards;
    public IReadOnlyList<ForwardRowViewModel> PairedConnectionRoutes
        => Forwards.Where(route => route.IsPaired).ToList();
    public bool IsNodeLibraryVisible => !IsProxyEditorExpanded;
    public bool IsConnected => string.Equals(ConnectionStatus, "connected", StringComparison.OrdinalIgnoreCase);
    public bool IsSignedIn => !IsLoginVisible;
    public int ForwardCount => Forwards.Count;
    public int RunningForwardCount => Forwards.Count(forward => forward.IsRunning);
    public string ConnectionLabel => ConnectionStatus switch
    {
        "connected" => L.T("StatusConnected", "Connected"),
        DesktopClientController.DeviceLimitReachedCode => L.T("StatusDeviceLimit", "Device limit reached"),
        "error" => L.T("StatusConnectionError", "Connection error"),
        _ => L.T("StatusDisconnected", "Disconnected"),
    };
    public string VersionLabel => L.F("VersionLabelFormat", "Version {0}", typeof(MainWindowViewModel).Assembly.GetName().Version?.ToString(3) ?? "1.0.0");
    public bool IsDebugVisible => string.Equals(
        Environment.GetEnvironmentVariable("LEIKWAN_DEBUG"),
        "1",
        StringComparison.Ordinal);
    public bool IsEmailPasswordAuthentication =>
        string.Equals(SelectedAuthenticationMode, EmailPasswordAuthentication, StringComparison.Ordinal);
    public bool IsClientIdTokenAuthentication =>
        string.Equals(SelectedAuthenticationMode, ClientIdTokenAuthentication, StringComparison.Ordinal);
    public bool HasConnectionProfiles => ConnectionProfiles.Count > 0;
    public bool HasSelectedConnectionProfile => SelectedConnectionProfile is not null;
    public string PanelEndpointPrompt => L.T("PanelEndpointPrompt", "Choose the site for your region.");
    public string LocalConnectionModeLabel => SelectedConnectionMode == MacConnectionMode.Vpn
        ? L.T("ModeVpn", "TUN (VPN)")
        : SelectedConnectionMode == MacConnectionMode.Proxy ? L.T("ModeProxy", "System proxy") : L.T("ModeNotSelected", "Not selected");
    public bool IsConnectionModeSelected => SelectedConnectionMode is not null;
    public bool IsLocalConnectionActive =>
        IsConnectionStarting || LocalConnectionStatus is "Connected" or "Starting";
    public bool CanStartLocalConnection
        => IsConnectionModeSelected && SelectedConnectionForward?.IsPaired == true && !IsLocalConnectionActive;
    public bool IsStandardConnectionModeVisible => SelectedConnectionProfile?.Protocol != MacConnectionProtocol.External;
    public string SelectedConnectionEndpoint => SelectedConnectionForward?.PortText ?? string.Empty;
    public bool IsNativeOutboundVisible
        => NativeOutboundCapability is { Enabled: true } && NativeOutboundForwards.Count > 0;
    public IReadOnlyList<NativeOutboundForward> NativeOutboundForwards
        => NativeOutboundCapability?.Forwards ?? [];
    public IReadOnlyList<NativeRouteScopeOption> NativeRouteScopeOptions
        => (NativeOutboundCapability?.FindForward(SelectedNativeForwardId)?.RouteScopes
                ?? NativeOutboundCapability?.RouteScopes
                ?? [])
            .Distinct(StringComparer.OrdinalIgnoreCase)
            .Select(scope => new NativeRouteScopeOption(
                scope,
                string.Equals(scope, "cn_only", StringComparison.OrdinalIgnoreCase)
                    ? L.T("ScopeCnOnly", "Mainland China only")
                    : string.Equals(scope, "intl_only", StringComparison.OrdinalIgnoreCase)
                        ? L.T("ScopeIntlOnly", "International only")
                        : L.T("ScopeGlobal", "Global")))
            .ToList();
    public bool IsNativeRouteScopeSelectable => NativeRouteScopeOptions.Count > 1;
    public IReadOnlyList<MacConnectionMode> NativeConnectionModes { get; } =
        [MacConnectionMode.Proxy, MacConnectionMode.Vpn];
    public bool IsNativeRouteCnOnly
        => string.Equals(SelectedNativeRouteScope, "cn_only", StringComparison.OrdinalIgnoreCase);
    public bool IsNativeRouteIntlOnly
        => string.Equals(SelectedNativeRouteScope, "intl_only", StringComparison.OrdinalIgnoreCase);
    public bool IsNativeRouteGlobal
        => string.Equals(SelectedNativeRouteScope, "global", StringComparison.OrdinalIgnoreCase);
    public bool IsNativeBypassChinaEnabled
        => IsNativeRouteGlobal && IsNativeOutboundVisible;
    public bool IsNativeUnrestrictedRouteVisible
        => IsNativeOutboundVisible && SelectedNativeForward?.UnrestrictedAllowed == true;
    public bool IsNativeUnrestrictedRouteEnabled => IsNativeUnrestrictedRouteVisible;

    partial void OnConnectionStatusChanged(string value)
    {
        OnPropertyChanged(nameof(IsConnected));
        OnPropertyChanged(nameof(ConnectionLabel));
    }

    partial void OnIsUpdateVisibleChanged(bool value) => NotifyUpdateSurfaces();

    partial void OnShowUpdateErrorChanged(bool value) => NotifyUpdateSurfaces();

    partial void OnIsUpdateRequiredChanged(bool value) => NotifyUpdateSurfaces();

    partial void OnIsUpdateInProgressChanged(bool value) => NotifyUpdateSurfaces();

    private void NotifyUpdateSurfaces()
    {
        OnPropertyChanged(nameof(IsUpdateBannerVisible));
        OnPropertyChanged(nameof(IsRequiredUpdateInstallVisible));
        OnPropertyChanged(nameof(IsRequiredUpdateRetryVisible));
        OnPropertyChanged(nameof(IsRequiredUpdateUnavailable));
    }

    /// <summary>
    /// Applies a full pull's <c>client_update</c> policy. Null (the pull
    /// carried none) keeps the remembered policy. When the gate closes and no
    /// update is known yet, the manifest is checked so Install can be offered.
    /// The connection is never touched.
    /// </summary>
    public void ApplyClientUpdatePolicy(ClientUpdatePolicy? policy)
    {
        if (!_updateRequirement.Observe(policy))
            return;
        RequiredUpdateMinimumVersion = _updateRequirement.MinimumVersion;
        var wasRequired = IsUpdateRequired;
        IsUpdateRequired = _updateRequirement.IsRequired;
        if (!IsUpdateRequired || wasRequired)
            return;
        Log.Warning(
            "client_update_required current={Current} minimum={Minimum}",
            _updateRequirement.CurrentVersion,
            _updateRequirement.MinimumVersion);
        if (!IsUpdateVisible && !IsUpdateInProgress && !IsUpdateInstalling)
            _ = CheckForUpdatesAsync();
    }

    partial void OnIsLoginVisibleChanged(bool value) => OnPropertyChanged(nameof(IsSignedIn));

    partial void OnSelectedPanelEndpointChanged(PanelEndpointOption value)
    {
        if (!string.Equals(PanelUrl, value.Url, StringComparison.OrdinalIgnoreCase))
            PanelUrl = value.Url;
        // Persist immediately so update downloads (manifest, package,
        // runtime-drift repair and installer) follow the chosen endpoint
        // without waiting for "Save preferences".
        _ = PersistSelectedEndpointAsync(value.Url);
    }

    private async Task PersistSelectedEndpointAsync(string url)
    {
        if (_savePreferences is null || !_endpointPersistenceReady)
            return;
        try
        {
            await _savePreferences(url, Language, CloseToTray, CancellationToken.None);
        }
        catch
        {
            // Persistence failures must not break the login/selection flow.
        }
    }

    partial void OnPanelUrlChanged(string value)
    {
        var endpoint = PanelEndpoints.ForUrl(value);
        if (!string.Equals(endpoint.Url, value, StringComparison.OrdinalIgnoreCase)
            && !string.Equals(endpoint.Url, SelectedPanelEndpoint?.Url, StringComparison.OrdinalIgnoreCase))
            return;
        if (!string.Equals(SelectedPanelEndpoint?.Url, endpoint.Url, StringComparison.OrdinalIgnoreCase))
            SelectedPanelEndpoint = endpoint;
    }

    partial void OnSelectedAuthenticationModeChanged(string value)
    {
        OnPropertyChanged(nameof(IsEmailPasswordAuthentication));
        OnPropertyChanged(nameof(IsClientIdTokenAuthentication));
        ValidationMessage = string.Empty;
    }

    partial void OnRememberLoginInfoChanged(bool value)
        => _rememberLoginInfoChanged?.Invoke(value);

    public bool IsEgressInterfaceNoticeVisible => EgressInterfaceNotice.Length > 0;

    partial void OnPreferredEgressInterfaceChanged(string value)
    {
        // The sign-in page puts the adapter into force immediately so the next
        // panel request already leaves through it; the settings page keeps its
        // explicit "Save preferences" step. Both bind this one property.
        if (value is null || !_egressApplyReady || _suppressEgressApply || !IsLoginVisible)
            return;
        EgressInterfaceNotice = string.Empty;
        _ = ApplyEgressInterfaceAsync();
    }

    [RelayCommand]
    private void RefreshEgressInterfaces() => ReloadEgressInterfaces();

    /// <summary>
    /// Re-reads the eligible adapters and keeps the selection valid: a saved
    /// adapter that is no longer present falls back to Automatic, and the
    /// notice says so.
    /// </summary>
    private EgressInterfaceChoice ReloadEgressInterfaces()
    {
        var saved = PreferredEgressInterface;
        var options = _egressInterfaceCatalog(ResourceString("EgressBindingAutomatic", "Automatic (recommended)"));
        var choice = EgressInterfaceSelection.Reconcile(options, saved);
        _suppressEgressApply = true;
        try
        {
            EgressInterfaceOptions = options;
            PreferredEgressInterface = choice.SelectedId;
        }
        finally
        {
            _suppressEgressApply = false;
        }

        EgressInterfaceNotice = choice.FellBackToAutomatic
            ? string.Format(
                System.Globalization.CultureInfo.CurrentCulture,
                ResourceString("EgressBindingMissing", "The saved adapter \"{0}\" is not available. Automatic is used instead."),
                choice.MissingInterface)
            : string.Empty;
        return choice;
    }

    private async Task ApplyEgressInterfaceAsync()
    {
        if (_saveEgressInterface is null)
            return;
        try
        {
            await _saveEgressInterface(PreferredEgressInterface, CancellationToken.None);
        }
        catch
        {
            // Persisting the preference must not block sign-in.
            AddLog("egress_interface_save_failed");
        }
    }

    private static string ResourceString(string key, string fallback) => L.T(key, fallback);

    partial void OnSelectedConnectionModeChanged(MacConnectionMode? value)
    {
        OnPropertyChanged(nameof(LocalConnectionModeLabel));
        OnPropertyChanged(nameof(IsConnectionModeSelected));
        OnPropertyChanged(nameof(CanStartLocalConnection));
    }

    partial void OnSelectedConnectionProfileChanged(MacConnectionProfile? value)
    {
        NodeEditor.Load(value);
        OnPropertyChanged(nameof(HasSelectedConnectionProfile));
        OnPropertyChanged(nameof(IsStandardConnectionModeVisible));
        SelectedConnectionForward?.SetSavedNode(value);
        PersistConnectionSelection();
    }

    partial void OnIsConnectionStartingChanged(bool value)
    {
        OnPropertyChanged(nameof(IsLocalConnectionActive));
        OnPropertyChanged(nameof(CanStartLocalConnection));
    }

    partial void OnSelectedConnectionForwardChanged(ForwardRowViewModel? value)
    {
        OnPropertyChanged(nameof(SelectedConnectionEndpoint));
        OnPropertyChanged(nameof(CanStartLocalConnection));
        if (_loadingConnectionSelection)
            return;
        if (value is not null && _connectionSelection.ForwardProfiles.TryGetValue(value.ForwardId, out var profileId))
            SelectedConnectionProfile = ConnectionProfiles.FirstOrDefault(profile => profile.Id == profileId);
        else
            SelectedConnectionProfile = null;
        PersistConnectionSelection();
    }

    private void PersistConnectionSelection()
    {
        if (_loadingConnectionSelection || _connections is null)
            return;
        var forwardId = SelectedConnectionForward?.ForwardId ?? 0;
        var profileId = SelectedConnectionProfile?.Id ?? 0;
        var pairs = new Dictionary<int, int>(_connectionSelection.ForwardProfiles);
        if (forwardId > 0 && profileId > 0)
            pairs[forwardId] = profileId;
        else if (forwardId > 0)
            pairs.Remove(forwardId);
        _connectionSelection = new MacConnectionSelection(forwardId, profileId, pairs);
        _ = SaveConnectionSelectionAsync(forwardId, profileId);
    }

    private async Task SaveConnectionSelectionAsync(int forwardId, int profileId)
    {
        try
        {
            await _connections!.SaveSelectionAsync(forwardId, profileId);
        }
        catch
        {
            _dispatch(() => LocalConnectionError = L.T("SelectionSaveFailed", "The selected route and node could not be saved."));
        }
    }

    partial void OnNativeOutboundCapabilityChanged(NativeOutboundCapability? value)
    {
        if (value is not { Enabled: true })
            IsNativeOutboundExpanded = false;
        var id = value?.FindForward(SelectedNativeForwardId)?.ForwardId
            ?? value?.DefaultForwardId
            ?? value?.Forwards.FirstOrDefault()?.ForwardId
            ?? 0;
        if (SelectedNativeForwardId != id)
            SelectedNativeForwardId = id;
        var carrier = value?.FindForward(id);
        var scope = carrier?.RouteScopes.FirstOrDefault(item =>
                string.Equals(item, SelectedNativeRouteScope, StringComparison.OrdinalIgnoreCase))
            ?? carrier?.DefaultRouteScope
            ?? value?.DefaultRouteScope
            ?? "global";
        if (!string.Equals(SelectedNativeRouteScope, scope, StringComparison.OrdinalIgnoreCase))
            SelectedNativeRouteScope = scope;
        SelectedNativeForward = carrier;
        SelectedNativeRouteScopeOption = NativeRouteScopeOptions.FirstOrDefault(item =>
            string.Equals(item.Key, SelectedNativeRouteScope, StringComparison.OrdinalIgnoreCase));
        if (!IsNativeRouteGlobal && NativeBypassChinaMainland)
            NativeBypassChinaMainland = false;
        if (!IsNativeUnrestrictedRouteVisible && NativeUnrestrictedRoute)
            NativeUnrestrictedRoute = false;
        OnPropertyChanged(nameof(IsNativeOutboundVisible));
        OnPropertyChanged(nameof(NativeOutboundForwards));
        OnPropertyChanged(nameof(NativeRouteScopeOptions));
        OnPropertyChanged(nameof(IsNativeRouteScopeSelectable));
        OnPropertyChanged(nameof(IsNativeRouteCnOnly));
        OnPropertyChanged(nameof(IsNativeRouteIntlOnly));
        OnPropertyChanged(nameof(IsNativeRouteGlobal));
        OnPropertyChanged(nameof(IsNativeBypassChinaEnabled));
        OnPropertyChanged(nameof(IsNativeUnrestrictedRouteVisible));
        OnPropertyChanged(nameof(IsNativeUnrestrictedRouteEnabled));
    }

    partial void OnSelectedNativeForwardIdChanged(int value)
    {
        var carrier = NativeOutboundCapability?.FindForward(value);
        if (SelectedNativeForward?.ForwardId != value)
            SelectedNativeForward = carrier;
        if (carrier is not null
            && !carrier.RouteScopes.Contains(SelectedNativeRouteScope, StringComparer.OrdinalIgnoreCase))
            SelectedNativeRouteScope = carrier.DefaultRouteScope;
        SelectedNativeRouteScopeOption = NativeRouteScopeOptions.FirstOrDefault(item =>
            string.Equals(item.Key, SelectedNativeRouteScope, StringComparison.OrdinalIgnoreCase));
        if (!IsNativeUnrestrictedRouteVisible && NativeUnrestrictedRoute)
            NativeUnrestrictedRoute = false;
        OnPropertyChanged(nameof(NativeRouteScopeOptions));
        OnPropertyChanged(nameof(IsNativeRouteScopeSelectable));
        OnPropertyChanged(nameof(IsNativeRouteIntlOnly));
        OnPropertyChanged(nameof(IsNativeBypassChinaEnabled));
        OnPropertyChanged(nameof(IsNativeUnrestrictedRouteVisible));
        OnPropertyChanged(nameof(IsNativeUnrestrictedRouteEnabled));
    }

    partial void OnSelectedNativeRouteScopeChanged(string value)
    {
        var normalized = value?.Trim().ToLowerInvariant() ?? "global";
        if (normalized is not ("cn_only" or "intl_only" or "global"))
            normalized = "global";
        if (!string.Equals(value, normalized, StringComparison.Ordinal))
        {
            SelectedNativeRouteScope = normalized;
            return;
        }
        if (normalized != "global" && NativeBypassChinaMainland)
            NativeBypassChinaMainland = false;
        SelectedNativeRouteScopeOption = NativeRouteScopeOptions.FirstOrDefault(item =>
            string.Equals(item.Key, normalized, StringComparison.OrdinalIgnoreCase));
        OnPropertyChanged(nameof(IsNativeRouteCnOnly));
        OnPropertyChanged(nameof(IsNativeRouteIntlOnly));
        OnPropertyChanged(nameof(IsNativeRouteGlobal));
        OnPropertyChanged(nameof(IsNativeBypassChinaEnabled));
    }

    partial void OnNativeBypassChinaMainlandChanged(bool value)
    {
        if (value && !IsNativeRouteGlobal)
            NativeBypassChinaMainland = false;
        OnPropertyChanged(nameof(IsNativeBypassChinaEnabled));
    }

    partial void OnNativeUnrestrictedRouteChanged(bool value)
    {
        if (value && !IsNativeUnrestrictedRouteVisible)
            NativeUnrestrictedRoute = false;
        OnPropertyChanged(nameof(IsNativeUnrestrictedRouteVisible));
        OnPropertyChanged(nameof(IsNativeUnrestrictedRouteEnabled));
    }

    partial void OnSelectedNativeForwardChanged(NativeOutboundForward? value)
    {
        if (value is not null && SelectedNativeForwardId != value.ForwardId)
            SelectedNativeForwardId = value.ForwardId;
    }

    partial void OnSelectedNativeRouteScopeOptionChanged(NativeRouteScopeOption? value)
    {
        if (value is not null && !string.Equals(SelectedNativeRouteScope, value.Key, StringComparison.OrdinalIgnoreCase))
            SelectedNativeRouteScope = value.Key;
    }

    [RelayCommand]
    private async Task LoginAsync()
    {
        ValidationMessage = string.Empty;
        var selectedPanelUrl = SelectedPanelEndpoint?.Url?.Trim();
        if (string.IsNullOrWhiteSpace(selectedPanelUrl))
            selectedPanelUrl = PanelUrl?.Trim();
        PanelUrl = selectedPanelUrl ?? string.Empty;
        if (!Uri.TryCreate(PanelUrl, UriKind.Absolute, out var panel)
            || panel.Scheme is not ("https" or "http"))
        {
            ValidationMessage = L.T("SignInInvalid", "Enter a valid panel URL, client ID, and token.");
            return;
        }

        if (!int.TryParse(ClientIdText.Trim(), System.Globalization.NumberStyles.None,
                System.Globalization.CultureInfo.InvariantCulture, out var clientId)
            || clientId <= 0
            || string.IsNullOrWhiteSpace(AgentToken))
        {
            ValidationMessage = L.T("SignInInvalid", "Enter a valid panel URL, client ID, and token.");
            return;
        }

        // The adapter list may have changed since the page was drawn: re-check
        // it and put the choice into force before the sign-in request.
        ReloadEgressInterfaces();
        await ApplyEgressInterfaceAsync();

        var agentToken = AgentToken;
        IsBusy = true;
        try
        {
            try
            {
                await _controller.LoginWithAgentTokenAsync(PanelUrl, clientId, agentToken, RememberLoginInfo);
            }
            catch (MasterApiException exception) when (exception.StatusCode == 401)
            {
                ValidationMessage = L.T("SignInRejected", "Client ID or token was rejected by the panel.");
                return;
            }
            catch (DeviceLimitReachedException exception)
            {
                ValidationMessage = FormatDeviceLimitMessage(exception.ActiveOnlineDevices, exception.OnlineDeviceLimit);
                return;
            }
            catch
            {
                ValidationMessage = L.T("SignInFailed", "Sign-in failed. Check the panel address, client ID, and token.");
                return;
            }

            if (_controller.Snapshot.IsSignedIn
                && !_controller.Snapshot.RequiresClientSelection
                && !string.IsNullOrWhiteSpace(_controller.Snapshot.ActiveClientName))
            {
                try
                {
                    await _controller.ConnectAsync();
                }
                catch
                {
                    ValidationMessage = L.T("SignInConnectFailed", "Signed in, but the client could not connect.");
                    ShowNotice(ValidationMessage);
                }
            }
        }
        finally
        {
            Password = string.Empty;
            if (!RememberLoginInfo)
            {
                ClientIdText = string.Empty;
                AgentToken = string.Empty;
            }
            IsBusy = false;
        }
    }

    [RelayCommand]
    private async Task ImportProxyUriAsync()
    {
        ProxyImportError = string.Empty;
        if (string.IsNullOrWhiteSpace(ProxyImportUri))
        {
            ProxyImportError = L.T("ProxyErrUriRequired", "Paste an ss:// or wg:// URI first.");
            return;
        }

        if (!NodeEditor.TryImport(ProxyImportUri, out var error))
        {
            ProxyImportError = error switch
            {
                "proxy_uri_required" => L.T("ProxyErrUriRequired", "Paste an ss:// or wg:// URI first."),
                "proxy_protocol_unsupported" => L.T("ProxyErrProtocol", "This proxy URI protocol is not supported."),
                "proxy_plugin_unsupported" => L.T("ProxyErrPlugin", "Shadowsocks plugin URIs are not supported."),
                "proxy_wg_config_invalid" => L.T("NodeErrWireGuard", "The WireGuard configuration is incomplete."),
                "proxy_credentials_invalid" => L.T("NodeErrCredentials", "The Shadowsocks credentials are invalid."),
                "proxy_endpoint_invalid" or "proxy_port_invalid" => L.T("ProxyErrEndpoint", "The proxy server or port is invalid."),
                _ => L.T("ProxyErrImport", "The proxy URI could not be imported."),
            };
            return;
        }

        await SaveConnectionProfileAsync();
        if (string.IsNullOrEmpty(LocalConnectionError))
            ProxyImportUri = string.Empty;
    }

    [RelayCommand]
    private async Task SelectClientAsync(int clientId)
    {
        if (clientId <= 0)
            return;
        IsBusy = true;
        try
        {
            await _controller.SelectClientAsync(clientId);
            await _controller.ConnectAsync();
        }
        catch
        {
            ValidationMessage = L.T("ClientOpenFailed", "The selected client could not be opened.");
        }
        finally
        {
            IsBusy = false;
        }
    }

    [RelayCommand]
    private async Task ConnectAsync()
    {
        try
        {
            await _controller.ConnectAsync();
        }
        catch (Exception exception)
        {
            ValidationMessage = L.T("ConnectFailed", "Connection failed.");
            Log.Warning("mac manual connect failed type={ErrorType} code={ErrorCode}",
                exception.GetType().Name,
                BoundErrorCode(exception));
            ShowNotice(ValidationMessage);
        }
    }

    [RelayCommand]
    private async Task RefreshAsync()
    {
        try
        {
            await _controller.RefreshAsync();
        }
        catch (DeviceLimitReachedException)
        {
            // The device-limit banner already explains this and stays up.
        }
        catch
        {
            ValidationMessage = L.T("RefreshFailed", "Refresh failed. The current connection is still running.");
            ShowNotice(ValidationMessage);
        }
    }

    [RelayCommand] private async Task DisconnectAsync()
    {
        await StopConnectionAsync();
        await _controller.DisconnectAsync();
    }
    [RelayCommand] private Task StartAllAsync() => _controller.StartAllAsync();
    [RelayCommand] private async Task StopAllAsync()
    {
        Exception? failure = null;
        try
        {
            await StopConnectionAsync();
        }
        catch (Exception exception)
        {
            failure = exception;
            Log.Warning("mac stop local connection failed type={ErrorType}", exception.GetType().Name);
        }

        try
        {
            await _controller.StopAllAsync();
        }
        catch (Exception exception)
        {
            failure ??= exception;
            Log.Warning("mac stop all forwards failed type={ErrorType}", exception.GetType().Name);
        }

        if (failure is null)
        {
            ValidationMessage = string.Empty;
            ShowNotice(L.T("AllForwardsStopped", "All forwards stopped."));
        }
        else if (failure is InvalidOperationException
                 && string.Equals(failure.Message, "client_not_connected", StringComparison.Ordinal))
        {
            ValidationMessage = string.Empty;
            ShowNotice(L.T("AllForwardsAlreadyStopped", "All forwards are already stopped."));
        }
        else
        {
            ValidationMessage = L.T("StopAllFailed", "Could not stop all forwards. Please try again.");
            ShowNotice(ValidationMessage);
        }
    }

    [RelayCommand]
    private async Task SignOutAsync()
    {
        await StopConnectionAsync();
        await _controller.SignOutAsync();
        ClientIdText = string.Empty;
        AgentToken = string.Empty;
        SelectedSection = "dashboard";
        ShowDashboard();
    }

    [RelayCommand]
    private void ShowConnections()
    {
        IsNodesVisible = false;
        SelectedSection = "connections";
        IsPairingVisible = false;
        IsDashboardVisible = false;
        IsLogsVisible = false;
        IsSettingsVisible = false;
        IsConnectionsVisible = !IsLoginVisible && !IsClientPickerVisible;
    }

    [RelayCommand]
    private void ShowPairing()
    {
        IsNodesVisible = false;
        SelectedSection = "pairing";
        IsDashboardVisible = false;
        IsLogsVisible = false;
        IsSettingsVisible = false;
        IsConnectionsVisible = false;
        IsPairingVisible = !IsLoginVisible && !IsClientPickerVisible;
    }

    [RelayCommand]
    private void NewConnectionProfile()
    {
        SelectedConnectionProfile = null;
        NodeEditor.Load(new MacConnectionProfile
        {
            Name = L.T("NewNodeName", "New node"),
            Protocol = MacConnectionProtocol.Shadowsocks,
            Port = 443,
        });
        LocalConnectionError = string.Empty;
        IsProxyEditorExpanded = true;
    }

    [RelayCommand]
    private async Task SaveConnectionProfileAsync()
    {
        if (_connections is null)
            return;
        LocalConnectionError = string.Empty;
        if (NodeEditor.IsShareLinkNode && NodeEditor.Id > 0)
        {
            // Hosted / share-link nodes are cloud copies or carry settings the field
            // editor cannot show; saving here would fork them from the panel.
            LocalConnectionError = L.T("EditorManagedOnNodesPage", "Cloud and share-link nodes are edited on the Nodes page.");
            return;
        }
        try
        {
            var profile = NodeEditor.ToProfile();
            await _connections.SaveProfileAsync(profile);
            ReplaceConnectionProfiles(_connections.Profiles, profile.Id);
            await _connections.SaveSelectionAsync(SelectedConnectionForward?.ForwardId ?? 0, profile.Id);
            IsProxyEditorExpanded = false;
        }
        catch (Exception exception)
        {
            LocalConnectionError = exception.Message.Contains("protocol", StringComparison.OrdinalIgnoreCase)
                ? L.T("ProfileSaveProtocol", "Choose a supported protocol and complete its required fields.")
                : L.T("ProfileSaveFailed", "The node could not be saved. Check the required fields.");
        }
    }

    [RelayCommand]
    private async Task DeleteConnectionProfileAsync()
    {
        if (_connections is null || SelectedConnectionProfile is null)
            return;
        await StopConnectionAsync();
        await _connections.DeleteProfileAsync(SelectedConnectionProfile.Id);
        _connectionSelection = _connections.Selection;
        ReplaceConnectionProfiles(_connections.Profiles, 0);
    }

    [RelayCommand]
    private async Task StartConnectionAsync()
    {
        if (_connections is null)
            return;
        var profile = SelectedConnectionProfile;
        if (profile is null)
        {
            LocalConnectionError = L.T("SelectNodeFirst", "Select or save a node first.");
            return;
        }
        var forward = SelectedConnectionForward;
        if (forward is null)
        {
            LocalConnectionError = L.T("SelectRouteFirst", "Select an available route first.");
            return;
        }
        if (SelectedConnectionMode is not { } mode)
        {
            LocalConnectionError = L.T("SelectModeFirst", "Select a connection mode first.");
            return;
        }
        var configuredForward = _forwardsProvider().FirstOrDefault(item => item.ForwardId == forward.ForwardId);
        if (configuredForward is null)
        {
            LocalConnectionError = L.T("RouteUnavailable", "The selected route is no longer available. Refresh the routes and try again.");
            return;
        }
        if (!IsConnectionTransportCompatible(profile, configuredForward))
        {
            AddLog("proxy_forward_transport_mismatch");
            LocalConnectionError = ConnectionTransportError(profile);
            return;
        }
        LocalConnectionError = string.Empty;
        using var cancellation = new CancellationTokenSource();
        _connectionStartCancellation = cancellation;
        await _connectionGate.WaitAsync();
        try
        {
            IsConnectionStarting = true;
            _connectionStartErrorCode = string.Empty;
            await _connections.StopAsync(cancellation.Token);
            await ReleaseConnectionForwardAsync();
            LocalConnectionStatus = "Starting";
            await _connections.SaveSelectionAsync(forward.ForwardId, profile.Id, cancellation.Token);
            _pendingConnectionForwardId = forward.ForwardId;
            _pendingConnectionForwardWasRunning = _controller.Snapshot.Forwards
                .Any(item => item.ForwardId == forward.ForwardId && item.IsRunning);
            _connectionForwardLease = await _controller.AcquireProxyForwardAsync(forward.ForwardId, cancellation.Token);
            cancellation.Token.ThrowIfCancellationRequested();
            ValidateConnectionForward(profile, _connectionForwardLease);
            await _connections.StartRoutedAsync(
                mode,
                profile.Id,
                _connectionForwardLease.ListenPort,
                PanelUrl,
                _forwardsProvider(),
                cancellation.Token);
            cancellation.Token.ThrowIfCancellationRequested();
            ValidateConnectionForward(profile, _connectionForwardLease);
            IsConnectionStarting = false;
            ApplyConnectionSnapshot(_connections.Snapshot);
        }
        catch (Exception exception)
        {
            await StopLocalConnectionCoreAsync();
            var errorCode = string.IsNullOrEmpty(_connectionStartErrorCode) ? exception.Message : _connectionStartErrorCode;
            if (errorCode is "proxy_forward_transport_mismatch" or "proxy_forward_unavailable")
                AddLog(errorCode);
            LocalConnectionError = errorCode == "proxy_forward_transport_mismatch" ? ConnectionTransportError(profile)
                : errorCode == "proxy_forward_unavailable" ? L.T("RouteStopped", "The selected route was stopped or removed. No direct connection was made.")
                : cancellation.IsCancellationRequested
                ? string.Empty : L.T("RouteStartFailed", "The selected route or node could not be started. No direct connection was made.");
        }
        finally
        {
            _pendingConnectionForwardId = 0;
            _pendingConnectionForwardWasRunning = false;
            IsConnectionStarting = false;
            if (ReferenceEquals(_connectionStartCancellation, cancellation))
                _connectionStartCancellation = null;
            _connectionGate.Release();
        }
    }

    [RelayCommand]
    private async Task StartRouteAsync(ForwardRowViewModel? route)
    {
        if (route is null)
            return;
        SelectedConnectionForward = route;
        await StartConnectionAsync();
    }

    [RelayCommand]
    private async Task StopRouteAsync(ForwardRowViewModel? route)
    {
        if (route is null)
            return;
        if (SelectedConnectionForward?.ForwardId != route.ForwardId || !IsLocalConnectionActive)
            return;
        await StopConnectionAsync();
    }

    private static bool IsConnectionTransportCompatible(MacConnectionProfile profile, ForwardConfig forward)
    {
        var transport = forward.Transport?.Trim().ToLowerInvariant();
        return profile.Protocol.Trim().ToLowerInvariant() switch
        {
            MacConnectionProtocol.WireGuard => transport is "udp" or "tcp+udp",
            MacConnectionProtocol.Shadowsocks => transport is "tcp" or "tcp+udp",
            MacConnectionProtocol.Socks5 or MacConnectionProtocol.Vless => transport is "tcp" or "tcp+udp",
            MacConnectionProtocol.External => transport is "tcp" or "udp" or "tcp+udp",
            _ => false,
        };
    }

    private static string ConnectionTransportError(MacConnectionProfile profile)
        => profile.Protocol.Equals(MacConnectionProtocol.WireGuard, StringComparison.OrdinalIgnoreCase)
            ? L.T("TransportWireGuard", "The selected route can't carry this node. Choose a route with UDP or TCP + UDP transport.")
            : profile.Protocol.Equals(MacConnectionProtocol.Shadowsocks, StringComparison.OrdinalIgnoreCase)
                ? L.T("TransportShadowsocks", "The selected route can't carry this node. Choose a route with TCP or TCP + UDP transport.")
                : L.T("TransportShadowsocks", "The selected route can't carry this node. Choose a route with TCP or TCP + UDP transport.");

    private void ValidateConnectionForward(MacConnectionProfile profile, ProxyForwardLease lease)
    {
        var current = _controller.Snapshot.Forwards.FirstOrDefault(item => item.ForwardId == lease.Forward.ForwardId);
        var configured = _forwardsProvider().FirstOrDefault(item => item.ForwardId == lease.Forward.ForwardId);
        if (current is not { IsRunning: true } || current.ListenPort != lease.ListenPort
            || configured is null || configured.ListenPort != lease.ListenPort
            || !string.Equals(configured.ServiceStatus, "active", StringComparison.OrdinalIgnoreCase))
            throw new InvalidOperationException("proxy_forward_unavailable");
        if (!IsConnectionTransportCompatible(profile, configured))
            throw new InvalidOperationException("proxy_forward_transport_mismatch");
    }

    [RelayCommand]
    private async Task StartNativeConnectionAsync()
    {
        if (_connections is null)
            return;
        if (!IsNativeOutboundVisible || SelectedNativeForwardId <= 0)
        {
            LocalConnectionError = L.T("NativeNotInPlan", "Native outbound is not included in the active plan.");
            return;
        }
        var carrier = NativeOutboundCapability?.FindForward(SelectedNativeForwardId);
        if (carrier is null
            || !carrier.RouteScopes.Contains(SelectedNativeRouteScope, StringComparer.OrdinalIgnoreCase))
        {
            LocalConnectionError = L.T("NativeScopeInvalid", "Choose a valid native traffic scope.");
            return;
        }
        LocalConnectionError = string.Empty;
        try
        {
            await StopConnectionAsync();
            var useUnrestrictedRoute = NativeUnrestrictedRoute && carrier.UnrestrictedAllowed;
            var routePolicy = useUnrestrictedRoute ? "unrestricted" : "restricted";
            Log.Information("mac native lease request mode={Mode} forward={ForwardId} scope={Scope} policy={Policy}",
                SelectedNativeConnectionMode, SelectedNativeForwardId, SelectedNativeRouteScope, routePolicy);
            var lease = await _controller.ResolveNativeOutboundLeaseAsync(
                SelectedNativeForwardId,
                SelectedNativeRouteScope,
                routePolicy,
                CancellationToken.None);
            if (lease is null)
            {
                LocalConnectionError = L.T("NativeLeaseUnavailable", "The native outbound lease is unavailable.");
                return;
            }
            _nativeActiveMode = SelectedNativeConnectionMode;
            _nativeActiveForwardId = SelectedNativeForwardId;
            _nativeActiveRouteScope = SelectedNativeRouteScope;
            _nativeActiveBypassChina = NativeBypassChinaMainland && IsNativeRouteGlobal;
            _nativeActiveUnrestricted = useUnrestrictedRoute;
            NativeBypassChinaMainland = _nativeActiveBypassChina;
            await _connections.StartNativeAsync(
                SelectedNativeConnectionMode,
                lease,
                PanelUrl,
                _forwardsProvider(),
                _nativeActiveBypassChina,
                routePolicy,
                CancellationToken.None);
        }
        catch (Exception exception)
        {
            var apiCode = exception is MasterApiException apiError
                ? $"native_lease_http_{apiError.StatusCode}" : null;
            Log.Warning("mac native start failed type={ErrorType} code={ErrorCode}", exception.GetType().Name, apiCode ?? "native_start_failed");
            LocalConnectionError = _connections.Snapshot.State == MacConnectionState.Error
                && !string.IsNullOrEmpty(_connections.Snapshot.ErrorCode)
                ? _connections.Snapshot.ErrorCode.Replace('_', ' ')
                : apiCode is not null ? apiCode.Replace('_', ' ')
                : exception.Message.Contains("native", StringComparison.OrdinalIgnoreCase)
                ? exception.Message.Replace('_', ' ')
                : L.T("NativeStartFailed", "The native outbound connection could not be started.");
        }
    }

    [RelayCommand]
    private async Task StopConnectionAsync()
    {
        if (_connections is null)
            return;
        _connectionStartCancellation?.Cancel();
        await _connectionGate.WaitAsync();
        try
        {
            await StopLocalConnectionCoreAsync();
            _nativeActiveForwardId = 0;
            _nativeActiveRouteScope = "global";
            _nativeActiveUnrestricted = false;
        }
        finally
        {
            _connectionGate.Release();
        }
    }

    private Task ReleaseConnectionForwardAsync()
    {
        lock (_connectionForwardReleaseLock)
        {
            var lease = Interlocked.Exchange(ref _connectionForwardLease, null);
            if (lease is not null)
                _connectionForwardRelease = lease.DisposeAsync().AsTask();
            return _connectionForwardRelease;
        }
    }

    private async Task StopLocalConnectionCoreAsync()
    {
        try
        {
            await _connections!.StopAsync();
        }
        finally
        {
            await ReleaseConnectionForwardAsync();
        }
    }

    [RelayCommand]
    private void DismissNotice()
    {
        IsNoticeVisible = false;
        NoticeText = string.Empty;
    }

    [RelayCommand]
    private void DismissUpdate()
    {
        // A required update cannot be dismissed (the button is not shown either).
        if (IsUpdateRequired)
            return;
        IsUpdateVisible = false;
        UpdateVersion = string.Empty;
        ShowUpdateError = false;
        UpdateErrorMessage = string.Empty;
        IsUpdateInProgress = false;
    }

    [RelayCommand]
    private async Task CheckForUpdatesAsync()
    {
        ShowUpdateError = false;
        UpdateErrorMessage = string.Empty;
        _updateFailureStage = "check";
        try
        {
            if (_checkForUpdatesWithProgress is not null)
                await _checkForUpdatesWithProgress(CreateUpdateProgress(), CancellationToken.None);
            else if (_checkForUpdates is not null)
                await _checkForUpdates(CancellationToken.None);
        }
        catch (Exception exception)
        {
            ReportUpdateFailure(BoundErrorCode(exception));
        }
    }

    [RelayCommand]
    private async Task InstallUpdateAsync()
    {
        if ((_installUpdate is null && _installUpdateWithProgress is null) || IsUpdateInstalling)
            return;
        IsUpdateInstalling = true;
        ShowUpdateError = false;
        UpdateErrorMessage = string.Empty;
        _updateFailureStage = "install";
        try
        {
            if (_installUpdateWithProgress is not null)
                await _installUpdateWithProgress(CreateUpdateProgress(), CancellationToken.None);
            else
                await _installUpdate!(CancellationToken.None);
        }
        catch (Exception exception)
        {
            ReportUpdateFailure(BoundErrorCode(exception));
        }
        finally
        {
            IsUpdateInstalling = false;
        }
    }

    [RelayCommand]
    private Task RetryUpdateAsync()
        => string.Equals(_updateFailureStage, "install", StringComparison.Ordinal) && IsUpdateVisible
            ? InstallUpdateAsync()
            : CheckForUpdatesAsync();

    /// <summary>Applies a progress update reported by the update service.</summary>
    public void ReportUpdateProgress(UpdateProgress progress)
        => _dispatch(() =>
        {
            UpdatePhaseText = PhaseLabel(progress.Phase);
            UpdatePercent = progress.Percent;
            UpdateProgressMessage = progress.Message;
            IsUpdateInProgress = true;
        });

    /// <summary>Shows a failed update step with details and a retry action.</summary>
    public void ReportUpdateFailure(string message, string? stage = null)
        => _dispatch(() =>
        {
            if (!string.IsNullOrWhiteSpace(stage))
                _updateFailureStage = stage;
            IsUpdateInProgress = false;
            UpdateErrorMessage = message;
            ShowUpdateError = true;
        });

    /// <summary>Clears progress/error state after an update flow completes.</summary>
    public void ReportUpdateFinished(string? message = null)
        => _dispatch(() =>
        {
            IsUpdateInProgress = false;
            UpdatePhaseText = string.Empty;
            UpdatePercent = 0;
            UpdateProgressMessage = message ?? string.Empty;
            UpdateErrorMessage = string.Empty;
            ShowUpdateError = false;
        });

    private static string PhaseLabel(UpdatePhase phase) => phase switch
    {
        UpdatePhase.Check => L.T("UpdatePhaseCheck", "Checking for updates"),
        UpdatePhase.Download => L.T("UpdatePhaseDownload", "Downloading update"),
        UpdatePhase.Verify => L.T("UpdatePhaseVerify", "Verifying download"),
        UpdatePhase.Stage => L.T("UpdatePhaseStage", "Preparing update"),
        UpdatePhase.Apply => L.T("UpdatePhaseApply", "Applying update"),
        _ => string.Empty,
    };

    private IProgress<UpdateProgress> CreateUpdateProgress()
        => new Progress<UpdateProgress>(ReportUpdateProgress);

    [RelayCommand]
    private async Task ToggleAutostartAsync()
    {
        if (_autostart is null)
            return;
        try
        {
            await _autostart.SetEnabledAsync(AutoStartEnabled, CancellationToken.None);
        }
        catch
        {
            AutoStartEnabled = !AutoStartEnabled;
            AddLog("autostart_update_failed");
        }
    }

    [RelayCommand]
    private async Task SavePreferencesAsync()
    {
        if (_savePreferences is not null)
            await _savePreferences(PanelUrl, Language, CloseToTray, CancellationToken.None);
        if (_saveLogLevel is not null)
            await _saveLogLevel(DesktopLogLevel.Normalize(LogLevel), CancellationToken.None);
        if (_saveEgressInterface is not null)
            await _saveEgressInterface(PreferredEgressInterface, CancellationToken.None);
    }

    [RelayCommand]
    private void ShowDashboard()
    {
        IsNodesVisible = false;
        SelectedSection = "dashboard";
        IsPairingVisible = false;
        IsDashboardVisible = !IsLoginVisible && !IsClientPickerVisible;
        IsLogsVisible = false;
        IsSettingsVisible = false;
        IsConnectionsVisible = false;
    }

    [RelayCommand]
    private void ShowLogs()
    {
        IsNodesVisible = false;
        SelectedSection = "logs";
        IsPairingVisible = false;
        IsDashboardVisible = false;
        IsLogsVisible = true;
        IsSettingsVisible = false;
        IsConnectionsVisible = false;
    }

    [RelayCommand]
    private void ShowSettings()
    {
        IsNodesVisible = false;
        SelectedSection = "settings";
        IsPairingVisible = false;
        IsDashboardVisible = false;
        IsLogsVisible = false;
        IsSettingsVisible = true;
        IsConnectionsVisible = false;
    }

    private void ApplySnapshot(DesktopClientSnapshot snapshot)
    {
        IsBusy = snapshot.IsBusy;
        ConnectionStatus = snapshot.ConnectionStatus;
        ApplyDeviceLimit(snapshot);
        SignedInUsername = snapshot.SignedInUsername;
        ActiveClientName = snapshot.ActiveClientName;
        if (!string.IsNullOrWhiteSpace(snapshot.PanelUrl))
            PanelUrl = snapshot.PanelUrl;
        Clients.Clear();
        foreach (var client in snapshot.AvailableClients)
            Clients.Add(client);
        IsLoginVisible = !snapshot.IsSignedIn;
        IsClientPickerVisible = snapshot.IsSignedIn && snapshot.RequiresClientSelection;
        if (SelectedSection == "dashboard")
            IsDashboardVisible = !IsLoginVisible && !IsClientPickerVisible;
        if (SelectedSection == "connections")
            IsConnectionsVisible = !IsLoginVisible && !IsClientPickerVisible;
        if (SelectedSection == "pairing")
            IsPairingVisible = !IsLoginVisible && !IsClientPickerVisible;
        if (SelectedSection == "nodes")
            IsNodesVisible = !IsLoginVisible && !IsClientPickerVisible;
        ApplyNativeOutbound(snapshot.NativeOutbound);
        ApplyForwards(snapshot.Forwards);
        if (!snapshot.IsBusy)
            _preparationProgress = string.Empty;
        OnPropertyChanged(nameof(BusyMessage));
    }

    public string BusyMessage => IsLoginVisible ? L.T("BusySigningIn", "Signing in")
        : ConnectionStatus == "refreshing" ? L.T("BusyRefreshing", "Refreshing routes")
        : L.T("BusyStarting", "Starting routes") + (_preparationProgress.Length == 0 ? string.Empty : " " + _preparationProgress);

    private async Task LoadConnectionsAsync()
    {
        if (_connections is null)
            return;
        try
        {
            await _connections.InitializeAsync();
            _dispatch(() =>
            {
                _connectionSelection = _connections.Selection;
                ReplaceConnectionProfiles(_connections.Profiles, _connectionSelection.ProfileId);
                SelectedConnectionForward = Forwards.FirstOrDefault(forward => forward.ForwardId == _connectionSelection.ForwardId);
                if (SelectedConnectionForward is not null
                    && _connectionSelection.ForwardProfiles.TryGetValue(SelectedConnectionForward.ForwardId, out var profileId))
                    SelectedConnectionProfile = ConnectionProfiles.FirstOrDefault(profile => profile.Id == profileId);
                ApplySavedNodeNames();
                if (SelectedConnectionForward is null || !SelectedConnectionForward.IsPaired)
                {
                    SelectedConnectionForward = PairedConnectionRoutes.FirstOrDefault();
                    SelectedConnectionProfile = SelectedConnectionForward?.SelectedNode;
                }
                _loadingConnectionSelection = false;
                ApplyConnectionSnapshot(_connections.Snapshot);
            });
        }
        catch
        {
            _dispatch(() => LocalConnectionError = L.T("SavedNodesLoadFailed", "Saved nodes could not be loaded."));
        }
    }

    private void ReplaceConnectionProfiles(
        IReadOnlyList<MacConnectionProfile> profiles,
        int preferredId)
    {
        ConnectionProfiles.Clear();
        foreach (var profile in profiles)
            ConnectionProfiles.Add(profile);
        OnPropertyChanged(nameof(HasConnectionProfiles));
        SelectedConnectionProfile = ConnectionProfiles.FirstOrDefault(profile => profile.Id == preferredId)
            ?? null;
        RefreshNodeOptions();
        RefreshNodesPage();
    }

    private void RefreshNodeOptions()
    {
        var options = new[]
        {
            new MacConnectionProfile { Id = 0, Name = L.T("NoSavedNodeSelected", "No saved node selected") },
        }.Concat(ConnectionProfiles).ToList();
        foreach (var row in Forwards)
            row.NodeOptions = options;
    }

    private void ApplyConnectionSnapshot(MacConnectionSnapshot snapshot)
    {
        LocalConnectionStatus = snapshot.State switch
        {
            MacConnectionState.Connected => IsConnectionStarting ? "Starting" : "Connected",
            MacConnectionState.Starting => "Starting",
            MacConnectionState.Error => "Connection error",
            _ => "Disconnected",
        };
        BypassAddressCount = snapshot.BypassAddressCount;
        if (!string.IsNullOrWhiteSpace(snapshot.ErrorCode))
            LocalConnectionError = snapshot.ErrorCode.Replace('_', ' ');
        OnPropertyChanged(nameof(LocalConnectionStatus));
        OnPropertyChanged(nameof(LocalConnectionStatusText));
        OnPropertyChanged(nameof(IsLocalConnectionActive));
        if (snapshot.State is MacConnectionState.Error or MacConnectionState.Disconnected)
            _ = ReleaseConnectionForwardAsync();
        ScheduleNativeLeaseRefresh();
    }

    private void ApplyNativeOutbound(NativeOutboundCapability? capability)
    {
        NativeOutboundCapability = capability;
        if (_connections?.IsNativeOutboundActive == true
            && !NativeCapabilityCoversActiveSession(capability))
        {
            _ = StopRevokedNativeAsync();
            return;
        }
        ScheduleNativeLeaseRefresh();
    }

    private bool NativeCapabilityCoversActiveSession(NativeOutboundCapability? capability)
    {
        if (capability is not { Enabled: true }
            || !string.Equals(capability.Protocol, "socks5", StringComparison.OrdinalIgnoreCase)
            || !string.Equals(capability.LeaseProtocol, MasterApiClient.NativeOutboundLeaseProtocol, StringComparison.Ordinal)
            || !string.Equals(capability.Transport, "tcp+udp", StringComparison.OrdinalIgnoreCase))
            return false;

        var carrier = capability.FindForward(_nativeActiveForwardId);
        return carrier is not null
            && string.Equals(carrier.Transport, "tcp+udp", StringComparison.OrdinalIgnoreCase)
            && carrier.RouteScopes.Contains(_nativeActiveRouteScope, StringComparer.OrdinalIgnoreCase)
            && (!_nativeActiveUnrestricted || carrier.UnrestrictedAllowed);
    }

    private async Task StopRevokedNativeAsync()
    {
        if (_connections is null)
            return;
        try
        {
            await _connections.StopAsync().ConfigureAwait(false);
        }
        catch (Exception exception)
        {
            _dispatch(() => AddLog($"native_outbound_stop_failed:{exception.GetType().Name}"));
        }
        finally
        {
            _nativeActiveForwardId = 0;
            _nativeActiveRouteScope = "global";
            _nativeActiveUnrestricted = false;
            NativeUnrestrictedRoute = false;
        }
    }

    private void ApplyForwards(IReadOnlyList<DesktopForwardSnapshot> forwards)
    {
        var selectedId = SelectedConnectionForward?.ForwardId ?? _connectionSelection.ForwardId;
        var wasLoading = _loadingConnectionSelection;
        _loadingConnectionSelection = true;
        Forwards.Clear();
        foreach (var forward in forwards)
        {
            var row = new ForwardRowViewModel(_controller, forward);
            row.StartConnection = () => StartRouteAsync(row);
            row.StopConnection = () => StopRouteAsync(row);
            row.NodeOptions = [];
            row.PairNode = profileId => PairRouteNodeAsync(row, profileId);
            row.PropertyChanged += (_, args) =>
            {
                if (args.PropertyName == nameof(ForwardRowViewModel.IsRunning))
                    OnPropertyChanged(nameof(RunningForwardCount));
            };
            Forwards.Add(row);
        }
        SelectedConnectionForward = Forwards.FirstOrDefault(forward => forward.ForwardId == selectedId);
        foreach (var row in Forwards)
            row.SetSavedNode(_connectionSelection.ForwardProfiles.TryGetValue(row.ForwardId, out var profileId)
                ? ConnectionProfiles.FirstOrDefault(item => item.Id == profileId)
                : null);
        _loadingConnectionSelection = wasLoading;
        if (_pendingConnectionForwardId > 0)
        {
            var pending = forwards.FirstOrDefault(forward => forward.ForwardId == _pendingConnectionForwardId);
            var unavailable = pending is null || (!pending.IsRunning
                && (_pendingConnectionForwardWasRunning || _connectionForwardLease is not null));
            if (pending?.IsRunning == true)
                _pendingConnectionForwardWasRunning = true;
            if (unavailable && _connectionStartCancellation is { IsCancellationRequested: false } cancellation)
            {
                _connectionStartErrorCode = "proxy_forward_unavailable";
                cancellation.Cancel();
            }
        }
        else if (_connectionForwardLease is { } lease && !IsConnectionStarting
            && !forwards.Any(forward => forward.ForwardId == lease.Forward.ForwardId && forward.IsRunning))
            _ = StopConnectionAsync();
        OnPropertyChanged(nameof(HasForwards));
        OnPropertyChanged(nameof(ForwardCount));
        OnPropertyChanged(nameof(RunningForwardCount));
        RefreshNodeOptions();
        OnPropertyChanged(nameof(PairedConnectionRoutes));
        OnPropertyChanged(nameof(CanStartLocalConnection));
        RefreshNodesPage();
        ScheduleNativeLeaseRefresh();
    }

    private void ApplySavedNodeNames()
    {
        foreach (var row in Forwards)
        {
            var profile = _connectionSelection.ForwardProfiles.TryGetValue(row.ForwardId, out var profileId)
                ? ConnectionProfiles.FirstOrDefault(item => item.Id == profileId)
                : null;
            row.SetSavedNode(profile);
        }
    }

    private async Task PairRouteNodeAsync(ForwardRowViewModel row, int profileId, bool pushToCloud = true)
    {
        if (_connections is null || _loadingConnectionSelection)
            return;
        if (profileId > 0 && !ConnectionProfiles.Any(profile => profile.Id == profileId))
            return;
        var previousProfileId = _connectionSelection.ForwardProfiles.TryGetValue(row.ForwardId, out var previous) ? previous : 0;
        _connectionSelection = _connectionSelection with
        {
            ForwardId = row.ForwardId,
            ProfileId = profileId,
            ForwardProfiles = profileId > 0
                ? new Dictionary<int, int>(_connectionSelection.ForwardProfiles) { [row.ForwardId] = profileId }
                : _connectionSelection.ForwardProfiles.Where(pair => pair.Key != row.ForwardId)
                    .ToDictionary(pair => pair.Key, pair => pair.Value),
        };
        if (SelectedConnectionForward?.ForwardId == row.ForwardId)
            SelectedConnectionProfile = profileId > 0 ? ConnectionProfiles.FirstOrDefault(profile => profile.Id == profileId) : null;
        OnPropertyChanged(nameof(PairedConnectionRoutes));
        OnPropertyChanged(nameof(CanStartLocalConnection));
        try
        {
            await _connections.SaveSelectionAsync(row.ForwardId, profileId).ConfigureAwait(false);
        }
        catch
        {
            _dispatch(() => LocalConnectionError = L.T("SelectionSaveFailed", "The selected route and node could not be saved."));
        }
        if (pushToCloud)
            await PushPairingToCloudAsync(row.ForwardId, profileId, previousProfileId).ConfigureAwait(false);
    }

    private void ScheduleNativeLeaseRefresh()
    {
        if (_connections?.IsNativeOutboundActive != true)
            return;
        _ = Task.Run(RefreshNativeLeaseIfNeededAsync);
        EnsureNativeTunMtuWatch();
    }

    private void EnsureNativeTunMtuWatch()
    {
        if (Interlocked.CompareExchange(ref _nativeTunMtuWatchRunning, 1, 0) != 0)
            return;
        _ = Task.Run(WatchNativeTunMtuAsync);
    }

    /// <summary>
    /// A live relay re-measures its payload ceiling and republishes the status file with no
    /// event to hang the reaction on. The watcher watches <see cref="IMacConnectionService.IsNativeTunMtuPending"/>
    /// for the flip and hot-applies the new TUN MTU to the running tunnel, so the user no
    /// longer has to reconnect or wait for the next refresh for it to take effect.
    /// </summary>
    private async Task WatchNativeTunMtuAsync()
    {
        try
        {
            while (_connections?.IsNativeOutboundActive == true)
            {
                await Task.Delay(_nativeTunMtuWatchInterval).ConfigureAwait(false);
                if (_connections?.IsNativeOutboundActive != true)
                    return;
                if (_connections.IsNativeTunMtuPending)
                    await ApplyPendingNativeTunMtuAsync().ConfigureAwait(false);
            }
        }
        finally
        {
            Interlocked.Exchange(ref _nativeTunMtuWatchRunning, 0);
        }
    }

    private async Task ApplyPendingNativeTunMtuAsync()
    {
        try
        {
            if (await _connections!.ApplyPendingNativeTunMtuAsync(CancellationToken.None).ConfigureAwait(false))
            {
                Log.Information("mac_native_tun_mtu_applied forward={ForwardId}", _nativeActiveForwardId);
                return;
            }
            // Debounced or not yet proven: the flag stays set and the watcher retries.
            Log.Information("mac_native_tun_mtu_apply_pending forward={ForwardId}", _nativeActiveForwardId);
        }
        catch (Exception exception)
        {
            _dispatch(() => AddLog($"native_tun_mtu_apply_failed:{exception.GetType().Name}"));
        }
    }

    private async Task RefreshNativeLeaseIfNeededAsync()
    {
        if (Interlocked.CompareExchange(ref _nativeRefreshInProgress, 1, 0) != 0)
            return;
        try
        {
            if (_connections?.IsNativeOutboundActive != true)
                return;
            var now = DateTimeOffset.UtcNow;
            var expiresAt = _connections.ActiveNativeLeaseExpiresAt;
            if (!NativeCapabilityCoversActiveSession(NativeOutboundCapability))
            {
                await StopRevokedNativeAsync().ConfigureAwait(false);
                return;
            }
            // A re-measured payload ceiling changes the TUN MTU. sing-box re-reads its
            // config on reload, so the MTU no longer forces a restart cycle: apply it to the
            // live tunnel, and keep the restart path below as the fallback for the case the
            // reload cannot be proven (it runs anyway once the lease has to be renewed).
            if (_connections.IsNativeTunMtuPending
                && await _connections.ApplyPendingNativeTunMtuAsync(CancellationToken.None).ConfigureAwait(false))
                return;
            if (expiresAt is DateTimeOffset expiry && expiry > now.AddMinutes(1))
                return;
            var lease = await _controller.ResolveNativeOutboundLeaseAsync(
                _nativeActiveForwardId,
                _nativeActiveRouteScope,
                _nativeActiveUnrestricted ? "unrestricted" : "restricted",
                CancellationToken.None);
            if (lease is null)
                return;

            // Refresh the short-lived relay lease in place first. A full
            // sing-box restart is only needed when the relay/control channel
            // is no longer usable.
            if (_connections.IsNativeOutboundActive != true)
            {
                lease.Dispose();
                return;
            }
            bool hotRefreshed;
            try
            {
                hotRefreshed = await _connections.TryRefreshNativeAsync(
                    lease,
                    CancellationToken.None).ConfigureAwait(false);
            }
            catch
            {
                lease.Dispose();
                throw;
            }
            if (!hotRefreshed)
            {
                if (_connections.IsNativeOutboundActive)
                {
                    await _connections.StartNativeAsync(
                        _nativeActiveMode,
                        lease,
                        PanelUrl,
                        _forwardsProvider(),
                        _nativeActiveBypassChina,
                        _nativeActiveUnrestricted ? "unrestricted" : "restricted",
                        CancellationToken.None);
                }
                else
                {
                    lease.Dispose();
                    return;
                }
            }
        }
        catch (Exception exception)
        {
            AddLog($"native_outbound_refresh_failed:{exception.GetType().Name}");
        }
        finally
        {
            Interlocked.Exchange(ref _nativeRefreshInProgress, 0);
        }
    }

    /// <summary>
    /// Keeps the device-limit explanation up while the panel refuses this
    /// device; the controller clears the code on the next admitted pull.
    /// </summary>
    private void ApplyDeviceLimit(DesktopClientSnapshot snapshot)
    {
        var limited = string.Equals(snapshot.ConnectionStatus, DesktopClientController.DeviceLimitReachedCode, StringComparison.Ordinal);
        DeviceLimitMessage = limited
            ? FormatDeviceLimitMessage(snapshot.ActiveOnlineDevices, snapshot.OnlineDeviceLimit)
            : string.Empty;
        IsDeviceLimitVisible = limited;
    }

    internal static string FormatDeviceLimitMessage(int activeOnlineDevices, int onlineDeviceLimit)
    {
        var key = onlineDeviceLimit > 0 ? "DeviceLimitReachedWithCounts" : "DeviceLimitReachedMessage";
        object? value = null;
        if (Avalonia.Application.Current?.TryGetResource(key, null, out value) != true || value is not string template)
            return DeviceLimitReachedException.FormatMessage(activeOnlineDevices, onlineDeviceLimit);
        return onlineDeviceLimit > 0
            ? string.Format(System.Globalization.CultureInfo.CurrentCulture, template, activeOnlineDevices, onlineDeviceLimit)
            : template;
    }

    private void ShowNotice(string notice)
    {
        NoticeText = notice;
        IsNoticeVisible = true;
    }

    private static string BoundErrorCode(Exception exception)
    {
        var message = exception.Message.Replace('\r', ' ').Replace('\n', ' ').Trim();
        if (message.Length > 120)
            message = message[..120];
        return string.IsNullOrWhiteSpace(message) ? "unknown" : message;
    }

    private void ShowUpdate(string version)
    {
        UpdateVersion = version;
        IsUpdateVisible = true;
        ShowUpdateError = false;
        UpdateErrorMessage = string.Empty;
        IsUpdateInProgress = false;
    }

    private void AddLog(string message)
    {
        var bounded = LogPrivacy.Redact(message ?? string.Empty).Replace('\r', ' ').Replace('\n', ' ');
        if (bounded.Length > 512)
            bounded = bounded[..512];
        Logs.Insert(0, $"{DateTimeOffset.Now:HH:mm:ss}  {bounded}");
        while (Logs.Count > 500)
            Logs.RemoveAt(Logs.Count - 1);
    }

    private async Task LoadAutostartAsync()
    {
        if (_autostart is null)
            return;
        try
        {
            var enabled = await _autostart.IsEnabledAsync(CancellationToken.None);
            _dispatch(() => AutoStartEnabled = enabled);
        }
        catch
        {
            _dispatch(() => AddLog("autostart_status_unavailable"));
        }
    }

    private static void Dispatch(Action action)
    {
        if (Dispatcher.UIThread.CheckAccess())
            action();
        else
            Dispatcher.UIThread.Post(action);
    }
}

public sealed record NativeRouteScopeOption(string Key, string Label);
