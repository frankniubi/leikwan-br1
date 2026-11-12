using LeikwanBridge.Core.Services;
using Xunit;

namespace LeikwanBridge.Core.Tests.Runtime;

public sealed class SleepWakeDetectorTests
{
    private static readonly TimeSpan Tick = TimeSpan.FromSeconds(5);
    private static readonly TimeSpan Slack = TimeSpan.FromSeconds(20);
    private static readonly DateTimeOffset Start = new(2026, 11, 13, 1, 0, 0, TimeSpan.Zero);

    [Theory]
    [InlineData(5, false)]
    [InlineData(24, false)]
    [InlineData(26, true)]
    [InlineData(3600, true)]
    public void IsWake_NeedsAGapWellBeyondTheTick(int gapSeconds, bool expected)
        => Assert.Equal(expected, SleepWakeDetector.IsWake(Start, Start.AddSeconds(gapSeconds), Tick, Slack));

    [Fact]
    public void IsWake_IgnoresAClockSetBackwards()
        => Assert.False(SleepWakeDetector.IsWake(Start, Start.AddHours(-2), Tick, Slack));

    [Fact]
    public async Task Detector_ReportsTheSleptDurationOnce()
    {
        var clock = new SteppingClock(Start);
        var woke = new TaskCompletionSource<TimeSpan>(TaskCreationOptions.RunContinuationsAsynchronously);
        var wakes = 0;
        await using var detector = new SleepWakeDetector(slept =>
        {
            Interlocked.Increment(ref wakes);
            woke.TrySetResult(slept);
        }, TimeSpan.FromMilliseconds(20), TimeSpan.FromSeconds(20), clock);
        detector.Start();

        await Task.Delay(100);
        clock.Jump(TimeSpan.FromMinutes(30));
        var slept = await woke.Task.WaitAsync(TimeSpan.FromSeconds(5));
        await Task.Delay(100);

        Assert.True(slept >= TimeSpan.FromMinutes(30));
        Assert.Equal(1, Volatile.Read(ref wakes));
    }

    /// <summary>Real timers, a wall clock that can jump forward like a wake.</summary>
    private sealed class SteppingClock(DateTimeOffset start) : TimeProvider
    {
        private readonly DateTimeOffset _start = start;
        private readonly DateTimeOffset _realStart = DateTimeOffset.UtcNow;
        private long _offsetTicks;

        public void Jump(TimeSpan by) => Interlocked.Add(ref _offsetTicks, by.Ticks);

        public override DateTimeOffset GetUtcNow()
            => _start + (DateTimeOffset.UtcNow - _realStart) + TimeSpan.FromTicks(Interlocked.Read(ref _offsetTicks));
    }
}
