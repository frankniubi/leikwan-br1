using System.Collections.ObjectModel;

namespace LeikwanBridge.App.ViewModels;

public sealed class ConnectionQualityForwardViewModel
{
    public int ForwardId { get; init; }
    public string ModeDisplay { get; init; } = "";
    public string StatusText { get; init; } = "";
    public string SummaryText { get; init; } = "";
    public ObservableCollection<ConnectionQualityLegViewModel> Legs { get; } = [];
}

public sealed class ConnectionQualityLegViewModel
{
    public string Kind { get; init; } = "";
    public string Role { get; init; } = "";
    public string RouteText { get; init; } = "";
    public string RemoteText { get; init; } = "";
    public string LatencyText { get; init; } = "";
    public string QualityText { get; init; } = "";
    public string IssueText { get; init; } = "";
}
