using System.Net.Sockets;
using Serilog;

namespace LeikwanBridge.Core.Services.PanelEgress;

/// <summary>
/// Production provider that dispatches to the platform-appropriate
/// implementation. Windows uses iphlpapi enumeration (gateways, metrics,
/// tunnel detection). macOS uses the managed API plus the
/// <c>route -n get default</c> probe to learn the default gateway and the
/// physical gateway while a utun TUN holds the default route.
/// </summary>
public sealed class SystemPhysicalEgressInterfaceProvider : IPhysicalEgressInterfaceProvider
{
    private readonly IPhysicalEgressInterfaceProvider _inner;

    public SystemPhysicalEgressInterfaceProvider()
    {
        if (OperatingSystem.IsWindows())
            _inner = new WindowsPhysicalEgressProvider(new WindowsInterfaceEnumerator());
        else if (OperatingSystem.IsMacOS())
            _inner = new MacPhysicalEgressProvider(new MacInterfaceEnumerator(), new MacDefaultRouteProbe());
        else
            _inner = new PassivePhysicalEgressProvider();
    }

    public PhysicalEgressInterface? GetPreferredInterface(AddressFamily family)
        => _inner.GetPreferredInterface(family);

    public bool IsDefaultRoutePhysical(AddressFamily family)
        => _inner.IsDefaultRoutePhysical(family);
}

public sealed class WindowsPhysicalEgressProvider : IPhysicalEgressInterfaceProvider
{
    private readonly ISystemInterfaceEnumerator _enumerator;

    public WindowsPhysicalEgressProvider(ISystemInterfaceEnumerator enumerator)
    {
        _enumerator = enumerator;
    }

    public PhysicalEgressInterface? GetPreferredInterface(AddressFamily family)
        => PhysicalInterfaceSelector.SelectPreferred(_enumerator.Enumerate(), family);

    public bool IsDefaultRoutePhysical(AddressFamily family)
        => PhysicalInterfaceSelector.IsDefaultRoutePhysical(_enumerator.Enumerate(), family);
}

internal sealed class MacPhysicalEgressProvider : IPhysicalEgressInterfaceProvider
{
    private readonly ISystemInterfaceEnumerator _enumerator;
    private readonly MacDefaultRouteProbe _probe;

    public MacPhysicalEgressProvider(ISystemInterfaceEnumerator enumerator, MacDefaultRouteProbe probe)
    {
        _enumerator = enumerator;
        _probe = probe;
    }

    public PhysicalEgressInterface? GetPreferredInterface(AddressFamily family)
    {
        var familyFlag = family == AddressFamily.InterNetworkV6 ? "-inet6" : "-inet";
        var selected = PhysicalInterfaceSelector.SelectPreferred(
            _enumerator.Enumerate(), family, preferMacPhysicalNaming: true);
        if (selected is null)
            return null;

        // Attach the physical gateway. When a utun TUN holds the default
        // route, ask for the route scoped to the physical interface.
        var probed = _probe.GetDefault(familyFlag, selected.Name);
        return probed is null ? selected : selected with { Gateway = probed.Gateway };
    }

    public bool IsDefaultRoutePhysical(AddressFamily family)
    {
        var familyFlag = family == AddressFamily.InterNetworkV6 ? "-inet6" : "-inet";
        var probed = _probe.GetDefault(familyFlag);
        if (probed is null)
        {
            // Unknown: assume the default already runs on a physical
            // interface so no host routes are added (zero side effects).
            return true;
        }

        var holder = _enumerator.Enumerate()
            .FirstOrDefault(adapter => string.Equals(
                adapter.Name, probed.InterfaceName, StringComparison.OrdinalIgnoreCase));
        return holder is null || !holder.IsTunnelLike;
    }
}

internal sealed class PassivePhysicalEgressProvider : IPhysicalEgressInterfaceProvider
{
    public PhysicalEgressInterface? GetPreferredInterface(AddressFamily family)
    {
        Log.Warning("panel_direct_egress platform unsupported, socket binding disabled");
        return null;
    }

    public bool IsDefaultRoutePhysical(AddressFamily family) => true;
}
