using LeikwanBridge.Mac.Services;
using Xunit;

namespace LeikwanBridge.Mac.Tests.Services;

public sealed class MacTunDnsSessionTests
{
    [Fact]
    public void ResolverIsTemporaryAndRoutesDefaultNamesToTunGateway()
    {
        var commands = MacTunDnsSession.BuildCommands("abcdef123456");
        Assert.Contains("d.add ServerAddresses * 172.19.0.2", commands);
        Assert.Contains("d.add SupplementalMatchDomains * \"\"", commands);
        Assert.Contains("d.add SupplementalMatchOrders * # 1", commands);
        Assert.Contains("add State:/Network/Service/LeiKwan-abcdef123456/DNS temporary", commands);
        Assert.DoesNotContain("Setup:/", commands);
        Assert.DoesNotContain("quit", commands);
    }

    [Theory]
    [InlineData("anything\nquit")]
    [InlineData("../../old")]
    public void ResolverSessionNameCannotInjectCommands(string value)
        => Assert.Throws<ArgumentException>(() => MacTunDnsSession.BuildCommands(value));

    [Theory]
    [InlineData("Permission denied\n", false)]
    [InlineData("No such key", false)]
    [InlineData("172.19.0.2", false)]
    [InlineData("<dictionary> {\n ServerAddresses : <array> {\n 0 : 172.19.0.2\n }\n SupplementalMatchDomains : <array> {\n 0 : \n }\n}", true)]
    public void ReadinessRequiresTheRegisteredResolverDictionary(string response, bool expected)
        => Assert.Equal(expected, MacTunDnsSession.IsReady(response));
}
