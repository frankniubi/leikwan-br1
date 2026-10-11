using LeikwanBridge.Core.Services;
using Xunit;

namespace LeikwanBridge.Core.Tests.Runtime;

public sealed class ConnectionRecoverySupervisorTests
{
    [Fact]
    public async Task RetriesAfterInitialFailureUntilConnectionSucceeds()
    {
        var attempts = 0;
        var supervisor = new ConnectionRecoverySupervisor(
            _ =>
            {
                var attempt = Interlocked.Increment(ref attempts);
                return attempt < 3
                    ? Task.FromException(new InvalidOperationException("control_plane_unavailable"))
                    : Task.CompletedTask;
            },
            [TimeSpan.FromMilliseconds(10), TimeSpan.FromMilliseconds(10)]);

        await supervisor.StartAsync().WaitAsync(TimeSpan.FromSeconds(2));

        Assert.Equal(3, attempts);
        await supervisor.StopAsync();
    }

    [Fact]
    public async Task StartIsIdempotentAndStopCancelsPendingRetries()
    {
        var attempts = 0;
        var supervisor = new ConnectionRecoverySupervisor(
            ct =>
            {
                Interlocked.Increment(ref attempts);
                return Task.FromException(new InvalidOperationException("control_plane_unavailable"));
            },
            [TimeSpan.FromMilliseconds(100)]);

        var first = supervisor.StartAsync();
        var second = supervisor.StartAsync();
        Assert.Same(first, second);
        await Task.Delay(30);
        await supervisor.StopAsync();
        var stoppedAttempts = attempts;
        await Task.Delay(150);

        Assert.Equal(stoppedAttempts, attempts);
        Assert.True(first.IsCompleted);
    }
}
