using CommunityToolkit.Mvvm.ComponentModel;
using CommunityToolkit.Mvvm.Input;
using LeikwanBridge.Core.Services;
using LeikwanBridge.Mac.Localization;
using LeikwanBridge.Mac.Services;
using Serilog;

namespace LeikwanBridge.Mac.ViewModels;

/// <summary>
/// Window shell for the FlClash-style layout: sidebar sections, dashboard cards,
/// the start / stop button with its running timer, live traffic and the Nodes page.
/// The view drives <see cref="Tick"/> once a second; nothing here owns a timer, so
/// the view model stays testable without a dispatcher.
/// </summary>
/// <summary>A settings choice: a stored value shown through the localization catalog.</summary>
public sealed record ChoiceOption(string Value);

public sealed partial class MainWindowViewModel
{
    private Func<string, CancellationToken, Task>? _saveThemeMode;
    private bool _shellReady;
    private readonly TrafficMonitor _trafficMonitor = new();
    private Func<IReadOnlyDictionary<string, TrafficCounter>>? _trafficCounters;
    private INodeLibrary? _nodeLibrary;
    private DateTimeOffset? _runningSince;
    private DateTimeOffset _lastTick = DateTimeOffset.MinValue;

    public NodesViewModel NodesPage { get; private set; } = null!;

    [ObservableProperty, NotifyPropertyChangedFor(nameof(IsNodesSectionVisible))]
    private bool _isNodesVisible;

    [ObservableProperty] private string _runningTimeText = "00:00:00";
    [ObservableProperty] private string _downloadRateText = "0 B/s";
    [ObservableProperty] private string _uploadRateText = "0 B/s";
    [ObservableProperty] private string _sessionDownloadText = "0 B";
    [ObservableProperty] private string _sessionUploadText = "0 B";
    [ObservableProperty] private double _sessionDownloadBytes;
    [ObservableProperty] private double _sessionUploadBytes;
    [ObservableProperty] private IReadOnlyList<double> _downloadHistory = new double[TrafficMonitor.HistoryLength];
    [ObservableProperty] private IReadOnlyList<double> _uploadHistory = new double[TrafficMonitor.HistoryLength];
    [ObservableProperty] private bool _isCheckingForUpdates;

    public bool IsNodesSectionVisible => IsNodesVisible || IsPairingVisible;
    public bool IsDashboardSelected => SelectedSection == "dashboard";
    public bool IsServicesSelected => SelectedSection == "connections";
    public bool IsNodesSelected => SelectedSection is "nodes" or "pairing";
    public bool IsSettingsSelected => SelectedSection == "settings";
    public bool IsLogsSelected => SelectedSection == "logs";
    public bool IsShellVisible => IsSignedIn && !IsClientPickerVisible;

    /// <summary>Anything carrying traffic: an assigned forward or the local proxy / TUN connection.</summary>
    public bool IsAnyRunning => RunningForwardCount > 0 || IsLocalConnectionActive;
    public string RunToggleTip => IsAnyRunning
        ? L.T("RunToggleStop", "Stop all forwards and the local connection")
        : L.T("RunToggleStart", "Start all forwards");

    public string VersionNumber => typeof(MainWindowViewModel).Assembly.GetName().Version?.ToString(3) ?? "1.0.0";
    public string UpdateStatusText => IsUpdateInProgress ? UpdatePhaseText
        : IsUpdateVisible ? L.F("UpdateVersionAvailable", "Version {0} available", UpdateVersion)
        : ShowUpdateError ? L.T("UpdateCheckFailed", "Update check failed")
        : IsCheckingForUpdates ? L.T("CheckingForUpdates", "Checking for updates…")
        : L.T("CheckForUpdates", "Check for updates");
    public string UpdateReadyText => L.F("UpdateReadyFormat", "Version {0} is ready.", UpdateVersion);
    public string BypassEntriesText => L.F("BypassEntriesFormat", "Direct bypass entries: {0}", BypassAddressCount);
    public bool HasUpdateAttention => IsUpdateVisible || ShowUpdateError || IsUpdateRequired;

