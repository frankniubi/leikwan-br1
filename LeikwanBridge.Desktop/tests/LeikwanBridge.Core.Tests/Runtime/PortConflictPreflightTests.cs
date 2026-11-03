using LeikwanBridge.Core.Models;
using LeikwanBridge.Core.Services;
using Xunit;

namespace LeikwanBridge.Core.Tests.Runtime;

public sealed class PortConflictPreflightTests
{
    private const string InstallRoot = @"C:\Program Files\LeiKwan Bridge";
    private const string BondExe = @"C:\Program Files\LeiKwan Bridge\bin\leikwan-bond.exe";

    [Fact]
    public void Classify_Free_WhenNoOwners()
    {
        var result = PortConflictPreflight.Classify([], [], _ => null, InstallRoot);

        Assert.Equal(PortOwnerClass.Free, result.Class);
    }

    [Fact]
    public void Classify_ManagedOld_WhenOwnerIsManagedBinaryUnderInstallRoot()
    {
        var result = PortConflictPreflight.Classify(
            [new PortOwner("udp", "0.0.0.0", 30522, 23156)],
            [],
            _ => BondExe,
            InstallRoot);

        Assert.Equal(PortOwnerClass.ManagedOld, result.Class);
        Assert.Equal([23156], result.ManagedOldPids);
    }

    [Fact]
    public void Classify_ManagedOld_ForMacRuntimeInsideTheAppBundle()
    {
        const string installRoot = "/Applications/LeiKwan Bridge.app/Contents/Resources/bin";
        var result = PortConflictPreflight.Classify(
            [new PortOwner("tcp", "127.0.0.1", 12042, 24001)],
            [],
            _ => installRoot + "/ultra-client",
            installRoot);

        Assert.Equal(PortOwnerClass.ManagedOld, result.Class);
    }

    [Fact]
    public void Classify_Foreign_WhenOwnerIsUnknownProcess()
    {
        var result = PortConflictPreflight.Classify(
            [new PortOwner("udp", "0.0.0.0", 30522, 999)],
            [],
            _ => @"C:\Windows\System32\svchost.exe",
            InstallRoot);

        Assert.Equal(PortOwnerClass.Foreign, result.Class);
        Assert.Equal(999, result.ForeignPid);
    }

    [Fact]
    public void Classify_Foreign_WhenExecutablePathIsDenied()
    {
        var result = PortConflictPreflight.Classify(
            [new PortOwner("udp", "0.0.0.0", 30522, 999)],
            [],
            _ => null,
            InstallRoot);

        Assert.Equal(PortOwnerClass.Foreign, result.Class);
    }

    [Fact]
    public void Classify_ManagedCurrent_WhenOwnerPidIsTracked()
    {
        var result = PortConflictPreflight.Classify(
            [new PortOwner("udp", "127.0.0.1", 30522, 55)],
            [55],
            _ => null,
            InstallRoot);

        Assert.Equal(PortOwnerClass.ManagedCurrent, result.Class);
    }

    [Fact]
    public void Run_QuiescesManagedOldOwner()
    {
        var killed = new List<int>();
        var owners = new[] { new PortOwner("udp", "0.0.0.0", 30522, 23156) };

        var result = PortConflictPreflight.Run(
            30522,
            [],
            _ => owners,
            _ => BondExe,
            InstallRoot,
            killed.Add,
            quiesceTimeout: TimeSpan.Zero);

        Assert.False(result.Blocked);
        Assert.Equal([23156], result.QuiescedPids);
        Assert.Equal([23156], killed);
    }

    [Fact]
    public void Run_BlocksForeignOwner_WithoutKilling()
    {
        var killed = new List<int>();

        var result = PortConflictPreflight.Run(
            30522,
            [],
            _ => [new PortOwner("udp", "0.0.0.0", 30522, 999)],
            _ => @"C:\Windows\System32\svchost.exe",
            InstallRoot,
            killed.Add,
            quiesceTimeout: TimeSpan.Zero);

        Assert.True(result.Blocked);
        Assert.Equal("FOREIGN_PORT_IN_USE", result.ConflictCode);
        Assert.Equal(999, result.ForeignPid);
        Assert.Empty(killed);
    }

    [Fact]
    public void Run_BlocksFreePortWhenWindowsExcludesIt()
    {
        var result = PortConflictPreflight.Run(
            54544,
            [],
            _ => [],
            _ => null,
            InstallRoot,
            _ => { },
            quiesceTimeout: TimeSpan.Zero,
            classifyFreePort: _ => "PORT_EXCLUDED");

        Assert.True(result.Blocked);
        Assert.Equal("PORT_EXCLUDED", result.ConflictCode);
        Assert.Equal(0, result.ForeignPid);
    }

    [Fact]
    public void Run_BlocksFreePortHeldBySystemWithoutVisibleOwner()
    {
        var result = PortConflictPreflight.Run(
            32216,
            [],
            _ => [],
            _ => null,
            InstallRoot,
            _ => { },
            quiesceTimeout: TimeSpan.Zero,
            classifyFreePort: _ => "PORT_HELD_BY_SYSTEM");

        Assert.True(result.Blocked);
        Assert.Equal("PORT_HELD_BY_SYSTEM", result.ConflictCode);
    }

    [Fact]
    public void Run_AllowsFreePortWhenNothingHoldsIt()
    {
        var result = PortConflictPreflight.Run(
            32217,
            [],
            _ => [],
            _ => null,
            InstallRoot,
            _ => { },
            quiesceTimeout: TimeSpan.Zero,
            classifyFreePort: _ => null);

        Assert.False(result.Blocked);
    }

    [Fact]
    public void Run_DoesNotProbeExcludedPortWhenManagedCurrentOwnerExists()
    {
        var result = PortConflictPreflight.Run(
            30522,
            [55],
            _ => [new PortOwner("tcp", "127.0.0.1", 30522, 55)],
            _ => null,
            InstallRoot,
            _ => { },
            quiesceTimeout: TimeSpan.Zero,
            classifyFreePort: _ => throw new InvalidOperationException("should not probe owned port"));

        Assert.False(result.Blocked);
    }
}
