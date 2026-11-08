using LeikwanBridge.Core.Services.Ultra;
using Xunit;

namespace LeikwanBridge.Core.Tests.Runtime;

public sealed class UltraNetworkGraceTests
{
    [Fact]
    public void DeadlineIsAbsoluteAndNeverSlidesFromRetryTime()
    {
        var expiry = DateTimeOffset.Parse("2026-11-09T00:00:00Z");
        Assert.Null(UltraNetworkGrace.Deadline(expiry, expiry.AddSeconds(-61)));
        Assert.Equal(expiry.AddSeconds(600), UltraNetworkGrace.Deadline(expiry, expiry.AddSeconds(-60)));
        Assert.Equal(expiry.AddSeconds(600), UltraNetworkGrace.Deadline(expiry, expiry.AddSeconds(599)));
        Assert.Null(UltraNetworkGrace.Deadline(expiry, expiry.AddSeconds(600)));
    }
}
