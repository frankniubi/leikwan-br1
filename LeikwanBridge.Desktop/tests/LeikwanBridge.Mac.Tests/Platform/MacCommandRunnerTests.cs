using System.Diagnostics;
using LeikwanBridge.Mac.Services;
using Xunit;

namespace LeikwanBridge.Mac.Tests.Platform;

public sealed class MacCommandRunnerTests
{
    [Fact]
    public async Task RunAsyncCompletesForAProcessThatExitsImmediately()
    {
        var startInfo = OperatingSystem.IsWindows()
            ? new ProcessStartInfo
            {
                FileName = Environment.GetEnvironmentVariable("ComSpec") ?? "cmd.exe",
                Arguments = "/c exit 0",
            }
            : new ProcessStartInfo
            {
                FileName = "/usr/bin/true",
            };

        var result = await new MacCommandRunner().RunAsync(startInfo, CancellationToken.None);

        Assert.Equal(0, result.ExitCode);
    }
}
