using System.Globalization;

namespace LeikwanBridge.Core.Services;

// Command line surface of the windowless clients (macOS and Windows).
//
//   LeikwanBridge.Mac --headless --panel <url> --client-id <n> --agent-token-file <path>
//                     [--forwards all|1,2] [--log-file <path>] [--status-file <path>]
//                     [--once] [--print-status]
//
// The windowless clients run the exact same managed payload, Core services and
// lease/heartbeat code as the windowed applications; only the process lifecycle
// differs. That keeps the runtime attestation (fingerprint + self hash), the
// update pinning and the failover behaviour identical between both surfaces.
public sealed record HeadlessClientOptions
{
    public const string Flag = "--headless";

    public string PanelUrl { get; init; } = string.Empty;
    public int ClientId { get; init; }
    public string AgentToken { get; init; } = string.Empty;
    public string AgentTokenFile { get; init; } = string.Empty;
    public string Forwards { get; init; } = "all";
    public string LogFile { get; init; } = string.Empty;
    public string StatusFile { get; init; } = string.Empty;
    public bool Once { get; init; }
    public bool PrintStatus { get; init; }
    public TimeSpan OnceWindow { get; init; } = TimeSpan.FromSeconds(75);
    public TimeSpan StatusInterval { get; init; } = TimeSpan.FromSeconds(15);

    public static string Usage =>
        "usage: <client> --headless [--panel <url>] [--client-id <n>] "
        + "[--agent-token <token>|--agent-token-file <path>] [--forwards all|1,2] "
        + "[--log-file <path>] [--status-file <path>] [--once] [--print-status]";

    public static bool TryParse(
        IReadOnlyList<string> args,
        out HeadlessClientOptions options,
        out string error)
    {
        options = new HeadlessClientOptions();
        error = string.Empty;
        string panel = string.Empty;
        string token = string.Empty;
        string tokenFile = string.Empty;
        string forwards = "all";
        string logFile = string.Empty;
        string statusFile = string.Empty;
        var once = false;
        var printStatus = false;
        var onceWindow = TimeSpan.FromSeconds(75);
        var statusInterval = TimeSpan.FromSeconds(15);
        var clientId = 0;

        for (var index = 0; index < args.Count; index++)
        {
            var argument = args[index];
            if (string.Equals(argument, Flag, StringComparison.Ordinal))
                continue;
            switch (argument)
            {
                case "--panel":
                    if (!TryRead(args, ref index, out panel))
                        return Fail(out options, out error, "--panel requires a value");
                    break;
                case "--client-id":
                    if (!TryRead(args, ref index, out var rawClientId)
                        || !int.TryParse(rawClientId, NumberStyles.Integer, CultureInfo.InvariantCulture, out clientId)
                        || clientId <= 0)
                        return Fail(out options, out error, "--client-id requires a positive integer");
                    break;
                case "--agent-token":
                    if (!TryRead(args, ref index, out token))
                        return Fail(out options, out error, "--agent-token requires a value");
                    break;
                case "--agent-token-file":
                    if (!TryRead(args, ref index, out tokenFile))
                        return Fail(out options, out error, "--agent-token-file requires a value");
                    break;
                case "--forwards":
                    if (!TryRead(args, ref index, out forwards) || string.IsNullOrWhiteSpace(forwards))
                        return Fail(out options, out error, "--forwards requires a value");
                    break;
                case "--log-file":
                    if (!TryRead(args, ref index, out logFile))
                        return Fail(out options, out error, "--log-file requires a value");
                    break;
                case "--status-file":
                    if (!TryRead(args, ref index, out statusFile))
                        return Fail(out options, out error, "--status-file requires a value");
                    break;
                case "--once":
                    once = true;
                    break;
                case "--print-status":
                    printStatus = true;
                    break;
                case "--once-window":
                    if (!TryRead(args, ref index, out var rawOnceWindow)
                        || !int.TryParse(rawOnceWindow, NumberStyles.Integer, CultureInfo.InvariantCulture, out var onceSeconds)
                        || onceSeconds is < 5 or > 900)
                        return Fail(out options, out error, "--once-window requires 5..900 seconds");
                    onceWindow = TimeSpan.FromSeconds(onceSeconds);
                    break;
                case "--status-interval":
                    if (!TryRead(args, ref index, out var rawStatusInterval)
                        || !int.TryParse(rawStatusInterval, NumberStyles.Integer, CultureInfo.InvariantCulture, out var statusSeconds)
                        || statusSeconds is < 5 or > 600)
                        return Fail(out options, out error, "--status-interval requires 5..600 seconds");
                    statusInterval = TimeSpan.FromSeconds(statusSeconds);
                    break;
                default:
                    return Fail(out options, out error, $"unknown argument: {argument}");
            }
        }

        if (string.IsNullOrWhiteSpace(token) && !string.IsNullOrWhiteSpace(tokenFile))
        {
            try
            {
                token = File.ReadAllText(tokenFile).Trim();
            }
            catch (Exception exception) when (exception is IOException or UnauthorizedAccessException)
            {
                return Fail(out options, out error, $"agent token file could not be read: {exception.GetType().Name}");
            }
        }

        if (clientId <= 0 && string.IsNullOrWhiteSpace(token))
            return Fail(out options, out error, "either --client-id with an agent token, or a previously signed-in config, is required");

        if (string.IsNullOrWhiteSpace(token) && !string.IsNullOrWhiteSpace(tokenFile))
            return Fail(out options, out error, "agent token file is empty");

        if (!string.IsNullOrWhiteSpace(panel)
            && !panel.StartsWith("http://", StringComparison.OrdinalIgnoreCase)
            && !panel.StartsWith("https://", StringComparison.OrdinalIgnoreCase))
            return Fail(out options, out error, "--panel must be an absolute http(s) URL");

        if (!string.IsNullOrWhiteSpace(logFile) && !Path.IsPathFullyQualified(logFile))
            return Fail(out options, out error, "--log-file must be an absolute path");
        if (!string.IsNullOrWhiteSpace(statusFile) && !Path.IsPathFullyQualified(statusFile))
            return Fail(out options, out error, "--status-file must be an absolute path");

        options = new HeadlessClientOptions
        {
            PanelUrl = panel.Trim().TrimEnd('/'),
            ClientId = clientId,
            AgentToken = token.Trim(),
            AgentTokenFile = tokenFile.Trim(),
            Forwards = forwards.Trim(),
            LogFile = logFile.Trim(),
            StatusFile = statusFile.Trim(),
            Once = once,
            PrintStatus = printStatus,
            OnceWindow = onceWindow,
            StatusInterval = statusInterval,
        };
        return true;
    }

