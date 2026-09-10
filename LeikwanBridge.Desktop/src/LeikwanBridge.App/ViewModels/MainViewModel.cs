using System.Collections.ObjectModel;
using System.IO;
using System.Diagnostics;
using System.Text.RegularExpressions;
using System.Windows;
using CommunityToolkit.Mvvm.ComponentModel;
using CommunityToolkit.Mvvm.Input;
using LeikwanBridge.App.Services;
using LeikwanBridge.Core.Helpers;
using LeikwanBridge.Core.Models;
using LeikwanBridge.Core.Services;
using Serilog;
using System.Windows.Threading;

namespace LeikwanBridge.App.ViewModels;

public partial class MainViewModel : ObservableObject
{
    private static readonly TimeSpan ShutdownWaitTimeout = TimeSpan.FromSeconds(12);
    private AgentConfig _config;
    private MasterApiClient? _api;
    private StateManager? _stateManager;
    private ProcessSupervisor? _supervisor;
    private ForwardRuntimeManager? _runtimeManager;
    private ConfigPollingService? _pollingService;
    private HeartbeatService? _heartbeatService;
    private HealthCheckService? _healthCheckService;
    private ClientDiagnosticsService? _diagnostics;
    private WireGuardManager? _wgManager;
    private InstalledRuntimeCleanupService? _installedRuntimeCleanup;
    private WindowsProxyConnectionService? _proxyService;
    private WindowsProxyGameProfileStore? _proxyGameStore;
    private readonly List<WindowsProxyGameProfile> _proxyGameProfiles = [];
    private bool _proxyServiceInitialized;
    private bool _proxyGameStoreInitialized;
    private WindowsClientAppArtifact? _availableUpdateArtifact;
    private DispatcherTimer? _usageTimer;
    private readonly RuntimeUsageReader _usageReader = new();
    private readonly CancellationTokenSource _lifecycleCts = new();
    private readonly object _shutdownGate = new();
    private readonly NativeLeaseRenewalLoop _nativeLeaseRenewalLoop = new();
    private Task? _shutdownTask;
    private Task? _heartbeatTask;
    private CancellationTokenSource? _nativeLeaseRenewalCts;
    private Task? _nativeLeaseRenewalTask;
    private Action<ClientMeta>? _clientUpdatedHandler;
    private int _nativeRefreshInProgress;
    private int _nativeRefreshPending;
    private WindowsProxyMode _nativeActiveMode = WindowsProxyMode.Tun;
    private int _nativeActiveForwardId;
    private string _nativeActiveRouteScope = "global";
    private bool _nativeActiveUnrestricted;
    private string _nativeActiveGameId = "";
    private bool _nativeActiveBypassChina;
    private int _shutdownRequested;

    [ObservableProperty] private bool _isConnected;
    [ObservableProperty] private string _connectionStatus = "Disconnected";
    [ObservableProperty] private string _lastSyncTime = "Never";
    [ObservableProperty] private int _activeForwardCount;
    [ObservableProperty] private bool _isLoadingForwards = true;
    [ObservableProperty] private string _totalBytesIn = "0 B";
    [ObservableProperty] private string _totalBytesOut = "0 B";
    [ObservableProperty] private string _currentView = "Dashboard";
    [ObservableProperty] private bool _showSetupWizard = true;
    [ObservableProperty] private bool _showClientPicker;
    [ObservableProperty] private int _pendingClientSelectionId;
    [ObservableProperty] private string _signedInUsername = "";
    [ObservableProperty] private string _signedInEmail = "";
    [ObservableProperty] private bool _hasSignedInEmail;
    [ObservableProperty] private string _activeClientName = "";
    [ObservableProperty] private bool _showUpdatePrompt;
    [ObservableProperty] private string _availableUpdateVersion = "";
    [ObservableProperty] private ForwardViewModel? _selectedForward;
    [ObservableProperty] private WindowsProxySnapshot _proxySnapshot = WindowsProxySnapshot.Disconnected;
    [ObservableProperty] private string _selectedProxyGameId = "";
    [ObservableProperty] private NativeOutboundCapability? _nativeOutboundCapability;
    [ObservableProperty] private int _selectedNativeForwardId;
    [ObservableProperty] private string _selectedNativeRouteScope = "global";
    [ObservableProperty] private bool _nativeBypassChinaMainland;
    [ObservableProperty] private bool _nativeUnrestrictedRoute;
    [ObservableProperty] private bool _isNativeOutboundExpanded;
    [ObservableProperty] private bool _isProxyModeSelected;
    private string _selectedProxyGameDisplayName = "";
    private bool _refreshingProxyRows;

    public ObservableCollection<ForwardViewModel> Forwards { get; } = [];
    public ObservableCollection<ProxyForwardRowViewModel> ProxyForwardRows { get; } = [];
    public IReadOnlyList<ProxyForwardRowViewModel> PairedProxyForwardRows
        => ProxyForwardRows.Where(row => !row.IsUnbound).ToList();
    public ProxyForwardRowViewModel? SelectedPairedProxyForward
        => PairedProxyForwardRows.FirstOrDefault(row => row.ForwardId == SelectedProxyForwardId);
    public string SelectedProxyPairedForwardEndpoint
        => SelectedPairedProxyForward?.ListenerEndpoint ?? string.Empty;
    public string SelectedProxyPairedForwardNode
        => SelectedPairedProxyForward?.SelectedProfileName ?? UnboundProxyProfileText;
    public bool IsProxyReadyForSelectedPairedForward
        => IsProxyModeSelected && SelectedPairedProxyForward?.CanConnect == true;
    public bool IsProxyConnectedForSelectedPairedForward
        => SelectedPairedProxyForward?.IsActive == true;
    public ObservableCollection<ConnectionQualityForwardViewModel> ConnectionQualityForwards { get; } = [];
    public ObservableCollection<DesktopClientChoice> AvailableClientChoices { get; } = [];
    public ObservableCollection<string> LogEntries { get; } = [];
    public ObservableCollection<WindowsProxyProfile> ProxyProfiles { get; } = [];
    public ObservableCollection<WindowsProxyGameOption> ProxyGameOptions { get; } = [];
    public WindowsProxyEditorViewModel ProxyEditor { get; } = new();

    public string SelectedProxyGameDisplayName => _selectedProxyGameDisplayName;
    public bool IsForwardPairingView => string.Equals(CurrentView, "Forwards", StringComparison.OrdinalIgnoreCase);

    partial void OnCurrentViewChanged(string value)
        => OnPropertyChanged(nameof(IsForwardPairingView));

    partial void OnIsProxyModeSelectedChanged(bool value)
        => NotifySelectedProxyPairingStateChanged();

    private void NotifySelectedProxyPairingStateChanged()
    {
        OnPropertyChanged(nameof(SelectedPairedProxyForward));
        OnPropertyChanged(nameof(SelectedProxyPairedForwardEndpoint));
        OnPropertyChanged(nameof(SelectedProxyPairedForwardNode));
        OnPropertyChanged(nameof(IsProxyReadyForSelectedPairedForward));
        OnPropertyChanged(nameof(IsProxyConnectedForSelectedPairedForward));
    }

    public bool IsProxyConnected => ProxySnapshot.State == WindowsProxyConnectionState.Connected;
    public bool IsProxyStarting => ProxySnapshot.State == WindowsProxyConnectionState.Starting;
    public bool IsProxyError => ProxySnapshot.State == WindowsProxyConnectionState.Error;
    public bool IsNativeOutboundVisible
        => NativeOutboundCapability is { Enabled: true } && NativeOutboundForwards.Count > 0;
    public IReadOnlyList<NativeOutboundForward> NativeOutboundForwards
        => NativeOutboundCapability?.Forwards ?? [];
    public IReadOnlyList<string> NativeRouteScopeOptions
        => NativeOutboundCapability?.RouteScopes ?? [];
    public bool IsNativeRouteScopeSelectable
        => NativeOutboundCapability?.FindForward(SelectedNativeForwardId)?.RouteScopes
            .Any(IsNativeRestrictedScope) == true;
    public bool IsNativeRouteCnOnly
        => string.Equals(SelectedNativeRouteScope, "cn_only", StringComparison.OrdinalIgnoreCase);
    public bool IsNativeRouteIntlOnly
        => string.Equals(SelectedNativeRouteScope, "intl_only", StringComparison.OrdinalIgnoreCase);
    public bool IsNativeRouteGlobal
        => string.Equals(SelectedNativeRouteScope, "global", StringComparison.OrdinalIgnoreCase);
    public bool IsNativeBypassChinaEnabled
        => IsNativeRouteGlobal && IsNativeOutboundVisible;
    public bool IsNativeUnrestrictedRouteVisible
        => NativeOutboundCapability?.FindForward(SelectedNativeForwardId)?.UnrestrictedAllowed == true;
    public bool IsNativeUnrestrictedRouteEnabled
        => IsNativeUnrestrictedRouteVisible
            && IsNativeOutboundVisible
            && ProxySnapshot.State is not (WindowsProxyConnectionState.Connected or WindowsProxyConnectionState.Starting);

    private readonly HashSet<int> _busyForwards = [];
    public bool ShouldAutoConnectOnStartup => !ShowSetupWizard && !IsConnected;
    public bool IsShuttingDown => Volatile.Read(ref _shutdownRequested) != 0;

    public MainViewModel()
    {
        _config = new AgentConfig();
    }

    partial void OnSelectedProxyGameIdChanged(string value)
    {
        _config.ProxyActiveGameId = value?.Trim() ?? string.Empty;
        UpdateSelectedProxyGameDisplayName();
    }

