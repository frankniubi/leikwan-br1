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

    [Fact]
    public void WindowsUiDoesNotExposeProviderBranding()
    {
        var repoRoot = FindRepositoryRoot();
        var appRoot = Path.Combine(repoRoot, "LeikwanBridge.Desktop", "src", "LeikwanBridge.App");
        var files = Directory.EnumerateFiles(appRoot, "*.*", SearchOption.AllDirectories)
            .Where(path => path.EndsWith(".cs", StringComparison.OrdinalIgnoreCase)
                || path.EndsWith(".xaml", StringComparison.OrdinalIgnoreCase));

        foreach (var file in files)
        {
            var source = File.ReadAllText(file);
            Assert.DoesNotContain("leigod", source, StringComparison.OrdinalIgnoreCase);
            Assert.DoesNotContain("雷神", source, StringComparison.Ordinal);
        }
    }

    [Fact]
    public void WindowsLoginUiUsesAgentTokenOnly()
    {
        var repoRoot = FindRepositoryRoot();
        var appRoot = Path.Combine(repoRoot, "LeikwanBridge.Desktop", "src", "LeikwanBridge.App");
        var xaml = File.ReadAllText(Path.Combine(appRoot, "Views", "MainWindow.xaml"));
        var codeBehind = File.ReadAllText(Path.Combine(appRoot, "Views", "MainWindow.xaml.cs"));

        Assert.Contains("TxtClientId", xaml, StringComparison.Ordinal);
        Assert.Contains("TxtAgentToken", xaml, StringComparison.Ordinal);
        Assert.DoesNotContain("TxtEmail", xaml, StringComparison.Ordinal);
        Assert.DoesNotContain("TxtPassword", xaml, StringComparison.Ordinal);
        Assert.DoesNotContain("ClientPickerOverlay", xaml, StringComparison.Ordinal);
        Assert.DoesNotContain("DesktopLoginAsync", codeBehind, StringComparison.Ordinal);
        Assert.DoesNotContain("SelectClientAsync", codeBehind, StringComparison.Ordinal);
    }

    [Fact]
    public void WindowsMasterUrlInputsNormalizeRetiredMirrorBeforeSaving()
    {
        var repoRoot = FindRepositoryRoot();
        var codeBehind = File.ReadAllText(Path.Combine(
            repoRoot,
            "LeikwanBridge.Desktop",
            "src",
            "LeikwanBridge.App",
            "Views",
            "MainWindow.xaml.cs"));

        Assert.Contains("AppDefaults.NormalizeMasterUrl(TxtMasterUrl.Text)", codeBehind, StringComparison.Ordinal);
        Assert.Contains("AppDefaults.NormalizeMasterUrl(SettMasterUrl.Text)", codeBehind, StringComparison.Ordinal);
    }

    private static string FindRepositoryRoot()
    {
        var directory = new DirectoryInfo(Directory.GetCurrentDirectory());
        while (directory is not null && !File.Exists(Path.Combine(directory.FullName, ".git", "HEAD")))
            directory = directory.Parent;
        return directory?.FullName ?? throw new DirectoryNotFoundException("repository root not found");
    }

    private static string CreateTempDirectory()
    {
        var path = Path.Combine(Path.GetTempPath(), "leikwan-branding-tests", Guid.NewGuid().ToString("N"));
        Directory.CreateDirectory(path);
        return path;
    }
}
