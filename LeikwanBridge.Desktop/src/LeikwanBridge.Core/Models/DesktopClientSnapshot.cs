namespace LeikwanBridge.Core.Models;

public sealed record DesktopForwardSnapshot(
    int ForwardId,
    string DisplayName,
    string Mode,
    int ListenPort,
    bool IsRunning,
    string Status);

public sealed record DesktopClientSnapshot
{
    public bool IsSignedIn { get; init; }
    public bool RequiresClientSelection { get; init; }
    public bool IsConnected { get; init; }
    public bool IsBusy { get; init; }
    public string PanelUrl { get; init; } = string.Empty;
    public string SignedInUsername { get; init; } = string.Empty;
    public string SignedInEmail { get; init; } = string.Empty;
    public string ActiveClientName { get; init; } = string.Empty;
    public string ConnectionStatus { get; init; } = "disconnected";
    public string LastErrorCode { get; init; } = string.Empty;
    public IReadOnlyList<DesktopClientChoice> AvailableClients { get; init; } = [];
    public IReadOnlyList<DesktopForwardSnapshot> Forwards { get; init; } = [];
}