    partial void OnNativeOutboundCapabilityChanged(NativeOutboundCapability? value)
    {
        if (value is not { Enabled: true })
            IsNativeOutboundExpanded = false;
        var preferredId = value?.FindForward(SelectedNativeForwardId)?.ForwardId
            ?? value?.DefaultForwardId
            ?? value?.Forwards.FirstOrDefault()?.ForwardId
            ?? 0;
        if (SelectedNativeForwardId != preferredId)
            SelectedNativeForwardId = preferredId;

        var carrier = value?.FindForward(preferredId);
        var preferredScope = carrier?.RouteScopes
            .FirstOrDefault(scope => string.Equals(scope, SelectedNativeRouteScope, StringComparison.OrdinalIgnoreCase))
            ?? carrier?.DefaultRouteScope
            ?? value?.DefaultRouteScope
            ?? "global";
        if (!string.Equals(SelectedNativeRouteScope, preferredScope, StringComparison.OrdinalIgnoreCase))
            SelectedNativeRouteScope = preferredScope;
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
        if (carrier is not null
            && !carrier.RouteScopes.Contains(SelectedNativeRouteScope, StringComparer.OrdinalIgnoreCase))
            SelectedNativeRouteScope = carrier.DefaultRouteScope;
        OnPropertyChanged(nameof(IsNativeRouteCnOnly));
        OnPropertyChanged(nameof(IsNativeRouteIntlOnly));
        OnPropertyChanged(nameof(IsNativeRouteGlobal));
        OnPropertyChanged(nameof(IsNativeRouteScopeSelectable));
        OnPropertyChanged(nameof(IsNativeBypassChinaEnabled));
        if (!IsNativeUnrestrictedRouteVisible && NativeUnrestrictedRoute)
            NativeUnrestrictedRoute = false;
        OnPropertyChanged(nameof(IsNativeUnrestrictedRouteVisible));
        OnPropertyChanged(nameof(IsNativeUnrestrictedRouteEnabled));
    }

    partial void OnSelectedNativeRouteScopeChanged(string value)
    {
        var normalized = NormalizeNativeScope(value);
        if (!string.Equals(value, normalized, StringComparison.Ordinal))
        {
            SelectedNativeRouteScope = normalized;
            return;
        }
        if (normalized != "global" && NativeBypassChinaMainland)
            NativeBypassChinaMainland = false;
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
        OnPropertyChanged(nameof(IsNativeUnrestrictedRouteEnabled));
    }

    /// <summary>
    /// Initializes local proxy state without requiring a successful panel
    /// login.  The service is lazy so unit tests and the setup wizard do not
    /// touch ProgramData until the window is actually loaded.
    /// </summary>
    public async Task InitializeProxyAsync(CancellationToken ct = default)
    {
        ThrowIfShuttingDown();
        if (_proxyService is null)
        {
            var stateDir = _config.GetEffectiveStateDir();
            _proxyGameStore = new WindowsProxyGameProfileStore(Path.Combine(stateDir, "proxy-games.json"));
            _proxyService = new WindowsProxyConnectionService(
                new WindowsProxyProfileStore(Path.Combine(stateDir, "proxy-profiles.json")),
                _config.GetEffectiveProxySingBoxBin(),
                Path.Combine(stateDir, "proxy-runtime"),
                processAdapter: new WindowsNetchProcessProxyAdapter(
                    _config.GetEffectiveProxyRedirectorBin(),
                    _config.GetEffectiveProxyNetfilterDriver()),
                proxyPort: _config.GetEffectiveProxyPort(),
                tunMtu: _config.GetEffectiveProxyTunMtu(),
                tunStack: _config.GetEffectiveProxyTunStack(),
                chinaIpListPath: _config.GetEffectiveProxyChinaIpList());
            _proxyService.SnapshotChanged += HandleProxySnapshotChanged;
        }

        if (_proxyServiceInitialized)
        {
            RefreshProxyGameOptions();
            return;
        }

        await _proxyService.InitializeAsync(ct);
        ThrowIfShuttingDown();
        if (_proxyGameStore is not null)
        {
            _proxyGameProfiles.Clear();
            _proxyGameProfiles.AddRange(await _proxyGameStore.LoadAsync(ct));
            await ImportDiscoveredNetchModesAsync(_proxyGameStore, ct);
            ThrowIfShuttingDown();
            _proxyGameStoreInitialized = true;
        }
        ThrowIfShuttingDown();
        _proxyServiceInitialized = true;
        RefreshProxyProfiles();
        RefreshProxyGameOptions();
    }

    public void SetProxyPort(int proxyPort)
    {
        if (proxyPort is < 1 or > 65535)
            throw new InvalidOperationException("proxy_port_invalid");
        _proxyService?.SetProxyPort(proxyPort);
        _config.ProxyPort = proxyPort;
    }

    public void RefreshProxyProfiles()
    {
        ProxyProfiles.Clear();
        if (_proxyService is not null)
        {
            foreach (var profile in _proxyService.Profiles)
                ProxyProfiles.Add(profile);
        }

        RestoreSavedProxySelection();
        if (!_refreshingProxyRows)
            RefreshProxyForwardRows();
        OnPropertyChanged(nameof(PairedProxyForwardOptions));
    }

    public string UnboundProxyProfileText =>
        System.Globalization.CultureInfo.CurrentUICulture.Name.StartsWith("zh", StringComparison.OrdinalIgnoreCase)
            ? "未选择已保存节点" : "No saved node selected";

    public int GetProxyProfileIdForForward(int forwardId)
        => _config.ProxyProfileIdsByForwardId.TryGetValue(forwardId, out var profileId)
            && ProxyProfiles.Any(profile => profile.Id == profileId)
            ? profileId : 0;

    public void SetProxyProfileForForward(int forwardId, int profileId)
    {
        if (forwardId <= 0)
            return;
        if (profileId > 0 && ProxyProfiles.Any(profile => profile.Id == profileId))
            _config.ProxyProfileIdsByForwardId[forwardId] = profileId;
        else
            _config.ProxyProfileIdsByForwardId.Remove(forwardId);

        if (SelectedProxyForwardId == forwardId)
            SelectedProxyProfileId = profileId;
        OnPropertyChanged(nameof(PairedProxyForwardRows));
        OnPropertyChanged(nameof(PairedProxyForwardOptions));
        NotifySelectedProxyPairingStateChanged();
        if (!_refreshingProxyRows)
            RefreshProxyForwardRows();
    }

    public bool IsProxySessionForForward(int forwardId)
        => _proxyForwardLease?.Forward.ForwardId == forwardId
            || (_proxyPendingForward?.ForwardId == forwardId && IsProxyStarting);

    public async Task StartProxyForForwardAsync(int forwardId, int profileId, CancellationToken ct = default)
    {
        if (!ProxyForwardOptions.Any(item => item.ForwardId == forwardId))
            throw new InvalidOperationException("proxy_forward_required");
        SelectedProxyForwardId = forwardId;
        SetProxyProfileForForward(forwardId, profileId);
        await StartProxyAsync(WindowsProxyMode.Http, profileId, ct: ct);
        RefreshProxyForwardRows();
    }

    public async Task StopProxyForForwardAsync(int forwardId, CancellationToken ct = default)
    {
        if (!IsProxySessionForForward(forwardId))
            return;
        await StopProxyAsync(ct);
        RefreshProxyForwardRows();
    }

    private void RefreshProxyForwardRows()
    {
        if (_refreshingProxyRows)
            return;
        _refreshingProxyRows = true;
        try
        {
            var forwards = ProxyForwardOptions.GroupBy(item => item.ForwardId).Select(group => group.First()).ToList();
            for (var index = ProxyForwardRows.Count - 1; index >= 0; index--)
                if (!forwards.Any(forward => forward.ForwardId == ProxyForwardRows[index].ForwardId))
                    ProxyForwardRows.RemoveAt(index);
            for (var index = 0; index < forwards.Count; index++)
            {
                var forward = forwards[index];
                var row = ProxyForwardRows.FirstOrDefault(item => item.ForwardId == forward.ForwardId);
                if (row is null)
                {
                    row = new ProxyForwardRowViewModel(this, forward);
                    ProxyForwardRows.Insert(index, row);
                }
                else
                {
                    var oldIndex = ProxyForwardRows.IndexOf(row);
                    if (oldIndex != index)
                        ProxyForwardRows.Move(oldIndex, index);
                    row.UpdateForward(forward);
                    row.Refresh();
                }
            }
        }
        finally { _refreshingProxyRows = false; }
        OnPropertyChanged(nameof(PairedProxyForwardRows));
        NotifySelectedProxyPairingStateChanged();
    }

    public void RefreshProxyGameOptions()
    {
        var selectedId = SelectedProxyGameId;
        ProxyGameOptions.Clear();
        var runningNames = new HashSet<string>(StringComparer.OrdinalIgnoreCase);
        try
        {
            foreach (var process in Process.GetProcesses())
            {
                try { runningNames.Add(process.ProcessName + ".exe"); }
                catch { }
                finally { process.Dispose(); }
            }
        }
        catch (Exception exception)
        {
            Log.Debug(exception, "failed to enumerate running games");
        }

        foreach (var option in WindowsProxyGameCatalog.BuildOptions(runningNames, _proxyGameProfiles))
            ProxyGameOptions.Add(option);

        // Rebuilding the collection temporarily clears a WPF ComboBox
        // selection. Keep the source value and restore the display name after
        // the new option objects have been created.
        if (!string.IsNullOrWhiteSpace(selectedId)
            && !string.Equals(SelectedProxyGameId, selectedId, StringComparison.Ordinal))
        {
            SelectedProxyGameId = selectedId;
        }
        UpdateSelectedProxyGameDisplayName();
    }

    public async Task<WindowsProxyGameProfile> AddProxyGameAsync(
        string executablePath,
        string? displayName = null,
        CancellationToken ct = default)
    {
        return await AddProxyGameFilesAsync([executablePath], displayName, ct);
    }

    public async Task<WindowsProxyGameProfile> AddProxyGameFolderAsync(
        string folderPath,
        string? displayName = null,
        CancellationToken ct = default)
    {
        var path = folderPath?.Trim() ?? string.Empty;
        if (path.Length == 0 || !Path.IsPathFullyQualified(path))
            throw new InvalidOperationException("proxy_game_folder_required");
        if (!Directory.Exists(path))
            throw new DirectoryNotFoundException("proxy_game_folder_missing");

        IReadOnlyList<string> executables;
        try
        {
            executables = Directory.EnumerateFiles(
                    Path.GetFullPath(path),
                    "*.exe",
                    new EnumerationOptions
                    {
                        RecurseSubdirectories = true,
                        IgnoreInaccessible = true,
                        ReturnSpecialDirectories = false,
                    })
                .OrderBy(value => value, StringComparer.OrdinalIgnoreCase)
                .Take(64)
                .ToList();
        }
        catch (Exception exception) when (exception is IOException or UnauthorizedAccessException)
        {
            throw new InvalidOperationException("proxy_game_folder_unreadable", exception);
        }

        if (executables.Count == 0)
            throw new InvalidOperationException("proxy_game_folder_no_executables");

        var fallbackName = new DirectoryInfo(path).Name;
        return await AddProxyGameFilesAsync(executables, displayName, ct, fallbackName);
    }

