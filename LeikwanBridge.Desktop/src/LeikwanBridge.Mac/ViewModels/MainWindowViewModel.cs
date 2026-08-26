using System.Collections.ObjectModel;
using Avalonia.Threading;
using CommunityToolkit.Mvvm.ComponentModel;
using CommunityToolkit.Mvvm.Input;
using LeikwanBridge.Core.Models;
using LeikwanBridge.Core.Services;
using LeikwanBridge.Core.Services.Platform;

namespace LeikwanBridge.Mac.ViewModels;

public sealed partial class MainWindowViewModel : ObservableObject
{
    private readonly IDesktopClientController _controller;
    private readonly IAutostartService? _autostart;
    private readonly Func<string, string, bool, CancellationToken, Task>? _savePreferences;
    private readonly Func<CancellationToken, Task>? _checkForUpdates;
    private readonly Action<Action> _dispatch;

    public MainWindowViewModel(
        IDesktopClientController controller,
        IAutostartService? autostart = null,
        bool closeToTray = true,
        string language = "en-US",
        Func<string, string, bool, CancellationToken, Task>? savePreferences = null,
        Func<CancellationToken, Task>? checkForUpdates = null,
        Action<Action>? dispatch = null)
    {
        _controller = controller;
        _autostart = autostart;
        _savePreferences = savePreferences;
        _checkForUpdates = checkForUpdates;
        _dispatch = dispatch ?? Dispatch;
        CloseToTray = closeToTray;
        Language = string.Equals(language, "zh-CN", StringComparison.OrdinalIgnoreCase) ? "zh-CN" : "en-US";
        PanelUrl = controller.Snapshot.PanelUrl;
        ApplySnapshot(controller.Snapshot);
        _controller.SnapshotChanged += snapshot => _dispatch(() => ApplySnapshot(snapshot));
        _controller.ForwardsChanged += forwards => _dispatch(() => ApplyForwards(forwards));
        _controller.NoticeRaised += notice => _dispatch(() => ShowNotice(notice));
        _controller.LogRaised += message => _dispatch(() => AddLog(message));
        _controller.UpdateAvailable += version => _dispatch(() => ShowUpdate(version));
        _ = LoadAutostartAsync();
    }

    public ObservableCollection<DesktopClientChoice> Clients { get; } = [];
    public ObservableCollection<ForwardRowViewModel> Forwards { get; } = [];
    public ObservableCollection<string> Logs { get; } = [];
    public IReadOnlyList<string> Languages { get; } = ["en-US", "zh-CN"];

    [ObservableProperty] private string _panelUrl = string.Empty;
    [ObservableProperty] private string _email = string.Empty;
    [ObservableProperty] private string _password = string.Empty;
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
    [ObservableProperty] private bool _isNoticeVisible;
    [ObservableProperty] private string _noticeText = string.Empty;
    [ObservableProperty] private bool _isUpdateVisible;
    [ObservableProperty] private string _updateVersion = string.Empty;
    [ObservableProperty] private bool _closeToTray;
    [ObservableProperty] private bool _autoStartEnabled;
    [ObservableProperty] private string _language = "en-US";
    [ObservableProperty] private bool _trayAvailable;
    [ObservableProperty] private string _selectedSection = "dashboard";

    public bool HasForwards => Forwards.Count > 0;
    public bool IsConnected => string.Equals(ConnectionStatus, "connected", StringComparison.OrdinalIgnoreCase);
    public bool IsSignedIn => !IsLoginVisible;
    public int ForwardCount => Forwards.Count;
    public int RunningForwardCount => Forwards.Count(forward => forward.IsRunning);
    public string ConnectionLabel => ConnectionStatus switch
    {
        "connected" => "Connected",
        "error" => "Connection error",
        _ => "Disconnected",
    };
    public string VersionLabel => $"Version {typeof(MainWindowViewModel).Assembly.GetName().Version?.ToString(3) ?? "1.0.0"}";
    public bool IsDebugVisible => string.Equals(
        Environment.GetEnvironmentVariable("LEIKWAN_DEBUG"),
        "1",
        StringComparison.Ordinal);

