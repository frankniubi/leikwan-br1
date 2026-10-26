using System;
using System.Collections.Generic;
using System.Diagnostics;
using System.IO;
using System.Linq;
using System.Net;
using System.Net.Sockets;
using System.Threading;
using LeikwanBridge.Core.Helpers;
using LeikwanBridge.Core.Models;
using Serilog;

namespace LeikwanBridge.Core.Services;

/// <summary>
/// Ownership-aware preflight for a local listen port. Classifies the owner of
/// every TCP/UDP listener as managed-current, managed-old, or foreign, quiesces
/// managed-old owners, and blocks (never kills) foreign owners.
/// </summary>
public static class PortConflictPreflight
{
    /// <summary>Our managed runtime binary names; a basename is never sufficient on its own.</summary>
    public static readonly IReadOnlySet<string> ManagedRuntimeExecutableNames = new HashSet<string>(StringComparer.OrdinalIgnoreCase)
    {
        "leikwan-client-win.exe",
        "leikwan-bond.exe",
        "leikwan-ingress.exe",
        "leikwan-uot-bond.exe",
        "leikwan-mptcp-relay.exe",
        "ultra-client.exe",
        "wstunnel.exe",
        "LeikwanBridge.Mac",
        "leikwan-bond",
        "leikwan-ingress",
        "leikwan-uot-bond",
        "ultra-client",
        "wstunnel",
    };

    public static bool IsManagedRuntimeOwner(string? executablePath, string? installRoot)
    {
        if (string.IsNullOrWhiteSpace(executablePath))
            return false;

        var normalizedExecutablePath = NormalizePathSeparators(executablePath);
        var separator = normalizedExecutablePath.LastIndexOf('/');
        var fileName = separator >= 0
            ? normalizedExecutablePath[(separator + 1)..]
            : normalizedExecutablePath;
        if (string.IsNullOrWhiteSpace(fileName) || !ManagedRuntimeExecutableNames.Contains(fileName))
            return false;

        if (!string.IsNullOrWhiteSpace(installRoot))
        {
            var root = NormalizeDirectoryPath(installRoot);
            if (normalizedExecutablePath.StartsWith(root, StringComparison.OrdinalIgnoreCase))
                return true;
        }

        return normalizedExecutablePath.IndexOf("LeiKwan Bridge", StringComparison.OrdinalIgnoreCase) >= 0;
    }

    public static PortClassification Classify(
        IReadOnlyList<PortOwner> owners,
        IReadOnlyCollection<int> managedCurrentPids,
        Func<int, string?> pidToExe,
        string? installRoot)
    {
        if (owners.Count == 0)
            return new PortClassification(PortOwnerClass.Free, 0, []);

        var managedOld = new List<int>();
        var foreignPid = 0;
        var hasForeign = false;
        foreach (var owner in owners)
        {
            if (managedCurrentPids.Contains(owner.Pid))
                continue;

            if (owner.Pid <= 0 || !IsManagedRuntimeOwner(pidToExe(owner.Pid), installRoot))
            {
                hasForeign = true;
                foreignPid = owner.Pid;
                break;
            }

            managedOld.Add(owner.Pid);
        }

        if (hasForeign)
            return new PortClassification(PortOwnerClass.Foreign, foreignPid, managedOld);
        if (managedOld.Count > 0)
            return new PortClassification(PortOwnerClass.ManagedOld, 0, managedOld);
        return new PortClassification(PortOwnerClass.ManagedCurrent, 0, []);
    }