    private async Task<WindowsProxyGameProfile> AddProxyGameFilesAsync(
        IEnumerable<string> executablePaths,
        string? displayName,
        CancellationToken ct,
        string? fallbackName = null)
    {
        await EnsureProxyGameStoreAsync(ct);
        var paths = NormalizeProxyGameExecutablePaths(executablePaths);
        var processNames = paths
            .Select(Path.GetFileName)
            .Where(value => !string.IsNullOrWhiteSpace(value))
            .Select(value => value!)
            .Distinct(StringComparer.OrdinalIgnoreCase)
            .Take(64)
            .ToList();
        if (processNames.Count == 0)
            throw new InvalidOperationException("proxy_game_executable_invalid");

        var identity = string.Join("\n", paths.Select(value => value.ToLowerInvariant()));
        var id = "custom:" + Convert.ToHexString(
            System.Security.Cryptography.SHA256.HashData(System.Text.Encoding.UTF8.GetBytes(identity)))[..16].ToLowerInvariant();
        var profile = new WindowsProxyGameProfile
        {
            Id = id,
            DisplayName = string.IsNullOrWhiteSpace(displayName)
                ? (paths.Count == 1
                    ? Path.GetFileNameWithoutExtension(processNames[0]) ?? "Custom game"
                    : (string.IsNullOrWhiteSpace(fallbackName) ? "Custom game" : fallbackName.Trim()))
                : displayName.Trim(),
            ProcessPatterns = [.. processNames],
            PatternsAreRegex = false,
            Source = "custom",
        };
        UpsertProxyGameProfile(profile);
        await _proxyGameStore!.SaveAsync(_proxyGameProfiles, ct);
        RefreshProxyGameOptions();
        return profile;
    }

    private static IReadOnlyList<string> NormalizeProxyGameExecutablePaths(IEnumerable<string> executablePaths)
    {
        ArgumentNullException.ThrowIfNull(executablePaths);
        var result = new List<string>();
        foreach (var raw in executablePaths)
        {
            var path = raw?.Trim() ?? string.Empty;
            if (path.Length == 0 || !Path.IsPathFullyQualified(path))
                throw new InvalidOperationException("proxy_game_executable_required");
            var fullPath = Path.GetFullPath(path);
            if (!File.Exists(fullPath))
                throw new FileNotFoundException("proxy_game_executable_missing", fullPath);
            if (!string.Equals(Path.GetExtension(fullPath), ".exe", StringComparison.OrdinalIgnoreCase))
                throw new InvalidOperationException("proxy_game_executable_invalid");
            if (!result.Contains(fullPath, StringComparer.OrdinalIgnoreCase))
                result.Add(fullPath);
            if (result.Count >= 64)
                break;
        }
        return result;
    }

    private void UpdateSelectedProxyGameDisplayName()
    {
        var id = SelectedProxyGameId?.Trim() ?? string.Empty;
        var name = WindowsProxyGameCatalog.Find(id, _proxyGameProfiles)?.DisplayName
            ?? ProxyGameOptions.FirstOrDefault(option =>
                string.Equals(option.Id, id, StringComparison.OrdinalIgnoreCase))?.DisplayName
            ?? (id.Length == 0 ? string.Empty : id);
        if (string.Equals(_selectedProxyGameDisplayName, name, StringComparison.Ordinal))
            return;
        _selectedProxyGameDisplayName = name;
        OnPropertyChanged(nameof(SelectedProxyGameDisplayName));
    }

    public async Task<int> ImportNetchModesAsync(string modeDirectory, CancellationToken ct = default)
    {
        await EnsureProxyGameStoreAsync(ct);
        var imported = WindowsNetchModeImporter.Load(modeDirectory);
        ReplaceNetchGameProfiles(imported);
        await _proxyGameStore!.SaveAsync(_proxyGameProfiles, ct);
        _config.ProxyNetchModePath = modeDirectory;
        RefreshProxyGameOptions();
        return imported.Count;
    }

    private async Task EnsureProxyGameStoreAsync(CancellationToken ct)
    {
        if (_proxyGameStoreInitialized)
            return;
        await InitializeProxyAsync(ct);
    }

    private async Task ImportDiscoveredNetchModesAsync(
        WindowsProxyGameProfileStore store,
        CancellationToken ct)
    {
        var modeDirectory = _config.GetEffectiveProxyNetchModePath();
        if (!Directory.Exists(modeDirectory))
            return;

        try
        {
            var imported = WindowsNetchModeImporter.Load(modeDirectory);
            if (imported.Count == 0)
                return;
            ReplaceNetchGameProfiles(imported);
            await store.SaveAsync(_proxyGameProfiles, ct);
        }
        catch (Exception exception) when (exception is IOException or UnauthorizedAccessException or InvalidOperationException)
        {
            Log.Debug(exception, "automatic Netch mode import skipped");
        }
    }

    private void UpsertProxyGameProfile(WindowsProxyGameProfile profile)
    {
        var index = _proxyGameProfiles.FindIndex(item =>
            string.Equals(item.Id, profile.Id, StringComparison.OrdinalIgnoreCase));
        if (index >= 0)
            _proxyGameProfiles[index] = profile.Clone();
        else
            _proxyGameProfiles.Add(profile.Clone());
    }

    private void ReplaceNetchGameProfiles(IEnumerable<WindowsProxyGameProfile> imported)
    {
        _proxyGameProfiles.RemoveAll(profile =>
            string.Equals(profile.Source, "netch", StringComparison.OrdinalIgnoreCase));
        foreach (var profile in imported)
            UpsertProxyGameProfile(profile);
    }

    public async Task SaveProxyProfileAsync(CancellationToken ct = default)
    {
        await EnsureProxyServiceAsync(ct);
        var profile = ProxyEditor.ToProfile();
        if (string.IsNullOrWhiteSpace(profile.Name))
            profile.Name = $"{profile.Server}:{profile.Port}";
        await _proxyService!.SaveProfileAsync(profile, ct);
        _config.ProxyActiveProfileId = profile.Id;
        if (SelectedProxyForwardId > 0)
            _config.ProxyProfileIdsByForwardId[SelectedProxyForwardId] = profile.Id;
        RefreshProxyProfiles();
        ProxyEditor.Load(ProxyProfiles.FirstOrDefault(item => item.Id == profile.Id) ?? profile);
        AddLog($"Saved proxy profile {profile.Name}.");
    }

    public async Task DeleteProxyProfileAsync(int profileId, CancellationToken ct = default)
    {
        if (profileId <= 0)
            return;
        await EnsureProxyServiceAsync(ct);
        if (_proxyForwardProfileId == profileId)
            await StopProxyAsync(ct);
        await _proxyService!.DeleteProfileAsync(profileId, ct);
        foreach (var forwardId in _config.ProxyProfileIdsByForwardId
            .Where(pair => pair.Value == profileId).Select(pair => pair.Key).ToList())
            _config.ProxyProfileIdsByForwardId.Remove(forwardId);
        if (_config.ProxyActiveProfileId == profileId)
            _config.ProxyActiveProfileId = 0;
        RefreshProxyProfiles();
    }

    public async Task StartProxyAsync(
        WindowsProxyMode mode,
        int profileId,
        string? selectedGameId = null,
        CancellationToken ct = default)
    {
        if (profileId <= 0)
            throw new InvalidOperationException("proxy_saved_profile_required");
        await EnsureProxyServiceAsync(ct);
        var profile = _proxyService!.Profiles.FirstOrDefault(item => item.Id == profileId)
            ?? throw new InvalidOperationException("proxy_saved_profile_required");
        var forward = ProxyForwardOptions.FirstOrDefault(item => item.ForwardId == SelectedProxyForwardId)?.Config
            ?? throw new InvalidOperationException("proxy_forward_required");
        var transport = forward.Transport.Trim().ToLowerInvariant();
        if (transport is not ("tcp" or "udp" or "tcp+udp"))
            throw new InvalidOperationException("proxy_forward_transport_invalid");
        if (!profile.IsExternal && transport == "udp")
            throw new InvalidOperationException("proxy_forward_tcp_required");
        selectedGameId = string.IsNullOrWhiteSpace(selectedGameId)
            ? (string.IsNullOrWhiteSpace(SelectedProxyGameId)
                ? _config.ProxyActiveGameId
                : SelectedProxyGameId)
            : selectedGameId;
        var game = ResolveProxyGame(selectedGameId);
        if (!profile.IsExternal && mode == WindowsProxyMode.Process && game is null)
            throw new InvalidOperationException("process_game_required");

        var rules = (game?.ProcessNames ?? [])
            .Distinct(StringComparer.OrdinalIgnoreCase)
            .Take(64)
            .Select(name => new WindowsProcessProxyRule(
                game?.PatternsAreRegex == true ? name : BuildProcessRule(name)))
            .ToList();
        var dnsMode = _config.GetEffectiveProxyDnsMode();
        await RunProxyThroughForwardAsync(mode, profile, forward, (listenPort, startupToken) => _proxyService!.StartAsync(
            mode,
            profileId,
            BuildProxyBypassHosts(),
            rules,
            startupToken,
            _config.ProxyBypassChinaMainland,
            dnsMode,
            dnsMode == WindowsProxyDnsMode.Custom
                ? _config.GetEffectiveProxyDnsServers()
                : null,
            forwardListenPort: listenPort), ct);
        _config.ProxyActiveProfileId = profileId;
        _config.ProxyActiveMode = mode.ToString();
        SelectedProxyGameId = !profile.IsExternal && mode == WindowsProxyMode.Process ? game!.Id : string.Empty;
    }

