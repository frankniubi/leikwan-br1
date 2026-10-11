using System.Diagnostics;
using System.Net;
using System.Net.NetworkInformation;

namespace LeikwanBridge.Core.Services.PanelEgress;

public sealed record MacDefaultRouteResult(IPAddress Gateway, string InterfaceName);

/// <summary>
/// macOS default-route probe built on <c>/sbin/route -n get default</c>. The
/// managed NetworkInformation API does not expose gateway addresses on macOS,
/// so the probe is the source of truth for which interface currently holds
/// the default route (including utun when a TUN tool is active).
/// </summary>
public sealed class MacDefaultRouteProbe
{
    private readonly Func<string, string> _run;

    public MacDefaultRouteProbe(Func<string, string>? run = null)
    {
        _run = run ?? Run;
    }

    public MacDefaultRouteResult? GetDefault(string familyFlag, string? interfaceScope = null)
    {
        // BSD route syntax: `route [-dnqtv] command [[modifiers] args]`.
        // Family/ifscope modifiers must follow the command word.
        var family = string.IsNullOrWhiteSpace(familyFlag) ? string.Empty : $" {familyFlag.Trim()}";
        var scope = string.IsNullOrWhiteSpace(interfaceScope) ? string.Empty : $" -ifscope {interfaceScope}";
        return ParseOutput(_run($"-n get{scope}{family} default"));
    }

    private static string Run(string arguments)
    {
        if (!OperatingSystem.IsMacOS())
            return string.Empty;
        try
        {
            using var process = new Process
            {
                StartInfo = new ProcessStartInfo("/sbin/route", arguments)
                {
                    RedirectStandardOutput = true,
                    RedirectStandardError = true,
                    UseShellExecute = false,
                    CreateNoWindow = true,
                },
            };
            process.Start();
            var output = process.StandardOutput.ReadToEnd();
            output += process.StandardError.ReadToEnd();
            process.WaitForExit(5000);
            return output;
        }
        catch
        {
            return string.Empty;
        }
    }

    /// <summary>Parses <c>route -n get default</c> output. Pure and testable on any OS.</summary>
    public static MacDefaultRouteResult? ParseOutput(string output)
    {
        if (string.IsNullOrWhiteSpace(output))
            return null;

        IPAddress? gateway = null;
        string? interfaceName = null;
        foreach (var rawLine in output.Split(['\r', '\n'], StringSplitOptions.RemoveEmptyEntries))
        {
            var line = rawLine.Trim();
            if (line.StartsWith("gateway:", StringComparison.OrdinalIgnoreCase))
            {
                var value = line["gateway:".Length..].Trim();
                var scopeSeparator = value.IndexOf('%');
                if (scopeSeparator >= 0)
                    value = value[..scopeSeparator];
                if (IPAddress.TryParse(value, out var parsed))
                    gateway = parsed;
            }
            else if (line.StartsWith("interface:", StringComparison.OrdinalIgnoreCase))
            {
                interfaceName = line["interface:".Length..].Trim();
            }
        }

        if (gateway is null || string.IsNullOrWhiteSpace(interfaceName))
            return null;
        return new MacDefaultRouteResult(gateway, interfaceName);
    }
}

/// <summary>
/// macOS adapter enumeration through the managed NetworkInformation API.
/// Gateways are intentionally empty here; <see cref="MacDefaultRouteProbe"/>
/// supplies the default gateway/interface pair, which the Mac provider
/// attaches to the matching adapter.
/// </summary>
internal sealed class MacInterfaceEnumerator : ISystemInterfaceEnumerator
{
    public IReadOnlyList<NetworkAdapterInfo> Enumerate()
    {
        if (!OperatingSystem.IsMacOS())
            return [];
        try
        {
            return EnumerateCore();
        }
        catch
        {
            return [];
        }
    }

    private static IReadOnlyList<NetworkAdapterInfo> EnumerateCore()
    {
        var adapters = new List<NetworkAdapterInfo>();
        foreach (var networkInterface in NetworkInterface.GetAllNetworkInterfaces())
        {
            var properties = networkInterface.GetIPProperties();
            var v4 = properties.GetIPv4Properties();
            var v6 = properties.GetIPv6Properties();
            var v4Index = v4?.Index ?? 0;
            var v6Index = v6?.Index ?? v4Index;
            var addresses = properties.UnicastAddresses
                .Select(unicast => unicast.Address)
                .Where(address => address is not null)
                .ToList();
            adapters.Add(new NetworkAdapterInfo(
                networkInterface.Name,
                networkInterface.Description,
                v4Index,
                v6Index,
                networkInterface.OperationalStatus == OperationalStatus.Up,
                ClassifyKind(networkInterface.NetworkInterfaceType),
                -1,
                -1,
                [],
                addresses));
        }
        return adapters;
    }

    private static NetworkAdapterKind ClassifyKind(NetworkInterfaceType type)
    {
        return type switch
        {
            NetworkInterfaceType.Ethernet
                or NetworkInterfaceType.GigabitEthernet
                or NetworkInterfaceType.FastEthernetT
                or NetworkInterfaceType.FastEthernetFx
                or NetworkInterfaceType.Wireless80211 => NetworkAdapterKind.Physical,
            NetworkInterfaceType.Tunnel => NetworkAdapterKind.Tunnel,
            NetworkInterfaceType.Loopback => NetworkAdapterKind.Loopback,
            NetworkInterfaceType.Ppp => NetworkAdapterKind.Virtual,
            _ => NetworkAdapterKind.Unknown,
        };
    }
}
