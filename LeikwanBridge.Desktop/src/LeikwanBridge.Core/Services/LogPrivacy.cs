using System.Net;
using System.Text.RegularExpressions;

namespace LeikwanBridge.Core.Services;

/// <summary>Removes credentials, tokens, and literal IP addresses from diagnostics.</summary>
public static partial class LogPrivacy
{
    private const string RedactedIp = "[REDACTED]";

    public static string Redact(string value)
    {
        var text = (value ?? string.Empty).Replace("\0", string.Empty);
        text = BearerPattern().Replace(text, "$1[REDACTED]");
        text = SecretPattern().Replace(text, "$1[REDACTED]");
        text = QuerySecretPattern().Replace(text, "$1[REDACTED]");
        text = Ipv4Pattern().Replace(text, match => IsAddress(match.Value) ? RedactedIp : match.Value);
        text = Ipv6CandidatePattern().Replace(text, match => IsIpv6(match.Value) ? RedactedIp : match.Value);
        return string.Join('\n', text.Split('\n').Select(line =>
            new string(line.Where(ch => ch == '\t' || ch == '\r' || ch >= ' ').ToArray())));
    }

    private static bool IsAddress(string value)
        => IPAddress.TryParse(value, out _);

    private static bool IsIpv6(string value)
    {
        var normalized = value.Trim('[', ']');
        var zoneIndex = normalized.IndexOf('%');
        if (zoneIndex >= 0)
            normalized = normalized[..zoneIndex];
        return value.Contains(':')
            && IPAddress.TryParse(normalized, out var address)
            && address.AddressFamily == System.Net.Sockets.AddressFamily.InterNetworkV6;
    }

    [GeneratedRegex("(?i)(authorization\\s*:\\s*bearer\\s+)[^\\s,;]+", RegexOptions.CultureInvariant)]
    private static partial Regex BearerPattern();

    [GeneratedRegex("(?i)(\\b(?:password|passwd|secret|token|credential|session[_-]?ticket|private[_-]?key|api[_-]?key)\\b\\s*[:=]\\s*)(?:\\\"[^\\\"]*\\\"|'[^']*'|[^\\s,;]+)", RegexOptions.CultureInvariant)]
    private static partial Regex SecretPattern();

    [GeneratedRegex("(?i)([?&](?:token|password|secret|credential|key)=)[^&\\s]+", RegexOptions.CultureInvariant)]
    private static partial Regex QuerySecretPattern();

    [GeneratedRegex("(?<![A-Za-z0-9])(?:25[0-5]|2[0-4][0-9]|1[0-9]{2}|[1-9]?[0-9])(?:\\.(?:25[0-5]|2[0-4][0-9]|1[0-9]{2}|[1-9]?[0-9])){3}(?![A-Za-z0-9])", RegexOptions.CultureInvariant)]
    private static partial Regex Ipv4Pattern();

    // Candidate matching keeps the parser authoritative, so timestamps and
    // ordinary colon-separated values are left alone.
    [GeneratedRegex("(?<![A-Za-z0-9])(?:[0-9A-Fa-f]{0,4}:){2,}[0-9A-Fa-f:.]*(?:%[A-Za-z0-9_.-]+)?(?![A-Za-z0-9])", RegexOptions.CultureInvariant)]
    private static partial Regex Ipv6CandidatePattern();
}
