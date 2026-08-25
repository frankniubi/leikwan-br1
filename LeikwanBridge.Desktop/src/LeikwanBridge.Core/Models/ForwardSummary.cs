namespace LeikwanBridge.Core.Models;

/// <summary>Public lifecycle state for a forward row. Never inferred from process spawn alone.</summary>
public enum ForwardLifecycleState
{
    Stopped,
    Connecting,
    Connected,
    Unavailable,
}

/// <summary>Friendly quality bucket mapped from raw telemetry by Panel-signed thresholds.</summary>
public enum ForwardQualityState
{
    Good,
    Degraded,
    Unavailable,
}

/// <summary>
/// The credential-free public surface exposed for a forward row. Contains no
/// Ultra mode, target, transport, endpoint, provider, lease, policy generation,
/// pid, or control-path details.
/// </summary>
public sealed record ForwardSummary(
    int ForwardId,
    string DisplayName,
    ForwardLifecycleState Lifecycle,
    ForwardQualityState Quality,
    string QualityText,
    string FriendlyMessage,
    bool IsBusy,
    bool CanStart,
    bool CanStop,
    long LastTransitionAt);
