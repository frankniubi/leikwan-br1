using LeikwanBridge.Mac.Services;
using Xunit;

namespace LeikwanBridge.Mac.Tests.Platform;

public sealed class MacPlatformPathsTests
{
    [Fact]
    public void PathsMatchTheMacUserAndBundleConventions()
    {
        var paths = new MacPlatformPaths("/Users/tester", "/Applications/LeiKwan Bridge.app", ensureDirectories: false);

        Assert.Equal("/Users/tester/Library/Application Support/LeiKwan/ClientAgent", paths.Root);
        Assert.Equal("/Users/tester/Library/Application Support/LeiKwan/ClientAgent/config.json", paths.ConfigPath);
        Assert.Equal("/Users/tester/Library/Logs/LeiKwan/ClientAgent", paths.LogDirectory);
        Assert.Equal("/Applications/LeiKwan Bridge.app", paths.BundleDirectory);
        Assert.Equal("/Applications/LeiKwan Bridge.app/Contents/Resources/bin", paths.BinDirectory);
    }

    [Fact]
    public void DeviceIdentityIsRandomStableAndMacSpecific()
    {
        using var temp = new TemporaryDirectory();
        var paths = new MacPlatformPaths(temp.Path, Path.Combine(temp.Path, "LeiKwan Bridge.app"));
        var provider = new MacPlatformIdentityProvider(paths, () => "Test Mac");

        var first = provider.GetOrCreate();
        var second = provider.GetOrCreate();

        Assert.Matches("^macos-client-[a-f0-9]{32}$", first.DeviceId);
        Assert.Equal(first, second);
        Assert.Equal("macos-desktop", first.Platform);
        Assert.Equal("Leikwan-Mac-GUI", first.UserAgentProduct);
        Assert.False(first.SupportsMptcp);
    }
}