    public bool IsProxyModeSelected => SelectedConnectionMode == MacConnectionMode.Proxy;
    public bool IsVpnModeSelected => SelectedConnectionMode == MacConnectionMode.Vpn;
    /// <summary>The local proxy / TUN connection state, for display (LocalConnectionStatus stays an internal code).</summary>
    public string LocalConnectionStatusText => LocalConnectionStatus switch
    {
        "Connected" => L.T("LocalStatusRunning", "Running"),
        "Starting" => L.T("LocalStatusStarting", "Starting"),
        "Connection error" => L.T("LocalStatusError", "Error"),
        _ => L.T("LocalStatusIdle", "Not running"),
    };

    /// <summary>Dashboard "Node route" card: running shows the mode, otherwise the same state words as Services.</summary>
    public string NodeRouteStateText => LocalConnectionStatus == "Connected" && SelectedConnectionMode is not null
        ? L.F("NodeRouteRunningFormat", "Running · {0}", LocalConnectionModeLabel)
        : LocalConnectionStatusText;

    /// <summary>Dashboard "Current service" card: the panel link, never the local node connection.</summary>
    public string ServiceStatusText => ConnectionStatus switch
    {
        "connected" or "refreshing" => L.T("ServiceOnline", "Panel online"),
        DesktopClientController.DeviceLimitReachedCode => L.T("StatusDeviceLimit", "Device limit reached"),
        "error" => L.T("ServicePanelError", "Panel connection error"),
        _ => L.T("ServiceOffline", "Panel offline"),
    };

    public string ServiceHeadline => string.IsNullOrWhiteSpace(ActiveClientName) ? L.T("NoClientSelected", "No client selected") : ActiveClientName;
    public string ServiceForwardSummary => ForwardCount == 0
        ? L.T("NoForwardsAssigned", "No forwards assigned")
        : L.F("ForwardsRunningFormat", "{0} of {1} forwards running", RunningForwardCount, ForwardCount);
    public string LocalRouteSummary => SelectedConnectionForward is { } route
        ? route.DisplayName
        : PairedConnectionRoutes.Count == 0 ? L.T("NoRoutePaired", "No route paired with a node") : L.T("NoRouteSelected", "No route selected");
    public string LocalNodeSummary => SelectedConnectionProfile?.Name is { Length: > 0 } name ? name : L.T("NoNodeSelected", "No node selected");

    public IReadOnlyList<ChoiceOption> LanguageOptions { get; } = [new(L.English), new(L.SimplifiedChinese)];

    [ObservableProperty, NotifyPropertyChangedFor(nameof(IsThemeSystem), nameof(IsThemeDay), nameof(IsThemeNight))]
    private string _themeMode = MacAppearance.System;

    public bool IsThemeSystem => ThemeMode == MacAppearance.System;
    public bool IsThemeDay => ThemeMode == MacAppearance.Day;
    public bool IsThemeNight => ThemeMode == MacAppearance.Night;
    public int PairedRouteCount => PairedConnectionRoutes.Count;

