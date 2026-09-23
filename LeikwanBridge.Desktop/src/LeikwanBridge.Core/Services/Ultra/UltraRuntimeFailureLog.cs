using System.Collections.Concurrent;
using System.Text.RegularExpressions;

namespace LeikwanBridge.Core.Services.Ultra;

/// <summary>
/// Selects the Ultra runtime stderr lines that describe a data-plane failure,
/// so they reach the ordinary (Information+) log instead of only Debug.
/// Normal stream ends and progress telemetry stay at Debug. Addresses are
/// redacted and each (forward, kind) is rate-limited per window; the runtime
/// already emits only opaque hashes for leases, candidates and targets.
/// </summary>
public sealed partial class UltraRuntimeFailureLog
{
    public const string RuntimeRole = "ultra-runtime";
    public const int MaxLineLength = 512;

    private static readonly (string Prefix, string Kind)[] FailurePrefixes =
    [
        ("tcp stream ended ", "tcp_stream_ended"),
        ("tcp stream setup failed ", "tcp_stream_setup_failed"),
        ("tcp stream had no downstream bytes ", "tcp_stream_silent"),
        ("tcp candidate failed ", "tcp_candidate_failed"),
        ("udp candidate failed ", "udp_candidate_failed"),
        ("udp session failed ", "udp_session_failed"),
        ("native tcp candidate failed ", "native_tcp_candidate_failed"),
        ("native udp candidate failed ", "native_udp_candidate_failed"),
        ("active candidate forwarding test failed ", "active_candidate_test_failed"),
        ("external UoT carrier reconnect ", "external_uot_carrier_reconnect"),
        ("policy has been missing ", "runtime_policy_missing"),
        ("runtime stopped", "runtime_stopped"),
    ];

    // Ordinary completions of a stream direction are not failures.
    private static readonly HashSet<string> BenignStreamReasons =
        new(StringComparer.Ordinal) { "complete", "eof", "canceled", "closed" };

    private readonly ConcurrentDictionary<(int ForwardId, string Kind), Window> _windows = new();
    private readonly int _maxPerWindow;
    private readonly TimeSpan _window;

    public UltraRuntimeFailureLog(int maxPerWindow = 5, TimeSpan? window = null)
    {
        _maxPerWindow = Math.Max(1, maxPerWindow);
        _window = window ?? TimeSpan.FromMinutes(1);
    }

    /// <summary>Returns the failure kind of a runtime log line, or null.</summary>
    public static string? Classify(string line)
    {
        var message = StripGoLogTimestamp(line);
        foreach (var (prefix, kind) in FailurePrefixes)
        {
            if (!message.StartsWith(prefix, StringComparison.Ordinal))
                continue;
            if (kind == "tcp_stream_ended")
            {
                var reason = ReasonPattern().Match(message);
                if (!reason.Success || BenignStreamReasons.Contains(reason.Groups[1].Value))
                    return null;
                // The local application resetting or aborting its own socket
                // (browsers and probes do this routinely) says nothing about
                // the data plane.
                var stage = StagePattern().Match(message);
                if (stage.Success && stage.Groups[1].Value.StartsWith("local_", StringComparison.Ordinal))
                    return null;
            }
            return kind;
        }
        return null;
    }

    public static string Redact(string line)
    {
        var redacted = Ipv6Pattern().Replace(Ipv4Pattern().Replace(line, "<addr>"), "<addr>");
        return redacted.Length <= MaxLineLength ? redacted : redacted[..MaxLineLength] + "...";
    }

    /// <summary>
    /// Decides whether a failure line is emitted now. When a window reopens
    /// after suppression, <paramref name="suppressed"/> reports how many lines
    /// of this kind the previous window dropped.
    /// </summary>
    public bool TryAdmit(int forwardId, string kind, DateTimeOffset now, out int suppressed)
    {
        var window = _windows.GetOrAdd((forwardId, kind), _ => new Window());
        lock (window)
        {
            suppressed = 0;
            if (window.Count == 0 || now - window.StartedAt >= _window)
            {
                suppressed = window.Suppressed;
                window.StartedAt = now;
                window.Count = 0;
                window.Suppressed = 0;
            }
            if (window.Count >= _maxPerWindow)
            {
                window.Suppressed++;
                return false;
            }
            window.Count++;
            return true;
        }
    }

    /// <summary>
    /// Emits the line through <paramref name="warn"/> when it is an admitted
    /// failure. Returns true when the line was classified as a failure.
    /// </summary>
    public bool Observe(int forwardId, string line, DateTimeOffset now, Action<string, string, int> warn)
    {
        var kind = Classify(line);
        if (kind is null)
            return false;
        if (TryAdmit(forwardId, kind, now, out var suppressed))
            warn(kind, Redact(StripGoLogTimestamp(line)), suppressed);
        return true;
    }

    private static string StripGoLogTimestamp(string line)
    {
        var match = GoLogTimestampPattern().Match(line);
        return match.Success ? line[match.Length..] : line;
    }

    private sealed class Window
    {
        public DateTimeOffset StartedAt;
        public int Count;
        public int Suppressed;
    }

    [GeneratedRegex(@"^\d{4}/\d{2}/\d{2} \d{2}:\d{2}:\d{2}(\.\d+)? ")]
    private static partial Regex GoLogTimestampPattern();

    [GeneratedRegex(@" reason=([A-Za-z0-9_]+)")]
    private static partial Regex ReasonPattern();

    [GeneratedRegex(@" stage=([A-Za-z0-9_]+)")]
    private static partial Regex StagePattern();

    [GeneratedRegex(@"\b(?:\d{1,3}\.){3}\d{1,3}(?::\d{1,5})?\b")]
    private static partial Regex Ipv4Pattern();

    // Timestamps are stripped before redaction, so any run with two or more
    // colons of hex groups (including "::" compression) is treated as IPv6.
    [GeneratedRegex(@"\[?(?:[0-9A-Fa-f]{0,4}:){2,7}[0-9A-Fa-f]{0,4}\]?(?::\d{1,5})?")]
    private static partial Regex Ipv6Pattern();
}
