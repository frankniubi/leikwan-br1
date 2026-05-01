using System;
using System.IO;
using LeikwanBridge.App.Services;
using Xunit;

namespace LeikwanBridge.App.Tests.Services;

public sealed class BrandingAssetsTests
{
    [Fact]
    public void ResolveTrayIconSource_PrefersPackagedIconWhenPresent()
    {
        var root = CreateTempDirectory();
        Directory.CreateDirectory(Path.Combine(root, "Resources", "Icons"));
        var iconPath = Path.Combine(root, "Resources", "Icons", "leikwan-host.ico");
        File.WriteAllBytes(iconPath, new byte[] { 0, 0, 1, 0 });

        var source = BrandingAssets.ResolveTrayIconSource(root, Path.Combine(root, "LeikwanBridge.App.exe"));

        Assert.Equal(iconPath, source);
    }

    [Fact]
    public void ResolveTrayIconSource_FallsBackToExecutableWhenPackagedIconMissing()
    {
        var root = CreateTempDirectory();
        var exePath = Path.Combine(root, "LeikwanBridge.App.exe");
        File.WriteAllBytes(exePath, new byte[] { 77, 90 });

        var source = BrandingAssets.ResolveTrayIconSource(root, exePath);

        Assert.Equal(exePath, source);
    }

    [Fact]
    public void ResolveTrayIconSource_ReturnsAbsolutePath()
    {
        var root = CreateTempDirectory();
        Directory.CreateDirectory(Path.Combine(root, "Resources", "Icons"));
        var iconPath = Path.Combine(root, "Resources", "Icons", "leikwan-host.ico");
        File.WriteAllBytes(iconPath, new byte[] { 0, 0, 1, 0 });

        var source = BrandingAssets.ResolveTrayIconSource(root, Path.Combine(root, "LeikwanBridge.App.exe"));

        Assert.True(Path.IsPathFullyQualified(source));
    }

    private static string CreateTempDirectory()
    {
        var path = Path.Combine(Path.GetTempPath(), "leikwan-branding-tests", Guid.NewGuid().ToString("N"));
        Directory.CreateDirectory(path);
        return path;
    }
}