    private void InitializeShell(
        Func<IReadOnlyDictionary<string, TrafficCounter>>? trafficCounters,
        INodeLibrary? nodeLibrary,
        string themeMode,
        Func<string, CancellationToken, Task>? saveThemeMode)
    {
        _trafficCounters = trafficCounters;
        _saveThemeMode = saveThemeMode;
        ThemeMode = MacAppearance.Normalize(themeMode);
        _nodeLibrary = nodeLibrary ?? (_connections is not null ? new MacLocalNodeLibrary(_connections) : null);
        NodesPage = new NodesViewModel(
            () => _nodeLibrary,
            PairNodeFromNodesPageAsync,
            DeleteNodeFromNodesPageAsync,
            _dispatch,
            EditNodeInClassicEditor);
        if (_nodeLibrary is not null)
        {
            _nodeLibrary.Changed += () => _dispatch(OnNodeLibraryChanged);
            _nodeLibrary.PanelPairingsChanged += pairings => _dispatch(() => _ = ApplyPanelPairingsAsync(pairings));
        }
        PropertyChanged += (_, args) =>
        {
            switch (args.PropertyName)
            {
                case nameof(SelectedSection):
                    OnPropertyChanged(nameof(IsDashboardSelected));
                    OnPropertyChanged(nameof(IsServicesSelected));
                    OnPropertyChanged(nameof(IsNodesSelected));
                    OnPropertyChanged(nameof(IsSettingsSelected));
                    OnPropertyChanged(nameof(IsLogsSelected));
                    break;
                case nameof(IsPairingVisible):
                    OnPropertyChanged(nameof(IsNodesSectionVisible));
                    break;
                case nameof(IsLoginVisible) or nameof(IsClientPickerVisible):
                    OnPropertyChanged(nameof(IsShellVisible));
                    break;
                case nameof(RunningForwardCount) or nameof(LocalConnectionStatus) or nameof(IsConnectionStarting)
                    or nameof(IsLocalConnectionActive):
                    OnRunningStateChanged();
                    break;
                case nameof(SelectedConnectionMode):
                    OnPropertyChanged(nameof(IsProxyModeSelected));
                    OnPropertyChanged(nameof(IsVpnModeSelected));
                    OnPropertyChanged(nameof(NodeRouteStateText));
                    break;
                case nameof(ActiveClientName):
                    OnPropertyChanged(nameof(ServiceHeadline));
                    break;
                case nameof(ForwardCount):
                    OnPropertyChanged(nameof(ServiceForwardSummary));
                    break;
                case nameof(SelectedConnectionForward) or nameof(PairedConnectionRoutes):
                    OnPropertyChanged(nameof(LocalRouteSummary));
                    OnPropertyChanged(nameof(PairedRouteCount));
                    break;
                case nameof(SelectedConnectionProfile):
                    OnPropertyChanged(nameof(LocalNodeSummary));
                    break;
                case nameof(ConnectionStatus):
                    OnPropertyChanged(nameof(ServiceStatusText));
                    break;
                case nameof(BypassAddressCount):
                    OnPropertyChanged(nameof(BypassEntriesText));
                    break;
                case nameof(Language):
                    OnLanguageSelected();
                    break;
                case nameof(IsUpdateVisible) or nameof(ShowUpdateError) or nameof(IsUpdateInProgress)
                    or nameof(UpdatePhaseText) or nameof(UpdateVersion) or nameof(IsCheckingForUpdates)
                    or nameof(IsUpdateRequired):
                    OnPropertyChanged(nameof(UpdateStatusText));
                    OnPropertyChanged(nameof(UpdateReadyText));
                    OnPropertyChanged(nameof(HasUpdateAttention));
                    break;
            }
        };
        RefreshNodesPage();
        _shellReady = true;
    }

    /// <summary>Applies a newly picked language at once (DynamicResource views update live) and saves it.</summary>
    private void OnLanguageSelected()
    {
        if (!_shellReady)
            return;
        L.Apply(Language);
        RefreshLocalizedText();
        _ = PersistSelectedEndpointAsync(PanelUrl);
    }

    /// <summary>Re-reads every computed, localized text (after a language switch).</summary>
    public void RefreshLocalizedText()
    {
        ApplySavedNodeNames();
        RefreshNodeOptions();
        foreach (var row in Forwards)
            row.RefreshLocalizedText();
        NodesPage?.Refresh();
        OnPropertyChanged(string.Empty);
    }

    [RelayCommand]
    private async Task SelectThemeAsync(string? mode)
    {
        ThemeMode = MacAppearance.Normalize(mode);
        MacAppearance.Apply(ThemeMode);
        if (_saveThemeMode is null)
            return;
        try
        {
            await _saveThemeMode(ThemeMode, CancellationToken.None);
        }
        catch
        {
            AddLog("theme_save_failed");
        }
    }

    /// <summary>WireGuard / external nodes keep the classic editor on the Route pairing tab.</summary>
    private void EditNodeInClassicEditor(string key)
    {
        if (!MacLocalNodeLibrary.TryParseKey(key, out var id))
            return;
        ShowPairing();
        SelectedConnectionProfile = ConnectionProfiles.FirstOrDefault(profile => profile.Id == id);
        IsProxyEditorExpanded = true;
    }

