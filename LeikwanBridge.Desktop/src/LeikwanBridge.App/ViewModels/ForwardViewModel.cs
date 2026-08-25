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
