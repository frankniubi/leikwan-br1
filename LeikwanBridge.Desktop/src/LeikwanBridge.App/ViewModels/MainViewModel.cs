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
    private AgentConfig _config;
    private MasterApiClient? _api;
    private StateManager? _stateManager;
    private ProcessSupervisor? _supervisor;
    private ForwardRuntimeManager? _runtimeManager;
    private ConfigPollingService? _pollingService;
    private HeartbeatService? _heartbeatService;
    private HealthCheckService? _healthCheckService;
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

    [ObservableProperty] private bool _isConnected;
    [ObservableProperty] private string _connectionStatus = "Disconnected";
    [ObservableProperty] private string _lastSyncTime = "Never";
    [ObservableProperty] private int _activeForwardCount;
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
    private string _selectedProxyGameDisplayName = "";

    public ObservableCollection<ForwardViewModel> Forwards { get; } = [];
    public ObservableCollection<ConnectionQualityForwardViewModel> ConnectionQualityForwards { get; } = [];
    public ObservableCollection<DesktopClientChoice> AvailableClientChoices { get; } = [];
    public ObservableCollection<string> LogEntries { get; } = [];
    public ObservableCollection<WindowsProxyProfile> ProxyProfiles { get; } = [];
    public ObservableCollection<WindowsProxyGameOption> ProxyGameOptions { get; } = [];
    public WindowsProxyEditorViewModel ProxyEditor { get; } = new();

    public string SelectedProxyGameDisplayName => _selectedProxyGameDisplayName;

    public bool IsProxyConnected => ProxySnapshot.State == WindowsProxyConnectionState.Connected;
    public bool IsProxyStarting => ProxySnapshot.State == WindowsProxyConnectionState.Starting;
    public bool IsProxyError => ProxySnapshot.State == WindowsProxyConnectionState.Error;

    private readonly HashSet<int> _busyForwards = [];
    public bool ShouldAutoConnectOnStartup => !ShowSetupWizard && !IsConnected;

    public MainViewModel()
    {
        _config = new AgentConfig();
    }

    partial void OnSelectedProxyGameIdChanged(string value)
    {
        _config.ProxyActiveGameId = value?.Trim() ?? string.Empty;
        UpdateSelectedProxyGameDisplayName();
    }

    /// <summary>
    /// Initializes local proxy state without requiring a successful panel
    /// login.  The service is lazy so unit tests and the setup wizard do not
    /// touch ProgramData until the window is actually loaded.
    /// </summary>
    public async Task InitializeProxyAsync(CancellationToken ct = default)
    {
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
        if (_proxyGameStore is not null)
        {
            _proxyGameProfiles.Clear();
            _proxyGameProfiles.AddRange(await _proxyGameStore.LoadAsync(ct));
            await ImportDiscoveredNetchModesAsync(_proxyGameStore, ct);
            _proxyGameStoreInitialized = true;
        }
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

        var selected = ProxyProfiles.FirstOrDefault(profile => profile.Id == _config.ProxyActiveProfileId)
            ?? ProxyProfiles.FirstOrDefault();
        ProxyEditor.Load(selected);
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
        RefreshProxyProfiles();
        ProxyEditor.Load(ProxyProfiles.FirstOrDefault(item => item.Id == profile.Id) ?? profile);
        AddLog($"Saved proxy profile {profile.Name}.");
    }

    public async Task DeleteProxyProfileAsync(int profileId, CancellationToken ct = default)
    {
        if (profileId <= 0)
            return;
        await EnsureProxyServiceAsync(ct);
        await _proxyService!.DeleteProfileAsync(profileId, ct);
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
        await EnsureProxyServiceAsync(ct);
        selectedGameId = string.IsNullOrWhiteSpace(selectedGameId)
            ? (string.IsNullOrWhiteSpace(SelectedProxyGameId)
                ? _config.ProxyActiveGameId
                : SelectedProxyGameId)
            : selectedGameId;
        var game = ResolveProxyGame(selectedGameId);
        if (mode == WindowsProxyMode.Process && game is null)
            throw new InvalidOperationException("process_game_required");

        var rules = (game?.ProcessNames ?? [])
            .Distinct(StringComparer.OrdinalIgnoreCase)
            .Take(64)
            .Select(name => new WindowsProcessProxyRule(
                game?.PatternsAreRegex == true ? name : BuildProcessRule(name)))
            .ToList();
        var dnsMode = _config.GetEffectiveProxyDnsMode();
        await _proxyService!.StartAsync(
            mode,
            profileId,
            BuildProxyBypassHosts(),
            rules,
            ct,
            _config.ProxyBypassChinaMainland,
            dnsMode,
            dnsMode == WindowsProxyDnsMode.Custom
                ? _config.GetEffectiveProxyDnsServers()
                : null);
        _config.ProxyActiveProfileId = profileId;
        _config.ProxyActiveMode = mode.ToString();
        SelectedProxyGameId = mode == WindowsProxyMode.Process ? game!.Id : string.Empty;
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

    public async Task StopProxyAsync(CancellationToken ct = default)
    {
        if (_proxyService is null)
            return;
        await _proxyService.StopAsync(ct);
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
        try { StopProxyAsync(CancellationToken.None).GetAwaiter().GetResult(); }
        catch (Exception exception) { Log.Warning(exception, "failed to stop Windows proxy during shutdown"); }
    }

    public void DisposeProxyForShutdown()
    {
        try { _proxyService?.DisposeAsync().AsTask().GetAwaiter().GetResult(); }
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
        InvokeOnUiThread(() =>
        {
            ProxySnapshot = snapshot;
            OnPropertyChanged(nameof(IsProxyConnected));
            OnPropertyChanged(nameof(IsProxyStarting));
            OnPropertyChanged(nameof(IsProxyError));
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
        _healthCheckService = new HealthCheckService(_supervisor, _runtimeManager, _stateManager, config: _config);
        _installedRuntimeCleanup = new InstalledRuntimeCleanupService();

        _wgManager = new WireGuardManager(_config.GetEffectiveWgBin(), _config.GetEffectiveStateDir());
        _wgManager.EnsureIdentity();

        _heartbeatService = new HeartbeatService(
            _api, _stateManager,
            _wgManager.PublicKey,
            _config.GetEffectiveWireguardListenPort(),
            _config.GetEffectiveWireguardFallbackTcpPort(),
            ApplyUltraPolicyActions);

        _pollingService.ForwardsUpdated += OnForwardsUpdated;
        _pollingService.PullStateChanged += OnPullStateChanged;
        _pollingService.SyncError += OnSyncError;
        _healthCheckService.HealthIssue += OnHealthIssue;

        var installRoot = AppDefaults.GetInstallRoot();
        var cleaned = _installedRuntimeCleanup.KillProcessesUnderInstallRoot(installRoot, Environment.ProcessId);
        if (cleaned > 0)
            AddLog($"Stopped {cleaned} stale installed runtime process(es).");

        _pollingService.Start(_config.PollIntervalSeconds);

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
        IsConnected = false;
        ConnectionStatus = GetString("DashDisconnected");
        Forwards.Clear();
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
        foreach (var fwd in Forwards)
        {
            if (fwd.Config != null && fwd.Summary.CanStart)
                await StartForward(fwd);
        }
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
        var port = forward.ListenPort > 0 ? forward.ListenPort.ToString() : "-";
        var direction = string.IsNullOrWhiteSpace(forward.ForwardCombinationDirection)
            ? "-"
            : forward.ForwardCombinationDirection.Trim();
        return $"Forward #{forward.ForwardId} | Port {port} | Direction {direction}";
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
        System.Windows.Application.Current.Dispatcher.Invoke(() =>
        {
            ApplyForwardSnapshot(forwards, forwardId => _runtimeManager?.IsForwardRunning(forwardId) == true);
            _healthCheckService?.UpdateForwards(forwards);
        });

        // Send heartbeat after sync
        Task.Run(async () =>
        {
            try { await _heartbeatService!.SendHeartbeatAsync(); }
            catch (Exception ex) { Log.Error(ex, "heartbeat failed"); }
        });
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

    private void OnUsageTimerTick(object? sender, EventArgs e)
    {
        ApplyUsageSnapshot(_usageReader.Read(_stateManager?.EffectiveStateDir ?? ""));
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
            ConnectionStatus = $"Error: {ex.Message}";
            AddLog($"Sync error: {ex.Message}");
        });
    }

    private void OnHealthIssue(int forwardId, string issue)
    {
        System.Windows.Application.Current.Dispatcher.Invoke(() =>
        {
            AddLog($"Health issue on forward {forwardId}: {issue}");
        });
    }

    private void OnProcessOutput(int forwardId, string label, string line)
    {
        System.Windows.Application.Current.Dispatcher.Invoke(() =>
        {
            AddLog($"[fwd-{forwardId}/{label}] {line}");
        });
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

    private static void InvokeOnUiThread(Action action)
    {
        var app = System.Windows.Application.Current;
        if (app?.Dispatcher == null)
        {
            action();
            return;
        }

        app.Dispatcher.Invoke(action);
    }
}