    public async Task StartNativeOutboundAsync(
        WindowsProxyMode mode,
        int forwardId,
        string routeScope,
        string? selectedGameId = null,
        bool? bypassChinaMainland = null,
        bool? unrestrictedRoute = null,
        CancellationToken ct = default)
    {
        await EnsureProxyServiceAsync(ct);
        var capability = NativeOutboundCapability;
        if (capability is not { Enabled: true })
            throw new InvalidOperationException("native_outbound_not_allowed");
        var forward = _pollingService?.LatestForwards
            .FirstOrDefault(item => item.ForwardId == forwardId)
            ?? Forwards.FirstOrDefault(item => item.ForwardId == forwardId)?.Config;
        if (forward is null)
            throw new KeyNotFoundException("native_forward_not_found");

        var carrier = capability.FindForward(forwardId)
            ?? throw new InvalidOperationException("native_forward_not_allowed");
        var normalizedScope = TryNormalizeNativeScope(routeScope)
            ?? throw new InvalidOperationException("native_route_scope_invalid");
        if (!carrier.RouteScopes.Contains(normalizedScope, StringComparer.OrdinalIgnoreCase))
            throw new InvalidOperationException("native_route_scope_invalid");
        var useUnrestrictedRoute = unrestrictedRoute ?? NativeUnrestrictedRoute;
        if (useUnrestrictedRoute && !carrier.UnrestrictedAllowed)
            throw new InvalidOperationException("native_route_policy_not_allowed");
        forward.NativeOutbound = capability;
        forward.NativeRouteScope = normalizedScope;

        selectedGameId = string.IsNullOrWhiteSpace(selectedGameId)
            ? (string.IsNullOrWhiteSpace(SelectedProxyGameId)
                ? _config.ProxyActiveGameId
                : SelectedProxyGameId)
            : selectedGameId;
        var game = ResolveProxyGame(selectedGameId);
        if (mode == WindowsProxyMode.Process && game is null)
            throw new InvalidOperationException("process_game_required");
        if (_proxyForwardLease is not null || _proxyForwardStartCts is not null)
            await StopProxyAsync(ct);
        var rules = (game?.ProcessNames ?? [])
            .Distinct(StringComparer.OrdinalIgnoreCase)
            .Take(64)
            .Select(name => new WindowsProcessProxyRule(
                game?.PatternsAreRegex == true ? name : BuildProcessRule(name)))
            .ToList();

        var api = _api ?? throw new InvalidOperationException("master_api_unavailable");
        var routePolicy = useUnrestrictedRoute ? "unrestricted" : "restricted";
        var leases = await api.ResolveFreshNativeOutboundLeasesAsync(
            forward.ForwardId,
            normalizedScope,
            routePolicy,
            ct);
        if (leases.Count == 0)
            throw new InvalidOperationException("native_lease_unavailable");

        var effectiveBypassChina = normalizedScope == "global"
            && (bypassChinaMainland ?? NativeBypassChinaMainland);
        _nativeActiveMode = mode;
        _nativeActiveForwardId = forwardId;
        _nativeActiveRouteScope = normalizedScope;
        _nativeActiveUnrestricted = useUnrestrictedRoute;
        _nativeActiveGameId = mode == WindowsProxyMode.Process ? game!.Id : string.Empty;
        _nativeActiveBypassChina = effectiveBypassChina;
        NativeBypassChinaMainland = effectiveBypassChina;
        var dnsMode = _config.GetEffectiveProxyDnsMode();
        await _proxyService!.StartNativeAsync(
            mode,
            leases,
            BuildProxyBypassHosts(),
            rules,
            ct,
            effectiveBypassChina,
            dnsMode,
            dnsMode == WindowsProxyDnsMode.Custom
                ? _config.GetEffectiveProxyDnsServers()
                : null);
        StartNativeLeaseRenewalLoop();
        AddLog($"Started {WindowsProxyConnectionService.NativeOutboundDisplayName} ({normalizedScope}).");
    }

    private WindowsProxyGameDefinition? ResolveProxyGame(string? selectedGameId)
    {
        var game = WindowsProxyGameCatalog.Find(selectedGameId, _proxyGameProfiles);
        if (game is not null)
            return game;

        var option = ProxyGameOptions.FirstOrDefault(item =>
            string.Equals(item.Id, selectedGameId, StringComparison.OrdinalIgnoreCase)
            || string.Equals(item.DisplayName, selectedGameId, StringComparison.OrdinalIgnoreCase));
        return option is null
            ? null
            : WindowsProxyGameCatalog.Find(option.Id, _proxyGameProfiles);
    }

    private static string NormalizeNativeScope(string? value)
        => TryNormalizeNativeScope(value) ?? "global";

    private static string? TryNormalizeNativeScope(string? value)
    {
        var normalized = value?.Trim().ToLowerInvariant() ?? "global";
        return normalized switch
        {
            "international_only" or "overseas_only" => "intl_only",
            "mainland_only" or "china_only" => "cn_only",
            "cn_only" or "intl_only" or "global" => normalized,
            _ => null,
        };
    }

    private static bool IsNativeRestrictedScope(string scope)
        => string.Equals(scope, "cn_only", StringComparison.OrdinalIgnoreCase)
            || string.Equals(scope, "intl_only", StringComparison.OrdinalIgnoreCase);

    private static void DisposeNativeLeases(IEnumerable<NativeOutboundLease> leases)
    {
        foreach (var lease in leases)
            lease.Dispose();
    }

    public Task StopProxyAsync(CancellationToken ct = default)
        => StopProxyAsync("user_request", ct);

    private async Task StopProxyAsync(string reason, CancellationToken ct)
    {
        CancelProxyForwardStart();
        await _proxyForwardGate.WaitAsync(ct);
        try
        {
            _ = CancelNativeLeaseRenewalLoop();
            if (_proxyService is null)
            {
                await ReleaseProxyForwardAsync();
                return;
            }
            Log.Information(
                "stopping Windows proxy (reason={Reason}, native={Native}, forward={ForwardId})",
                reason,
                _proxyService.IsNativeOutboundActive,
                _nativeActiveForwardId);
            try { await _proxyService.StopAsync(ct); }
            finally { await ReleaseProxyForwardAsync(); }
            HandleProxySnapshotChanged(WindowsProxySnapshot.Disconnected);
            _nativeActiveForwardId = 0;
            _nativeActiveRouteScope = "global";
            _nativeActiveUnrestricted = false;
            NativeUnrestrictedRoute = false;
            _nativeActiveGameId = "";
            _nativeActiveBypassChina = false;
        }
        finally { _proxyForwardGate.Release(); }
    }

    public async Task<WindowsSocks5UdpProbeResult> TestProxyUdpAsync(CancellationToken ct = default)
    {
        if (_proxyService is null)
            return WindowsSocks5UdpProbeResult.Failed("proxy_not_initialized");
        var result = await _proxyService.TestUdpAsync(ct);
        AddLog(result.Succeeded ? "Proxy UDP probe succeeded." : $"Proxy UDP probe failed: {result.ErrorCode}");
        return result;
    }

    public void StopProxyForShutdown()
    {
        try
        {
            // Shutdown callers run on the WPF dispatcher.  The proxy stop
            // path includes native/SCM awaits; run the async cleanup on the
            // thread pool so its continuations cannot wait on a blocked UI
            // thread.
            Task.Run(() => StopProxyAsync("application_disconnect_or_shutdown", CancellationToken.None))
                .GetAwaiter()
                .GetResult();
        }
        catch (Exception exception) { Log.Warning(exception, "failed to stop Windows proxy during shutdown"); }
    }

    /// <summary>
    /// Signals shutdown synchronously so any in-flight connect/start operation
    /// cannot create new runtime processes while the window is closing.
    /// Actual cleanup is started by <see cref="ShutdownForExitAsync"/>.
    /// </summary>
    public void RequestShutdown()
    {
        if (Interlocked.Exchange(ref _shutdownRequested, 1) != 0)
            return;

        try { _lifecycleCts.Cancel(); }
        catch (ObjectDisposedException) { }

        try { ConnectCommand.Cancel(); }
        catch (Exception exception) { Log.Debug(exception, "connect cancellation during shutdown was unavailable"); }
    }

    /// <summary>
    /// Stops all background work and managed child processes before the WPF
    /// application exits. The operation is idempotent and runs off the UI
    /// dispatcher so service continuations cannot deadlock window close.
    /// </summary>
    public Task ShutdownForExitAsync()
    {
        RequestShutdown();
        lock (_shutdownGate)
        {
            return _shutdownTask ??= Task.Run(ShutdownCoreAsync);
        }
    }

    private async Task ShutdownCoreAsync()
    {
        StopUsageRefreshSafely();
        try { await StopProxyAsync("application_shutdown", CancellationToken.None).ConfigureAwait(false); }
        catch (Exception exception) { Log.Warning(exception, "failed to stop Windows proxy before runtime shutdown"); }
        var nativeLeaseRenewalTask = CancelNativeLeaseRenewalLoop();
        await WaitForTaskAsync(nativeLeaseRenewalTask, "native lease renewal").ConfigureAwait(false);

        // ConnectAsync can still be in its panel handshake when the user
        // closes the window. Wait briefly for its cancellation to settle so
        // we capture every service it may have initialized.
        await WaitForTaskAsync(ConnectCommand.ExecutionTask, "connect").ConfigureAwait(false);

        var polling = Interlocked.Exchange(ref _pollingService, null);
        var health = Interlocked.Exchange(ref _healthCheckService, null);
        DetachServiceHandlers(polling, health);

        var serviceStops = new List<Task>(2);
        if (polling is not null)
            serviceStops.Add(StopPollingAsync(polling));
        if (health is not null)
            serviceStops.Add(StopHealthAsync(health));
        if (serviceStops.Count > 0)
            await Task.WhenAll(serviceStops).ConfigureAwait(false);
        if (health is not null)
        {
            try { health.Dispose(); }
            catch (Exception exception) { Log.Warning(exception, "health check disposal failed during shutdown"); }
        }

        Task? heartbeat;
        lock (_shutdownGate)
        {
            heartbeat = _heartbeatTask;
        }
        await WaitForTaskAsync(heartbeat, "heartbeat").ConfigureAwait(false);

        var runtime = Interlocked.Exchange(ref _runtimeManager, null);
        var supervisor = Interlocked.Exchange(ref _supervisor, null);
        try
        {
            runtime?.StopAll();
        }
        catch (Exception exception)
        {
            Log.Warning(exception, "failed to stop managed forwards during shutdown");
        }
        finally
        {
            try { runtime?.Dispose(); }
            catch (Exception exception) { Log.Warning(exception, "failed to dispose forward runtime during shutdown"); }
            if (runtime is null)
            {
                try { supervisor?.Dispose(); }
                catch (Exception exception) { Log.Warning(exception, "failed to dispose process supervisor during shutdown"); }
            }
        }

        var proxy = Interlocked.Exchange(ref _proxyService, null);
        if (proxy is not null)
        {
            proxy.SnapshotChanged -= HandleProxySnapshotChanged;
            try { await proxy.DisposeAsync().ConfigureAwait(false); }
            catch (Exception exception) { Log.Warning(exception, "failed to dispose Windows proxy service during shutdown"); }
        }

        var api = Interlocked.Exchange(ref _api, null);
        try { api?.Dispose(); }
        catch (Exception exception) { Log.Warning(exception, "failed to dispose master API during shutdown"); }

        _diagnostics = null;
        _heartbeatService = null;
        _wgManager = null;
        _installedRuntimeCleanup = null;
        _proxyGameStore = null;
        _proxyGameProfiles.Clear();
        _proxyGameStoreInitialized = false;
        _proxyServiceInitialized = false;
    }

