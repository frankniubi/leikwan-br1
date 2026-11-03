using System.Net;
using LeikwanBridge.Core.Services;
using Xunit;

namespace LeikwanBridge.Core.Tests.Runtime;

public sealed class EgressInterfaceConfigTests
{
    [Fact]
    public void Apply_WritesOnlyBindingMetadata()
    {
        var config = new Dictionary<string, object?>();
        var binding = new EgressInterfaceBinding("en0", 8, IPAddress.Parse("192.0.2.10"));

        EgressInterfaceConfig.Apply(config, null, binding);

        Assert.Equal("en0", config["egress_interface"]);
        Assert.Equal(8, config["egress_interface_index"]);
        Assert.Equal("192.0.2.10", config["egress_local_address"]);
    }

    [Fact]
    public void ResolveOrThrow_RejectsMissingExplicitInterface()
    {
        var exception = Assert.Throws<InvalidOperationException>(() =>
            EgressInterfaceConfig.ResolveOrThrow("interface-that-does-not-exist"));

        Assert.Equal("egress_interface_unavailable", exception.Message);
    }
}
