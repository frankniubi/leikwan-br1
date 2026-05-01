using System.Collections.ObjectModel;
using System.IO;
using System.Windows;
using CommunityToolkit.Mvvm.ComponentModel;
using CommunityToolkit.Mvvm.Input;
using LeikwanBridge.App.Services;
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

    [ObservableProperty] private bool _isConnected;
    [ObservableProperty] private string _connectionStatus = "Disconnected";
    [ObservableProperty] private string _lastSyncTime = "Never";
    [ObservableProperty] private int _activeForwardCount;
    [ObservableProperty] private string _totalBytesIn = "0 B";
    [ObservableProperty] private string _totalBytesOut = "0 B";
    [ObservableProperty] private string _currentView = "Dashboard";
    [ObservableProperty] private bool _showSetupWizard = true;
    [ObservableProperty] private string _activeModeFilter = "All";
    [ObservableProperty] private ForwardViewModel? _selectedForward;

    public ObservableCollection<ForwardViewModel> Forwards { get; } = [];
    public ObservableCollection<ForwardViewModel> VisibleForwards { get; } = [];
    public ObservableCollection<string> AvailableModeFilters { get; } = ["All"];
    public ObservableCollection<string> LogEntries { get; } = [];
    public bool ShouldAutoConnectOnStartup => !ShowSetupWizard && !IsConnected;

    public MainViewModel()
    {
        _config = new AgentConfig();
    }

    public void Initialize(AgentConfig config)
    {
        _config = config;
        ShowSetupWizard = string.IsNullOrWhiteSpace(config.MasterUrl)
                       || string.IsNullOrWhiteSpace(config.AgentToken)
                       || config.ClientId <= 0;
    }

    [RelayCommand]
    private async Task ConnectAsync(CancellationToken ct)
    {
        if (string.IsNullOrWhiteSpace(_config.MasterUrl) || string.IsNullOrWhiteSpace(_config.AgentToken))
            return;

        _api?.Dispose();
        _api = new MasterApiClient(_config.MasterUrl, _config.AgentToken);

        var ok = await _api.TestConnectionAsync(ct);
        if (!ok)
        {
            ConnectionStatus = GetString("DashDisconnected");
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
            _config.GetEffectiveWireguardFallbackTcpPort());

        _pollingService.ForwardsUpdated += OnForwardsUpdated;
        _pollingService.PullStateChanged += OnPullStateChanged;
        _pollingService.SyncError += OnSyncError;
        _healthCheckService.HealthIssue += OnHealthIssue;

        var installRoot = Path.Combine(
            Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData),
            "LeiKwan Bridge");
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
    public int VisibleForwardCount => VisibleForwards.Count;

    public void ApplyForwardSnapshot(IEnumerable<ForwardConfig> forwards, Func<int, bool> isRunning)
    {
        Forwards.Clear();
        foreach (var forward in forwards)
        {
            var vm = new ForwardViewModel(forward)
            {
                IsRunning = isRunning(forward.ForwardId),
            };
            Forwards.Add(vm);
        }

        RebuildAvailableModeFilters();
        ActiveModeFilter = Forwards.Any(forward => string.Equals(forward.Mode, "mptcp", StringComparison.OrdinalIgnoreCase))
            ? "MPTCP"
            : "All";
        RebuildVisibleForwards();
        ActiveForwardCount = Forwards.Count;
    }

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
        var filters = new List<string> { "All" };

        if (Forwards.Any(forward => string.Equals(forward.Mode, "mptcp", StringComparison.OrdinalIgnoreCase)))
            filters.Add("MPTCP");
        if (Forwards.Any(forward => string.Equals(forward.Mode, "bond", StringComparison.OrdinalIgnoreCase)))
            filters.Add("Bond");
        if (Forwards.Any(forward => string.Equals(forward.Mode, "race", StringComparison.OrdinalIgnoreCase)))
            filters.Add("Race");
        if (Forwards.Any(forward => string.Equals(forward.Mode, "single", StringComparison.OrdinalIgnoreCase)))
            filters.Add("Single");

        AvailableModeFilters.Clear();
        foreach (var filter in filters)
            AvailableModeFilters.Add(filter);
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
