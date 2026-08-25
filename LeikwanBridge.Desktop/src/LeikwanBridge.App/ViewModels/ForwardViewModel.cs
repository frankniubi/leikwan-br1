using CommunityToolkit.Mvvm.ComponentModel;
using LeikwanBridge.Core.Helpers;
using LeikwanBridge.Core.Models;
using LeikwanBridge.Core.Services;

namespace LeikwanBridge.App.ViewModels;

public partial class ForwardViewModel : ObservableObject
{
    private readonly ForwardStrategyStore? _strategyStore;
    [ObservableProperty] private int _forwardId;
    [ObservableProperty] private string _mode = "single";
    [ObservableProperty] private string _modeDisplay = "Direct";
    [ObservableProperty] private int _listenPort;
    [ObservableProperty] private string _target = "";
    [ObservableProperty] private string _transport = "tcp+udp";
    [ObservableProperty] private int _endpointCount;
    [ObservableProperty] private bool _isRunning;
    [ObservableProperty] private bool _isMptcpFallback;
    [ObservableProperty] private ForwardStrategy _selectedStrategy = ForwardStrategy.LatencyBest;

    public ForwardConfig? Config { get; }

    public ForwardViewModel(ForwardConfig config, ForwardStrategyStore? strategyStore = null)
    {
        _strategyStore = strategyStore;
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
        ModeDisplay = string.Equals(config.BackendMode, "ultra", StringComparison.OrdinalIgnoreCase)
            ? "Ultra"
            : ModeNormalizer.DisplayName(Mode, IsMptcpFallback);
        SelectedStrategy = strategyStore?.Get(config.ForwardId) ?? ForwardStrategy.LatencyBest;
    }

    public ForwardViewModel()
    {
    }

    public string StatusText => IsRunning ? "Running" : "Stopped";
    public bool IsSpeedMax
    {
        get => SelectedStrategy == ForwardStrategy.SpeedMax;
        set { if (value) SelectedStrategy = ForwardStrategy.SpeedMax; }
    }
    public bool IsLatencyBest
    {
        get => SelectedStrategy == ForwardStrategy.LatencyBest;
        set { if (value) SelectedStrategy = ForwardStrategy.LatencyBest; }
    }
    public bool IsRandom
    {
        get => SelectedStrategy == ForwardStrategy.Random;
        set { if (value) SelectedStrategy = ForwardStrategy.Random; }
    }

    partial void OnSelectedStrategyChanged(ForwardStrategy value)
    {
        _strategyStore?.Set(ForwardId, value);
        OnPropertyChanged(nameof(IsSpeedMax));
        OnPropertyChanged(nameof(IsLatencyBest));
        OnPropertyChanged(nameof(IsRandom));
    }

    public bool MatchesModeFilter(string filter)
    {
        return string.IsNullOrWhiteSpace(filter)
            || string.Equals(filter, "All", StringComparison.OrdinalIgnoreCase)
            || string.Equals(filter, ModeDisplay, StringComparison.OrdinalIgnoreCase);
    }
}
