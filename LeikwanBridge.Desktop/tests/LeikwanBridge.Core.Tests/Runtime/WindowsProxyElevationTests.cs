using LeikwanBridge.Core.Models;
using LeikwanBridge.Core.Services;
using Xunit;

namespace LeikwanBridge.Core.Tests.Runtime;

public sealed class WindowsProxyElevationTests
{
    [Fact]
    public void BuildRelaunchStartInfoRequestsRunAsAndCarriesProxySelection()
    {
        var startInfo = WindowsProxyElevation.BuildRelaunchStartInfo(
            @"C:\Program Files\LeiKwan Bridge\leikwan-client-win.exe",
            new WindowsProxyStartRequest(WindowsProxyMode.Process, 7, "steam:counter-strike"));

        Assert.True(startInfo.UseShellExecute);
        Assert.Equal("runas", startInfo.Verb);
        Assert.Equal(
            @"C:\Program Files\LeiKwan Bridge",
            startInfo.WorkingDirectory);
        Assert.Equal(
            [
                WindowsProxyElevation.ProxyStartArgument,
                "Process",
                "7",
                "steam:counter-strike",
            ],
            startInfo.ArgumentList);
    }

    [Fact]
    public void ParseProxyStartRequestRequiresProcessAndPositiveProfile()
    {
        Assert.True(WindowsProxyElevation.TryParseProxyStartRequest(
            [WindowsProxyElevation.ProxyStartArgument, "process", "7", "steam:counter-strike"],
            out var request));
        Assert.Equal(new WindowsProxyStartRequest(
            WindowsProxyMode.Process, 7, "steam:counter-strike"), request);

        Assert.False(WindowsProxyElevation.TryParseProxyStartRequest(
            [WindowsProxyElevation.ProxyStartArgument, "tun", "7", "steam:counter-strike"],
            out _));
        Assert.False(WindowsProxyElevation.TryParseProxyStartRequest(
            [WindowsProxyElevation.ProxyStartArgument, "process", "0", "steam:counter-strike"],
            out _));
    }
}
