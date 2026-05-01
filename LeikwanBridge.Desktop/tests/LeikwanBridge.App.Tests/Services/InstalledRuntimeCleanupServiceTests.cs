using LeikwanBridge.App.Services;
using Xunit;

namespace LeikwanBridge.App.Tests.Services;

public sealed class InstalledRuntimeCleanupServiceTests
{
    [Fact]
    public void KillProcessesUnderInstallRoot_KillsOnlyOtherInstalledProcesses()
    {
        var installRoot = @"C:\Users\frankniubi\AppData\Local\LeiKwan Bridge";
        var killed = new List<int>();
        var service = new InstalledRuntimeCleanupService(
            () =>
            [
                new InstalledRuntimeCleanupService.RunningProcessInfo(10, $@"{installRoot}\leikwan-client-win.exe"),
                new InstalledRuntimeCleanupService.RunningProcessInfo(11, $@"{installRoot}\bin\leikwan-bond.exe"),
                new InstalledRuntimeCleanupService.RunningProcessInfo(12, $@"{installRoot}\bin\leikwan-ingress.exe"),
                new InstalledRuntimeCleanupService.RunningProcessInfo(13, @"C:\Windows\System32\notepad.exe"),
                new InstalledRuntimeCleanupService.RunningProcessInfo(14, null),
            ],
            processId => killed.Add(processId));

        var count = service.KillProcessesUnderInstallRoot(installRoot, currentProcessId: 10);

        Assert.Equal(2, count);
        Assert.Equal([11, 12], killed);
    }
}
