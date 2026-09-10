using System.Net;
using System.Net.Sockets;
using LeikwanBridge.Core.Models;

namespace LeikwanBridge.Mac.Services;

public sealed record MacBypassResolution(
    IReadOnlyList<string> Hosts,
    IReadOnlyList<string> Ips)
{
    public IReadOnlyList<string> All => Hosts
        .Concat(Ips)
        .Distinct(StringComparer.OrdinalIgnoreCase)
        .ToList();
}

public sealed class MacBypassResolver
{
    private const int MaxHosts = 256;

    public async Task<MacBypassResolution> ResolveAsync(
        string panelUrl,
        IEnumerable<ForwardConfig> forwards,
        CancellationToken ct = default)
    {
        var hosts = CollectHosts(panelUrl, forwards).ToList();
        var ips = new HashSet<string>(StringComparer.OrdinalIgnoreCase);
        foreach (var host in hosts)
        {
            ct.ThrowIfCancellationRequested();
            if (IPAddress.TryParse(host, out var address))
            {
                ips.Add(address.ToString());
                continue;
            }
            try
            {
                foreach (var resolved in await Dns.GetHostAddressesAsync(host, ct))
                    ips.Add(resolved.ToString());
            }
            catch (SocketException)
            {
                // A stale node hostname must not prevent the rest of the routes from starting.
            }
            catch (ArgumentException)
            {
            }
        }
        return new MacBypassResolution(hosts, ips.Take(MaxHosts).ToList());
    }

    public static IReadOnlySet<string> CollectHosts(string panelUrl, IEnumerable<ForwardConfig> forwards)
    {
        var values = new HashSet<string>(StringComparer.OrdinalIgnoreCase);
        if (Uri.TryCreate(panelUrl?.Trim(), UriKind.Absolute, out var panel)
            && !string.IsNullOrWhiteSpace(panel.Host))
            values.Add(panel.Host.TrimEnd('.'));

        foreach (var forward in (forwards ?? []).Take(128))
        {
            var backend = (forward.BackendMode ?? string.Empty).Trim().ToLowerInvariant();
            if (backend is "ultra" or "current_mode" or "")
            {
                Add(values, forward.Target);
                Add(values, forward.Sni);
                foreach (var endpoint in (forward.Endpoints ?? []).Take(32))
                {
                    Add(values, endpoint.Host);
                    Add(values, endpoint.CdnHost);
                }
                var bonding = forward.Bonding;
                if (bonding is not null)
                {
                    Add(values, bonding.SidecarHost);
                    Add(values, bonding.AggregatorHost);
                    Add(values, bonding.RelayHost);
                    Add(values, bonding.RemoteTarget);
                    Add(values, bonding.QuicServerName);
                }
            }
            if (backend == "ultra")
                Add(values, forward.RuntimeLease?.ProviderIp);
        }
        return values.Take(MaxHosts).ToHashSet(StringComparer.OrdinalIgnoreCase);
    }

    private static void Add(ISet<string> values, string? value)
    {
        var normalized = value?.Trim().TrimEnd('.') ?? string.Empty;
        if (normalized.Length == 0 || normalized.Length > 255)
            return;
        if (normalized.Contains(':') && normalized.Count(character => character == ':') == 1)
        {
            if (Uri.TryCreate("tcp://" + normalized, UriKind.Absolute, out var hostPort)
                && !string.IsNullOrWhiteSpace(hostPort.Host))
                normalized = hostPort.Host;
            else
                return;
        }
        else if (normalized.Contains(':'))
        {
            if (!IPAddress.TryParse(normalized, out _))
                return;
        }
        if (normalized.Contains('/') && !IPAddress.TryParse(normalized.Split('/')[0], out _))
            return;
        if (Uri.CheckHostName(normalized.Split('/')[0]) is UriHostNameType.Unknown)
            return;
        values.Add(normalized);
    }
}
