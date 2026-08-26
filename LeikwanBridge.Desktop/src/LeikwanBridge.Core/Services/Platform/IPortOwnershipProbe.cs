using LeikwanBridge.Core.Models;

namespace LeikwanBridge.Core.Services.Platform;

public interface IPortOwnershipProbe
{
    IReadOnlyList<PortOwner> FindOwners(int port);
    string? ResolveExecutablePath(int pid);
}
