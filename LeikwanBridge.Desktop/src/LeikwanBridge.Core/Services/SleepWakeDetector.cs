namespace LeikwanBridge.Core.Services;

/// <summary>
/// Detects that the machine slept: a periodic tick whose wall-clock gap is far
/// longer than its interval means the process was suspended (lid closed, idle
/// sleep). On macOS no managed sleep/wake event exists, and while the lid is
/// closed the panel long poll, the lease renewal timers and the runtime's
/// relay sockets all freeze. After a long enough sleep the installed Ultra
/// lease has expired and the relay sockets are dead, but every timer resumes
/// as if no time had passed, so the client waited out its normal backoff and
/// the user saw the line stay down after opening the lid.
/// </summary>
public sealed class SleepWakeDetector : IAsyncDisposable
{
    public static readonly TimeSpan DefaultTick = TimeSpan.FromSeconds(5);

    /// <summary>A gap this much longer than the tick counts as a sleep.</summary>
    public static readonly TimeSpan DefaultSlack = TimeSpan.FromSeconds(20);

    private readonly Action<TimeSpan> _onWake;
    private readonly TimeSpan _tick;
    private readonly TimeSpan _slack;
    private readonly TimeProvider _time;
    private readonly CancellationTokenSource _cts = new();
    private Task? _loop;

    public SleepWakeDetector(
        Action<TimeSpan> onWake,
        TimeSpan? tick = null,
        TimeSpan? slack = null,
        TimeProvider? timeProvider = null)
    {
        ArgumentNullException.ThrowIfNull(onWake);
        _onWake = onWake;
        _tick = tick ?? DefaultTick;
        _slack = slack ?? DefaultSlack;
        _time = timeProvider ?? TimeProvider.System;
    }

    /// <summary>
    /// True when the time between two ticks shows the process was suspended.
    /// A backwards jump (clock set back) is not a sleep.
    /// </summary>
    public static bool IsWake(DateTimeOffset previous, DateTimeOffset now, TimeSpan tick, TimeSpan slack)
        => now - previous > tick + slack;

    public void Start()
    {
        if (_loop is not null)
            return;
        _loop = Task.Run(RunAsync);
    }

    private async Task RunAsync()
    {
        var previous = _time.GetUtcNow();
        while (!_cts.IsCancellationRequested)
        {
            try
            {
                await Task.Delay(_tick, _time, _cts.Token).ConfigureAwait(false);
            }
            catch (OperationCanceledException)
            {
                return;
            }
            var now = _time.GetUtcNow();
            if (IsWake(previous, now, _tick, _slack))
            {
                try
                {
                    _onWake(now - previous);
                }
                catch (Exception exception)
                {
                    Serilog.Log.Warning(exception, "wake handler failed");
                }
            }
            previous = now;
        }
    }

    public async ValueTask DisposeAsync()
    {
        _cts.Cancel();
        if (_loop is not null)
        {
            try
            {
                await _loop.ConfigureAwait(false);
            }
            catch (OperationCanceledException)
            {
            }
        }
        _cts.Dispose();
    }
}
