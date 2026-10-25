using LeikwanBridge.Core.Models;
using LeikwanBridge.Core.Services;
using Xunit;

namespace LeikwanBridge.Core.Tests.Runtime;

public sealed class LocalPortFailureTests
{
    private const string Svchost = @"C:\Windows\System32\svchost.exe";
    private const string BondExe = @"C:\Program Files\LeiKwan Bridge\bin\leikwan-bond.exe";

    [Fact]
    public void ForeignOwnerIsReportedAsInUse()
    {
        var cause = LocalPortFailure.Diagnose(
            32216,
            _ => [new PortOwner("tcp", "127.0.0.1", 32216, 4242)],
            _ => Svchost,
            _ => null);

        Assert.Equal(LocalPortFailure.InUse, cause);
    }

    [Fact]
    public void StaleLeiKwanRuntimeOwnerGivesNoSpecificCause()
    {
        var cause = LocalPortFailure.Diagnose(
            32216,
            _ => [new PortOwner("tcp", "127.0.0.1", 32216, 77)],
            _ => BondExe,
            _ => "PORT_HELD_BY_SYSTEM");

        Assert.Null(cause);
    }

    [Fact]
    public void FreePortHeldBySystemWithoutOwnerIsReportedAsHeldBySystem()
    {
        var cause = LocalPortFailure.Diagnose(32216, _ => [], _ => null, _ => "PORT_HELD_BY_SYSTEM");

        Assert.Equal(LocalPortFailure.HeldBySystem, cause);
    }

    [Fact]
    public void FreePortExcludedByWindowsIsReportedAsReserved()
    {
        var cause = LocalPortFailure.Diagnose(54544, _ => [], _ => null, _ => "PORT_EXCLUDED");

        Assert.Equal(LocalPortFailure.Reserved, cause);
    }

    [Fact]
    public void UnexplainedFreePortGivesNoSpecificCause()
    {
        var cause = LocalPortFailure.Diagnose(40000, _ => [], _ => null, _ => null);

        Assert.Null(cause);
    }

    [Fact]
    public void OutOfRangePortGivesNoSpecificCause()
    {
        var cause = LocalPortFailure.Diagnose(0, _ => [], _ => null, _ => "PORT_EXCLUDED");

        Assert.Null(cause);
    }

    [Theory]
    [InlineData("FOREIGN_PORT_IN_USE", LocalPortFailure.InUse)]
    [InlineData("PORT_HELD_BY_SYSTEM", LocalPortFailure.HeldBySystem)]
    [InlineData("PORT_EXCLUDED", LocalPortFailure.Reserved)]
    [InlineData("SOMETHING_ELSE", null)]
    public void PreflightConflictCodesMapToUserFacingCauses(string conflictCode, string? expected)
        => Assert.Equal(expected, LocalPortFailure.FromConflictCode(conflictCode));

    [Fact]
    public void SingBoxBindConflictWithoutLiveCauseIsReportedAsInUse()
    {
        var code = WindowsProxyConnectionService.ClassifyProcessExit(
            "FATAL start service: listen tcp 127.0.0.1:2082: bind: Only one usage of each socket address (protocol/network address/port) is normally permitted.",
            () => null);

        Assert.Equal(LocalPortFailure.InUse, code);
    }

    [Fact]
    public void SingBoxBindConflictUsesTheLiveCauseWhenKnown()
    {
        var code = WindowsProxyConnectionService.ClassifyProcessExit(
            "listen tcp 127.0.0.1:2082: bind: Only one usage of each socket address",
            () => LocalPortFailure.HeldBySystem);

        Assert.Equal(LocalPortFailure.HeldBySystem, code);
    }

    [Fact]
    public void SingBoxForbiddenBindIsReportedAsReserved()
    {
        var code = WindowsProxyConnectionService.ClassifyProcessExit(
            "listen tcp 127.0.0.1:2082: bind: An attempt was made to access a socket in a way forbidden by its access permissions.",
            () => null);

        Assert.Equal(LocalPortFailure.Reserved, code);
    }

    [Fact]
    public void UnrelatedSingBoxExitKeepsTheGenericCode()
    {
        var code = WindowsProxyConnectionService.ClassifyProcessExit("FATAL decode config: unknown field", () => null);

        Assert.Equal("proxy_process_exited", code);
    }
}
