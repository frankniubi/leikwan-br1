using LeikwanBridge.Core.Models;

namespace LeikwanBridge.Core.Services.Ultra;

public interface IUltraRuntimeHost
{
    void Start(ForwardConfig forward, UltraLease lease, string contractSignature, string applyReason);
    bool Refresh(ForwardConfig forward, UltraLease lease);

    void Start(ForwardConfig forward, IReadOnlyList<UltraLease> leases, string contractSignature, string applyReason)
    {
        if (leases is null || leases.Count == 0)
            throw new ArgumentException("Ultra lease candidates are required", nameof(leases));
        Start(forward, leases[0], contractSignature, applyReason);
    }

    bool Refresh(ForwardConfig forward, IReadOnlyList<UltraLease> leases)
    {
        if (leases is null || leases.Count == 0)
            return false;
        return Refresh(forward, leases[0]);
    }

    void Stop(int forwardId);

    /// <summary>
    /// Path of the status snapshot the running runtime for <paramref name="forwardId"/>
    /// writes ("status_path"), or null when the host does not know one.
    /// </summary>
    string? GetStatusPath(int forwardId) => null;
}
