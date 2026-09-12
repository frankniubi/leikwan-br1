using LeikwanBridge.Core.Services.Ultra;
using Xunit;

namespace LeikwanBridge.Tests;

public class UltraRuntimeMtuTests
{
    [Fact]
    public void LeavesConfiguredMtuWhenNothingWasMeasured()
    {
        Assert.Equal(1500, UltraRuntimeMtu.Resolve(1500, 0));
        Assert.Equal(1420, UltraRuntimeMtu.Resolve(1420, 0));
        Assert.Equal(0, UltraRuntimeMtu.Resolve(0, 1440));
    }

    [Fact]
    public void ClampsToMeasuredCeilingMinusEnvelope()
    {
        // 1472-byte payload ceiling -> 1428 MTU, which is below the usual 1500.
        Assert.Equal(1428, UltraRuntimeMtu.Resolve(1500, 1472));
        // A narrow association lowers the TUN MTU instead of dropping frames.
        Assert.Equal(1396, UltraRuntimeMtu.Resolve(1500, 1440));
        Assert.Equal(1356, UltraRuntimeMtu.Resolve(1500, 1400));
    }

    [Fact]
    public void NeverRaisesTheConfiguredMtu()
    {
        Assert.Equal(1280, UltraRuntimeMtu.Resolve(1280, 2000));
        Assert.Equal(1400, UltraRuntimeMtu.Resolve(1400, 2000));
    }

    [Fact]
    public void NeverGoesBelowTheIpv6Minimum()
    {
        Assert.Equal(1280, UltraRuntimeMtu.Resolve(1500, 1300));
        Assert.Equal(1280, UltraRuntimeMtu.Resolve(1500, 1280));
    }
}
