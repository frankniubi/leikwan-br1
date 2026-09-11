using System.Diagnostics;
using System.Net;
using System.Net.Sockets;
using Serilog;

namespace LeikwanBridge.Core.Services.PanelEgress;

internal sealed record RouteCommandResult(int ExitCode, string Output);

/// <summary>
/// Windows host-route executor over route.exe. Add tolerates the
/// already-exists failure and delete tolerates not-found; other failures are
/// logged and ignored so the raw socket binding remains the primary
/// mechanism (route.exe ADD normally requires elevation).
/// </summary>
public sealed class WindowsRouteCommandExecutor : IHostRouteExecutor
{
    private static readonly string[] AlreadyExistsMarkers =
    [
        "the object already exists",
        "object already exists",
    ];

    private static readonly string[] NotFoundMarkers =
    [
        "route specified was not found",
        "element not found",
    ];

    public RouteOperationResult AddHostRoute(HostRouteEntry entry)
    {
        if (!OperatingSystem.IsWindows())
            return RouteOperationResult.Skipped;

        var arguments = entry.Address.AddressFamily == AddressFamily.InterNetwork
            ? $"ADD {entry.Address} MASK 255.255.255.255 {FormatGateway(entry)} METRIC 1 IF {entry.InterfaceIndex}"
            : $"-6 ADD {entry.Address}/128 {FormatGateway(entry)} IF {entry.InterfaceIndex}";
        var result = Run("route.exe", arguments);
        if (result.ExitCode == 0)
            return RouteOperationResult.Applied;
        if (Matches(result.Output, AlreadyExistsMarkers))
            return RouteOperationResult.AlreadyPresent;
        Log.Warning(
            "panel_direct_egress route add failed address={Address} gateway={Gateway} output={Output}",
            entry.Address, entry.Gateway, result.Output.Trim());
        return RouteOperationResult.Failed;
    }

    public RouteOperationResult DeleteHostRoute(HostRouteEntry entry)
    {
        if (!OperatingSystem.IsWindows())
            return RouteOperationResult.Skipped;

        var arguments = entry.Address.AddressFamily == AddressFamily.InterNetwork
            ? $"DELETE {entry.Address} MASK 255.255.255.255 {FormatGateway(entry)}"
            : $"-6 DELETE {entry.Address}/128 {FormatGateway(entry)}";
        var result = Run("route.exe", arguments);
        if (result.ExitCode == 0)
            return RouteOperationResult.Applied;
        if (Matches(result.Output, NotFoundMarkers))
            return RouteOperationResult.NotFound;
        Log.Warning(
            "panel_direct_egress route delete failed address={Address} gateway={Gateway} output={Output}",
            entry.Address, entry.Gateway, result.Output.Trim());
        return RouteOperationResult.Failed;
    }

    private static string FormatGateway(HostRouteEntry entry)
    {
        // IPv6 link-local gateways must carry the interface scope on Windows.
        if (entry.Address.AddressFamily == AddressFamily.InterNetworkV6
            && entry.Gateway.IsIPv6LinkLocal
            && entry.Gateway.ToString().IndexOf('%') < 0
            && !string.IsNullOrWhiteSpace(entry.InterfaceName))
            return $"{entry.Gateway}%{entry.InterfaceName}";
        return entry.Gateway.ToString();
    }

    internal static RouteCommandResult Run(string executable, string arguments)
    {
        if (!OperatingSystem.IsWindows())
            return new RouteCommandResult(-1, string.Empty);
        try
        {
            using var process = new Process
            {
                StartInfo = new ProcessStartInfo(executable, arguments)
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
            process.WaitForExit(10_000);
            return new RouteCommandResult(process.HasExited ? process.ExitCode : -1, output);
        }
        catch (Exception ex)
        {
            Log.Warning(ex, "panel_direct_egress route command failed executable={Executable}", executable);
            return new RouteCommandResult(-1, ex.Message);
        }
    }

    private static bool Matches(string output, IEnumerable<string> markers)
    {
        var normalized = output.ToLowerInvariant();
        return markers.Any(marker => normalized.Contains(marker, StringComparison.Ordinal));
    }
}

/// <summary>
/// macOS host-route executor over /sbin/route. Requires root; permission
/// failures degrade to the raw socket binding only.
/// </summary>
public sealed class MacRouteCommandExecutor : IHostRouteExecutor
{
    public RouteOperationResult AddHostRoute(HostRouteEntry entry)
    {
        if (!OperatingSystem.IsMacOS())
            return RouteOperationResult.Skipped;

        var familyFlag = entry.Address.AddressFamily == AddressFamily.InterNetworkV6 ? " -inet6" : " -inet";
        var arguments = $"add{familyFlag} -host {entry.Address} {FormatGateway(entry)}";
        var result = Run("/sbin/route", arguments);
        if (result.ExitCode == 0)
            return RouteOperationResult.Applied;
        var normalized = result.Output.ToLowerInvariant();
        if (normalized.Contains("file exists", StringComparison.Ordinal))
            return RouteOperationResult.AlreadyPresent;
        if (normalized.Contains("permission denied", StringComparison.Ordinal)
            || normalized.Contains("not permitted", StringComparison.Ordinal))
        {
            Log.Warning("panel_direct_egress mac route add denied, keeping socket-only binding");
            return RouteOperationResult.Failed;
        }
        Log.Warning(
            "panel_direct_egress mac route add failed address={Address} gateway={Gateway} output={Output}",
            entry.Address, entry.Gateway, result.Output.Trim());
        return RouteOperationResult.Failed;
    }

    public RouteOperationResult DeleteHostRoute(HostRouteEntry entry)
    {
        if (!OperatingSystem.IsMacOS())
            return RouteOperationResult.Skipped;

        var familyFlag = entry.Address.AddressFamily == AddressFamily.InterNetworkV6 ? " -inet6" : " -inet";
        var arguments = $"delete{familyFlag} -host {entry.Address} {FormatGateway(entry)}";
        var result = Run("/sbin/route", arguments);
        if (result.ExitCode == 0)
            return RouteOperationResult.Applied;
        if (result.Output.ToLowerInvariant().Contains("not in table", StringComparison.Ordinal))
            return RouteOperationResult.NotFound;
        return RouteOperationResult.Failed;
    }

    private static string FormatGateway(HostRouteEntry entry)
    {
        if (entry.Address.AddressFamily == AddressFamily.InterNetworkV6
            && entry.Gateway.IsIPv6LinkLocal
            && entry.Gateway.ToString().IndexOf('%') < 0
            && !string.IsNullOrWhiteSpace(entry.InterfaceName))
            return $"{entry.Gateway}%{entry.InterfaceName}";
        return entry.Gateway.ToString();
    }

    internal static RouteCommandResult Run(string executable, string arguments)
    {
        if (!OperatingSystem.IsMacOS())
            return new RouteCommandResult(-1, string.Empty);
        try
        {
            using var process = new Process
            {
                StartInfo = new ProcessStartInfo(executable, arguments)
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
            process.WaitForExit(10_000);
            return new RouteCommandResult(process.HasExited ? process.ExitCode : -1, output);
        }
        catch (Exception ex)
        {
            Log.Warning(ex, "panel_direct_egress route command failed executable={Executable}", executable);
            return new RouteCommandResult(-1, ex.Message);
        }
    }
}
