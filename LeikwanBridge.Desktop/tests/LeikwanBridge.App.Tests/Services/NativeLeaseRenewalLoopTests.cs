using LeikwanBridge.App.Services;
using Xunit;

namespace LeikwanBridge.App.Tests.Services;

public sealed class NativeLeaseRenewalLoopTests
{
    [Fact]
    public void DefaultRefreshWindowIsSharedAtNinetySeconds()
    {
        var now = new DateTimeOffset(2026, 9, 5, 11, 54, 13, TimeSpan.Zero);

        Assert.False(NativeLeaseRenewalLoop.IsRefreshDue(now.AddSeconds(91), now));
        Assert.True(NativeLeaseRenewalLoop.IsRefreshDue(now.AddSeconds(90), now));
    }

    [Fact]
    public async Task FiveMinuteLeaseRefreshesNinetySecondsBeforeExpiryWithoutAnExternalSignal()
    {
        var now = new DateTimeOffset(2026, 9, 5, 10, 9, 1, TimeSpan.Zero);
        DateTimeOffset? expiresAt = now.AddMinutes(5);
        var delays = new List<TimeSpan>();
        var refreshes = 0;
        using var cancellation = new CancellationTokenSource();
        var loop = new NativeLeaseRenewalLoop(
            utcNow: () => now,
            delay: (delay, _) =>
            {
                delays.Add(delay);
                now += delay;
                return Task.CompletedTask;
            },
            healthCheckInterval: TimeSpan.FromMinutes(5));

        await loop.RunAsync(
            isActive: () => true,
            isDataPlaneRunning: () => true,
            leaseExpiresAt: () => expiresAt,
            refresh: _ =>
            {
                refreshes++;
                expiresAt = now.AddMinutes(5);
                cancellation.Cancel();
                return Task.CompletedTask;
            },
            onError: _ => { },
            cancellation.Token);

        Assert.Equal([TimeSpan.FromMinutes(3.5)], delays);
        Assert.Equal(1, refreshes);
    }

    [Fact]
    public async Task FailedRefreshRetriesTenSecondsLater()
    {
        var now = new DateTimeOffset(2026, 9, 5, 10, 13, 31, TimeSpan.Zero);
        DateTimeOffset? expiresAt = now.AddSeconds(30);
        var delays = new List<TimeSpan>();
        var refreshes = 0;
        var errors = new List<Exception>();
        using var cancellation = new CancellationTokenSource();
        var loop = new NativeLeaseRenewalLoop(
            utcNow: () => now,
            delay: (delay, _) =>
            {
                delays.Add(delay);
                now += delay;
                return Task.CompletedTask;
            });

        await loop.RunAsync(
            isActive: () => true,
            isDataPlaneRunning: () => true,
            leaseExpiresAt: () => expiresAt,
            refresh: _ =>
            {
                refreshes++;
                if (refreshes == 1)
                    throw new InvalidOperationException("temporary failure");
                cancellation.Cancel();
                return Task.CompletedTask;
            },
            onError: errors.Add,
            cancellation.Token);

        Assert.Equal([TimeSpan.FromSeconds(10)], delays);
        Assert.Equal(2, refreshes);
        Assert.Single(errors);
    }

    [Fact]
    public async Task DeadDataPlaneRefreshesWithinFiveSecondsEvenWhenLeaseIsFresh()
    {
        var now = new DateTimeOffset(2026, 9, 5, 10, 57, 46, TimeSpan.Zero);
        DateTimeOffset? expiresAt = now.AddMinutes(5);
        var dataPlaneRunning = true;
        var delays = new List<TimeSpan>();
        var refreshes = 0;
        using var cancellation = new CancellationTokenSource();
        var loop = new NativeLeaseRenewalLoop(
            utcNow: () => now,
            delay: (delay, _) =>
            {
                delays.Add(delay);
                now += delay;
                dataPlaneRunning = false;
                return Task.CompletedTask;
            });

        await loop.RunAsync(
            isActive: () => true,
            isDataPlaneRunning: () => dataPlaneRunning,
            leaseExpiresAt: () => expiresAt,
            refresh: _ =>
            {
                refreshes++;
                cancellation.Cancel();
                return Task.CompletedTask;
            },
            onError: _ => { },
            cancellation.Token);

        Assert.Equal([TimeSpan.FromSeconds(5)], delays);
        Assert.Equal(1, refreshes);
    }
}
