using System.Globalization;

namespace LeikwanBridge.Core.Services;

/// <summary>
/// The `leikwan` command surface for the windowless clients. One implementation
/// shared by macOS (launchd) and Windows (SCM) so `leikwan status` and
/// `leikwan update` mean exactly the same thing on both, and so an operator
/// never has to remember the platform's service manager.
/// </summary>
public interface IClientServiceController
{
    string PlatformName { get; }

    /// <summary>Name of the registered service/daemon, for diagnostics.</summary>
    string ServiceLabel { get; }

    string StatusFilePath { get; }

    string LogDirectory { get; }

    /// <summary>Glob the CLI prefers when several clients share a log directory.</summary>
    string LogFilePattern { get; }

    string Version { get; }

    Task<ClientServiceState> QueryAsync(CancellationToken ct);

    Task<bool> StartAsync(CancellationToken ct);

    Task<bool> StopAsync(CancellationToken ct);

    Task<bool> RestartAsync(CancellationToken ct);
}

public sealed record ClientServiceState(
    string ServiceLabel,
    bool Installed,
    bool Running,
    string Detail);

public static class HeadlessCli
{
    public const string Usage =
        "usage: leikwan <status|start|stop|restart|logs|version|doctor|update> [options]\n"
        + "  status            service state, client state and forwards\n"
        + "  start             start the service or daemon\n"
        + "  stop              stop it (forwards are stopped cleanly)\n"
        + "  restart           stop then start\n"
        + "  logs [-n 40] [-f] show the client log (optionally follow)\n"
        + "  doctor            service, status file, log and panel reachability\n"
        + "  version           installed client version\n"
        + "  update [--check]  update to the published version (--check only reports)\n";

    public static async Task<int> RunAsync(
        IReadOnlyList<string> args,
        IClientServiceController service,
        IClientUpdateService? updater,
        TextWriter output,
        TextWriter error,
        CancellationToken ct = default)
    {
        if (args.Count == 0 || IsHelp(args[0]))
        {
            output.WriteLine(Usage.TrimEnd());
            return args.Count == 0 ? 2 : 0;
        }

        var command = args[0].Trim().ToLowerInvariant();
        var rest = args.Skip(1).ToList();
        switch (command)
        {
            case "status":
                return await StatusAsync(service, output, ct).ConfigureAwait(false);
            case "start":
                return await MutateAsync(service, "start", service.StartAsync, output, error, ct).ConfigureAwait(false);
            case "stop":
                return await MutateAsync(service, "stop", service.StopAsync, output, error, ct).ConfigureAwait(false);
            case "restart":
                return await MutateAsync(service, "restart", service.RestartAsync, output, error, ct).ConfigureAwait(false);
            case "logs":
                return Logs(service, rest, output, error, ct);
            case "version":
                output.WriteLine(service.Version);
                return 0;
            case "doctor":
                return await DoctorAsync(service, updater, output, ct).ConfigureAwait(false);
            case "update":
                return await UpdateAsync(service, updater, rest, output, error, ct).ConfigureAwait(false);
            default:
                error.WriteLine($"unknown command: {command}");
                error.WriteLine(Usage.TrimEnd());
                return 2;
        }
    }

    private static bool IsHelp(string value)
        => value is "-h" or "--help" or "help" or "/?";

    private static async Task<int> StatusAsync(IClientServiceController service, TextWriter output, CancellationToken ct)
    {
        var state = await service.QueryAsync(ct).ConfigureAwait(false);
        output.WriteLine($"platform : {service.PlatformName}");
        output.WriteLine($"service  : {state.ServiceLabel} — {(state.Installed ? (state.Running ? "running" : "stopped") : "not installed")}");
        var detail = FirstLine(state.Detail);
        if (!string.IsNullOrWhiteSpace(detail))
            output.WriteLine($"detail   : {detail}");

        var status = ReadStatusFile(service.StatusFilePath);
        if (status is null)
        {
            output.WriteLine("client   : no status file yet (the client has not finished starting)");
            output.WriteLine($"status   : {service.StatusFilePath}");
            return state.Running ? 0 : 1;
        }

        output.WriteLine($"client   : {(status.Connected ? "connected" : "disconnected")}"
            + (string.IsNullOrWhiteSpace(status.ClientName) ? string.Empty : $" ({status.ClientName})"));
        foreach (var forward in status.Forwards)
        {
            output.WriteLine(string.Format(
                CultureInfo.InvariantCulture,
                "  #{0,-5} {1,-9} {2,-8} {3}:{4}",
                forward.ForwardId,
                forward.Running ? "running" : "stopped",
                forward.Mode,
                "127.0.0.1",
                forward.ListenPort));
        }

        var running = status.Forwards.Count(item => item.Running);
        output.WriteLine($"forwards : {running}/{status.Forwards.Count} running");
        if (!string.IsNullOrWhiteSpace(status.LastErrorCode))
            output.WriteLine($"last error: {status.LastErrorCode}");
        return status.Connected && running > 0 ? 0 : 1;
    }

