using CommunityToolkit.Mvvm.ComponentModel;
using LeikwanBridge.Core.Models;

namespace LeikwanBridge.App.ViewModels;

/// <summary>
/// Forward row view model. Exposes only the friendly <see cref="Summary"/>
/// surface; the raw <see cref="Config"/> is kept internal for apply only.
/// </summary>
public partial class ForwardViewModel : ObservableObject
{
    [ObservableProperty]
    private ForwardSummary _summary;

    public int ForwardId => Summary.ForwardId;
    public string DisplayName => Summary.DisplayName;

    /// <summary>The forwarded target's address (IP or host name), for the dashboard card.</summary>
    public string TargetAddress => ForwardTarget.Split(Config?.Target).Address;

    /// <summary>The forwarded target's port, for the dashboard card.</summary>
    public string TargetPort => ForwardTarget.Split(Config?.Target).Port;

    public ForwardConfig? Config { get; }

    public ForwardViewModel(ForwardConfig config, ForwardSummary summary)
    {
        Config = config;
        _summary = summary;
    }

    public ForwardViewModel()
    {
        _summary = new ForwardSummary(0, "", ForwardLifecycleState.Stopped, ForwardQualityState.Unavailable, "", "", false, true, false, 0);
    }
}
