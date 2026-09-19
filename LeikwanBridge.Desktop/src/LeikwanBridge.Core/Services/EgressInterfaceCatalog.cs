using System.Collections.Generic;
using System.Net;
using System.Net.NetworkInformation;
using LeikwanBridge.Core.Services.PanelEgress;

namespace LeikwanBridge.Core.Services;

/// <summary>
/// A user-selectable physical egress interface. The interface name is used as
/// the persisted key because it is shared by the managed enumerator, the
/// native helper configuration, and the platform route planner.
/// </summary>
public sealed record EgressInterfaceOption(
    string Id,
    string Name,
    string Description,
    int Index,
    IPAddress? LocalAddress,
    bool IsUp)
{
    public string DisplayName
        => string.IsNullOrWhiteSpace(Description) || string.Equals(Name, Description, StringComparison.OrdinalIgnoreCase)
            ? Name
            : $"{Name} - {Description}";
}

public sealed record EgressInterfaceBinding(
    string Name,
    int Index,
    IPAddress? LocalAddress)
{
    public bool IsUsable => !string.IsNullOrWhiteSpace(Name) && Index > 0;
}

public static class EgressInterfaceConfig
{
    public static EgressInterfaceBinding? ResolveOrThrow(string? selection)
    {
        var normalized = selection?.Trim() ?? string.Empty;
        if (normalized.Length == 0)
            return null;
        return EgressInterfaceCatalog.Resolve(normalized)
            ?? throw new InvalidOperationException("egress_interface_unavailable");
    }

    public static void Apply(
        IDictionary<string, object?> config,
        string? selection,
        EgressInterfaceBinding? binding = null)
    {
        ArgumentNullException.ThrowIfNull(config);
        var resolved = binding ?? ResolveOrThrow(selection);
        if (resolved is null)
            return;
        config["egress_interface"] = resolved.Name;
        config["egress_interface_index"] = resolved.Index;
        config["egress_local_address"] = resolved.LocalAddress?.ToString() ?? string.Empty;
    }
}

public static class EgressInterfaceCatalog
{
    public const string AutomaticId = "";

    public static IReadOnlyList<EgressInterfaceOption> Enumerate()
    {
        var options = new List<EgressInterfaceOption>
        {
            new(AutomaticId, "Automatic", "", 0, null, true),
        };

        if (!OperatingSystem.IsWindows() && !OperatingSystem.IsMacOS())
            return options;

        try
        {
            foreach (var networkInterface in NetworkInterface.GetAllNetworkInterfaces())
            {
                try
                {
                    if (networkInterface.OperationalStatus != OperationalStatus.Up
                        || networkInterface.NetworkInterfaceType is NetworkInterfaceType.Loopback
                            or NetworkInterfaceType.Tunnel
                            or NetworkInterfaceType.Ppp
                        || TunnelInterfaceHeuristics.IsTunnelLikeName(
                            networkInterface.Name,
                            networkInterface.Description))
                        continue;

                    var properties = networkInterface.GetIPProperties();
                    var localAddress = SelectLocalAddress(properties);
                    if (localAddress is null)
                        continue;
                    var index = properties.GetIPv4Properties()?.Index
                        ?? properties.GetIPv6Properties()?.Index
                        ?? 0;
                    if (index <= 0)
                        continue;

                    options.Add(new EgressInterfaceOption(
                        networkInterface.Name,
                        networkInterface.Name,
                        networkInterface.Description,
                        index,
                        localAddress,
                        IsUp: true));
                }
                catch
                {
                    // One adapter can disappear while the OS snapshot is read.
                }
            }
        }
        catch
        {
            // An unavailable adapter snapshot must not prevent the client from
            // starting in automatic mode.
        }

        return options
            .GroupBy(option => option.Id, StringComparer.OrdinalIgnoreCase)
            .Select(group => group.First())
            .ToList();
    }

    public static EgressInterfaceBinding? Resolve(string? selection)
    {
        var normalized = selection?.Trim() ?? string.Empty;
        if (normalized.Length == 0)
            return null;

        var option = Enumerate()
            .Skip(1)
            .FirstOrDefault(candidate => string.Equals(candidate.Id, normalized, StringComparison.OrdinalIgnoreCase));
        return option is null
            ? null
            : new EgressInterfaceBinding(option.Name, option.Index, option.LocalAddress);
    }

    private static IPAddress? SelectLocalAddress(IPInterfaceProperties properties)
    {
        var addresses = properties.UnicastAddresses
            .Select(item => item.Address)
            .Where(address => address is not null && !IPAddress.IsLoopback(address))
            .ToList();
        return addresses.FirstOrDefault(address => address.AddressFamily == System.Net.Sockets.AddressFamily.InterNetwork
                && !address.IsIPv6LinkLocal)
            ?? addresses.FirstOrDefault();
    }
}
