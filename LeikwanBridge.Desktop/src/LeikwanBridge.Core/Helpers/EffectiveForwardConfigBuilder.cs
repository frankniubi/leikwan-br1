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
            SpeedLimitMbps = forward.SpeedLimitMbps,
            Bonding = forward.Bonding,
            Mptcp = forward.Mptcp,
            Endpoints = forward.Endpoints
                .Where(endpoint => string.Equals(endpoint.HealthStatus, "active", StringComparison.OrdinalIgnoreCase))
                .Select(endpoint => endpoint.Clone())
                .ToList(),
        };
    }
}
