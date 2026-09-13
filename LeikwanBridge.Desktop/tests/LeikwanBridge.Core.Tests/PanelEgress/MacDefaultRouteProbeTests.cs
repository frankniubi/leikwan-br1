using System.Net;
using LeikwanBridge.Core.Services.PanelEgress;
using Xunit;

namespace LeikwanBridge.Core.Tests.PanelEgress;

/// <summary>
/// The macOS <c>route -n get</c> probe is exercised through its pure parser
/// so it stays green on the Windows test host; the runner itself is guarded
/// by OperatingSystem.IsMacOS().
/// </summary>
public sealed class MacDefaultRouteProbeTests
{
    private const string PhysicalV4Output = """
        route to: default
        destination: default
               mask: default
            gateway: 192.168.1.1
          interface: en0
              flags: <UP,GATEWAY,DONE,STATIC,PRCLONING,GLOBAL>
        """;

    private const string TunOutput = """
        route to: default
        destination: default
               mask: default
            gateway: 172.16.0.1
          interface: utun3
              flags: <UP,GATEWAY,DONE,STATIC,PRCLONING,GLOBAL>
        """;

    private const string V6LinkLocalOutput = """
        route to: default
        destination: ::
               mask: default
            gateway: fe80::1%en0
          interface: en0
        """;

    [Fact]
    public void ParseOutput_PhysicalDefaultRoute()
    {
        var result = MacDefaultRouteProbe.ParseOutput(PhysicalV4Output);

        Assert.NotNull(result);
        Assert.Equal(IPAddress.Parse("192.168.1.1"), result.Gateway);
        Assert.Equal("en0", result.InterfaceName);
    }

    [Fact]
    public void ParseOutput_TunDefaultRoute_ReportsTunInterface()
    {
        var result = MacDefaultRouteProbe.ParseOutput(TunOutput);

        Assert.NotNull(result);
        Assert.Equal("utun3", result.InterfaceName);
    }

    [Fact]
    public void ParseOutput_Ipv6LinkLocalGateway_StripsInterfaceScope()
    {
        var result = MacDefaultRouteProbe.ParseOutput(V6LinkLocalOutput);

        Assert.NotNull(result);
        Assert.Equal(IPAddress.Parse("fe80::1"), result.Gateway);
        Assert.Equal("en0", result.InterfaceName);
    }

    [Theory]
    [InlineData("")]
    [InlineData("")]
    [InlineData("gateway: 192.168.1.1")]
    [InlineData("interface: en0")]
    [InlineData("gateway: link#4")]
    public void ParseOutput_IncompleteOrUnparseable_ReturnsNull(string output)
    {
        Assert.Null(MacDefaultRouteProbe.ParseOutput(output));
    }

    [Fact]
    public void Run_IsNoOpOffMacOs()
    {
        var probe = new MacDefaultRouteProbe();
        // On the Windows test host the real runner returns empty output and
        // must never throw; the parse side handles it as "unknown".
        var result = probe.GetDefault("-inet");
        if (!OperatingSystem.IsMacOS())
            Assert.Null(result);
    }
}