    public static PortPreflightResult Run(
        int port,
        IReadOnlyCollection<int> managedCurrentPids,
        Func<int, IReadOnlyList<PortOwner>> ownerEnumerator,
        Func<int, string?> pidToExe,
        string? installRoot,
        Action<int> killProcess,
        TimeSpan? quiesceTimeout = null,
        Func<int, string?>? classifyFreePort = null)
    {
        var classification = Classify(ownerEnumerator(port), managedCurrentPids, pidToExe, installRoot);

        switch (classification.Class)
        {
            case PortOwnerClass.Free:
                var freeConflict = (classifyFreePort ?? ClassifyFreePort)(port);
                return freeConflict is null
                    ? new PortPreflightResult(false, string.Empty, 0, [])
                    : new PortPreflightResult(true, freeConflict, 0, []);
            case PortOwnerClass.ManagedCurrent:
                return new PortPreflightResult(false, string.Empty, 0, []);
            case PortOwnerClass.Foreign:
                return new PortPreflightResult(true, "FOREIGN_PORT_IN_USE", classification.ForeignPid, []);
            case PortOwnerClass.ManagedOld:
                var quiesced = new List<int>();
                foreach (var pid in classification.ManagedOldPids.Distinct())
                {
                    try
                    {
                        killProcess(pid);
                        quiesced.Add(pid);
                    }
                    catch (Exception ex)
                    {
                        Log.Warning(ex, "failed to quiesce managed-old owner pid={Pid}", pid);
                    }
                }

                var deadline = DateTime.UtcNow + (quiesceTimeout ?? TimeSpan.FromSeconds(5));
                while (DateTime.UtcNow < deadline && ownerEnumerator(port).Count > 0)
                    Thread.Sleep(100);

                return new PortPreflightResult(false, string.Empty, 0, quiesced);
            default:
                return new PortPreflightResult(true, "FOREIGN_PORT_IN_USE", classification.ForeignPid, []);
        }
    }

    /// <summary>Binds the real preflight for production wiring; injectable in tests via the caller.</summary>
    public static Func<int, PortPreflightResult> Bind(ProcessSupervisor supervisor, string? installRoot)
    {
        return port => Run(
            port,
            ManagedPids(supervisor),
            PortOwnerEnumerator.FindOwners,
            PortOwnerEnumerator.ResolveExecutablePath,
            installRoot,
            KillTree,
            TimeSpan.FromSeconds(5),
            ClassifyFreePort);
    }

    /// <summary>
    /// Explains why a port with no visible owner still cannot be listened on.
    /// AccessDenied means Windows reserved it (excluded range). AddressAlreadyInUse
    /// with no owner means the system holds it, e.g. WSL mirrored networking.
    /// </summary>
    public static string? ClassifyFreePort(int port)
    {
        if (!OperatingSystem.IsWindows() || port is < 1 or > 65535)
            return null;

        var errors = new[]
        {
            BindError(SocketType.Stream, ProtocolType.Tcp, port),
            BindError(SocketType.Dgram, ProtocolType.Udp, port),
        };
        if (errors.Any(error => error == SocketError.AccessDenied))
            return "PORT_EXCLUDED";
        if (errors.Any(error => error == SocketError.AddressAlreadyInUse))
            return "PORT_HELD_BY_SYSTEM";
        return null;
    }

    private static SocketError? BindError(SocketType socketType, ProtocolType protocolType, int port)
    {
        using var socket = new Socket(AddressFamily.InterNetwork, socketType, protocolType);
        try
        {
            socket.Bind(new IPEndPoint(IPAddress.Loopback, port));
            return null;
        }
        catch (SocketException exception)
        {
            return exception.SocketErrorCode;
        }
        catch
        {
            return null;
        }
    }

    private static IReadOnlyCollection<int> ManagedPids(ProcessSupervisor supervisor)
    {
        var pids = new HashSet<int>();
        foreach (var list in supervisor.RunningProcesses.Values)
        {
            foreach (var managed in list)
            {
                try
                {
                    if (!managed.Process.HasExited)
                        pids.Add(managed.Process.Id);
                }
                catch
                {
                    // Ignore disposed/terminated handles.
                }
            }
        }
        return pids;
    }

    private static void KillTree(int pid)
    {
        try
        {
            using var process = Process.GetProcessById(pid);
            process.Kill(entireProcessTree: true);
            process.WaitForExit(5000);
        }
        catch (Exception ex)
        {
            Log.Warning(ex, "kill managed-old owner pid={Pid} failed", pid);
        }
    }

    private static string NormalizeDirectoryPath(string path)
        => NormalizePathSeparators(path).TrimEnd('/') + "/";

    private static string NormalizePathSeparators(string path)
        => path.Trim().Replace('\\', '/');
}