    private static async Task<int> MutateAsync(
        IClientServiceController service,
        string action,
        Func<CancellationToken, Task<bool>> operation,
        TextWriter output,
        TextWriter error,
        CancellationToken ct)
    {
        var ok = await operation(ct).ConfigureAwait(false);
        if (ok)
        {
            output.WriteLine($"{action}: ok");
            return 0;
        }

        error.WriteLine($"{action}: failed — run \"leikwan doctor\" and check the log in {service.LogDirectory}");
        return 1;
    }

    private static int Logs(
        IClientServiceController service,
        IReadOnlyList<string> args,
        TextWriter output,
        TextWriter error,
        CancellationToken ct)
    {
        var count = 40;
        var follow = false;
        for (var index = 0; index < args.Count; index++)
        {
            switch (args[index])
            {
                case "-n":
                case "--lines":
                    if (index + 1 >= args.Count
                        || !int.TryParse(args[index + 1], NumberStyles.Integer, CultureInfo.InvariantCulture, out count)
                        || count is < 1 or > 5000)
                    {
                        error.WriteLine("-n requires 1..5000");
                        return 2;
                    }

                    index++;
                    break;
                case "-f":
                case "--follow":
                    follow = true;
                    break;
                default:
                    error.WriteLine($"unknown option: {args[index]}");
                    return 2;
            }
        }

        var file = NewestLogFile(service.LogDirectory, service.LogFilePattern);
        if (file is null)
        {
            error.WriteLine($"no log file in {service.LogDirectory} yet");
            return 1;
        }

        output.WriteLine($"# {file}");
        using var stream = new FileStream(file, FileMode.Open, FileAccess.Read, FileShare.ReadWrite | FileShare.Delete);
        var lines = ReadAllLines(stream);
        foreach (var line in lines.TakeLast(count))
            output.WriteLine(line);
        if (!follow)
            return 0;

        stream.Seek(0, SeekOrigin.End);
        using var reader = new StreamReader(stream);
        while (!ct.IsCancellationRequested)
        {
            var line = reader.ReadLine();
            if (line is null)
            {
                Thread.Sleep(400);
                continue;
            }

            output.WriteLine(line);
        }

        return 0;
    }

    private static async Task<int> DoctorAsync(
        IClientServiceController service,
        IClientUpdateService? updater,
        TextWriter output,
        CancellationToken ct)
    {
        var state = await service.QueryAsync(ct).ConfigureAwait(false);
        output.WriteLine($"service   : {(state.Installed ? (state.Running ? "running" : "stopped") : "not installed")} ({state.ServiceLabel})");
        output.WriteLine($"version   : {service.Version}");
        var statusPath = service.StatusFilePath;
        output.WriteLine($"status    : {(File.Exists(statusPath) ? $"present ({statusPath})" : $"missing ({statusPath})")}");
        var log = NewestLogFile(service.LogDirectory, service.LogFilePattern);
        output.WriteLine($"log       : {(log is null ? $"none in {service.LogDirectory}" : log)}");
        if (updater is not null)
        {
            output.WriteLine($"published : {await updater.DescribeLatestAsync(ct).ConfigureAwait(false)}");
        }

        return state.Installed ? 0 : 1;
    }

