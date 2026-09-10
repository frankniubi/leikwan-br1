using LeikwanBridge.Core.Models;

namespace LeikwanBridge.Core.Helpers;

public static class WindowsForwardPolicy
{
    public static bool IsSupportedOnWindows(ForwardConfig forward)
    {
        var backendMode = (forward.BackendMode ?? string.Empty).Trim().ToLowerInvariant();
        if (backendMode == "cf_mode")
            return false;

        var mode = NormalizeModeOrEmpty(FirstNonEmpty(forward.ClientMode, forward.Mode));
        if (backendMode == "ultra")
            return UltraForwardPolicy.IsSupported(forward, mode);

        if (GetSidecarFrontendMode(forward) != "")
            return true;
        if (mode is not ("single" or "bond" or "mptcp"))
            return false;

        if (!HasCanonicalContract(forward))
            return true;

        if (IsSidecarAggregation(forward))
            return mode is "bond" or "mptcp" && HasSidecarRuntimeTarget(forward, mode);

        if (IsDedicatedLeiGod(forward))
            return mode == "single"
                && !forward.SidecarRequired
                && !forward.AggregationEnabled
                && !string.IsNullOrWhiteSpace(forward.Target);

        return mode is "single" or "bond" or "mptcp";
    }

    public static List<ForwardConfig> FilterSupportedForwards(IEnumerable<ForwardConfig> forwards)
    {
        return forwards
            .Where(IsSupportedOnWindows)
            .ToList();
    }

    public static string GetSidecarFrontendMode(ForwardConfig forward)
    {
        var frontendMode = (forward.Bonding?.FrontendMode ?? "").Trim().ToLowerInvariant();
        if (frontendMode is not ("single" or "race"))
            return "";

        var mode = NormalizeModeOrEmpty(FirstNonEmpty(forward.ClientMode, forward.Mode));
        if (!string.Equals(mode, frontendMode, StringComparison.Ordinal)
            || !forward.SidecarRequired
            || !IsSidecarAggregation(forward)
            || !forward.BondSidecarPointId.HasValue
            || !HasBondSidecarTarget(forward.Bonding))
            return "";

        return frontendMode;
    }

    public static bool UsesBondRuntime(ForwardConfig forward)
    {
        var mode = NormalizeModeOrEmpty(FirstNonEmpty(forward.ClientMode, forward.Mode));
        return mode == "bond" || GetSidecarFrontendMode(forward) != "";
    }

    private static bool HasCanonicalContract(ForwardConfig forward)
    {
        return forward.ContractVersion > 0
            || !string.IsNullOrWhiteSpace(forward.ClientMode)
            || !string.IsNullOrWhiteSpace(forward.RuntimePreference)
            || !string.IsNullOrWhiteSpace(forward.BondIngressMode)
            || !string.IsNullOrWhiteSpace(forward.ActiveFamily)
            || !string.IsNullOrWhiteSpace(forward.TargetContract)
            || forward.AggregationEnabled
            || forward.SidecarRequired
            || forward.BondSidecarPointId.HasValue;
    }

    private static bool IsSidecarAggregation(ForwardConfig forward)
    {
        return forward.AggregationEnabled
            || forward.SidecarRequired
            || string.Equals(forward.TargetContract, "sidecar_aggregation", StringComparison.OrdinalIgnoreCase)
            || string.Equals(forward.ActiveFamily, "sidecar_aggregation", StringComparison.OrdinalIgnoreCase);
    }

    private static bool IsDedicatedLeiGod(ForwardConfig forward)
    {
        return string.Equals(forward.BackendMode, "leigod_mode", StringComparison.OrdinalIgnoreCase)
            || string.Equals(forward.TargetContract, "dedicated_forward_node", StringComparison.OrdinalIgnoreCase)
            || string.Equals(forward.ActiveFamily, "leigod_only", StringComparison.OrdinalIgnoreCase);
    }

    private static bool HasSidecarRuntimeTarget(ForwardConfig forward, string mode)
    {
        if (!forward.BondSidecarPointId.HasValue)
            return false;

        return mode switch
        {
            "bond" => HasBondSidecarTarget(forward.Bonding),
            "mptcp" => HasMptcpSidecarTarget(forward.Mptcp),
            _ => false,
        };
    }

    private static bool HasBondSidecarTarget(BondingConfig? bonding)
    {
        if (bonding is null)
            return false;

        return HasHostPort(bonding.SidecarHost, bonding.SidecarPort)
            || HasHostPort(bonding.AggregatorHost, bonding.AggregatorPort)
            || HasHostPort(bonding.RelayHost, bonding.RelayPort)
            || !string.IsNullOrWhiteSpace(bonding.RemoteTarget);
    }

    private static bool HasMptcpSidecarTarget(MptcpConfig? mptcp)
    {
        if (mptcp is null)
            return false;

        return HasHostPort(mptcp.SidecarHost, mptcp.SidecarPort)
            || HasHostPort(mptcp.RelayHost, mptcp.RelayPort)
            || HasHostPort(mptcp.RemoteHost, mptcp.RemotePort)
            || HasHostPort(mptcp.SlaveRelayHost, mptcp.SlaveRelayPort);
    }

    private static bool HasHostPort(string host, int port)
    {
        return !string.IsNullOrWhiteSpace(host) && port > 0;
    }

    private static string NormalizeModeOrEmpty(string? mode)
    {
        try
        {
            return ModeNormalizer.Normalize(mode ?? "single");
        }
        catch
        {
            return "";
        }
    }

    private static string FirstNonEmpty(params string?[] values)
    {
        foreach (var value in values)
        {
            if (!string.IsNullOrWhiteSpace(value))
                return value.Trim();
        }

        return "";
    }
}
