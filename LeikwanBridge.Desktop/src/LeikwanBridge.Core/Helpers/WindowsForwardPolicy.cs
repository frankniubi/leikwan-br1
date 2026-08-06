using LeikwanBridge.Core.Models;

namespace LeikwanBridge.Core.Helpers;

public static class WindowsForwardPolicy
{
    public static bool IsSupportedOnWindows(ForwardConfig forward)
    {
        var mode = NormalizeModeOrEmpty(FirstNonEmpty(forward.ClientMode, forward.Mode));
        if (HasBondingAggregationPlacement(forward))
            return UsesSlaveSidecarIngress(forward);
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

    public static bool HasBondingAggregationPlacement(ForwardConfig forward)
    {
        return !string.IsNullOrWhiteSpace(forward.Bonding?.AggregationPlacement);
    }

    public static bool UsesSlaveSidecarIngress(ForwardConfig forward)
    {
        var bonding = forward.Bonding;
        if (bonding is null
            || !string.Equals((bonding.AggregationPlacement ?? "").Trim(), "slave_sidecar", StringComparison.OrdinalIgnoreCase))
            return false;

        var mode = NormalizeModeOrEmpty(FirstNonEmpty(forward.ClientMode, forward.Mode));
        if (mode != "bond"
            || !string.Equals((forward.Transport ?? "").Trim(), "tcp", StringComparison.OrdinalIgnoreCase)
            || forward.ListenPort is < 1 or > 65535
            || !string.Equals((bonding.IngressMode ?? "").Trim(), "plain_tcp_mux", StringComparison.OrdinalIgnoreCase)
            || string.IsNullOrWhiteSpace(FirstNonEmpty(bonding.RouteKey, forward.PathPrefix)))
            return false;

        var endpoints = forward.Endpoints ?? [];
        if (endpoints.Count != 1 || endpoints[0] is null)
            return false;

        var endpoint = endpoints[0];
        return !string.IsNullOrWhiteSpace(endpoint.Host)
            && endpoint.Port is > 0 and <= 65535
            && !string.IsNullOrWhiteSpace(endpoint.IngressAuthToken);
    }

    public static string GetSidecarFrontendMode(ForwardConfig forward)
    {
        if (HasBondingAggregationPlacement(forward))
            return "";

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
        if (HasBondingAggregationPlacement(forward))
            return UsesSlaveSidecarIngress(forward);

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
            || forward.BondSidecarPointId.HasValue
            || HasBondingAggregationPlacement(forward);
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
