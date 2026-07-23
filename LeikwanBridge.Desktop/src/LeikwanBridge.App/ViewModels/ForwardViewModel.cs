using CommunityToolkit.Mvvm.ComponentModel;
using LeikwanBridge.Core.Helpers;
using LeikwanBridge.Core.Models;

namespace LeikwanBridge.App.ViewModels;

public partial class ForwardViewModel : ObservableObject
{
    [ObservableProperty] private int _forwardId;
    [ObservableProperty] private string _mode = "single";
    [ObservableProperty] private string _modeDisplay = "LeiGod";
    [ObservableProperty] private int _listenPort;
    [ObservableProperty] private string _target = "";
    [ObservableProperty] private string _transport = "tcp+udp";
    [ObservableProperty] private int _endpointCount;
    [ObservableProperty] private bool _isRunning;
    [ObservableProperty] private bool _isMptcpFallback;

    public ForwardConfig? Config { get; }

    public ForwardViewModel(ForwardConfig config)
    {
        Config = config;
        ForwardId = config.ForwardId;
        ListenPort = config.ListenPort;
        Target = config.Target;
        Transport = config.Transport;
        EndpointCount = config.Endpoints.Count;

        try
        {
            Mode = ModeNormalizer.Normalize(config.Mode);
        }
        catch
        {
            Mode = "single";
        }

        IsMptcpFallback = false;
        ModeDisplay = ModeNormalizer.DisplayName(Mode, IsMptcpFallback);
    }

    public ForwardViewModel()
    {
    }

    public string StatusText => IsRunning ? "Running" : "Stopped";

    public bool MatchesModeFilter(string filter)
    {
        return true;
    }
}
