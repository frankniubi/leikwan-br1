using LeikwanBridge.Core.Models;
using LeikwanBridge.Core.Services;
using Xunit;

namespace LeikwanBridge.Core.Tests.Runtime;

[Trait("Platform", "Windows")]
public sealed class WindowsNetchProcessProxyAdapterTests
{
    [Fact]
    public async Task StopBeforeStartDoesNotStopPreexistingDriverService()
    {
        var stopCalls = 0;
        var stateCalls = 0;
        await using var adapter = new WindowsNetchProcessProxyAdapter(
            "missing-redirector.bin",
            "missing-netfilter2.sys",
            stopDriverService: () =>
            {
                stopCalls++;
                return false;
            },
            isDriverServiceStopped: () =>
            {
                stateCalls++;
                return false;
            });

        await adapter.StopAsync();

        Assert.Equal(0, stopCalls);
        Assert.Equal(0, stateCalls);
    }

    [Fact]
    public void ProcessDialPlanKeepsDnsDirectAndBypassesOwnRuntime()
    {
        var plan = WindowsNetchProcessProxyAdapter.BuildDialPlan(
            2081,
            [new WindowsProcessProxyRule("NatTypeTester.exe")],
            @"C:\Program Files\LeiKwan Bridge\bin");

        Assert.Contains(plan, dial => dial.Name == 6 && dial.Value == "false");
        Assert.Contains(plan, dial => dial.Name == 8 && dial.Value == "false");
        Assert.Contains(plan, dial => dial.Name == 9 && dial.Value == "false");
        Assert.DoesNotContain(plan, dial => dial.Name is 10 or 11);
        Assert.Contains(plan, dial => dial.Name == 18
            && dial.Value == @"^C:\\Program Files\\LeiKwan Bridge\\bin");
        Assert.Contains(plan, dial => dial.Name == 17 && dial.Value == "NatTypeTester.exe");
        Assert.Contains(plan, dial => dial.Name == 13 && dial.Value == "2081");
    }

    [Fact]
    public void ProcessDialPlanPreservesExplicitBypassRules()
    {
        var plan = WindowsNetchProcessProxyAdapter.BuildDialPlan(
            2080,
            [
                new WindowsProcessProxyRule("^launcher\\.exe$", Bypass: true),
                new WindowsProcessProxyRule("^game\\.exe$"),
            ]);

        Assert.Contains(plan, dial => dial.Name == 18 && dial.Value == "^launcher\\.exe$");
        Assert.Contains(plan, dial => dial.Name == 17 && dial.Value != "");
    }
}