    private void DetachServiceHandlers(ConfigPollingService? polling, HealthCheckService? health)
    {
        if (polling is not null)
        {
            polling.ForwardsUpdated -= OnForwardsUpdated;
            polling.NativeOutboundUpdated -= OnNativeOutboundUpdated;
            polling.PullStateChanged -= OnPullStateChanged;
            polling.SyncError -= OnSyncError;
            if (_clientUpdatedHandler is not null)
                polling.ClientUpdated -= _clientUpdatedHandler;
        }
        _clientUpdatedHandler = null;
        if (health is not null)
            health.HealthIssue -= OnHealthIssue;
    }

    private async Task StopPollingAsync(ConfigPollingService polling)
    {
        try { await polling.StopAsync().WaitAsync(ShutdownWaitTimeout).ConfigureAwait(false); }
        catch (TimeoutException) { Log.Warning("config polling did not stop before shutdown timeout"); }
        catch (Exception exception) { Log.Warning(exception, "config polling stop failed during shutdown"); }
    }

    private async Task StopHealthAsync(HealthCheckService health)
    {
        try { await health.StopAsync().WaitAsync(ShutdownWaitTimeout).ConfigureAwait(false); }
        catch (TimeoutException) { Log.Warning("health checks did not stop before shutdown timeout"); }
        catch (Exception exception) { Log.Warning(exception, "health check stop failed during shutdown"); }
    }

    private async Task WaitForTaskAsync(Task? task, string name)
    {
        if (task is null)
            return;
        try
        {
            await task.WaitAsync(ShutdownWaitTimeout).ConfigureAwait(false);
        }
        catch (TimeoutException)
        {
            Log.Warning("{TaskName} task did not finish before shutdown timeout", name);
        }
        catch (OperationCanceledException)
        {
        }
        catch (Exception exception)
        {
            Log.Debug(exception, "{TaskName} task failed while shutting down", name);
        }
    }

    private void StopUsageRefreshSafely()
    {
        try
        {
            var timer = _usageTimer;
            if (timer is null)
                return;
            if (timer.Dispatcher.CheckAccess())
                StopUsageRefresh();
            else
                timer.Dispatcher.BeginInvoke(new Action(StopUsageRefresh));
        }
        catch (Exception exception)
        {
            Log.Debug(exception, "usage timer cleanup during shutdown failed");
        }
    }

    private void ThrowIfShuttingDown()
    {
        if (IsShuttingDown)
            throw new OperationCanceledException("application_shutdown_requested");
    }

    public void DisposeProxyForShutdown()
    {
        try
        {
            Task.Run(async () =>
            {
                try { await StopProxyAsync("application_dispose", CancellationToken.None).ConfigureAwait(false); }
                finally
                {
                    if (_proxyService is not null)
                        await _proxyService.DisposeAsync().ConfigureAwait(false);
                }
            }).GetAwaiter().GetResult();
        }
        catch (Exception exception) { Log.Warning(exception, "failed to dispose Windows proxy service"); }
        finally { _proxyService = null; }
        _proxyGameStore = null;
        _proxyGameProfiles.Clear();
        _proxyGameStoreInitialized = false;
        _proxyServiceInitialized = false;
    }

    private async Task EnsureProxyServiceAsync(CancellationToken ct)
    {
        await InitializeProxyAsync(ct);
    }

    private void HandleProxySnapshotChanged(WindowsProxySnapshot snapshot)
    {
        if (_proxyPendingForward is not null && snapshot.State == WindowsProxyConnectionState.Connected)
            return;
        InvokeOnUiThread(() =>
        {
            ProxySnapshot = snapshot;
            RefreshProxyForwardRows();
            OnPropertyChanged(nameof(IsProxyConnected));
            OnPropertyChanged(nameof(IsProxyStarting));
            OnPropertyChanged(nameof(IsProxyError));
            NotifySelectedProxyPairingStateChanged();
            if (snapshot.State == WindowsProxyConnectionState.Error)
                AddLog($"Proxy error: {snapshot.ErrorCode}");
        });
    }

    private IEnumerable<string> BuildProxyBypassHosts()
    {
        var values = new HashSet<string>(StringComparer.OrdinalIgnoreCase);
        if (Uri.TryCreate(_config.MasterUrl, UriKind.Absolute, out var master)
            && !string.IsNullOrWhiteSpace(master.Host))
            values.Add(master.Host);

        foreach (var forward in Forwards.Select(item => item.Config).Where(item => item is not null).Cast<ForwardConfig>())
        {
            AddHostPortValue(forward.Target, values);
            foreach (var endpoint in forward.Endpoints)
            {
                AddHostPortValue(endpoint.Host, values);
                AddHostPortValue(endpoint.CdnHost, values);
            }
        }
        return values;
    }

    private static void AddHostPortValue(string? value, ISet<string> values)
    {
        if (string.IsNullOrWhiteSpace(value))
            return;
        var candidate = value.Trim();
        if (Uri.TryCreate(candidate.Contains("://", StringComparison.Ordinal) ? candidate : $"tcp://{candidate}", UriKind.Absolute, out var uri)
            && !string.IsNullOrWhiteSpace(uri.Host))
        {
            values.Add(uri.Host);
            return;
        }
        values.Add(candidate);
    }

    private static string BuildProcessRule(string processName)
    {
        var escaped = Regex.Escape(processName.Trim());
        return $"(^|\\\\){escaped}$";
    }

    public void Initialize(AgentConfig config)
    {
        _config = config;
        _config.MasterUrl = AppDefaults.NormalizeMasterUrl(_config.MasterUrl);
        SelectedProxyGameId = _config.ProxyActiveGameId ?? string.Empty;
        _config.ProxyProfileIdsByForwardId ??= [];
        _proxyForwardsAvailable = new(TaskCreationOptions.RunContinuationsAsynchronously);
        if (config.ClientId <= 0 && config.ActiveClientId > 0)
            config.ClientId = config.ActiveClientId;
        if (config.ActiveClientId <= 0 && config.ClientId > 0)
            config.ActiveClientId = config.ClientId;
        SignedInUsername = config.SignedInUsername;
        SignedInEmail = config.SignedInEmail;
        HasSignedInEmail = !string.IsNullOrWhiteSpace(SignedInEmail);
        ActiveClientName = config.ActiveClientName;
        AvailableClientChoices.Clear();
        foreach (var client in config.AvailableClients)
            AvailableClientChoices.Add(client);

        ShowSetupWizard = string.IsNullOrWhiteSpace(config.MasterUrl)
                       || string.IsNullOrWhiteSpace(config.AgentToken)
                       || config.ActiveClientId <= 0;
        ShowClientPicker = false;
        PendingClientSelectionId = 0;
    }


    public void CompleteDesktopLogin(DesktopLoginResponse login)
    {
        _config.DesktopSessionToken = login.SessionToken;
        _config.SignedInUserId = login.UserId;
        _config.SignedInUsername = login.Username;
        _config.SignedInEmail = login.Email;
        _config.AvailableClients = login.Clients;

        SignedInUsername = login.Username;
        SignedInEmail = login.Email;
        HasSignedInEmail = !string.IsNullOrWhiteSpace(SignedInEmail);

        AvailableClientChoices.Clear();
        foreach (var client in login.Clients)
            AvailableClientChoices.Add(client);

        if (login.Clients.Count == 1)
        {
            PendingClientSelectionId = login.Clients[0].Id;
            ShowClientPicker = false;
            return;
        }

        PendingClientSelectionId = 0;
        ShowClientPicker = login.Clients.Count > 1;
    }


    public void CompleteClientSelection(ClientSelectionResponse selection)
    {
        _config.ClientId = selection.ClientId;
        _config.ActiveClientId = selection.ClientId;
        _config.ActiveClientName = selection.ClientName;
        _config.AgentToken = selection.AgentToken;
        ActiveClientName = selection.ClientName;
        ShowClientPicker = false;
        ShowSetupWizard = false;
    }

    public void SignOutForTests()
    {
        StopProxyForShutdown();
        _config.DesktopSessionToken = string.Empty;
        _config.SignedInUserId = 0;
        _config.SignedInUsername = string.Empty;
        _config.SignedInEmail = string.Empty;
        _config.AvailableClients = [];
        _config.ActiveClientId = 0;
        _config.ActiveClientName = string.Empty;
        _config.ClientId = 0;
        _config.AgentToken = string.Empty;
        AvailableClientChoices.Clear();
        SignedInUsername = string.Empty;
        SignedInEmail = string.Empty;
        HasSignedInEmail = false;
        ActiveClientName = string.Empty;
        SelectedForward = null;
        Forwards.Clear();
        NativeOutboundCapability = null;
        _nativeActiveForwardId = 0;
        _nativeActiveRouteScope = "global";
        _nativeActiveUnrestricted = false;
        NativeUnrestrictedRoute = false;
        ShowClientPicker = false;
        ShowSetupWizard = true;
    }


    public void ShowUpdatePromptForTests(string version)
    {
        AvailableUpdateVersion = version;
        ShowUpdatePrompt = true;
    }

    public void PresentUpdatePrompt(WindowsClientAppArtifact artifact)
    {
        _availableUpdateArtifact = artifact;
        AvailableUpdateVersion = artifact.Version;
        ShowUpdatePrompt = true;
    }

    public void DeferUpdateForRestartForTests(string version, string installerPath, string sha256)
    {
        _config.PendingUpdateVersion = version;
        _config.PendingUpdateInstallerPath = installerPath;
        _config.PendingUpdateSha256 = sha256;
        _config.PendingUpdateDownloadedAt = DateTimeOffset.UtcNow.ToString("O");
        ShowUpdatePrompt = false;
        AvailableUpdateVersion = string.Empty;
    }

    public void ReportNoUpdateAvailableForTests(string version)
    {
        ShowUpdatePrompt = false;
        AvailableUpdateVersion = string.Empty;
        ConnectionStatus = $"Already up to date ({version}).";
    }

    public void ReportUpdateCheckFailedForTests(string message)
    {
        ShowUpdatePrompt = false;
        AvailableUpdateVersion = string.Empty;
        ConnectionStatus = $"Update check failed: {message}";
    }

