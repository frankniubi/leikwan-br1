using CommunityToolkit.Mvvm.ComponentModel;
using CommunityToolkit.Mvvm.Input;
using LeikwanBridge.Core.Models;
using LeikwanBridge.Core.Services;

namespace LeikwanBridge.Mac.ViewModels;

public sealed partial class ForwardRowViewModel : ObservableObject
{
    private readonly IDesktopClientController _controller;

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
}
