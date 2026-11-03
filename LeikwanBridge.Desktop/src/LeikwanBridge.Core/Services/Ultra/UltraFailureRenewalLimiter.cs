using System.Collections.Concurrent;

namespace LeikwanBridge.Core.Services.Ultra;

/// <summary>
/// Paces failure-driven Ultra lease renewals per forward.
/// <para>
/// When the source host re-acquires a line it invalidates the credential the
/// runtime holds and uploads the new one within seconds. Every stream on that
/// line then fails, so one re-acquisition produces a burst of failure lines;
/// it must produce one renewal. The first signal is admitted at once; the next
/// one no sooner than <see cref="MinInterval"/> later (a renewal that raced the
/// upload and fetched the old credential is repeated after that interval).
/// While signals keep coming the interval doubles up to <see cref="MaxInterval"/>,
/// so a line that is broken for another reason cannot turn into a renewal loop.
/// After <see cref="QuietReset"/> without any signal the forward starts again at
/// <see cref="MinInterval"/>, so the next re-acquisition (~20 min later) is
/// handled as fast as the first.
/// </para>
/// </summary>
public sealed class UltraFailureRenewalLimiter
{
    public static readonly TimeSpan DefaultMinInterval = TimeSpan.FromSeconds(20);
    public static readonly TimeSpan DefaultMaxInterval = TimeSpan.FromSeconds(160);
    public static readonly TimeSpan DefaultQuietReset = TimeSpan.FromMinutes(2);

    private readonly ConcurrentDictionary<int, State> _states = new();

    public UltraFailureRenewalLimiter(TimeSpan? minInterval = null, TimeSpan? maxInterval = null, TimeSpan? quietReset = null)
    {
        MinInterval = minInterval ?? DefaultMinInterval;
        MaxInterval = maxInterval ?? DefaultMaxInterval;
        QuietReset = quietReset ?? DefaultQuietReset;
        if (MinInterval <= TimeSpan.Zero || MaxInterval < MinInterval || QuietReset <= MinInterval)
            throw new ArgumentOutOfRangeException(nameof(minInterval), "renewal limiter bounds are inconsistent");
    }

    public TimeSpan MinInterval { get; }
    public TimeSpan MaxInterval { get; }
    public TimeSpan QuietReset { get; }

    /// <summary>
    /// Records a failure signal for <paramref name="forwardId"/> and returns
    /// true when it may trigger a renewal now. <paramref name="nextAllowedIn"/>
    /// is the time until the next signal could be admitted.
    /// </summary>
    public bool TryAdmit(int forwardId, DateTimeOffset now, out TimeSpan nextAllowedIn)
    {
        var state = _states.GetOrAdd(forwardId, static _ => new State());
        lock (state)
        {
            if (state.LastSignal == DateTimeOffset.MinValue || now - state.LastSignal >= QuietReset)
            {
                // The previous burst is over (QuietReset > MinInterval, so the
                // once-per-MinInterval bound still holds).
                state.Interval = TimeSpan.Zero;
                state.NextAllowed = DateTimeOffset.MinValue;
            }
            state.LastSignal = now;
            if (now < state.NextAllowed)
            {
                nextAllowedIn = state.NextAllowed - now;
                return false;
            }
            state.Interval = state.Interval == TimeSpan.Zero
                ? MinInterval
                : TimeSpan.FromTicks(Math.Min(state.Interval.Ticks * 2, MaxInterval.Ticks));
            state.NextAllowed = now + state.Interval;
            nextAllowedIn = state.Interval;
            return true;
        }
    }

    /// <summary>Forgets a forward (removed or stopped).</summary>
    public void Forget(int forwardId) => _states.TryRemove(forwardId, out _);

    private sealed class State
    {
        public DateTimeOffset LastSignal = DateTimeOffset.MinValue;
        public DateTimeOffset NextAllowed = DateTimeOffset.MinValue;
        public TimeSpan Interval = TimeSpan.Zero;
    }
}
