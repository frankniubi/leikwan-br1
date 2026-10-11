using System.Collections.Generic;
using System.Net;
using System.Net.Sockets;
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
    public bool IsAutomatic => Id.Length == 0;

    public string DisplayName
        => string.IsNullOrWhiteSpace(Description) || string.Equals(Name, Description, StringComparison.OrdinalIgnoreCase)
            ? Name
            : $"{Name} - {Description}";

    /// <summary>Short sign-in label: adapter name plus its local IPv4 address.</summary>
    public string CompactLabel
        => IsAutomatic || LocalAddress is not { AddressFamily: AddressFamily.InterNetwork }
            ? Name
            : $"{Name} · {LocalAddress}";
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
    public const string DefaultAutomaticLabel = "Automatic";

    /// <summary>
    /// Automatic first, then every eligible adapter. Uses the same platform
    /// enumerator and <see cref="NetworkAdapterInfo.IsEgressEligible"/> rule as
    /// panel direct egress, so the list never offers a TUN/VPN or hypervisor
    /// adapter that Automatic selection would refuse.
    /// </summary>
    public static IReadOnlyList<EgressInterfaceOption> Enumerate(string? automaticLabel = null)
    {
        IReadOnlyList<NetworkAdapterInfo> adapters;
        try
        {
            adapters = SystemInterfaceEnumerator.ForCurrentPlatform().Enumerate();
        }
        catch
        {
            // An unavailable adapter snapshot must not prevent the client from
            // starting in automatic mode.
            adapters = [];
        }
        return Build(adapters, automaticLabel);
    }

    /// <summary>Pure list construction over an adapter snapshot (testable on any OS).</summary>
    public static IReadOnlyList<EgressInterfaceOption> Build(
        IReadOnlyList<NetworkAdapterInfo> adapters,
        string? automaticLabel = null)
    {
        var options = new List<EgressInterfaceOption>
        {
            new(AutomaticId, string.IsNullOrWhiteSpace(automaticLabel) ? DefaultAutomaticLabel : automaticLabel, "", 0, null, true),
        };

        foreach (var adapter in adapters ?? [])
        {
            if (adapter is null || string.IsNullOrWhiteSpace(adapter.Name) || !adapter.IsEgressEligible)
                continue;
            var localAddress = SelectLocalAddress(adapter.Addresses);
            if (localAddress is null)
                continue;
            var index = adapter.V4Index > 0 ? adapter.V4Index : adapter.V6Index;
            if (index <= 0)
                continue;
            options.Add(new EgressInterfaceOption(
                adapter.Name,
                adapter.Name,
                adapter.Description ?? string.Empty,
                index,
                localAddress,
                IsUp: true));
        }

        return options
            .GroupBy(option => option.Id, StringComparer.OrdinalIgnoreCase)
            .Select(group => group.First())
            .ToList();
    }

    public static EgressInterfaceBinding? Resolve(string? selection)
        => Resolve(Enumerate(), selection);

    public static EgressInterfaceBinding? Resolve(IReadOnlyList<EgressInterfaceOption> options, string? selection)
    {
        var option = Find(options, selection);
        return option is null
            ? null
            : new EgressInterfaceBinding(option.Name, option.Index, option.LocalAddress);
    }

    /// <summary>The eligible adapter with this id, or null for Automatic/absent.</summary>
    public static EgressInterfaceOption? Find(IReadOnlyList<EgressInterfaceOption> options, string? selection)
    {
        var normalized = selection?.Trim() ?? string.Empty;
        if (normalized.Length == 0)
            return null;
        return options
            .Where(candidate => !candidate.IsAutomatic)
            .FirstOrDefault(candidate => string.Equals(candidate.Id, normalized, StringComparison.OrdinalIgnoreCase));
    }

    private static IPAddress? SelectLocalAddress(IReadOnlyList<IPAddress> addresses)
    {
        var usable = (addresses ?? [])
            .Where(address => address is not null && !IPAddress.IsLoopback(address))
            .ToList();
        return usable.FirstOrDefault(address => address.AddressFamily == AddressFamily.InterNetwork)
            ?? usable.FirstOrDefault(address => !address.IsIPv6LinkLocal)
            ?? usable.FirstOrDefault();
    }
}

/// <summary>
/// Result of matching a saved adapter preference against the current list.
/// <see cref="SelectedId"/> is always present in the options (Automatic when
/// the saved adapter disappeared); <see cref="MissingInterface"/> names the
/// saved adapter the UI should report as unavailable.
/// </summary>
public sealed record EgressInterfaceChoice(string SelectedId, string MissingInterface)
{
    public bool FellBackToAutomatic => MissingInterface.Length > 0;
}

public static class EgressInterfaceSelection
{
    public static EgressInterfaceChoice Reconcile(
        IReadOnlyList<EgressInterfaceOption> options,
        string? savedInterface)
    {
        var saved = savedInterface?.Trim() ?? string.Empty;
        if (saved.Length == 0)
            return new EgressInterfaceChoice(EgressInterfaceCatalog.AutomaticId, string.Empty);
        var match = EgressInterfaceCatalog.Find(options, saved);
        return match is null
            ? new EgressInterfaceChoice(EgressInterfaceCatalog.AutomaticId, saved)
            : new EgressInterfaceChoice(match.Id, string.Empty);
    }
}
