using System.Collections.ObjectModel;
using Avalonia.Threading;
using CommunityToolkit.Mvvm.ComponentModel;
using CommunityToolkit.Mvvm.Input;
using LeikwanBridge.Core.Models;
using LeikwanBridge.Core.Services;
using LeikwanBridge.Core.Services.Platform;
using LeikwanBridge.Mac.Services;

namespace LeikwanBridge.Mac.ViewModels;

public sealed partial class MainWindowViewModel : ObservableObject
{
    public const string EmailPasswordAuthentication = "Email + Password";
    public const string ClientIdTokenAuthentication = "Client ID + Token";

    private readonly IDesktopClientController _controller;
    private readonly IMacConnectionService? _connections;
    private readonly Func<IReadOnlyList<ForwardConfig>> _forwardsProvider;
    private readonly IAutostartService? _autostart;
    private readonly Func<string, string, bool, CancellationToken, Task>? _savePreferences;
    private readonly Func<CancellationToken, Task>? _checkForUpdates;
    private readonly Func<CancellationToken, Task>? _installUpdate;
    private readonly Action<Action> _dispatch;

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
        Action<Action>? dispatch = null)
    {
        _controller = controller;
        _connections = connections;
        _forwardsProvider = forwardsProvider ?? (() => []);
        _autostart = autostart;
        _savePreferences = savePreferences;
        _checkForUpdates = checkForUpdates;
        _installUpdate = installUpdate;
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
        if (_connections is not null)
        {
            _connections.SnapshotChanged += snapshot => _dispatch(() => ApplyConnectionSnapshot(snapshot));
            _ = LoadConnectionsAsync();
        }
        _ = LoadAutostartAsync();
    }

    public ObservableCollection<DesktopClientChoice> Clients { get; } = [];
    public ObservableCollection<ForwardRowViewModel> Forwards { get; } = [];
    public ObservableCollection<MacConnectionProfile> ConnectionProfiles { get; } = [];
    public ObservableCollection<string> Logs { get; } = [];
    public IReadOnlyList<string> Languages { get; } = ["en-US", "zh-CN"];
    public IReadOnlyList<string> AuthenticationModes { get; } =
        [ClientIdTokenAuthentication];
    public IReadOnlyList<MacConnectionMode> ConnectionModes { get; } =
        [MacConnectionMode.Proxy, MacConnectionMode.Vpn];
    public MacConnectionEditorViewModel NodeEditor { get; } = new();

    [ObservableProperty] private string _panelUrl = string.Empty;
    [ObservableProperty] private string _email = string.Empty;
    [ObservableProperty] private string _password = string.Empty;
    [ObservableProperty] private string _selectedAuthenticationMode = ClientIdTokenAuthentication;
    [ObservableProperty] private string _clientIdText = string.Empty;
    [ObservableProperty] private string _agentToken = string.Empty;
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
    [ObservableProperty] private bool _isConnectionsVisible;
    [ObservableProperty] private bool _isNoticeVisible;
    [ObservableProperty] private string _noticeText = string.Empty;
    [ObservableProperty] private bool _isUpdateVisible;
    [ObservableProperty] private string _updateVersion = string.Empty;
    [ObservableProperty] private bool _isUpdateInstalling;
    [ObservableProperty] private bool _closeToTray;
    [ObservableProperty] private bool _autoStartEnabled;
    [ObservableProperty] private string _language = "en-US";
    [ObservableProperty] private bool _trayAvailable;
    [ObservableProperty] private string _selectedSection = "dashboard";
    [ObservableProperty] private MacConnectionProfile? _selectedConnectionProfile;
    [ObservableProperty] private MacConnectionMode _selectedConnectionMode = MacConnectionMode.Proxy;
    [ObservableProperty] private string _localConnectionStatus = "Disconnected";
    [ObservableProperty] private string _localConnectionError = string.Empty;
    [ObservableProperty] private int _bypassAddressCount;

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
    public bool IsEmailPasswordAuthentication =>
        string.Equals(SelectedAuthenticationMode, EmailPasswordAuthentication, StringComparison.Ordinal);
    public bool IsClientIdTokenAuthentication =>
        string.Equals(SelectedAuthenticationMode, ClientIdTokenAuthentication, StringComparison.Ordinal);
    public bool HasConnectionProfiles => ConnectionProfiles.Count > 0;
    public bool HasSelectedConnectionProfile => SelectedConnectionProfile is not null;
    public string LocalConnectionModeLabel => SelectedConnectionMode == MacConnectionMode.Vpn
        ? "VPN (TUN)"
        : "System proxy";
    public bool IsLocalConnectionActive =>
        LocalConnectionStatus is "Connected" or "Starting";

    partial void OnConnectionStatusChanged(string value)
    {
        OnPropertyChanged(nameof(IsConnected));
        OnPropertyChanged(nameof(ConnectionLabel));
    }

    partial void OnIsLoginVisibleChanged(bool value) => OnPropertyChanged(nameof(IsSignedIn));

    partial void OnSelectedAuthenticationModeChanged(string value)
    {
        OnPropertyChanged(nameof(IsEmailPasswordAuthentication));
        OnPropertyChanged(nameof(IsClientIdTokenAuthentication));
        ValidationMessage = string.Empty;
    }

    partial void OnSelectedConnectionModeChanged(MacConnectionMode value)
        => OnPropertyChanged(nameof(LocalConnectionModeLabel));

    partial void OnSelectedConnectionProfileChanged(MacConnectionProfile? value)
    {
        NodeEditor.Load(value);
        OnPropertyChanged(nameof(HasSelectedConnectionProfile));
    }

    [RelayCommand]
    private async Task LoginAsync()
    {
        ValidationMessage = string.Empty;
        if (!Uri.TryCreate(PanelUrl?.Trim(), UriKind.Absolute, out var panel)
            || panel.Scheme is not ("https" or "http"))
        {
            ValidationMessage = "Enter a valid panel URL, client ID, and token.";
            return;
        }

        if (!int.TryParse(ClientIdText.Trim(), System.Globalization.NumberStyles.None,
                System.Globalization.CultureInfo.InvariantCulture, out var clientId)
            || clientId <= 0
            || string.IsNullOrWhiteSpace(AgentToken))
        {
            ValidationMessage = "Enter a valid panel URL, client ID, and token.";
            return;
        }

        var agentToken = AgentToken;
        IsBusy = true;
        try
        {
            try
            {
                await _controller.LoginWithAgentTokenAsync(PanelUrl, clientId, agentToken);
            }
            catch (MasterApiException exception) when (exception.StatusCode == 401)
            {
                ValidationMessage = "Client ID or token was rejected by the panel.";
                return;
            }
            catch
            {
                ValidationMessage = "Sign-in failed. Check the panel address, client ID, and token.";
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
                    ValidationMessage = "Signed in, but the client could not connect.";
                    ShowNotice(ValidationMessage);
                }
            }
        }
        finally
        {
            Password = string.Empty;
            AgentToken = string.Empty;
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

    [RelayCommand]
    private async Task RefreshAsync()
    {
        try
        {
            await _controller.RefreshAsync();
        }
        catch
        {
            ValidationMessage = "Refresh failed. The current connection is still running.";
            ShowNotice(ValidationMessage);
        }
    }

    [RelayCommand] private Task DisconnectAsync() => _controller.DisconnectAsync();
    [RelayCommand] private Task StartAllAsync() => _controller.StartAllAsync();
    [RelayCommand] private Task StopAllAsync() => _controller.StopAllAsync();

    [RelayCommand]
    private async Task SignOutAsync()
    {
        if (_connections is not null)
            await _connections.StopAsync();
        await _controller.SignOutAsync();
        SelectedSection = "dashboard";
        ShowDashboard();
    }

    [RelayCommand]
    private void ShowConnections()
    {
        SelectedSection = "connections";
        IsDashboardVisible = false;
        IsLogsVisible = false;
        IsSettingsVisible = false;
        IsConnectionsVisible = !IsLoginVisible && !IsClientPickerVisible;
    }

    [RelayCommand]
    private void NewConnectionProfile()
    {
        SelectedConnectionProfile = null;
        NodeEditor.Load(new MacConnectionProfile
        {
            Name = "New node",
            Protocol = MacConnectionProtocol.Shadowsocks,
            Port = 443,
        });
        LocalConnectionError = string.Empty;
    }

    [RelayCommand]
    private async Task SaveConnectionProfileAsync()
    {
        if (_connections is null)
            return;
        LocalConnectionError = string.Empty;
        try
        {
            var profile = NodeEditor.ToProfile();
            await _connections.SaveProfileAsync(profile);
            ReplaceConnectionProfiles(_connections.Profiles, profile.Name, profile.Server);
        }
        catch (Exception exception)
        {
            LocalConnectionError = exception.Message.Contains("protocol", StringComparison.OrdinalIgnoreCase)
                ? "Choose a supported protocol and complete its required fields."
                : "The node could not be saved. Check the required fields.";
        }
    }

    [RelayCommand]
    private async Task DeleteConnectionProfileAsync()
    {
        if (_connections is null || SelectedConnectionProfile is null)
            return;
        await _connections.DeleteProfileAsync(SelectedConnectionProfile.Id);
        ReplaceConnectionProfiles(_connections.Profiles, string.Empty, string.Empty);
        SelectedConnectionProfile = ConnectionProfiles.FirstOrDefault();
    }

    [RelayCommand]
    private async Task StartConnectionAsync()
    {
        if (_connections is null)
            return;
        var profile = SelectedConnectionProfile;
        if (profile is null)
        {
            LocalConnectionError = "Select or save a node first.";
            return;
        }
        LocalConnectionError = string.Empty;
        try
        {
            await _connections.StartAsync(
                SelectedConnectionMode,
                profile.Id,
                PanelUrl,
                _forwardsProvider());
        }
        catch
        {
            LocalConnectionError = "The connection could not be started. Check the node and sing-box helper.";
        }
    }

    [RelayCommand]
    private async Task StopConnectionAsync()
    {
        if (_connections is null)
            return;
        await _connections.StopAsync();
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
    private async Task InstallUpdateAsync()
    {
        if (_installUpdate is null || IsUpdateInstalling)
            return;
        IsUpdateInstalling = true;
        try
        {
            await _installUpdate(CancellationToken.None);
        }
        finally
        {
            IsUpdateInstalling = false;
        }
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
        IsConnectionsVisible = false;
    }

    [RelayCommand]
    private void ShowLogs()
    {
        SelectedSection = "logs";
        IsDashboardVisible = false;
        IsLogsVisible = true;
        IsSettingsVisible = false;
        IsConnectionsVisible = false;
    }

    [RelayCommand]
    private void ShowSettings()
    {
        SelectedSection = "settings";
        IsDashboardVisible = false;
        IsLogsVisible = false;
        IsSettingsVisible = true;
        IsConnectionsVisible = false;
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
        if (SelectedSection == "connections")
            IsConnectionsVisible = !IsLoginVisible && !IsClientPickerVisible;
        ApplyForwards(snapshot.Forwards);
    }

    private async Task LoadConnectionsAsync()
    {
        if (_connections is null)
            return;
        try
        {
            await _connections.InitializeAsync();
            _dispatch(() =>
            {
                ReplaceConnectionProfiles(_connections.Profiles, string.Empty, string.Empty);
                SelectedConnectionProfile = ConnectionProfiles.FirstOrDefault();
                ApplyConnectionSnapshot(_connections.Snapshot);
            });
        }
        catch
        {
            _dispatch(() => LocalConnectionError = "Saved nodes could not be loaded.");
        }
    }

    private void ReplaceConnectionProfiles(
        IReadOnlyList<MacConnectionProfile> profiles,
        string preferredName,
        string preferredServer)
    {
        ConnectionProfiles.Clear();
        foreach (var profile in profiles)
            ConnectionProfiles.Add(profile);
        OnPropertyChanged(nameof(HasConnectionProfiles));
        var preferred = ConnectionProfiles.FirstOrDefault(profile =>
            string.Equals(profile.Name, preferredName, StringComparison.Ordinal)
            && string.Equals(profile.Server, preferredServer, StringComparison.Ordinal));
        SelectedConnectionProfile = preferred ?? SelectedConnectionProfile ?? ConnectionProfiles.FirstOrDefault();
    }

    private void ApplyConnectionSnapshot(MacConnectionSnapshot snapshot)
    {
        LocalConnectionStatus = snapshot.State switch
        {
            MacConnectionState.Connected => "Connected",
            MacConnectionState.Starting => "Starting",
            MacConnectionState.Error => "Connection error",
            _ => "Disconnected",
        };
        BypassAddressCount = snapshot.BypassAddressCount;
        if (!string.IsNullOrWhiteSpace(snapshot.ErrorCode))
            LocalConnectionError = snapshot.ErrorCode.Replace('_', ' ');
        OnPropertyChanged(nameof(LocalConnectionStatus));
        OnPropertyChanged(nameof(IsLocalConnectionActive));
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
