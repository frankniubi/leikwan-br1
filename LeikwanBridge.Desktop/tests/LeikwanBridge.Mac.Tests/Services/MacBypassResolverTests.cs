using LeikwanBridge.Core.Models;
using LeikwanBridge.Mac.Services;
using Xunit;

namespace LeikwanBridge.Mac.Tests.Services;

public sealed class MacBypassResolverTests
{
    [Fact]
    public void CollectHostsIncludesPanelUltraProviderAndCurrentModeSlaveAddresses()
    {
        var forwards = new[]
        {
            new ForwardConfig
            {
                BackendMode = "ultra",
                Target = "198.51.100.40:443",
                RuntimeLease = new UltraLease { ProviderIp = "203.0.113.40" },
            },
            new ForwardConfig
            {
                BackendMode = "current_mode",
                Endpoints =
                [
                    new EndpointConfig { Host = "203.0.113.41", CdnHost = "slave.example.test" },
                ],
                Bonding = new BondingConfig
                {
                    SidecarHost = "203.0.113.42",
                    RelayHost = "relay.example.test",
                },
            },
        };

        var hosts = MacBypassResolver.CollectHosts("https://panel.example.test", forwards);

        Assert.Contains("panel.example.test", hosts);
        Assert.Contains("203.0.113.40", hosts);
        Assert.Contains("203.0.113.41", hosts);
        Assert.Contains("slave.example.test", hosts);
        Assert.Contains("203.0.113.42", hosts);
        Assert.DoesNotContain("agent-token", string.Join("\n", hosts));
    }

    [Fact]
    public async Task ResolveAsyncKeepsLiteralIpsAndIgnoresUnknownHostnames()
    {
        var resolution = await new MacBypassResolver().ResolveAsync(
            "https://panel.example.test",
            [new ForwardConfig
            {
                BackendMode = "ultra",
                RuntimeLease = new UltraLease { ProviderIp = "203.0.113.44" },
            }]);

        Assert.Contains("203.0.113.44", resolution.Ips);
        Assert.Contains("panel.example.test", resolution.Hosts);
    }
}
