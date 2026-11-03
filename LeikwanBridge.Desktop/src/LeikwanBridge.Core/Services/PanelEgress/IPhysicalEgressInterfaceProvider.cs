using System.Net.Sockets;

namespace LeikwanBridge.Core.Services.PanelEgress;

public interface IPhysicalEgressInterfaceProvider
{
    /// <summary>
    /// Best non-TUN physical interface for egressing traffic of the given
    /// family, or null when none is available. Gateway may be null when the
    /// platform could not determine it (route fallback is skipped then; raw
    /// socket binding still works from <see cref="PhysicalEgressInterface.Index"/>).
    /// </summary>
    PhysicalEgressInterface? GetPreferredInterface(AddressFamily family);

    /// <summary>
    /// True when the current effective default route for the family already
    /// lives on a physical (non-TUN) interface, or when no default route is
    /// known. In that case no host routes should be added (zero side effects).
    /// </summary>
    bool IsDefaultRoutePhysical(AddressFamily family);

    void SetPreferredInterface(string? interfaceName) { }
}
