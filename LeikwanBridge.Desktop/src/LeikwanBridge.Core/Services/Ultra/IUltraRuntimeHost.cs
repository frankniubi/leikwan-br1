using LeikwanBridge.Core.Models;

namespace LeikwanBridge.Core.Services.Ultra;

public interface IUltraRuntimeHost
{
    void Start(ForwardConfig forward, UltraLease lease, string contractSignature, string applyReason);
    bool Refresh(ForwardConfig forward, UltraLease lease);
    void Stop(int forwardId);
}
