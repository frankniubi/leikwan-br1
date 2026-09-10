namespace LeikwanBridge.Core.Models;

/// <summary>
/// A non-secret record of one socket owner on a local port. No provider or
/// credential material is carried here.
/// </summary>
public sealed record PortOwner(string Protocol, string Address, int Port, int Pid);

public enum PortOwnerClass
{
    Free,
    ManagedCurrent,
    ManagedOld,
    Foreign,
}

/// <summary>Result of classifying every owner of a single local port.</summary>
public readonly record struct PortClassification(
    PortOwnerClass Class,
    int ForeignPid,
    IReadOnlyList<int> ManagedOldPids);

/// <summary>Outcome of an ownership-aware port preflight.</summary>
public sealed record PortPreflightResult(
    bool Blocked,
    string ConflictCode,
    int ForeignPid,
    IReadOnlyList<int> QuiescedPids);
