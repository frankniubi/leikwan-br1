using System.Diagnostics;
using LeikwanBridge.Mac.Services;
using Xunit;

namespace LeikwanBridge.Mac.Tests.Platform;

public sealed class MacPortOwnershipProbeTests
{
    [Fact]
    public void LsofAndPsUseStructuredArgumentsWithoutAShell()
    {
        var starts = new List<ProcessStartInfo>();
        var runner = new FakeCommandRunner(startInfo =>
        {
            starts.Add(startInfo);
            if (startInfo.FileName == "/usr/sbin/lsof")
            {
                var output = startInfo.ArgumentList.Any(argument => argument.Contains("TCP", StringComparison.Ordinal))
                    ? "p123\ncwstunnel\nu501\n"
                    : "p456\ncultra-client\nu501\n";
                return new MacCommandResult(0, output, "");
            }
            return new MacCommandResult(0, "/Applications/LeiKwan Bridge.app/Contents/Resources/bin/ultra-client\n", "");
        });
        var probe = new MacPortOwnershipProbe(runner);

        var owners = probe.FindOwners(12042);
        var executable = probe.ResolveExecutablePath(456);

        Assert.Equal([123, 456], owners.Select(owner => owner.Pid).Order().ToArray());
        Assert.Equal("/Applications/LeiKwan Bridge.app/Contents/Resources/bin/ultra-client", executable);
        Assert.All(starts, start => Assert.False(start.UseShellExecute));
        Assert.All(starts, start => Assert.Equal(string.Empty, start.Arguments));
        Assert.Contains(starts, start => start.FileName == "/usr/sbin/lsof" && start.ArgumentList.Contains("-Fpcu"));
        Assert.Throws<ArgumentOutOfRangeException>(() => probe.FindOwners(70000));
    }
}
