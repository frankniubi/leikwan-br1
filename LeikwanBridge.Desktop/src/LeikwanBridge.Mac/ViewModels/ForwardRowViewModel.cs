using CommunityToolkit.Mvvm.ComponentModel;
using CommunityToolkit.Mvvm.Input;
using LeikwanBridge.Core.Models;
using LeikwanBridge.Core.Services;
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
    }

    public int ForwardId { get; }
    public string DisplayName { get; }
    public string Mode { get; }
    public int ListenPort { get; }

    [ObservableProperty]
    private string _savedNodeName = "未选择已保存节点";

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
            ? "未选择已保存节点"
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
        SavedNodeName = string.IsNullOrWhiteSpace(value?.Name) ? "未选择已保存节点" : value.Name.Trim();
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

    public string PortText => $"127.0.0.1:{ListenPort}";
    public bool CanStart => !IsBusy && !IsRunning;
    public bool CanStop => !IsBusy && IsRunning;

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
        if (!CanStart)
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
        if (!CanStop)
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
