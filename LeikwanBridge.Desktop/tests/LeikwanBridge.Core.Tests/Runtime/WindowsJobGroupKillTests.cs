using System.Diagnostics;
using LeikwanBridge.Core.Services;
using Xunit;

namespace LeikwanBridge.Core.Tests.Runtime;

public sealed class WindowsJobGroupKillTests
{
    [Fact]
    public void DisposingTheJobKillsTheAttachedChild()
    {
        if (!OperatingSystem.IsWindows())
            return;

        using var child = Process.Start(new ProcessStartInfo
        {
            FileName = Path.Combine(Environment.SystemDirectory, "ping.exe"),
            ArgumentList = { "-n", "120", "127.0.0.1" },
            UseShellExecute = false,
            CreateNoWindow = true,
        })!;
        using var job = WindowsJobGroup.Create(0);
        Assert.True(job.Attach(child));

        job.Dispose();

        Assert.True(child.WaitForExit(10_000));
    }
}
