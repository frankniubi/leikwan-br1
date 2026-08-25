using System.Collections.ObjectModel;
using System.IO;
using System.Windows;
using CommunityToolkit.Mvvm.ComponentModel;
using CommunityToolkit.Mvvm.Input;
using LeikwanBridge.App.Services;
using LeikwanBridge.Core.Helpers;
using LeikwanBridge.Core.Models;
using LeikwanBridge.Core.Services;
using Serilog;

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
    private WindowsClientAppArtifact? _availableUpdateArtifact;

    [ObservableProperty] private bool _isConnected;
    [ObservableProperty] private string _connectionStatus = "Disconnected";
    [ObservableProperty] private string _lastSyncTime = "Never";
    [ObservableProperty] private int _activeForwardCount;
    [ObservableProperty] private string _totalBytesIn = "0 B";
    [ObservableProperty] private string _totalBytesOut = "0 B";
    [ObservableProperty] private string _currentView = "Dashboard";
    [ObservableProperty] private bool _showSetupWizard = true;
    [ObservableProperty] private string _activeModeFilter = "All";
    [ObservableProperty] private bool _showClientPicker;
    [ObservableProperty] private int _pendingClientSelectionId;
    [ObservableProperty] private string _signedInUsername = "";
    [ObservableProperty] private string _signedInEmail = "";
    [ObservableProperty] private string _activeClientName = "";
    [ObservableProperty] private bool _showUpdatePrompt;
    [ObservableProperty] private string _availableUpdateVersion = "";
    [ObservableProperty] private ForwardViewModel? _selectedForward;

    public ObservableCollection<ForwardViewModel> Forwards { get; } = [];
    public ObservableCollection<ForwardViewModel> VisibleForwards { get; } = [];
    public ObservableCollection<ConnectionQualityForwardViewModel> ConnectionQualityForwards { get; } = [];
    public ObservableCollection<string> AvailableModeFilters { get; } = ["All"];
    public ObservableCollection<DesktopClientChoice> AvailableClientChoices { get; } = [];
    public ObservableCollection<string> LogEntries { get; } = [];
    public bool ShouldAutoConnectOnStartup => !ShowSetupWizard && !IsConnected;

    public MainViewModel()
    {
        _config = new AgentConfig();
    }

    public void Initialize(AgentConfig config)
    {
        _config = config;
        _config.MasterUrl = AppDefaults.NormalizeMasterUrl(_config.MasterUrl);
        if (config.ClientId <= 0 && config.ActiveClientId > 0)
            config.ClientId = config.ActiveClientId;
        if (config.ActiveClientId <= 0 && config.ClientId > 0)
            config.ActiveClientId = config.ClientId;
        SignedInUsername = config.SignedInUsername;
        SignedInEmail = config.SignedInEmail;
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
        ActiveClientName = string.Empty;
        SelectedForward = null;
        Forwards.Clear();
        VisibleForwards.Clear();
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
        _supervisor = new ProcessSupervisor(OnProcessOutput);
        _runtimeManager = new ForwardRuntimeManager(_config, _supervisor, _stateManager);
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
    private void ApplyModeFilter(string filter)
    {
        ActiveModeFilter = string.IsNullOrWhiteSpace(filter) ? "All" : filter;
    }

    [RelayCommand]
    private void SelectForward(ForwardViewModel? forward)
    {
        if (forward == null)
            return;

        SelectedForward = forward;
        CurrentView = "Forwards";
    }

    [RelayCommand]
    private async Task StartForward(ForwardViewModel? vm)
    {
        if (vm?.Config == null || _runtimeManager == null) return;
        await Task.Run(() => _runtimeManager.ApplyForward(vm.Config));
        vm.IsRunning = true;
        AddLog($"Started forward {vm.ForwardId}.");
    }

    [RelayCommand]
    private async Task StopForward(ForwardViewModel? vm)
    {
        if (vm == null || _runtimeManager == null) return;
        await Task.Run(() => _runtimeManager.StopForward(vm.ForwardId));
        vm.IsRunning = false;
        AddLog($"Stopped forward {vm.ForwardId}.");
    }

    [RelayCommand]
    private async Task RestartForward(ForwardViewModel? vm)
    {
        if (vm?.Config == null || _runtimeManager == null) return;
        await Task.Run(() => _runtimeManager.RestartForward(vm.Config));
        vm.IsRunning = true;
        AddLog($"Restarted forward {vm.ForwardId}.");
    }

    [RelayCommand]
    private async Task StartAll()
    {
        foreach (var fwd in Forwards)
        {
            if (fwd.Config != null && !fwd.IsRunning)
                await Task.Run(() => _runtimeManager?.ApplyForward(fwd.Config));
            fwd.IsRunning = true;
        }
        AddLog("Started all forwards.");
    }

    [RelayCommand]
    private async Task StopAllForwards()
    {
        await Task.Run(() => _runtimeManager?.StopAll());
        foreach (var fwd in Forwards)
            fwd.IsRunning = false;
        AddLog("Stopped all forwards.");
    }

    [RelayCommand]
    private void ClearLog() => LogEntries.Clear();

    public AgentConfig Config => _config;
    public WindowsClientAppArtifact? AvailableUpdateArtifact => _availableUpdateArtifact;
    public int VisibleForwardCount => VisibleForwards.Count;

    public void ApplyForwardSnapshot(IEnumerable<ForwardConfig> forwards, Func<int, bool> isRunning, StateManager? stateManager = null)
    {
        var snapshot = forwards.ToList();
        var qualityStateManager = stateManager ?? _stateManager;

        Forwards.Clear();
        ConnectionQualityForwards.Clear();
        var strategyStore = new ForwardStrategyStore(
            _config.GetEffectiveStateDir(),
            _config.MasterUrl,
            _config.ClientId > 0 ? _config.ClientId : _config.ActiveClientId);
        foreach (var forward in snapshot)
        {
            var running = isRunning(forward.ForwardId);
            var vm = new ForwardViewModel(forward, strategyStore)
            {
                IsRunning = running,
            };
            Forwards.Add(vm);
            ConnectionQualityForwards.Add(BuildConnectionQuality(forward, running, qualityStateManager));
        }

        RebuildAvailableModeFilters();
        ActiveModeFilter = "All";
        RebuildVisibleForwards();
        ActiveForwardCount = Forwards.Count;
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

    partial void OnActiveModeFilterChanged(string value)
    {
        RebuildVisibleForwards();
    }

    private void RebuildAvailableModeFilters()
    {
        AvailableModeFilters.Clear();
        AvailableModeFilters.Add("All");
    }

    private void RebuildVisibleForwards()
    {
        VisibleForwards.Clear();
        foreach (var forward in Forwards.Where(forward => forward.MatchesModeFilter(ActiveModeFilter)))
            VisibleForwards.Add(forward);

        if (VisibleForwards.Count == 0)
        {
            SelectedForward = null;
        }
        else if (SelectedForward == null || VisibleForwards.All(forward => forward.ForwardId != SelectedForward.ForwardId))
        {
            SelectedForward = VisibleForwards[0];
        }

        OnPropertyChanged(nameof(VisibleForwardCount));
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