    [RelayCommand]
    private async Task ConnectAsync(CancellationToken ct)
    {
        using var lifecycle = CancellationTokenSource.CreateLinkedTokenSource(ct, _lifecycleCts.Token);
        ct = lifecycle.Token;
        ThrowIfShuttingDown();
        if (string.IsNullOrWhiteSpace(_config.MasterUrl) || string.IsNullOrWhiteSpace(_config.AgentToken))
            return;

        _api?.Dispose();
        _api = new MasterApiClient(
            _config.MasterUrl,
            _config.AgentToken,
            MasterApiClient.BuildWindowsDeviceId(_config.ActiveClientId > 0 ? _config.ActiveClientId : _config.ClientId, Environment.MachineName),
            $"Leikwan Bridge on {Environment.MachineName}",
            "windows-desktop");

        var ok = await _api.TestConnectionAsync(ct);
        ct.ThrowIfCancellationRequested();
        ThrowIfShuttingDown();
        if (!ok)
        {
            ConnectionStatus = string.IsNullOrWhiteSpace(_api.LastErrorMessage)
                ? GetString("DashDisconnected")
                : _api.LastErrorMessage;
            AddLog($"Connection failed: {ConnectionStatus}");
            IsConnected = false;
            return;
        }

        IsConnected = true;
        ConnectionStatus = GetString("DashConnected");
        ShowSetupWizard = false;

        // Initialize services
        _stateManager = new StateManager(_config.GetEffectiveStateDir());
        StartUsageRefresh();
        _supervisor = new ProcessSupervisor(OnProcessOutput);
        _runtimeManager = new ForwardRuntimeManager(
            _config,
            _supervisor,
            _stateManager,
            portPreflight: PortConflictPreflight.Bind(_supervisor, AppDefaults.GetInstallRoot()));
        _pollingService = new ConfigPollingService(_api, _runtimeManager, _stateManager);
        _diagnostics = new ClientDiagnosticsService(
            _supervisor,
            _config,
            logDirectory: Path.Combine(
                Environment.GetFolderPath(Environment.SpecialFolder.CommonApplicationData),
                "Leikwan", "ClientAgent", "logs"));
        _healthCheckService = new HealthCheckService(_supervisor, _runtimeManager, _stateManager, config: _config);
        _installedRuntimeCleanup = new InstalledRuntimeCleanupService();

        _wgManager = new WireGuardManager(_config.GetEffectiveWgBin(), _config.GetEffectiveStateDir());
        _wgManager.EnsureIdentity();

        _heartbeatService = new HeartbeatService(
            _api, _stateManager,
            _wgManager.PublicKey,
            _config.GetEffectiveWireguardListenPort(),
            _config.GetEffectiveWireguardFallbackTcpPort(),
            ApplyUltraPolicyActions,
            additionalRuntimeDirectories: [Path.Combine(_config.GetEffectiveStateDir(), "proxy-runtime")]);

        _pollingService.ForwardsUpdated += OnForwardsUpdated;
        _pollingService.NativeOutboundUpdated += OnNativeOutboundUpdated;
        _pollingService.PullStateChanged += OnPullStateChanged;
        _pollingService.SyncError += OnSyncError;
        _clientUpdatedHandler = client =>
        {
            if (IsShuttingDown || _diagnostics is null || _api is null)
                return;
            _diagnostics.QueueDebugLogUpload(client, _api, _lifecycleCts.Token);
        };
        _pollingService.ClientUpdated += _clientUpdatedHandler;
        _healthCheckService.HealthIssue += OnHealthIssue;

        var installRoot = AppDefaults.GetInstallRoot();
        var cleaned = _installedRuntimeCleanup.KillProcessesUnderInstallRoot(installRoot, Environment.ProcessId);
        if (cleaned > 0)
            AddLog($"Stopped {cleaned} stale installed runtime process(es).");

        ct.ThrowIfCancellationRequested();
        ThrowIfShuttingDown();
        _pollingService.Start(_config.PollIntervalSeconds);
        IsLoadingForwards = true;

        AddLog("Connected to master panel.");
    }

    [RelayCommand]
    private void Disconnect()
    {
        StopProxyForShutdown();
        StopUsageRefresh();
        _pollingService?.Stop();
        _healthCheckService?.Stop();
        _runtimeManager?.StopAll();
        _api?.Dispose();
        _api = null;
        _diagnostics = null;
        IsConnected = false;
        NativeOutboundCapability = null;
        _nativeActiveForwardId = 0;
        _nativeActiveRouteScope = "global";
        ConnectionStatus = GetString("DashDisconnected");
        Forwards.Clear();
        IsLoadingForwards = true;
        ActiveForwardCount = 0;
        AddLog("Disconnected.");
    }

    [RelayCommand]
    private void NavigateTo(string view) => CurrentView = view;

    [RelayCommand]
    private void SelectForward(ForwardViewModel? forward)
    {
        if (forward == null)
            return;

        SelectedForward = forward;
        CurrentView = "Forwards";
    }

    [RelayCommand]
    private async Task ToggleForward(ForwardViewModel? vm)
    {
        if (vm == null)
            return;

        if (vm.Summary.CanStop)
            await StopForward(vm);
        else if (vm.Summary.CanStart)
            await StartForward(vm);
    }

    [RelayCommand]
    private async Task StartForward(ForwardViewModel? vm)
    {
        if (vm?.Config == null || _runtimeManager == null || _busyForwards.Contains(vm.ForwardId))
            return;

        _busyForwards.Add(vm.ForwardId);
        SetLifecycle(vm.ForwardId, ForwardLifecycleState.Connecting);
        try
        {
            var succeeded = false;
            try
            {
                succeeded = await Task.Run(() =>
                {
                    _runtimeManager.ApplyForward(vm.Config);
                    return _runtimeManager.IsForwardRunning(vm.ForwardId);
                }).WaitAsync(TimeSpan.FromSeconds(10));
            }
            catch (TimeoutException)
            {
                succeeded = false;
            }
            catch (Exception ex)
            {
                Log.Warning(ex, "failed applying forward {ForwardId} from UI", vm.ForwardId);
                succeeded = false;
            }

            SetLifecycle(vm.ForwardId, succeeded ? ForwardLifecycleState.Connected : ForwardLifecycleState.Unavailable);
            AddLog(succeeded ? $"Started forward {vm.ForwardId}." : $"Forward {vm.ForwardId} failed to start.");
        }
        finally
        {
            _busyForwards.Remove(vm.ForwardId);
        }
    }

    [RelayCommand]
    private async Task StopForward(ForwardViewModel? vm)
    {
        if (vm == null || _runtimeManager == null)
            return;

        await Task.Run(() => _runtimeManager.StopForward(vm.ForwardId));
        SetLifecycle(vm.ForwardId, ForwardLifecycleState.Stopped);
        AddLog($"Stopped forward {vm.ForwardId}.");
    }

    [RelayCommand]
    private async Task StartAll()
    {
        using var gate = new SemaphoreSlim(4);
        var tasks = Forwards
            .Where(fwd => fwd.Config != null && fwd.Summary.CanStart)
            .Select(async fwd =>
            {
                await gate.WaitAsync();
                try { await StartForward(fwd); }
                finally { gate.Release(); }
            })
            .ToArray();
        await Task.WhenAll(tasks);
        AddLog("Started all forwards.");
    }

    [RelayCommand]
    private async Task StopAllForwards()
    {
        await Task.Run(() => _runtimeManager?.StopAll());
        foreach (var fwd in Forwards)
            SetLifecycle(fwd.ForwardId, ForwardLifecycleState.Stopped);
        AddLog("Stopped all forwards.");
    }

    [RelayCommand]
    private void ClearLog() => LogEntries.Clear();

    public AgentConfig Config => _config;
    public WindowsClientAppArtifact? AvailableUpdateArtifact => _availableUpdateArtifact;
    public int ForwardCount => Forwards.Count;

    public void ApplyForwardSnapshot(IEnumerable<ForwardConfig> forwards, Func<int, bool> isRunning, StateManager? stateManager = null)
    {
        IsLoadingForwards = false;
        var snapshot = forwards.ToList();
        var qualityStateManager = stateManager ?? _stateManager;
        var applied = qualityStateManager?.LoadApplied() ?? new Dictionary<string, AppliedForwardState>();

        Forwards.Clear();
        ConnectionQualityForwards.Clear();
        foreach (var forward in snapshot)
        {
            var running = isRunning(forward.ForwardId);
            var busy = _busyForwards.Contains(forward.ForwardId);
            var userStopped = applied.TryGetValue(forward.ForwardId.ToString(), out var appliedState) && appliedState!.UserStopped;
            var degraded = HasDegradedLeg(qualityStateManager, forward);
            var vm = new ForwardViewModel(forward, BuildSummary(forward, running, busy, userStopped, degraded));
            Forwards.Add(vm);
            ConnectionQualityForwards.Add(BuildConnectionQuality(forward, running, qualityStateManager));
        }

        SelectedForward = Forwards.FirstOrDefault();
        RefreshProxyForwardOptions();
        ActiveForwardCount = Forwards.Count;
    }

    private static ForwardSummary BuildSummary(ForwardConfig forward, bool running, bool busy, bool userStopped, bool degraded)
    {
        var lifecycle = busy ? ForwardLifecycleState.Connecting
            : running ? ForwardLifecycleState.Connected
            : userStopped ? ForwardLifecycleState.Stopped
            : ForwardLifecycleState.Unavailable;

        var quality = !running ? ForwardQualityState.Unavailable
            : degraded ? ForwardQualityState.Degraded
            : ForwardQualityState.Good;

        var friendly = lifecycle == ForwardLifecycleState.Unavailable ? "Unavailable" : "";

        return new ForwardSummary(
            forward.ForwardId,
            BuildForwardDisplayName(forward),
            lifecycle,
            quality,
            quality.ToString(),
            friendly,
            busy,
            CanStart: lifecycle is ForwardLifecycleState.Stopped or ForwardLifecycleState.Unavailable,
            CanStop: lifecycle is ForwardLifecycleState.Connected,
            LastTransitionAt: DateTimeOffset.UtcNow.ToUnixTimeSeconds());
    }

    private static string BuildForwardDisplayName(ForwardConfig forward)
    {
        var direction = string.IsNullOrWhiteSpace(forward.ForwardCombinationDirection)
            ? "-"
            : forward.ForwardCombinationDirection.Trim();
        return $"Forward #{forward.ForwardId} · {direction}";
    }

