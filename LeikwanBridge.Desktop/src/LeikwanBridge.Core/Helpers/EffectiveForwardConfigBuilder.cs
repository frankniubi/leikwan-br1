using LeikwanBridge.Core.Models;

namespace LeikwanBridge.Core.Helpers;

public static class EffectiveForwardConfigBuilder
{
    public static ForwardConfig Build(ForwardConfig forward)
    {
        return new ForwardConfig
        {
            ForwardId = forward.ForwardId,
            ServiceStatus = forward.ServiceStatus,
            Mode = forward.Mode,
            ListenPort = forward.ListenPort,
            Target = forward.Target,
            Sni = forward.Sni,
            PathPrefix = forward.PathPrefix,
            Transport = forward.Transport,
            BackendMode = forward.BackendMode,
            ContractVersion = forward.ContractVersion,
            ClientMode = forward.ClientMode,
            RuntimePreference = forward.RuntimePreference,
            ForwardCombinationDirection = forward.ForwardCombinationDirection,
            BondIngressMode = forward.BondIngressMode,
            ActiveFamily = forward.ActiveFamily,
            AggregationEnabled = forward.AggregationEnabled,
            TargetContract = forward.TargetContract,
            SidecarRequired = forward.SidecarRequired,
            BondSidecarPointId = forward.BondSidecarPointId,
            SpeedLimitMbps = forward.SpeedLimitMbps,
            Bonding = forward.Bonding,
            Mptcp = forward.Mptcp,
            UltraAccess = forward.UltraAccess,
            RuntimeLease = forward.RuntimeLease,
            NativeOutboundEnabled = forward.NativeOutboundEnabled,
            NativeDirection = forward.NativeDirection,
            NativeOutbound = forward.NativeOutbound,
            NativeRouteScope = forward.NativeRouteScope,
            AllowInboundProxy = forward.AllowInboundProxy,
            Endpoints = forward.Endpoints
                .Where(endpoint => string.Equals(endpoint.HealthStatus, "active", StringComparison.OrdinalIgnoreCase))
                .Select(endpoint => endpoint.Clone())
                .ToList(),
        };
    }
}