    partial void OnConnectionStatusChanged(string value)
    {
        OnPropertyChanged(nameof(IsConnected));
        OnPropertyChanged(nameof(ConnectionLabel));
    }

    partial void OnIsLoginVisibleChanged(bool value) => OnPropertyChanged(nameof(IsSignedIn));

    [RelayCommand]
    private async Task LoginAsync()
    {
        ValidationMessage = string.Empty;
        if (!Uri.TryCreate(PanelUrl, UriKind.Absolute, out var panel)
            || panel.Scheme is not ("https" or "http")
            || string.IsNullOrWhiteSpace(Email)
            || string.IsNullOrWhiteSpace(Password))
        {
            ValidationMessage = "Enter the panel URL, email, and password.";
            return;
        }
        var password = Password;
        IsBusy = true;
        try
        {
            await _controller.LoginAsync(PanelUrl, Email.Trim(), password);
            if (_controller.Snapshot.IsSignedIn
                && !_controller.Snapshot.RequiresClientSelection
                && !string.IsNullOrWhiteSpace(_controller.Snapshot.ActiveClientName))
                await _controller.ConnectAsync();
        }
        catch
        {
            ValidationMessage = "Sign-in failed. Check the panel address and credentials.";
        }
        finally
        {
            Password = string.Empty;
            IsBusy = false;
        }
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
            ValidationMessage = "The selected client could not be opened.";
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
        catch
        {
            ValidationMessage = "Connection failed.";
        }
    }

    [RelayCommand] private Task DisconnectAsync() => _controller.DisconnectAsync();
    [RelayCommand] private Task StartAllAsync() => _controller.StartAllAsync();
    [RelayCommand] private Task StopAllAsync() => _controller.StopAllAsync();

    [RelayCommand]
    private async Task SignOutAsync()
    {
        await _controller.SignOutAsync();
        SelectedSection = "dashboard";
        ShowDashboard();
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
        IsUpdateVisible = false;
        UpdateVersion = string.Empty;
    }

    [RelayCommand]
    private async Task CheckForUpdatesAsync()
    {
        if (_checkForUpdates is not null)
            await _checkForUpdates(CancellationToken.None);
    }

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
    }

    [RelayCommand]
    private void ShowDashboard()
    {
        SelectedSection = "dashboard";
        IsDashboardVisible = !IsLoginVisible && !IsClientPickerVisible;
        IsLogsVisible = false;
        IsSettingsVisible = false;
    }

    [RelayCommand]
    private void ShowLogs()
    {
        SelectedSection = "logs";
        IsDashboardVisible = false;
        IsLogsVisible = true;
        IsSettingsVisible = false;
    }

    [RelayCommand]
    private void ShowSettings()
    {
        SelectedSection = "settings";
        IsDashboardVisible = false;
        IsLogsVisible = false;
        IsSettingsVisible = true;
    }

    private void ApplySnapshot(DesktopClientSnapshot snapshot)
    {
        IsBusy = snapshot.IsBusy;
        ConnectionStatus = snapshot.ConnectionStatus;
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
        ApplyForwards(snapshot.Forwards);
    }

    private void ApplyForwards(IReadOnlyList<DesktopForwardSnapshot> forwards)
    {
        Forwards.Clear();
        foreach (var forward in forwards)
        {
            var row = new ForwardRowViewModel(_controller, forward);
            row.PropertyChanged += (_, args) =>
            {
                if (args.PropertyName == nameof(ForwardRowViewModel.IsRunning))
                    OnPropertyChanged(nameof(RunningForwardCount));
            };
            Forwards.Add(row);
        }
        OnPropertyChanged(nameof(HasForwards));
        OnPropertyChanged(nameof(ForwardCount));
        OnPropertyChanged(nameof(RunningForwardCount));
    }

    private void ShowNotice(string notice)
    {
        NoticeText = notice;
        IsNoticeVisible = true;
    }

    private void ShowUpdate(string version)
    {
        UpdateVersion = version;
        IsUpdateVisible = true;
    }

    private void AddLog(string message)
    {
        var bounded = (message ?? string.Empty).Replace('\r', ' ').Replace('\n', ' ');
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