    private void OnRunningStateChanged()
    {
        OnPropertyChanged(nameof(ServiceForwardSummary));
        OnPropertyChanged(nameof(LocalConnectionStatusText));
        OnPropertyChanged(nameof(NodeRouteStateText));
        var running = IsAnyRunning;
        if (running && _runningSince is null)
            _runningSince = _lastTick == DateTimeOffset.MinValue ? DateTimeOffset.UtcNow : _lastTick;
        else if (!running)
        {
            _runningSince = null;
            RunningTimeText = "00:00:00";
        }
        OnPropertyChanged(nameof(IsAnyRunning));
        OnPropertyChanged(nameof(RunToggleTip));
    }

    /// <summary>Advances the running timer and samples live traffic. Called by the view every second.</summary>
    public void Tick(DateTimeOffset now)
    {
        _lastTick = now;
        if (IsAnyRunning)
        {
            _runningSince ??= now;
            RunningTimeText = FormatElapsed(now - _runningSince.Value);
        }
        if (_trafficCounters is null)
            return;
        IReadOnlyDictionary<string, TrafficCounter> counters;
        try
        {
            counters = _trafficCounters();
        }
        catch (Exception exception)
        {
            Log.Debug("traffic_sample_failed type={ErrorType}", exception.GetType().Name);
            return;
        }
        _trafficMonitor.Sample(now, counters);
        DownloadRateText = TrafficMonitor.FormatRate(_trafficMonitor.DownloadRate);
        UploadRateText = TrafficMonitor.FormatRate(_trafficMonitor.UploadRate);
        SessionDownloadBytes = _trafficMonitor.SessionDownload;
        SessionUploadBytes = _trafficMonitor.SessionUpload;
        SessionDownloadText = TrafficMonitor.FormatBytes(_trafficMonitor.SessionDownload);
        SessionUploadText = TrafficMonitor.FormatBytes(_trafficMonitor.SessionUpload);
        DownloadHistory = _trafficMonitor.DownloadHistory;
        UploadHistory = _trafficMonitor.UploadHistory;
    }

    public static string FormatElapsed(TimeSpan elapsed)
    {
        if (elapsed < TimeSpan.Zero)
            elapsed = TimeSpan.Zero;
        var hours = (int)Math.Min(999, Math.Floor(elapsed.TotalHours));
        return $"{hours:00}:{elapsed.Minutes:00}:{elapsed.Seconds:00}";
    }

    [RelayCommand]
    private Task ToggleRunningAsync() => IsAnyRunning ? StopAllAsync() : StartAllAsync();

    [RelayCommand]
    private void ShowNodes()
    {
        SelectedSection = "nodes";
        IsPairingVisible = false;
        IsDashboardVisible = false;
        IsLogsVisible = false;
        IsSettingsVisible = false;
        IsConnectionsVisible = false;
        IsNodesVisible = !IsLoginVisible && !IsClientPickerVisible;
        RefreshNodesPage();
    }

    [RelayCommand]
    private void SelectOutboundMode(MacConnectionMode mode)
    {
        if (IsLocalConnectionActive)
        {
            ShowNotice(L.T("OutboundModeSwitchBlocked", "Stop the local connection before switching between system proxy and TUN."));
            return;
        }
        SelectedConnectionMode = mode;
    }

    [RelayCommand]
    private async Task CheckForUpdatesFromSidebarAsync()
    {
        if (IsUpdateVisible)
        {
            await InstallUpdateAsync();
            return;
        }
        if (ShowUpdateError)
        {
            await RetryUpdateAsync();
            return;
        }
        IsCheckingForUpdates = true;
        try
        {
            await CheckForUpdatesAsync();
        }
        finally
        {
            IsCheckingForUpdates = false;
        }
    }

    private void RefreshNodesPage()
    {
        if (NodesPage is null)
            return;
        NodesPage.Refresh(Forwards
            .Where(row => !row.IsNativeOutboundRule)
            .Select(row => new NodeServiceOption(row.ForwardId, row.DisplayName))
            .ToList());
    }

