using System.Diagnostics;
using LeikwanBridge.Mac.Services;
using Xunit;

namespace LeikwanBridge.Mac.Tests.Platform;

public sealed class MacAutostartServiceTests
{
    [Fact]
    public async Task EnableWritesTokenFreePlistAndUsesLaunchctlArgumentList()
    {
        using var temp = new TemporaryDirectory();
        var paths = new MacPlatformPaths(temp.Path, Path.Combine(temp.Path, "LeiKwan Bridge.app"));
        var starts = new List<ProcessStartInfo>();
        var runner = new FakeCommandRunner(startInfo =>
        {
            starts.Add(startInfo);
            return new MacCommandResult(0, "", "");
        });
        var service = new MacAutostartService(paths, runner, () => 501);

        await service.SetEnabledAsync(true, CancellationToken.None);

        Assert.True(await service.IsEnabledAsync(CancellationToken.None));
        var plist = File.ReadAllText(service.PlistPath);
        Assert.Contains("com.leikwan.bridge", plist);
        Assert.Contains("RunAtLoad", plist);
        Assert.Contains("--background", plist);
        Assert.DoesNotContain("token", plist, StringComparison.OrdinalIgnoreCase);
        Assert.Contains(starts, start => start.FileName == "/bin/launchctl"
            && start.ArgumentList.SequenceEqual(["bootstrap", "gui/501", service.PlistPath]));
        Assert.All(starts, start => Assert.False(start.UseShellExecute));

        await service.SetEnabledAsync(false, CancellationToken.None);

        Assert.False(File.Exists(service.PlistPath));
        Assert.Contains(starts, start => start.ArgumentList.SequenceEqual(["bootout", "gui/501/com.leikwan.bridge"]));
    }
}
