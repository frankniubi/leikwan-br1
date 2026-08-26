using LeikwanBridge.Core.Models;

namespace LeikwanBridge.Core.Helpers;

public static class UltraForwardPolicy
{
    public static bool IsSupported(ForwardConfig forward, string effectiveMode)
    {
        return string.Equals(forward.BackendMode?.Trim(), "ultra", StringComparison.OrdinalIgnoreCase)
            && string.Equals(effectiveMode, "single", StringComparison.Ordinal)
            && !forward.SidecarRequired
            && !forward.AggregationEnabled
            && !forward.BondSidecarPointId.HasValue
            && string.IsNullOrWhiteSpace(forward.BondIngressMode)
            && !string.IsNullOrWhiteSpace(forward.Target)
            && (string.IsNullOrWhiteSpace(forward.TargetContract)
                || string.Equals(forward.TargetContract, "ultra", StringComparison.OrdinalIgnoreCase)
                || string.Equals(forward.TargetContract, "ultra_direct", StringComparison.OrdinalIgnoreCase))
            && forward.Endpoints.Count == 0;
    }
}