    public static IReadOnlyList<int> ParseForwardSelection(string value)
    {
        if (string.IsNullOrWhiteSpace(value)
            || string.Equals(value.Trim(), "all", StringComparison.OrdinalIgnoreCase))
            return [];
        var result = new List<int>();
        foreach (var part in value.Split(',', StringSplitOptions.RemoveEmptyEntries | StringSplitOptions.TrimEntries))
        {
            if (!int.TryParse(part, NumberStyles.Integer, CultureInfo.InvariantCulture, out var id) || id <= 0)
                throw new InvalidOperationException($"invalid forward id: {part}");
            if (!result.Contains(id))
                result.Add(id);
        }
        return result;
    }

    private static bool TryRead(IReadOnlyList<string> args, ref int index, out string value)
    {
        if (index + 1 >= args.Count)
        {
            value = string.Empty;
            return false;
        }

        index++;
        value = args[index];
        return true;
    }

    private static bool Fail(out HeadlessClientOptions options, out string error, string message)
    {
        options = new HeadlessClientOptions();
        error = message;
        return false;
    }
}
// The windowless clients publish the same status document so a supervisor
// (launchd, the Windows SCM, or an operator) can read one shape on both
// platforms.
public sealed record HeadlessClientForward(
    int ForwardId,
    string Name,
    string Mode,
    int ListenPort,
    bool Running,
    string Status);

public sealed record HeadlessClientStatus(
    string Version,
    bool Connected,
    string ConnectionStatus,
    string LastErrorCode,
    string ClientName,
    IReadOnlyList<HeadlessClientForward> Forwards,
    DateTimeOffset UpdatedAt);

[System.Text.Json.Serialization.JsonSerializable(typeof(HeadlessClientStatus))]
public sealed partial class HeadlessClientStatusContext : System.Text.Json.Serialization.JsonSerializerContext
{
}