    private void OnNodeLibraryChanged()
    {
        if (_connections is not null)
        {
            var preferred = SelectedConnectionProfile?.Id ?? 0;
            _connectionSelection = _connections.Selection;
            // Only rebuild when the list really changed: replacing the selected
            // profile reloads the node editor and would drop unsaved edits.
            if (!SameProfiles(ConnectionProfiles, _connections.Profiles))
                ReplaceConnectionProfiles(_connections.Profiles, preferred);
            ApplySavedNodeNames();
            OnPropertyChanged(nameof(PairedConnectionRoutes));
            OnPropertyChanged(nameof(CanStartLocalConnection));
        }
        RefreshNodesPage();
    }

    private static bool SameProfiles(IReadOnlyList<MacConnectionProfile> current, IReadOnlyList<MacConnectionProfile> next)
        => current.Count == next.Count
            && current.Zip(next).All(pair => pair.First.Id == pair.Second.Id
                && pair.First.Name == pair.Second.Name
                && pair.First.Protocol == pair.Second.Protocol
                && pair.First.Server == pair.Second.Server
                && pair.First.Port == pair.Second.Port
                && pair.First.HostedId == pair.Second.HostedId
                && pair.First.ShareLink == pair.Second.ShareLink
                && pair.First.Method == pair.Second.Method
                && pair.First.Password == pair.Second.Password);

    private async Task PairNodeFromNodesPageAsync(string key, int forwardId)
    {
        if (!MacLocalNodeLibrary.TryParseKey(key, out var profileId))
            throw new NodeLibraryException("node_not_found");
        if (forwardId > 0)
        {
            var row = Forwards.FirstOrDefault(item => item.ForwardId == forwardId)
                ?? throw new NodeLibraryException("hosted_node_forward_not_found");
            // Saves the pick on this Mac and, for a hosted node, on the panel.
            await PairRouteNodeAsync(row, profileId);
            _dispatch(() => row.SetSavedNode(ConnectionProfiles.FirstOrDefault(profile => profile.Id == profileId)));
            return;
        }
        foreach (var row in Forwards.Where(item => item.SelectedNode?.Id == profileId).ToList())
        {
            await PairRouteNodeAsync(row, 0);
            _dispatch(() => row.SetSavedNode(null));
        }
    }

    /// <summary>
    /// Shares a pairing with the client's other devices when it involves a hosted node.
    /// A local-only pick stays on this Mac (the panel cannot reference it).
    /// </summary>
    private async Task PushPairingToCloudAsync(int forwardId, int profileId, int previousProfileId)
    {
        if (_nodeLibrary is not { IsCloudAvailable: true } library || forwardId <= 0)
            return;
        bool IsHosted(int id) => id > 0 && ConnectionProfiles.FirstOrDefault(profile => profile.Id == id)?.IsHosted == true;
        try
        {
            if (IsHosted(profileId))
                await library.PairAsync(forwardId, profileId.ToString(System.Globalization.CultureInfo.InvariantCulture));
            else if (IsHosted(previousProfileId))
                await library.PairAsync(forwardId, null);
        }
        catch (NodeLibraryException exception)
        {
            _dispatch(() => ShowNotice(L.F("PairingShareFailedFormat",
                "The pairing is saved on this Mac but could not be shared: {0}", NodesViewModel.DescribeError(exception.Code))));
        }
    }

    /// <summary>Applies pairings changed on the panel (console or another device) as each forward's node.</summary>
    private async Task ApplyPanelPairingsAsync(IReadOnlyList<NodePairing> pairings)
    {
        foreach (var pairing in pairings)
        {
            var row = Forwards.FirstOrDefault(item => item.ForwardId == pairing.ForwardId);
            if (row is null || !MacLocalNodeLibrary.TryParseKey(pairing.Key, out var profileId))
                continue;
            var profile = ConnectionProfiles.FirstOrDefault(item => item.Id == profileId);
            if (profile is null)
                continue;
            await PairRouteNodeAsync(row, profileId, pushToCloud: false);
            _dispatch(() => row.SetSavedNode(profile));
        }
    }

    private async Task DeleteNodeFromNodesPageAsync(string key)
    {
        if (_nodeLibrary is null)
            return;
        if (MacLocalNodeLibrary.TryParseKey(key, out var profileId)
            && SelectedConnectionProfile?.Id == profileId
            && IsLocalConnectionActive)
            await StopConnectionAsync();
        await _nodeLibrary.DeleteAsync(key);
    }
}
