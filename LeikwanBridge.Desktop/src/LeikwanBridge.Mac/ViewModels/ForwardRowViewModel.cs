using CommunityToolkit.Mvvm.ComponentModel;
using CommunityToolkit.Mvvm.Input;
using LeikwanBridge.Core.Models;
using LeikwanBridge.Core.Services;
using LeikwanBridge.Mac.Localization;
using LeikwanBridge.Mac.Services;

namespace LeikwanBridge.Mac.ViewModels;

public sealed partial class ForwardRowViewModel : ObservableObject
{
    private readonly IDesktopClientController _controller;
    private bool _suppressPairCallback;

    public Func<Task>? StartConnection { get; set; }
    public Func<Task>? StopConnection { get; set; }
    public Func<int, Task>? PairNode { get; set; }
    public IReadOnlyList<MacConnectionProfile> NodeOptions { get; set; } = [];

    public ForwardRowViewModel(IDesktopClientController controller, DesktopForwardSnapshot snapshot)
    {
        _controller = controller;
        ForwardId = snapshot.ForwardId;
        DisplayName = snapshot.DisplayName;
        Mode = snapshot.Mode;
        ListenPort = snapshot.ListenPort;
        IsRunning = snapshot.IsRunning;
        Status = snapshot.Status;
        IsNativeOutboundRule = snapshot.IsNativeOutboundRule;
    }

    public int ForwardId { get; }
    public string DisplayName { get; }
    public string Mode { get; }
    public int ListenPort { get; }

    [ObservableProperty]
    private string _savedNodeName = NoNodeText;

    [ObservableProperty]
    private MacConnectionProfile? _selectedNode;

    public bool IsPaired => SelectedNode is { Id: > 0 };

    public void SetSavedNode(MacConnectionProfile? profile)
    {
        if (profile is { Id: <= 0 })
            profile = null;
        _suppressPairCallback = true;
        SelectedNode = profile;
        _suppressPairCallback = false;
        SavedNodeName = string.IsNullOrWhiteSpace(profile?.Name)
            ? NoNodeText
            : profile.Name.Trim();
        OnPropertyChanged(nameof(IsPaired));
    }

    partial void OnSelectedNodeChanged(MacConnectionProfile? value)
    {
        if (value is { Id: <= 0 })
        {
            _suppressPairCallback = true;
            SelectedNode = null;
            _suppressPairCallback = false;
            return;
        }
        SavedNodeName = string.IsNullOrWhiteSpace(value?.Name) ? NoNodeText : value.Name.Trim();
        OnPropertyChanged(nameof(IsPaired));
        if (!_suppressPairCallback && PairNode is not null)
            _ = PairNode(value?.Id ?? 0);
    }

    [ObservableProperty]
    private bool _isRunning;

    [ObservableProperty]
    private string _status = "stopped";

    [ObservableProperty]
    private bool _isBusy;

    /// <summary>
    /// Panel-declared native outbound rule: configuration only. Its data plane
    /// belongs to a user-initiated native outbound connection, so the row never
    /// offers, and never honours, an agent-side start/stop action.
    /// </summary>
    public bool IsNativeOutboundRule { get; }

    private static string NoNodeText => L.T("NoSavedNodeSelected", "No saved node selected");

    /// <summary>The runtime status code in the active language (unknown codes are shown as-is).</summary>
    public string StatusText => Status?.Trim().ToLowerInvariant() switch
    {
        "running" => L.T("ForwardStatusRunning", "Running"),
        "stopped" => L.T("ForwardStatusStopped", "Stopped"),
        "starting" => L.T("ForwardStatusStarting", "Starting"),
        "error" or "failed" => L.T("ForwardStatusError", "Error"),
        _ => Status ?? string.Empty,
    };

    partial void OnStatusChanged(string value) => OnPropertyChanged(nameof(StatusText));

    /// <summary>Re-reads localized texts after a language switch.</summary>
    public void RefreshLocalizedText()
    {
        if (SelectedNode is null)
            SavedNodeName = NoNodeText;
        OnPropertyChanged(nameof(StatusText));
    }

    public string PortText => $"127.0.0.1:{ListenPort}";
    public bool CanStart => !IsNativeOutboundRule && !IsBusy && !IsRunning;
    public bool CanStop => !IsNativeOutboundRule && !IsBusy && IsRunning;

    partial void OnIsRunningChanged(bool value)
    {
        OnPropertyChanged(nameof(CanStart));
        OnPropertyChanged(nameof(CanStop));
    }

    partial void OnIsBusyChanged(bool value)
    {
        OnPropertyChanged(nameof(CanStart));
        OnPropertyChanged(nameof(CanStop));
    }

    [RelayCommand]
    private async Task StartAsync()
    {
        if (IsNativeOutboundRule || !CanStart)
            return;
        IsBusy = true;
        try
        {
            await _controller.StartForwardAsync(ForwardId);
            var current = _controller.Snapshot.Forwards.FirstOrDefault(item => item.ForwardId == ForwardId);
            IsRunning = current?.IsRunning == true;
            Status = current?.Status ?? "stopped";
        }
        finally
        {
            IsBusy = false;
        }
    }

    [RelayCommand]
    private async Task StopAsync()
    {
        if (IsNativeOutboundRule || !CanStop)
            return;
        IsBusy = true;
        try
        {
            await _controller.StopForwardAsync(ForwardId);
            IsRunning = false;
            Status = "stopped";
        }
        finally
        {
            IsBusy = false;
        }
    }

    [RelayCommand]
    private Task StartConnectionAsync() => StartConnection?.Invoke() ?? Task.CompletedTask;

    [RelayCommand]
    private Task StopConnectionAsync() => StopConnection?.Invoke() ?? Task.CompletedTask;
}
