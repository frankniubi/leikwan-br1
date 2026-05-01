using LeikwanBridge.Core.Helpers;
using LeikwanBridge.Core.Models;
using Xunit;

namespace LeikwanBridge.Core.Tests.Runtime;

public sealed class WindowsForwardPolicyTests
{
    [Fact]
    public void FilterSupportedForwards_KeepsOnlyBondAndSingle()
    {
        var forwards = new[]
        {
            CreateForward(23, "bond"),
            CreateForward(24, "mptcp"),
            CreateForward(25, "race"),
            CreateForward(26, "single"),
        };

        var filtered = WindowsForwardPolicy.FilterSupportedForwards(forwards);

        Assert.Equal([23, 26], filtered.Select(forward => forward.ForwardId).ToArray());
    }

    private static ForwardConfig CreateForward(int forwardId, string mode)
    {
        return new ForwardConfig
        {
            ForwardId = forwardId,
            Mode = mode,
            ListenPort = 10000 + forwardId,
            Target = "193.227.109.82:5201",
            Transport = "tcp",
            Endpoints =
            [
                new EndpointConfig
                {
                    Host = "127.0.0.1",
                    Port = 443,
                },
            ],
        };
    }
}
