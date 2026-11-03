using System.Net;
using System.Net.Http;

namespace LeikwanBridge.Core.Services;

/// <summary>
/// Exponential backoff with full jitter for control-plane retries
/// (docs/plans/2026-09-24-enterprise-ha-plan.md, W1).
/// <para>
/// After the n-th consecutive failure the next attempt waits a uniformly random
/// time in [<see cref="MinimumDelay"/>, min(<see cref="Cap"/>, <see cref="Initial"/> * 2^(n-1))],
/// so a fleet that lost the panel at the same moment spreads its retries out
/// instead of hitting it together when it comes back. One success resets the
/// sequence, so a healthy panel keeps the normal fast cadence.
/// </para>
/// <para>
/// The floor of <see cref="MinimumDelay"/> keeps a zero draw from turning into an
/// immediate retry; it is far below the jittered mean and does not change the
/// shape of the distribution in any way that matters for stampede avoidance.
/// </para>
/// </summary>
public sealed class ControlPlaneBackoff
{
    public static readonly TimeSpan DefaultInitial = TimeSpan.FromSeconds(5);
    public static readonly TimeSpan DefaultCap = TimeSpan.FromSeconds(60);
    public static readonly TimeSpan DefaultMinimumDelay = TimeSpan.FromSeconds(1);

    private readonly TimeProvider _clock;
    private readonly Func<double> _random;
    private readonly object _gate = new();
    private int _consecutiveFailures;
    private DateTimeOffset _notBefore = DateTimeOffset.MinValue;

    public ControlPlaneBackoff(
        TimeProvider? clock = null,
        Func<double>? random = null,
        TimeSpan? initial = null,
        TimeSpan? cap = null,
        TimeSpan? minimumDelay = null)
    {
        _clock = clock ?? TimeProvider.System;
        _random = random ?? Random.Shared.NextDouble;
        Initial = initial ?? DefaultInitial;
        Cap = cap ?? DefaultCap;
        MinimumDelay = minimumDelay ?? DefaultMinimumDelay;
        if (Initial <= TimeSpan.Zero || Cap < Initial || MinimumDelay < TimeSpan.Zero || MinimumDelay > Initial)
            throw new ArgumentOutOfRangeException(nameof(initial), "backoff bounds are inconsistent");
    }

    public TimeSpan Initial { get; }
    public TimeSpan Cap { get; }
    public TimeSpan MinimumDelay { get; }

    public int ConsecutiveFailures
    {
        get { lock (_gate) return _consecutiveFailures; }
    }

    /// <summary>True while a failure's retry window has not elapsed yet.</summary>
    public bool IsBackingOff => RemainingDelay > TimeSpan.Zero;

    /// <summary>Time until the next attempt may run; zero when it may run now.</summary>
    public TimeSpan RemainingDelay
    {
        get
        {
            lock (_gate)
            {
                var remaining = _notBefore - _clock.GetUtcNow();
                return remaining > TimeSpan.Zero ? remaining : TimeSpan.Zero;
            }
        }
    }

    /// <summary>Upper bound of the jittered delay after <paramref name="consecutiveFailures"/> failures.</summary>
    public TimeSpan CeilingFor(int consecutiveFailures)
    {
        if (consecutiveFailures <= 0)
            return TimeSpan.Zero;
        // 2^(n-1) overflows long before it matters; clamp the exponent.
        var exponent = Math.Min(consecutiveFailures - 1, 20);
        var ticks = Initial.Ticks * (double)(1L << exponent);
        return ticks >= Cap.Ticks ? Cap : TimeSpan.FromTicks((long)ticks);
    }

    /// <summary>Records a failed attempt and returns the delay before the next one.</summary>
    public TimeSpan RecordFailure()
    {
        lock (_gate)
        {
            _consecutiveFailures = Math.Min(_consecutiveFailures + 1, 1000);
            var ceiling = CeilingFor(_consecutiveFailures);
            var draw = Math.Clamp(_random(), 0.0, 1.0);
            var delay = TimeSpan.FromTicks((long)(ceiling.Ticks * draw));
            if (delay < MinimumDelay)
                delay = MinimumDelay;
            _notBefore = _clock.GetUtcNow() + delay;
            return delay;
        }
    }

    /// <summary>
    /// Records a failure whose retry cadence is set by the answer rather than
    /// by the outage sequence (the panel refused this device because its online
    /// device limit is full). The next attempt waits <paramref name="delay"/>
    /// plus up to <paramref name="jitter"/>, every time, so the device rejoins
    /// soon after a slot frees without ramping or hammering the panel.
    /// </summary>
    public TimeSpan RecordSteadyFailure(TimeSpan delay, TimeSpan jitter)
    {
        lock (_gate)
        {
            _consecutiveFailures = Math.Min(_consecutiveFailures + 1, 1000);
            var draw = Math.Clamp(_random(), 0.0, 1.0);
            var total = delay + TimeSpan.FromTicks((long)(Math.Max(0, jitter.Ticks) * draw));
            if (total < MinimumDelay)
                total = MinimumDelay;
            _notBefore = _clock.GetUtcNow() + total;
            return total;
        }
    }

    /// <summary>Resets the sequence. Returns true when a backoff was in effect.</summary>
    public bool RecordSuccess()
    {
        lock (_gate)
        {
            var wasBackingOff = _consecutiveFailures > 0;
            _consecutiveFailures = 0;
            _notBefore = DateTimeOffset.MinValue;
            return wasBackingOff;
        }
    }

    /// <summary>
    /// Failures that mean "the control plane is unreachable or overloaded":
    /// transport errors, timeouts, 5xx (including the CDN's 52x/530) and 429.
    /// A business answer such as 401/403/409 is not a control-plane outage, and
    /// neither is the 429 client_online_limit_reached device refusal.
    /// </summary>
    public static bool IsControlPlaneFailure(Exception exception)
    {
        switch (exception)
        {
            case DeviceLimitReachedException:
                return false;
            case HttpRequestException http:
                return http.StatusCode is null || IsTransientStatus((int)http.StatusCode.Value);
            case TimeoutException:
                return true;
            case TaskCanceledException:
                // HttpClient.Timeout surfaces as TaskCanceledException; caller
                // cancellation is filtered out by the caller before this point.
                return true;
            case MasterApiException api:
                return IsTransientStatus(api.StatusCode)
                    || (api.StatusCode is >= 200 and < 300
                        && (api.Message ?? string.Empty).StartsWith("master_api_invalid_json_response", StringComparison.Ordinal));
            default:
                return exception.InnerException is { } inner && inner != exception && IsControlPlaneFailure(inner);
        }
    }

    public static bool IsTransientStatus(int statusCode)
        => statusCode >= 500 || statusCode == (int)HttpStatusCode.TooManyRequests || statusCode == (int)HttpStatusCode.RequestTimeout;
}
