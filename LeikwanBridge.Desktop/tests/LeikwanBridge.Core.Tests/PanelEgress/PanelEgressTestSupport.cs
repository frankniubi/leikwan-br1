using System.Net;
using System.Net.Sockets;
using LeikwanBridge.Core.Services;
using LeikwanBridge.Core.Services.PanelEgress;

namespace LeikwanBridge.Core.Tests.PanelEgress;

internal sealed class FakeDnsResolver : ILeikwanDnsResolver
{
    public Func<string, IReadOnlyList<IPAddress>> Resolve { get; set; }
    public List<string> Hosts { get; } = [];
    public int CallCount => Hosts.Count;

    public FakeDnsResolver(params IPAddress[] addresses)
        : this(_ => addresses)
    {
    }

    public FakeDnsResolver(Func<string, IReadOnlyList<IPAddress>> resolve)
    {
        Resolve = resolve;
    }

    public Task<IReadOnlyList<IPAddress>> ResolveAAsync(string host, CancellationToken ct = default)
    {
        Hosts.Add(host);
        return Task.FromResult(Resolve(host));
    }
}

internal sealed class FakeInterfaceProvider : IPhysicalEgressInterfaceProvider
{
    public PhysicalEgressInterface? V4 { get; set; }
    public PhysicalEgressInterface? V6 { get; set; }
    public bool DefaultRoutePhysicalV4 { get; set; } = true;
    public bool DefaultRoutePhysicalV6 { get; set; } = true;

    public PhysicalEgressInterface? GetPreferredInterface(AddressFamily family)
        => family == AddressFamily.InterNetwork ? V4 : V6;

    public bool IsDefaultRoutePhysical(AddressFamily family)
        => family == AddressFamily.InterNetwork ? DefaultRoutePhysicalV4 : DefaultRoutePhysicalV6;

    public static PhysicalEgressInterface PhysicalV4(
        string name = "Ethernet", int index = 12, string gateway = "192.168.1.1")
        => new(name, index, AddressFamily.InterNetwork, IPAddress.Parse(gateway), false);
}

internal sealed class RecordingRouteExecutor : IHostRouteExecutor
{
    public RouteOperationResult AddResult { get; set; } = RouteOperationResult.Applied;
    public RouteOperationResult DeleteResult { get; set; } = RouteOperationResult.Applied;
    public List<HostRouteEntry> Additions { get; } = [];
    public List<HostRouteEntry> Deletions { get; } = [];

    public RouteOperationResult AddHostRoute(HostRouteEntry entry)
    {
        Additions.Add(entry);
        return AddResult;
    }

    public RouteOperationResult DeleteHostRoute(HostRouteEntry entry)
    {
        Deletions.Add(entry);
        return DeleteResult;
    }

    public void Clear()
    {
        Additions.Clear();
        Deletions.Clear();
    }
}

internal static class AdapterBuilder
{
    public static NetworkAdapterInfo Adapter(
        string name,
        NetworkAdapterKind kind = NetworkAdapterKind.Physical,
        int v4Index = 1,
        int v6Index = 1,
        bool isUp = true,
        int v4Metric = 25,
        int v6Metric = 25,
        IReadOnlyList<IPAddress>? gateways = null,
        IReadOnlyList<IPAddress>? addresses = null,
        string description = "")
        => new(
            name,
            description,
            v4Index,
            v6Index,
            isUp,
            kind,
            v4Metric,
            v6Metric,
            gateways ?? [],
            addresses ?? []);
}
