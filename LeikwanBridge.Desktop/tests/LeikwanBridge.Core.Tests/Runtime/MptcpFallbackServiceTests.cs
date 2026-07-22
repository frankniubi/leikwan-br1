using LeikwanBridge.Core.Models;
using LeikwanBridge.Core.Services;
using Xunit;

namespace LeikwanBridge.Core.Tests.Runtime;

public sealed class MptcpFallbackServiceTests
{
    [Fact]
    public void ConvertToBond_PreservesBackendContractFields()
    {
        var forward = new ForwardConfig
        {
            ForwardId = 73,
            Mode = "mptcp",
            BackendMode = "leigod_mode",
            ContractVersion = 1,
            ClientMode = "mptcp",
            RuntimePreference = "single",
            BondIngressMode = "plain_tcp_mux",
            ActiveFamily = "sidecar_aggregation",
            AggregationEnabled = true,
            TargetContract = "sidecar_aggregation",
            SidecarRequired = true,
            BondSidecarPointId = 9,
            ListenPort = 11073,
            Target = "162.245.221.78:80",
            Transport = "tcp+udp",
            Mptcp = new MptcpConfig
            {
                SidecarHost = "198.51.100.9",
                SidecarPort = 8898,
                TcpSessionToken = "tcp-token",
            },
        };

        var fallback = MptcpFallbackService.ConvertToBond(forward);

        Assert.Equal("bond", fallback.Mode);
        Assert.Equal("leigod_mode", fallback.BackendMode);
        Assert.Equal(1, fallback.ContractVersion);
        Assert.Equal("mptcp", fallback.ClientMode);
        Assert.Equal("single", fallback.RuntimePreference);
        Assert.Equal("plain_tcp_mux", fallback.BondIngressMode);
        Assert.Equal("sidecar_aggregation", fallback.ActiveFamily);
        Assert.True(fallback.AggregationEnabled);
        Assert.Equal("sidecar_aggregation", fallback.TargetContract);
        Assert.True(fallback.SidecarRequired);
        Assert.Equal(9, fallback.BondSidecarPointId);
    }
}
