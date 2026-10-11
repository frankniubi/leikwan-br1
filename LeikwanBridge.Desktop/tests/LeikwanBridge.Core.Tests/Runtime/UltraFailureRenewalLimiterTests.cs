using LeikwanBridge.Core.Services.Ultra;
using Xunit;

namespace LeikwanBridge.Core.Tests.Runtime;

public sealed class UltraFailureRenewalLimiterTests
{
    private static readonly DateTimeOffset Start = DateTimeOffset.Parse("2026-09-24T09:02:22Z");

    [Fact]
    public void ABurstIsOneRenewalAndTheNextComesNoSoonerThanTwentySeconds()
    {
        var limiter = new UltraFailureRenewalLimiter();

        Assert.True(limiter.TryAdmit(243, Start, out var next));
        Assert.Equal(TimeSpan.FromSeconds(20), next);
        // Every stream on the line fails within the same second.
        for (var i = 0; i < 200; i++)
            Assert.False(limiter.TryAdmit(243, Start.AddMilliseconds(5 * i), out _));
        Assert.False(limiter.TryAdmit(243, Start.AddSeconds(19.9), out var remaining));
        Assert.InRange(remaining, TimeSpan.FromMilliseconds(99), TimeSpan.FromMilliseconds(101));

        // Still failing 20 s later (the first renewal raced the upload): one more.
        Assert.True(limiter.TryAdmit(243, Start.AddSeconds(20), out _));
    }

    [Fact]
    public void ForwardsArePacedIndependently()
    {
        var limiter = new UltraFailureRenewalLimiter();
        Assert.True(limiter.TryAdmit(242, Start, out _));
        Assert.True(limiter.TryAdmit(243, Start, out _));
        Assert.False(limiter.TryAdmit(242, Start.AddSeconds(1), out _));
    }

    [Fact]
    public void PersistentFailuresBackOffToTheCapAndQuietResetsTheInterval()
    {
        var limiter = new UltraFailureRenewalLimiter();
        var now = Start;
        var admittedAt = new List<double>();
        // A line broken for another reason: a failure every second for 12 min.
        for (var second = 0; second < 720; second++)
        {
            if (limiter.TryAdmit(56, now.AddSeconds(second), out _))
                admittedAt.Add(second);
        }
        // 20, 40, 80, then capped at 160 s.
        Assert.Equal(new double[] { 0, 20, 60, 140, 300, 460, 620 }, admittedAt);

        // Two quiet minutes end the burst; the next re-acquisition is served at once.
        var later = now.AddSeconds(719 + 120);
        Assert.True(limiter.TryAdmit(56, later, out var next));
        Assert.Equal(TimeSpan.FromSeconds(20), next);
    }

    [Fact]
    public void InconsistentBoundsAreRejected()
    {
        Assert.Throws<ArgumentOutOfRangeException>(() => new UltraFailureRenewalLimiter(minInterval: TimeSpan.Zero));
        Assert.Throws<ArgumentOutOfRangeException>(() => new UltraFailureRenewalLimiter(
            minInterval: TimeSpan.FromSeconds(30), maxInterval: TimeSpan.FromSeconds(10)));
        Assert.Throws<ArgumentOutOfRangeException>(() => new UltraFailureRenewalLimiter(quietReset: TimeSpan.FromSeconds(20)));
    }
}
