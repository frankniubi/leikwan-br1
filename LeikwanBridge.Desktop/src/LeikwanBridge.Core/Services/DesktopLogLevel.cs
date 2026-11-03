using Serilog.Events;

namespace LeikwanBridge.Core.Services;

/// <summary>Supported desktop log levels and their persisted representation.</summary>
public static class DesktopLogLevel
{
    public const string Critical = "CRITICAL";
    public const string Warning = "WARNING";
    public const string Debug = "DEBUG";

    public static IReadOnlyList<string> Options { get; } = [Warning, Critical, Debug];

    public static string Normalize(string? value)
        => value?.Trim().ToUpperInvariant() switch
        {
            Critical or "FATAL" => Critical,
            Debug => Debug,
            _ => Warning,
        };

    public static LogEventLevel ToSerilog(string? value)
        => Normalize(value) switch
        {
            Critical => LogEventLevel.Fatal,
            Debug => LogEventLevel.Debug,
            _ => LogEventLevel.Warning,
        };

    public static bool IsDebug(string? value)
        => string.Equals(Normalize(value), Debug, StringComparison.Ordinal);
}
