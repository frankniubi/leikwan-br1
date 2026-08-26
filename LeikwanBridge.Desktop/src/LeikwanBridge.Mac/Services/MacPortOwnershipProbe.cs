using System.Diagnostics;
using LeikwanBridge.Core.Models;
using LeikwanBridge.Core.Services.Platform;

namespace LeikwanBridge.Mac.Services;

public sealed class MacPortOwnershipProbe : IPortOwnershipProbe
{
    private readonly IMacCommandRunner _runner;

    public MacPortOwnershipProbe(IMacCommandRunner? runner = null)
    {
        _runner = runner ?? new MacCommandRunner();
    }

    public IReadOnlyList<PortOwner> FindOwners(int port)
    {
        if (port is < 1 or > 65535)
            throw new ArgumentOutOfRangeException(nameof(port));
        var owners = new List<PortOwner>();
        owners.AddRange(QueryOwners("tcp", port));
        owners.AddRange(QueryOwners("udp", port));
        return owners.Distinct().OrderBy(owner => owner.Pid).ToList();
    }

    public string? ResolveExecutablePath(int pid)
    {
        if (pid <= 0)
            return null;
        var startInfo = NewStartInfo("/bin/ps");
        startInfo.ArgumentList.Add("-p");
        startInfo.ArgumentList.Add(pid.ToString(System.Globalization.CultureInfo.InvariantCulture));
        startInfo.ArgumentList.Add("-o");
        startInfo.ArgumentList.Add("comm=");
        var result = _runner.RunAsync(startInfo, CancellationToken.None).GetAwaiter().GetResult();
        if (result.ExitCode != 0)
            return null;
        var path = result.StandardOutput.Trim();
        return path.Length is > 0 and <= 4096 ? path : null;
    }

    private IEnumerable<PortOwner> QueryOwners(string protocol, int port)
    {
        var startInfo = NewStartInfo("/usr/sbin/lsof");
        startInfo.ArgumentList.Add("-nP");
        if (protocol == "tcp")
        {
            startInfo.ArgumentList.Add($"-iTCP:{port}");
            startInfo.ArgumentList.Add("-sTCP:LISTEN");
        }
        else
        {
            startInfo.ArgumentList.Add($"-iUDP:{port}");
        }
        startInfo.ArgumentList.Add("-Fpcu");
        var result = _runner.RunAsync(startInfo, CancellationToken.None).GetAwaiter().GetResult();
        if (result.ExitCode is not (0 or 1))
            return [];
        return ParseProcessIds(result.StandardOutput)
            .Select(pid => new PortOwner(protocol, "*", port, pid));
    }

    private static IEnumerable<int> ParseProcessIds(string output)
    {
        foreach (var line in output.Split('\n', StringSplitOptions.RemoveEmptyEntries | StringSplitOptions.TrimEntries))
        {
            if (line.Length > 1 && line[0] == 'p' && int.TryParse(line.AsSpan(1), out var pid) && pid > 0)
                yield return pid;
        }
    }

    private static ProcessStartInfo NewStartInfo(string fileName)
    {
        return new ProcessStartInfo
        {
            FileName = fileName,
            UseShellExecute = false,
            CreateNoWindow = true,
            RedirectStandardOutput = true,
            RedirectStandardError = true,
        };
    }
}