    private void SetLifecycle(int forwardId, ForwardLifecycleState lifecycle)
    {
        var vm = Forwards.FirstOrDefault(f => f.ForwardId == forwardId);
        if (vm?.Config == null)
            return;

        var running = lifecycle == ForwardLifecycleState.Connected;
        var busy = _busyForwards.Contains(forwardId);
        vm.Summary = new ForwardSummary(
            forwardId,
            vm.DisplayName,
            lifecycle,
            running ? ForwardQualityState.Good : ForwardQualityState.Unavailable,
            running ? ForwardQualityState.Good.ToString() : ForwardQualityState.Unavailable.ToString(),
            lifecycle == ForwardLifecycleState.Unavailable ? "Unavailable" : "",
            busy,
            CanStart: lifecycle is ForwardLifecycleState.Stopped or ForwardLifecycleState.Unavailable,
            CanStop: lifecycle is ForwardLifecycleState.Connected,
            DateTimeOffset.UtcNow.ToUnixTimeSeconds());
    }

    private static bool HasDegradedLeg(StateManager? stateManager, ForwardConfig forward)
    {
        if (stateManager == null || !stateManager.LoadBondEndpointHealth().TryGetValue(forward.ForwardId.ToString(), out var health))
            return false;
        return health.Values.Any(entry => !string.IsNullOrWhiteSpace(entry.LastError));
    }

    private static ConnectionQualityForwardViewModel BuildConnectionQuality(ForwardConfig forward, bool isRunning, StateManager? stateManager)
    {
        var normalizedMode = NormalizeModeOrSingle(forward.Mode);
        var result = new ConnectionQualityForwardViewModel
        {
            ForwardId = forward.ForwardId,
            ModeDisplay = string.Equals(forward.BackendMode, "ultra", StringComparison.OrdinalIgnoreCase)
                ? "Ultra"
                : ModeNormalizer.DisplayName(normalizedMode, false),
            StatusText = isRunning ? "Running" : "Stopped",
            SummaryText = BuildQualitySummary(forward, normalizedMode, stateManager),
        };

        foreach (var leg in BuildQualityLegs(forward, normalizedMode, stateManager))
            result.Legs.Add(leg);

        return result;
    }

    private static string BuildQualitySummary(ForwardConfig forward, string normalizedMode, StateManager? stateManager)
    {
        if (normalizedMode == "mptcp")
        {
            var wgLegCount = EnumerateWireGuardUnderlayLegs(forward).Count();
            if (wgLegCount > 0)
                return $"{wgLegCount} WG {Plural(wgLegCount, "leg", "legs")}";
        }

        var count = forward.Endpoints.Count;
        var parts = new List<string> { $"{count} {Plural(count, "leg", "legs")}" };
        if (normalizedMode == "bond")
        {
            var health = LoadForwardBondHealth(stateManager, forward.ForwardId);
            var bestLatency = health.Values
                .Select(entry => entry.LastLatencyMs)
                .Where(latency => latency.HasValue)
                .Select(latency => latency!.Value)
                .DefaultIfEmpty(double.NaN)
                .Min();
            if (!double.IsNaN(bestLatency))
                parts.Add($"best {FormatLatency(bestLatency)}");
        }

        return string.Join(", ", parts);
    }

    private static IEnumerable<ConnectionQualityLegViewModel> BuildQualityLegs(ForwardConfig forward, string normalizedMode, StateManager? stateManager)
    {
        if (normalizedMode == "mptcp")
        {
            foreach (var (_, leg) in EnumerateWireGuardUnderlayLegs(forward))
            {
                yield return new ConnectionQualityLegViewModel
                {
                    Kind = "WG slave transit",
                    RouteText = JoinNonEmpty(" -> ", leg.LocalAddress, leg.PeerAddress, leg.MptcpRemoteAddress),
                    RemoteText = FormatHostPort(leg.PeerPublicHost, leg.PeerUdpPort),
                };
            }

            yield break;
        }

        var bondHealth = normalizedMode == "bond"
            ? LoadForwardBondHealth(stateManager, forward.ForwardId)
            : [];

        foreach (var endpoint in forward.Endpoints)
        {
            bondHealth.TryGetValue(endpoint.EndpointId.ToString(), out var health);
            var role = FirstNonEmpty(health?.Role, endpoint.HealthRole);
            var quality = health?.QualityScore ?? endpoint.QualityScore;
            yield return new ConnectionQualityLegViewModel
            {
                Kind = normalizedMode == "bond" ? "Bond leg" : "Endpoint",
                Role = role,
                RemoteText = FormatHostPort(FirstNonEmpty(endpoint.CdnHost, endpoint.Host), endpoint.Port),
                LatencyText = health?.LastLatencyMs is double latency ? FormatLatency(latency) : "",
                QualityText = quality >= 0 ? $"{Math.Round(quality * 100):0}%" : "",
                IssueText = health?.LastError ?? "",
            };
        }
    }

    private static IEnumerable<(UnderlayConfig Underlay, UnderlayLeg Leg)> EnumerateWireGuardUnderlayLegs(ForwardConfig forward)
    {
        foreach (var underlay in EnumerateUnderlays(forward.Mptcp))
        {
            if (!string.Equals(underlay.Type, "wireguard", StringComparison.OrdinalIgnoreCase))
                continue;

            foreach (var leg in underlay.Legs)
                yield return (underlay, leg);
        }
    }

    private static IEnumerable<UnderlayConfig> EnumerateUnderlays(MptcpConfig? mptcp)
    {
        if (mptcp == null)
            yield break;

        if (mptcp.Underlay != null)
            yield return mptcp.Underlay;

        foreach (var underlay in mptcp.Underlays)
            yield return underlay;
    }

    private static Dictionary<string, BondEndpointHealthEntry> LoadForwardBondHealth(StateManager? stateManager, int forwardId)
    {
        if (stateManager == null)
            return [];

        return stateManager.LoadBondEndpointHealth().TryGetValue(forwardId.ToString(), out var health)
            ? health
            : [];
    }

    private static string NormalizeModeOrSingle(string? mode)
    {
        try
        {
            return ModeNormalizer.Normalize(mode ?? "single");
        }
        catch
        {
            return "single";
        }
    }

    private static string FirstNonEmpty(params string?[] values)
    {
        foreach (var value in values)
        {
            if (!string.IsNullOrWhiteSpace(value))
                return value.Trim();
        }

        return "";
    }

    private static string JoinNonEmpty(string separator, params string?[] values)
    {
        return string.Join(separator, values
            .Where(value => !string.IsNullOrWhiteSpace(value))
            .Select(value => value!.Trim()));
    }

    private static string FormatHostPort(string host, int port)
    {
        return string.IsNullOrWhiteSpace(host)
            ? ""
            : port > 0 ? $"{host}:{port}" : host;
    }

    private static string FormatLatency(double latencyMs) => $"{latencyMs:0.#} ms";

    private static string Plural(int count, string singular, string plural) => count == 1 ? singular : plural;

    private void OnForwardsUpdated(List<ForwardConfig> forwards)
    {
        if (IsShuttingDown)
            return;

        InvokeOnUiThread(() =>
        {
            ApplyForwardSnapshot(forwards, forwardId => _runtimeManager?.IsForwardRunning(forwardId) == true);
            _healthCheckService?.UpdateForwards(forwards);
        });

        // Send heartbeat after sync
        var heartbeatTask = Task.Run(async () =>
        {
            try
            {
                if (IsShuttingDown || _heartbeatService is null)
                    return;
                await _heartbeatService.SendHeartbeatAsync(_lifecycleCts.Token).ConfigureAwait(false);
            }
            catch (OperationCanceledException) when (_lifecycleCts.IsCancellationRequested)
            {
            }
            catch (Exception ex) { Log.Error(ex, "heartbeat failed"); }
        });
        lock (_shutdownGate)
            _heartbeatTask = heartbeatTask;

        ScheduleNativeLeaseRefresh();
    }

