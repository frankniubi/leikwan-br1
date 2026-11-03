using LeikwanBridge.App.ViewModels;
using LeikwanBridge.Core.Models;
using LeikwanBridge.Core.Services;
using Xunit;

namespace LeikwanBridge.App.Tests.ViewModels;

/// <summary>
/// A forward row that is not running names the local cause of its last failed start.
/// Without resources loaded, the row shows the raw code, which is what these tests read.
/// </summary>
public sealed class ForwardStartFailureMessageTests
{
    [Fact]
    public void StoppedRowNamesTheLocalPortCause()
    {
        var vm = new MainViewModel();

        vm.ApplyForwardSnapshot([CreateForward(334)], _ => false, startFailure: _ => LocalPortFailure.InUse);

        Assert.Equal(LocalPortFailure.InUse, vm.Forwards.Single().Summary.FriendlyMessage);
    }

    [Fact]
    public void RowWithoutAFailureKeepsTheGenericMessage()
    {
        var vm = new MainViewModel();

        vm.ApplyForwardSnapshot([CreateForward(334)], _ => false, startFailure: _ => null);

        Assert.Equal("Unavailable", vm.Forwards.Single().Summary.FriendlyMessage);
    }

    [Fact]
    public void RunningRowShowsNoMessageEvenWhenAnEarlierStartFailed()
    {
        var vm = new MainViewModel();

        vm.ApplyForwardSnapshot([CreateForward(334)], _ => true, startFailure: _ => LocalPortFailure.InUse);

        Assert.Equal(string.Empty, vm.Forwards.Single().Summary.FriendlyMessage);
    }

    private static ForwardConfig CreateForward(int forwardId)
        => new()
        {
            ForwardId = forwardId,
            ServiceStatus = "active",
            Mode = "single",
            ListenPort = 32216,
            Target = "198.51.100.9:443",
            Transport = "tcp",
        };
}
