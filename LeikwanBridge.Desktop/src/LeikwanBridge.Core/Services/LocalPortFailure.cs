using LeikwanBridge.Core.Helpers;
using LeikwanBridge.Core.Models;

namespace LeikwanBridge.Core.Services;

/// <summary>
/// Names why a local listen port cannot be opened, so the UI can say so instead
/// of reporting a generic timeout. Codes carry no port or process name: the UI
/// already shows the port the user picked.
/// </summary>
public static class LocalPortFailure
{
    public const string InUse = "local_port_in_use";
    public const string HeldBySystem = "local_port_held_by_system";
    public const string Reserved = "local_port_reserved";

    public static string? FromConflictCode(string? conflictCode) => conflictCode switch
    {
        "FOREIGN_PORT_IN_USE" => InUse,
        "PORT_HELD_BY_SYSTEM" => HeldBySystem,
        "PORT_EXCLUDED" => Reserved,
        _ => null,
    };

    /// <summary>Null when the port has no specific local cause, including a stale LeiKwan owner.</summary>
    public static string? Diagnose(
        int port,
        Func<int, IReadOnlyList<PortOwner>> ownerEnumerator,
        Func<int, string?> pidToExe,
        Func<int, string?> classifyFreePort)
    {
        if (port is < 1 or > 65535)
            return null;
        var owners = ownerEnumerator(port);
        if (owners.Count > 0)
        {
            var classification = PortConflictPreflight.Classify(owners, [], pidToExe, null);
            return classification.Class == PortOwnerClass.Foreign ? InUse : null;
        }
        return FromConflictCode(classifyFreePort(port));
    }

    public static string? DiagnoseLive(int port)
        => Diagnose(
            port,
            PortOwnerEnumerator.FindOwners,
            PortOwnerEnumerator.ResolveExecutablePath,
            PortConflictPreflight.ClassifyFreePort);
}
