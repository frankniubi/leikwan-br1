using System.Xml.Linq;
using LeikwanBridge.App.ViewModels;
using LeikwanBridge.Core.Models;
using Xunit;

namespace LeikwanBridge.App.Tests.ViewModels;

/// <summary>The dashboard's account values: rate limit summary, home province, and the Nodes-to-services nav mapping.</summary>
public sealed class AccountDashboardTests
{
    [Fact]
    public void RateLimitIsUnlimitedWithoutForwardsOrWithoutLimits()
    {
        var vm = new MainViewModel();
        Assert.Equal("DashRateLimitNone", vm.SpeedLimitText);

        vm.ApplyForwardSnapshot([CreateForward(334, null)], _ => false);

        Assert.Equal("DashRateLimitNone", vm.SpeedLimitText);
    }

    [Fact]
    public void RateLimitShowsOneValueWhenEveryLineSharesIt()
    {
        var vm = new MainViewModel();

        vm.ApplyForwardSnapshot([CreateForward(334, 300), CreateForward(65, 300)], _ => false);

        Assert.Equal("300 Mbps", vm.SpeedLimitText);
    }

    [Fact]
    public void RateLimitSaysVariesByLineWhenLinesDiffer()
    {
        var vm = new MainViewModel();

        vm.ApplyForwardSnapshot([CreateForward(334, 300), CreateForward(65, null)], _ => false);

        Assert.Equal("DashRateLimitMixed", vm.SpeedLimitText);
    }

    [Theory]
    [InlineData("GD", "Province_GD")]
    [InlineData(" sn ", "Province_SN")]
    [InlineData("gd", "Province_GD")]
    [InlineData("XX", "DashProvinceNotSet")]
    [InlineData("", "DashProvinceNotSet")]
    [InlineData(null, "DashProvinceNotSet")]
    public void ProvinceCodeMapsToItsNameKeyOnlyForMainlandCodes(string? code, string expected)
        => Assert.Equal(expected, MainViewModel.ProvinceResourceKey(code));

    [Theory]
    [InlineData(false, false, false, "GD", "DashProvinceNotConnected")]
    [InlineData(false, true, false, "GD", "DashProvinceNotConnected")]
    [InlineData(true, false, false, "GD", "DashProvinceReading")]
    [InlineData(true, false, true, "GD", "DashProvinceReadFailed")]
    [InlineData(true, true, false, null, "DashProvinceNotSet")]
    [InlineData(true, true, false, "GD", "Province_GD")]
    [InlineData(true, true, true, "GD", "Province_GD")]
    public void ProvinceStateIsExplicitForEachCase(bool connected, bool read, bool failed, string? code, string expected)
        => Assert.Equal(expected, MainViewModel.ProvinceDisplayKey(connected, read, failed, code));

    [Fact]
    public void EveryMainlandProvinceHasANameInBothLanguages()
    {
        foreach (var file in new[] { "zh-CN.xaml", "en-US.xaml" })
        {
            var keys = XDocument.Load(AppFile("Resources", "Strings", file))
                .Descendants()
                .Select(node => (string?)node.Attribute(XNamespace.Get("http://schemas.microsoft.com/winfx/2006/xaml") + "Key"))
                .Where(key => key is not null && key.StartsWith("Province_", StringComparison.Ordinal))
                .ToHashSet(StringComparer.Ordinal);
            foreach (var code in MainViewModel.MainlandProvinceCodes)
                Assert.Contains("Province_" + code, keys);
            Assert.Equal(MainViewModel.MainlandProvinceCodes.Count, keys.Count);
        }
    }

    [Fact]
    public void NavigatingToTheOldNodesViewOpensTheConnectPage()
    {
        var vm = new MainViewModel();

        vm.NavigateToCommand.Execute("Nodes");

        Assert.Equal("Proxy", vm.CurrentView);
    }

    [Fact]
    public void NavigatingToTheOldServicesViewOpensTheConnectPage()
    {
        var vm = new MainViewModel();

        vm.NavigateToCommand.Execute("Forwards");

        Assert.Equal("Proxy", vm.CurrentView);
    }

    private static ForwardConfig CreateForward(int forwardId, double? speedLimitMbps)
        => new()
        {
            ForwardId = forwardId,
            ServiceStatus = "active",
            Mode = "single",
            ListenPort = 32216,
            Target = "198.51.100.9:443",
            Transport = "tcp",
            SpeedLimitMbps = speedLimitMbps,
        };

    private static string AppFile(params string[] parts)
    {
        var directory = new DirectoryInfo(AppContext.BaseDirectory);
        while (directory is not null)
        {
            var path = Path.Combine(new[] { directory.FullName, "LeikwanBridge.Desktop", "src", "LeikwanBridge.App" }.Concat(parts).ToArray());
            if (File.Exists(path))
                return path;
            directory = directory.Parent;
        }
        throw new DirectoryNotFoundException("repository root not found");
    }
}
