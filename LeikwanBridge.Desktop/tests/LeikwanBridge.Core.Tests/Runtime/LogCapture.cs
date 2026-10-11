using Serilog;
using Serilog.Core;
using Serilog.Events;
using Xunit;

namespace LeikwanBridge.Core.Tests.Runtime;

/// <summary>Shared name for the collection that serializes logger swapping.</summary>
internal static class SerilogCapture
{
    public const string CollectionName = "serilog-capture";
}

/// <summary>
/// Test classes that install a <see cref="LogCapture"/> belong to this collection
/// so two captures can never interleave and steal each other's events. Events
/// from other parallel classes may still land in a capture, which is why
/// assertions must match on the forward under test.
/// </summary>
[CollectionDefinition(SerilogCapture.CollectionName)]
public sealed class SerilogCaptureCollection
{
}

/// <summary>
/// Captures events written through the static Serilog <see cref="Log"/> facade
/// and restores the previous logger on dispose. The logger is process-wide:
/// only classes in <see cref="SerilogCaptureCollection"/> may capture, and every
/// assertion must be scoped to the forward under test.
/// </summary>
internal sealed class LogCapture : IDisposable
{
    private static readonly object Gate = new();
    private readonly ILogger _previous;
    private readonly CollectingSink _sink = new();

    public LogCapture()
    {
        lock (Gate)
        {
            _previous = Log.Logger;
            Log.Logger = new LoggerConfiguration()
                .MinimumLevel.Verbose()
                .WriteTo.Sink(_sink)
                .CreateLogger();
        }
    }

    public IReadOnlyList<LogEvent> Events
    {
        get
        {
            lock (_sink.Events)
                return _sink.Events.ToList();
        }
    }

    /// <summary>
    /// Events that carry the given forward id. Assertions must filter through
    /// this helper: unrelated classes running in parallel also write into the
    /// installed sink.
    /// </summary>
    public IReadOnlyList<LogEvent> ForForward(int forwardId)
        => Events
            .Where(logEvent => logEvent.Properties.TryGetValue("ForwardId", out var value)
                && string.Equals(value.ToString().Trim('"'), forwardId.ToString(), StringComparison.Ordinal))
            .ToList();

    public void Dispose()
    {
        lock (Gate)
            Log.Logger = _previous;
    }

    private sealed class CollectingSink : ILogEventSink
    {
        public List<LogEvent> Events { get; } = [];

        public void Emit(LogEvent logEvent)
        {
            lock (Events)
                Events.Add(logEvent);
        }
    }
}
