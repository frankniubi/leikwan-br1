using LeikwanBridge.App.Services;
using Xunit;

namespace LeikwanBridge.App.Tests.Services;

public sealed class InstalledRuntimeCleanupServiceTests
{
    [Fact]
    public void KillProcessesUnderInstallRoot_KillsOnlyOtherInstalledProcesses()
    {
        var installRoot = @"C:\Program Files\LeiKwan Bridge";
        var killed = new List<int>();
        var service = new InstalledRuntimeCleanupService(
            () =>
            [
                new InstalledRuntimeCleanupService.RunningProcessInfo(10, $@"{installRoot}\leikwan-client-win.exe"),
                new InstalledRuntimeCleanupService.RunningProcessInfo(11, $@"{installRoot}\bin\leikwan-bond.exe"),
                new InstalledRuntimeCleanupService.RunningProcessInfo(12, $@"{installRoot}\bin\leikwan-ingress.exe"),
                new InstalledRuntimeCleanupService.RunningProcessInfo(13, $@"{installRoot}\bin\leikwan-mptcp-relay.exe"),
                new InstalledRuntimeCleanupService.RunningProcessInfo(14, $@"{installRoot}\bin\ultra-client.exe"),
                new InstalledRuntimeCleanupService.RunningProcessInfo(16, @"C:\Windows\System32\notepad.exe"),
                new InstalledRuntimeCleanupService.RunningProcessInfo(15, null),
            ],
            processId => killed.Add(processId));

        var count = service.KillProcessesUnderInstallRoot(installRoot, currentProcessId: 10);

        Assert.Equal(4, count);
        Assert.Equal([11, 12, 13, 14], killed);
    }

    [Fact]
    public void KillProcessesUnderInstallRoot_KillsStaleLeiKwanProcessesFromOtherRoots()
    {
        var installRoot = @"C:\Program Files\LeiKwan Bridge";
        var killed = new List<int>();
        var service = new InstalledRuntimeCleanupService(
            () =>
            [
                new InstalledRuntimeCleanupService.RunningProcessInfo(20, $@"{installRoot}\leikwan-client-win.exe"),
                new InstalledRuntimeCleanupService.RunningProcessInfo(21, @"C:\Users\frankniubi\Downloads\leikwan-br1\artifacts\windows\_smoke_extract\localapp\LeiKwan Bridge\bin\leikwan-ingress.exe"),
                new InstalledRuntimeCleanupService.RunningProcessInfo(22, @"C:\Users\frankniubi\AppData\Local\LeiKwan Bridge Backup\bin\leikwan-bond.exe"),
                new InstalledRuntimeCleanupService.RunningProcessInfo(23, @"C:\Windows\System32\notepad.exe"),
                new InstalledRuntimeCleanupService.RunningProcessInfo(24, @"C:\Temp\random\leikwan-ingress.exe"),
            ],
            processId => killed.Add(processId));

        var count = service.KillProcessesUnderInstallRoot(installRoot, currentProcessId: 20);

        Assert.Equal(2, count);
        Assert.Equal([21, 22], killed);
    }
}
