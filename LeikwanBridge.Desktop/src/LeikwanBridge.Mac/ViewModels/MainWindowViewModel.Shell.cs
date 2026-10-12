using CommunityToolkit.Mvvm.ComponentModel;
using CommunityToolkit.Mvvm.Input;
using LeikwanBridge.Mac.Services;
using Serilog;

namespace LeikwanBridge.Mac.ViewModels;

/// <summary>
/// Window shell for the FlClash-style layout: sidebar sections, dashboard cards,
/// the start / stop button with its running timer, live traffic and the Nodes page.
/// The view drives <see cref="Tick"/> once a second; nothing here owns a timer, so
/// the view model stays testable without a dispatcher.
/// </summary>
public sealed partial class MainWindowViewModel
{
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
    public string RunToggleTip => IsAnyRunning ? "Stop all forwards and the local connection" : "Start all forwards";

    public string VersionNumber => typeof(MainWindowViewModel).Assembly.GetName().Version?.ToString(3) ?? "1.0.0";
    public string UpdateStatusText => IsUpdateInProgress ? UpdatePhaseText
        : IsUpdateVisible ? $"Version {UpdateVersion} available"
        : ShowUpdateError ? "Update check failed"
        : IsCheckingForUpdates ? "Checking for updates…"
        : "Check for updates";
    public bool HasUpdateAttention => IsUpdateVisible || ShowUpdateError;

    public bool IsProxyModeSelected => SelectedConnectionMode == MacConnectionMode.Proxy;
    public bool IsVpnModeSelected => SelectedConnectionMode == MacConnectionMode.Vpn;
    public string OutboundModeSummary => IsLocalConnectionActive
        ? $"{LocalConnectionModeLabel} · {LocalConnectionStatus}"
        : SelectedConnectionMode is null ? "Choose how this Mac sends traffic" : $"{LocalConnectionModeLabel} · idle";

    public string ServiceHeadline => string.IsNullOrWhiteSpace(ActiveClientName) ? "No client selected" : ActiveClientName;
    public string ServiceForwardSummary => ForwardCount == 0
        ? "No forwards assigned"
        : $"{RunningForwardCount} of {ForwardCount} forward{(ForwardCount == 1 ? string.Empty : "s")} running";
    public string LocalRouteSummary => SelectedConnectionForward is { } route
        ? route.DisplayName
        : PairedConnectionRoutes.Count == 0 ? "No route paired with a node" : "No route selected";
    public string LocalNodeSummary => SelectedConnectionProfile?.Name is { Length: > 0 } name ? name : "No node selected";
    public int PairedRouteCount => PairedConnectionRoutes.Count;

    private void InitializeShell(
        Func<IReadOnlyDictionary<string, TrafficCounter>>? trafficCounters,
        INodeLibrary? nodeLibrary)
    {
        _trafficCounters = trafficCounters;
        _nodeLibrary = nodeLibrary ?? (_connections is not null ? new MacLocalNodeLibrary(_connections) : null);
        NodesPage = new NodesViewModel(
            () => _nodeLibrary,
            PairNodeFromNodesPageAsync,
            DeleteNodeFromNodesPageAsync,
            _dispatch);
        if (_nodeLibrary is not null)
            _nodeLibrary.Changed += () => _dispatch(OnNodeLibraryChanged);
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
                    OnPropertyChanged(nameof(OutboundModeSummary));
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
                case nameof(IsUpdateVisible) or nameof(ShowUpdateError) or nameof(IsUpdateInProgress)
                    or nameof(UpdatePhaseText) or nameof(UpdateVersion) or nameof(IsCheckingForUpdates):
                    OnPropertyChanged(nameof(UpdateStatusText));
                    OnPropertyChanged(nameof(HasUpdateAttention));
                    break;
            }
        };
        RefreshNodesPage();
    }

    private void OnRunningStateChanged()
    {
        OnPropertyChanged(nameof(ServiceForwardSummary));
        OnPropertyChanged(nameof(OutboundModeSummary));
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
            ShowNotice("Stop the local connection before switching between system proxy and TUN.");
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
            ReplaceConnectionProfiles(_connections.Profiles, preferred);
            ApplySavedNodeNames();
            OnPropertyChanged(nameof(PairedConnectionRoutes));
            OnPropertyChanged(nameof(CanStartLocalConnection));
        }
        RefreshNodesPage();
    }

    private async Task PairNodeFromNodesPageAsync(string key, int forwardId)
    {
        if (!MacLocalNodeLibrary.TryParseKey(key, out var profileId))
            throw new NodeLibraryException("node_not_found");
        if (forwardId > 0)
        {
            var row = Forwards.FirstOrDefault(item => item.ForwardId == forwardId)
                ?? throw new NodeLibraryException("hosted_node_forward_not_found");
            await PairRouteNodeAsync(row, profileId);
            _dispatch(() => row.SetSavedNode(ConnectionProfiles.FirstOrDefault(profile => profile.Id == profileId)));
            if (_nodeLibrary is { IsCloudAvailable: true } library)
                await library.PairAsync(forwardId, key);
            return;
        }
        foreach (var row in Forwards.Where(item => item.SelectedNode?.Id == profileId).ToList())
        {
            await PairRouteNodeAsync(row, 0);
            _dispatch(() => row.SetSavedNode(null));
            if (_nodeLibrary is { IsCloudAvailable: true } library)
                await library.PairAsync(row.ForwardId, null);
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
