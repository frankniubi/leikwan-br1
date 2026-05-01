using CommunityToolkit.Mvvm.ComponentModel;
using LeikwanBridge.Core.Helpers;
using LeikwanBridge.Core.Models;

namespace LeikwanBridge.App.ViewModels;

public partial class ForwardViewModel : ObservableObject
{
    [ObservableProperty] private int _forwardId;
    [ObservableProperty] private string _mode = "single";
    [ObservableProperty] private string _modeDisplay = "Single";
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

        IsMptcpFallback = config.Mode?.ToLowerInvariant() is "mptcp" or "mptcp-wireguard";
        ModeDisplay = ModeNormalizer.DisplayName(Mode, IsMptcpFallback);
    }

    public ForwardViewModel()
    {
    }

    public string StatusText => IsRunning ? "Running" : "Stopped";

    public bool MatchesModeFilter(string filter)
    {
        if (string.IsNullOrWhiteSpace(filter) || string.Equals(filter, "All", StringComparison.OrdinalIgnoreCase))
            return true;

        return filter.ToLowerInvariant() switch
        {
            "mptcp" => string.Equals(Mode, "mptcp", StringComparison.OrdinalIgnoreCase),
            "bond" => string.Equals(Mode, "bond", StringComparison.OrdinalIgnoreCase),
            "race" => string.Equals(Mode, "race", StringComparison.OrdinalIgnoreCase),
            "single" => string.Equals(Mode, "single", StringComparison.OrdinalIgnoreCase),
            _ => true,
        };
    }
}