    private void OnNativeOutboundUpdated(NativeOutboundCapability? capability)
    {
        if (IsShuttingDown)
            return;
        Log.Debug(
            "native outbound capability updated (present={Present}, enabled={Enabled}, forwards={ForwardCount})",
            capability is not null,
            capability?.Enabled == true,
            capability?.Forwards.Count ?? 0);
        InvokeOnUiThread(() => NativeOutboundCapability = capability);
        if (_proxyService?.IsNativeOutboundActive == true
            && !NativeCapabilityCoversActiveSession(capability))
        {
            Log.Warning(
                "native outbound capability no longer covers active session; stopping forward {ForwardId}",
                _nativeActiveForwardId);
            _ = Task.Run(async () =>
            {
                try { await StopProxyAsync("capability_removed", CancellationToken.None).ConfigureAwait(false); }
                catch (Exception exception) { Log.Warning(exception, "failed to stop native outbound after capability removal"); }
            });
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

    private void ScheduleNativeLeaseRefresh()
    {
        if (IsShuttingDown
            || _proxyService?.IsNativeOutboundActive != true)
            return;
        if (Volatile.Read(ref _nativeRefreshInProgress) != 0)
        {
            Interlocked.Exchange(ref _nativeRefreshPending, 1);
            return;
        }
        _ = Task.Run(() => RefreshNativeLeaseIfNeededAsync(_lifecycleCts.Token));
    }

    private void StartNativeLeaseRenewalLoop()
    {
        CancelNativeLeaseRenewalLoop();
        if (IsShuttingDown || _proxyService?.IsNativeOutboundActive != true)
            return;

        var cancellation = CancellationTokenSource.CreateLinkedTokenSource(_lifecycleCts.Token);
        Task task;
        lock (_shutdownGate)
        {
            task = Task.Run(() => _nativeLeaseRenewalLoop.RunAsync(
                isActive: () => !IsShuttingDown && _proxyService?.IsNativeOutboundActive == true,
                isDataPlaneRunning: () => _proxyService?.IsNativeDataPlaneRunning == true,
                leaseExpiresAt: () => _proxyService?.ActiveNativeLeaseExpiresAt,
                refresh: RefreshNativeLeaseIfNeededAsync,
                onError: exception => Log.Warning(exception, "native outbound lease renewal loop failed"),
                cancellation.Token));
            _nativeLeaseRenewalCts = cancellation;
            _nativeLeaseRenewalTask = task;
        }
        Log.Information(
            "native outbound lease renewal loop started for forward {ForwardId} (expires={ExpiresAt:O})",
            _nativeActiveForwardId,
            _proxyService.ActiveNativeLeaseExpiresAt);
    }

    private Task? CancelNativeLeaseRenewalLoop()
    {
        CancellationTokenSource? cancellation;
        Task? task;
        lock (_shutdownGate)
        {
            cancellation = _nativeLeaseRenewalCts;
            task = _nativeLeaseRenewalTask;
            _nativeLeaseRenewalCts = null;
            _nativeLeaseRenewalTask = null;
        }
        if (cancellation is null)
            return task;
        try { cancellation.Cancel(); }
        catch (ObjectDisposedException) { }
        if (task is null || task.IsCompleted)
        {
            cancellation.Dispose();
        }
        else
        {
            _ = task.ContinueWith(
                _ => cancellation.Dispose(),
                CancellationToken.None,
                TaskContinuationOptions.ExecuteSynchronously,
                TaskScheduler.Default);
        }
        return task;
    }

    private async Task RefreshNativeLeaseIfNeededAsync(CancellationToken ct)
    {
        if (Interlocked.CompareExchange(ref _nativeRefreshInProgress, 1, 0) != 0)
            return;
        try
        {
            if (IsShuttingDown
                || _proxyService?.IsNativeOutboundActive != true
                || _api is null)
                return;
            var proxyService = _proxyService;
            var now = DateTimeOffset.UtcNow;
            var expiresAt = proxyService.ActiveNativeLeaseExpiresAt;
            var dataPlaneRunning = proxyService.IsNativeDataPlaneRunning;
            if (dataPlaneRunning
                && !NativeLeaseRenewalLoop.IsRefreshDue(expiresAt, now))
                return;

            var forwardId = _nativeActiveForwardId;
            var routeScope = _nativeActiveRouteScope;
            var unrestricted = _nativeActiveUnrestricted;
            var mode = _nativeActiveMode;
            var gameId = _nativeActiveGameId;
            var bypassChina = _nativeActiveBypassChina;
            var leases = await _api.ResolveFreshNativeOutboundLeasesAsync(
                forwardId,
                routeScope,
                unrestricted ? "unrestricted" : "restricted",
                ct).ConfigureAwait(false);
            if (leases.Count == 0)
            {
                Log.Warning("native outbound lease refresh unavailable for forward {ForwardId}", forwardId);
                _pollingService?.RequestPolicyRefresh();
                return;
            }

            // The user may have pressed Stop while the panel request was in
            // flight. Do not resurrect a stopped proxy or leak the lease.
            if (!ReferenceEquals(_proxyService, proxyService)
                || !proxyService.IsNativeOutboundActive
                || _nativeActiveForwardId != forwardId
                || !string.Equals(_nativeActiveRouteScope, routeScope, StringComparison.OrdinalIgnoreCase)
                || _nativeActiveUnrestricted != unrestricted)
            {
                DisposeNativeLeases(leases);
                return;
            }
            var game = ResolveProxyGame(gameId);
            var rules = (game?.ProcessNames ?? [])
                .Distinct(StringComparer.OrdinalIgnoreCase)
                .Take(64)
                .Select(name => new WindowsProcessProxyRule(
                    game?.PatternsAreRegex == true ? name : BuildProcessRule(name)))
                .ToList();
            var dnsMode = _config.GetEffectiveProxyDnsMode();
            bool hotRefreshed;
            try
            {
                hotRefreshed = dataPlaneRunning
                    && await _proxyService.TryRefreshNativeAsync(
                        leases,
                        ct).ConfigureAwait(false);
            }
            catch
            {
                DisposeNativeLeases(leases);
                throw;
            }
            if (!hotRefreshed)
            {
                // A dead relay or sing-box process is the only case that needs
                // a lifecycle restart. A rejected/transient lease must not
                // tear down a healthy TUN data plane.
                if (_proxyService.IsNativeOutboundActive
                    && !_proxyService.IsNativeDataPlaneRunning)
                {
                    await _proxyService.StartNativeAsync(
                        mode,
                        leases,
                        BuildProxyBypassHosts(),
                        rules,
                        ct,
                        bypassChina,
                        dnsMode,
                        dnsMode == WindowsProxyDnsMode.Custom
                            ? _config.GetEffectiveProxyDnsServers()
                            : null).ConfigureAwait(false);
                }
                else if (_proxyService.IsNativeOutboundActive)
                {
                    DisposeNativeLeases(leases);
                    _pollingService?.RequestPolicyRefresh();
                    Log.Warning(
                        "native outbound lease refresh deferred for forward {ForwardId}; preserving active data plane",
                        _nativeActiveForwardId);
                    return;
                }
                else
                {
                    DisposeNativeLeases(leases);
                    return;
                }
            }
            Log.Information(
                "native outbound lease refreshed for forward {ForwardId} (expires={ExpiresAt:O})",
                forwardId,
                proxyService.ActiveNativeLeaseExpiresAt);
        }
        catch (OperationCanceledException) when (ct.IsCancellationRequested)
        {
        }
        catch (MasterApiException exception) when (IsNativeLeaseRefreshError(exception))
        {
            _pollingService?.RequestPolicyRefresh();
            Log.Warning(
                "native outbound lease refresh deferred for forward {ForwardId} (code={Code})",
                _nativeActiveForwardId,
                exception.Message);
        }
        catch (Exception exception)
        {
            Log.Warning(exception, "native outbound lease refresh failed");
        }
        finally
        {
            Interlocked.Exchange(ref _nativeRefreshInProgress, 0);
            var refreshPending = Interlocked.Exchange(ref _nativeRefreshPending, 0) != 0;
            if (!ct.IsCancellationRequested && refreshPending)
                ScheduleNativeLeaseRefresh();
        }
    }

    private static bool IsNativeLeaseRefreshError(MasterApiException exception)
    {
        var code = (exception.Message ?? string.Empty).Trim().ToLowerInvariant();
        return exception.StatusCode == 409
            && code is "lease_scope_invalid"
                or "policy_generation_invalid"
                or "policy_scope_invalid"
                or "policy_unavailable"
                or "no_approved_candidate"
                or "quota_exhausted";
    }

    public void ApplyUsageSnapshot(RuntimeUsageSnapshot snapshot)
    {
        TotalBytesIn = FormatBytes(snapshot.BytesIn);
        TotalBytesOut = FormatBytes(snapshot.BytesOut);
    }

    private void StartUsageRefresh()
    {
        StopUsageRefresh();
        ApplyUsageSnapshot(_usageReader.Read(_stateManager?.EffectiveStateDir ?? ""));
        _usageTimer = new DispatcherTimer(DispatcherPriority.Background)
        {
            Interval = TimeSpan.FromSeconds(1),
        };
        _usageTimer.Tick += OnUsageTimerTick;
        _usageTimer.Start();
    }

    private void StopUsageRefresh()
    {
        if (_usageTimer == null)
            return;
        _usageTimer.Stop();
        _usageTimer.Tick -= OnUsageTimerTick;
        _usageTimer = null;
    }

    private async void OnUsageTimerTick(object? sender, EventArgs e)
    {
        ApplyUsageSnapshot(_usageReader.Read(_stateManager?.EffectiveStateDir ?? ""));
        try { await CheckProxyForwardHealthAsync(); }
        catch (Exception exception) { Log.Warning(exception, "ordinary proxy health cleanup failed"); }
    }

    private static string FormatBytes(long bytes)
    {
        var value = Math.Max(0, bytes);
        var units = new[] { "B", "KB", "MB", "GB", "TB" };
        var index = 0;
        var scaled = (double)value;
        while (scaled >= 1024 && index < units.Length - 1)
        {
            scaled /= 1024;
            index++;
        }
        return $"{scaled:F1} {units[index]}";
    }

    private void ApplyUltraPolicyActions(IReadOnlyList<UltraPolicyAction> actions)
    {
        if (IsShuttingDown)
            return;

        foreach (var action in actions.Take(32))
        {
            if (!string.Equals(action.Action, "stop", StringComparison.OrdinalIgnoreCase)
                || action.ForwardId <= 0
                || !System.Text.RegularExpressions.Regex.IsMatch(action.PolicyGeneration ?? "", "^[a-f0-9]{64}$"))
                continue;
            var forward = _pollingService?.LatestForwards.FirstOrDefault(item => item.ForwardId == action.ForwardId);
            if (forward?.UltraAccess is null
                || !string.Equals(forward.BackendMode, "ultra", StringComparison.OrdinalIgnoreCase)
                || !string.Equals(forward.UltraAccess.PolicyGeneration, action.PolicyGeneration, StringComparison.Ordinal))
                continue;
            _runtimeManager?.StopForwardForPolicy(action.ForwardId);
            InvokeOnUiThread(() => AddLog($"Panel stopped Ultra forward {action.ForwardId}: {action.Reason}"));
        }
    }

    private void OnPullStateChanged(PullState state)
    {
        InvokeOnUiThread(() =>
        {
            LastSyncTime = state.LastSuccessAt > 0
                ? DateTimeOffset.FromUnixTimeSeconds((long)state.LastSuccessAt).LocalDateTime.ToString("HH:mm:ss")
                : "Never";

            if (string.Equals(state.Status, "ok", StringComparison.OrdinalIgnoreCase))
            {
                ConnectionStatus = GetString("DashConnected");
            }
        });
    }

    private void OnSyncError(Exception ex)
    {
        InvokeOnUiThread(() =>
        {
            IsLoadingForwards = false;
            ConnectionStatus = $"Error: {ex.Message}";
            AddLog($"Sync error: {ex.Message}");
        });
    }

    private void OnHealthIssue(int forwardId, string issue)
    {
        if (IsShuttingDown)
            return;
        InvokeOnUiThread(() => AddLog($"Health issue on forward {forwardId}: {issue}"));
    }

    private void OnProcessOutput(int forwardId, string label, string line)
    {
        if (IsShuttingDown)
            return;
        InvokeOnUiThread(() => AddLog($"[fwd-{forwardId}/{label}] {line}"));
    }

    private void AddLog(string message)
    {
        var entry = $"[{DateTime.Now:HH:mm:ss}] {message}";
        LogEntries.Add(entry);
        while (LogEntries.Count > 5000)
            LogEntries.RemoveAt(0);
    }

    private static string GetString(string key)
    {
        var app = System.Windows.Application.Current;
        return app?.TryFindResource(key) as string ?? key;
    }

    private void InvokeOnUiThread(Action action)
    {
        if (IsShuttingDown)
            return;

        var app = System.Windows.Application.Current;
        if (app?.Dispatcher == null)
        {
            action();
            return;
        }

        var dispatcher = app.Dispatcher;
        if (dispatcher.HasShutdownStarted || dispatcher.HasShutdownFinished)
            return;
        if (dispatcher.CheckAccess())
        {
            if (!IsShuttingDown)
                action();
            return;
        }

        try
        {
            dispatcher.Invoke(() =>
            {
                if (!IsShuttingDown)
                    action();
            });
        }
        catch (InvalidOperationException) when (dispatcher.HasShutdownStarted || dispatcher.HasShutdownFinished)
        {
        }
    }
}