    private static async Task<int> UpdateAsync(
        IClientServiceController service,
        IClientUpdateService? updater,
        IReadOnlyList<string> args,
        TextWriter output,
        TextWriter error,
        CancellationToken ct)
    {
        if (updater is null)
        {
            error.WriteLine("update is not available in this build; use the installer from the panel");
            return 1;
        }

        var checkOnly = args.Contains("--check", StringComparer.OrdinalIgnoreCase);
        var check = await updater.CheckAsync(ct).ConfigureAwait(false);
        output.WriteLine($"installed : {check.InstalledVersion}");
        // An update service that reports "no update" without a version (macOS
        // returns null when the installed build is the published one) still has
        // to print something truthful here.
        output.WriteLine($"published : {check.PublishedVersion ?? check.InstalledVersion}");
        if (!check.UpdateAvailable)
        {
            output.WriteLine("update    : already current");
            return 0;
        }

        if (checkOnly)
        {
            output.WriteLine("update    : available (run \"leikwan update\" to apply)");
            return 0;
        }

        output.WriteLine($"update    : downloading {check.ArtifactUrl}");
        var applied = await updater.ApplyAsync(check, service, output, ct).ConfigureAwait(false);
        if (!applied)
        {
            error.WriteLine("update failed; the installed version is unchanged");
            return 1;
        }

        output.WriteLine($"update    : applied {check.PublishedVersion}");
        return 0;
    }

    public static ClientStatusDocument? ReadStatusFile(string path)
    {
        try
        {
            if (!File.Exists(path))
                return null;
            var text = File.ReadAllText(path);
            return System.Text.Json.JsonSerializer.Deserialize<ClientStatusDocument>(text);
        }
        catch (Exception exception) when (exception is IOException or System.Text.Json.JsonException or UnauthorizedAccessException)
        {
            return null;
        }
    }

    public static string? NewestLogFile(string directory, string pattern = "*.log")
    {
        try
        {
            if (!Directory.Exists(directory))
                return null;
            var preferred = Newest(directory, pattern);
            return preferred ?? (pattern == "*.log" ? null : Newest(directory, "*.log"));
        }
        catch (Exception exception) when (exception is IOException or UnauthorizedAccessException)
        {
            return null;
        }
    }

    private static string? Newest(string directory, string pattern)
        => Directory.EnumerateFiles(directory, pattern)
            .Select(path => new FileInfo(path))
            .OrderByDescending(info => info.LastWriteTimeUtc)
            .FirstOrDefault()?.FullName;

    private static string FirstLine(string? value)
    {
        if (string.IsNullOrWhiteSpace(value))
            return string.Empty;
        var line = value.Split('\n').Select(part => part.Trim()).FirstOrDefault(part => part.Length > 0) ?? string.Empty;
        return line.Length > 160 ? line[..160] : line;
    }

    private static List<string> ReadAllLines(Stream stream)
    {
        var lines = new List<string>();
        using var reader = new StreamReader(stream);
        while (reader.ReadLine() is { } line)
            lines.Add(line);
        return lines;
    }
}

/// <summary>Subset of the status document the CLI prints.</summary>
public sealed class ClientStatusDocument
{
    public string Version { get; set; } = string.Empty;
    public bool Connected { get; set; }
    public string ConnectionStatus { get; set; } = string.Empty;
    public string LastErrorCode { get; set; } = string.Empty;
    public string ClientName { get; set; } = string.Empty;
    public List<ClientStatusForward> Forwards { get; set; } = [];
    public DateTimeOffset UpdatedAt { get; set; }
}

public sealed class ClientStatusForward
{
    public int ForwardId { get; set; }
    public string Name { get; set; } = string.Empty;
    public string Mode { get; set; } = string.Empty;
    public int ListenPort { get; set; }
    public bool Running { get; set; }
    public string Status { get; set; } = string.Empty;
}

public sealed record ClientUpdateCheck(
    string InstalledVersion,
    string? PublishedVersion,
    bool UpdateAvailable,
    string ArtifactUrl);

public interface IClientUpdateService
{
    Task<ClientUpdateCheck> CheckAsync(CancellationToken ct);

    Task<string> DescribeLatestAsync(CancellationToken ct);

    Task<bool> ApplyAsync(ClientUpdateCheck check, IClientServiceController service, TextWriter output, CancellationToken ct);
}
