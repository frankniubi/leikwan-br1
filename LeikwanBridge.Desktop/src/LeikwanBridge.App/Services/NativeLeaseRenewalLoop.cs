namespace LeikwanBridge.App.Services;

public sealed class NativeLeaseRenewalLoop
{
    public static TimeSpan DefaultRefreshLeadTime { get; } = TimeSpan.FromSeconds(90);
    private static readonly TimeSpan DefaultRetryDelay = TimeSpan.FromSeconds(10);
    private static readonly TimeSpan DefaultHealthCheckInterval = TimeSpan.FromSeconds(5);

    private readonly Func<DateTimeOffset> _utcNow;
    private readonly Func<TimeSpan, CancellationToken, Task> _delay;
    private readonly TimeSpan _refreshLeadTime;
    private readonly TimeSpan _retryDelay;
    private readonly TimeSpan _healthCheckInterval;

    public NativeLeaseRenewalLoop(
        Func<DateTimeOffset>? utcNow = null,
        Func<TimeSpan, CancellationToken, Task>? delay = null,
        TimeSpan? refreshLeadTime = null,
        TimeSpan? retryDelay = null,
        TimeSpan? healthCheckInterval = null)
    {
        _utcNow = utcNow ?? (() => DateTimeOffset.UtcNow);
        _delay = delay ?? Task.Delay;
        _refreshLeadTime = refreshLeadTime ?? DefaultRefreshLeadTime;
        _retryDelay = retryDelay ?? DefaultRetryDelay;
        _healthCheckInterval = healthCheckInterval ?? DefaultHealthCheckInterval;
        if (_refreshLeadTime <= TimeSpan.Zero)
            throw new ArgumentOutOfRangeException(nameof(refreshLeadTime));
        if (_retryDelay <= TimeSpan.Zero)
            throw new ArgumentOutOfRangeException(nameof(retryDelay));
        if (_healthCheckInterval <= TimeSpan.Zero)
            throw new ArgumentOutOfRangeException(nameof(healthCheckInterval));
    }

    public static bool IsRefreshDue(
        DateTimeOffset? leaseExpiresAt,
        DateTimeOffset now,
        TimeSpan? refreshLeadTime = null)
    {
        var leadTime = refreshLeadTime ?? DefaultRefreshLeadTime;
        if (leadTime <= TimeSpan.Zero)
            throw new ArgumentOutOfRangeException(nameof(refreshLeadTime));
        return leaseExpiresAt is not DateTimeOffset expiry
            || expiry - leadTime <= now;
    }

    public async Task RunAsync(
        Func<bool> isActive,
        Func<bool> isDataPlaneRunning,
        Func<DateTimeOffset?> leaseExpiresAt,
        Func<CancellationToken, Task> refresh,
        Action<Exception> onError,
        CancellationToken ct)
    {
        ArgumentNullException.ThrowIfNull(isActive);
        ArgumentNullException.ThrowIfNull(isDataPlaneRunning);
        ArgumentNullException.ThrowIfNull(leaseExpiresAt);
        ArgumentNullException.ThrowIfNull(refresh);
        ArgumentNullException.ThrowIfNull(onError);

        DateTimeOffset? attemptedExpiry = null;
        DateTimeOffset? lastAttemptAt = null;

        while (!ct.IsCancellationRequested && isActive())
        {
            var now = _utcNow();
            var expiry = leaseExpiresAt();
            var expiresAt = expiry.GetValueOrDefault();
            var wait = TimeSpan.Zero;
            var renewalDue = IsRefreshDue(expiry, now, _refreshLeadTime);
            if (!renewalDue && isDataPlaneRunning())
            {
                var untilRenewal = expiresAt - _refreshLeadTime - now;
                wait = untilRenewal < _healthCheckInterval
                    ? untilRenewal
                    : _healthCheckInterval;
            }
            else if (lastAttemptAt is DateTimeOffset attemptedAt
                && Nullable.Equals(attemptedExpiry, expiry))
            {
                wait = _retryDelay - (now - attemptedAt);
                if (wait < TimeSpan.Zero)
                    wait = TimeSpan.Zero;
            }

            if (wait > TimeSpan.Zero)
            {
                try
                {
                    await _delay(wait, ct).ConfigureAwait(false);
                }
                catch (OperationCanceledException) when (ct.IsCancellationRequested)
                {
                    return;
                }
                continue;
            }
            if (ct.IsCancellationRequested || !isActive())
                return;

            var expiryAtAttempt = leaseExpiresAt();
            attemptedExpiry = expiryAtAttempt;
            lastAttemptAt = _utcNow();
            try
            {
                await refresh(ct).ConfigureAwait(false);
            }
            catch (OperationCanceledException) when (ct.IsCancellationRequested)
            {
                return;
            }
            catch (Exception exception)
            {
                onError(exception);
            }

            if (!Nullable.Equals(leaseExpiresAt(), expiryAtAttempt))
            {
                attemptedExpiry = null;
                lastAttemptAt = null;
            }
        }
    }
}
