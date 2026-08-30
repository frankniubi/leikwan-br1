using System.Text.Json;
using LeikwanBridge.Core.Models;
using LeikwanBridge.Core.Services;
using LeikwanBridge.Core.Services.Platform;
using LeikwanBridge.Core.Services.Ultra;
using Xunit;

namespace LeikwanBridge.Core.Tests.Runtime;

public sealed class WindowsUltraRuntimeHostTests
{
    [Theory]
    [InlineData("tcp+udp", true)]
    [InlineData("udp", true)]
    [InlineData("tcp", false)]
    public void Start_WritesUdpBootstrapSettingsOnlyForUdpCapableTransport(
        string transport,
        bool expectsUdpSettings)
    {
        using var temp = new TempStateDirectory();
        var config = new AgentConfig
        {
            StateDir = temp.Path,
            UltraBin = Path.Combine(temp.Path, "bin", "ultra-client.exe"),
        };
        var state = new StateManager(temp.Path);
        var supervisor = new TestProcessSupervisor();
        var lease = CreateLease("203.0.113.199", transport);
        var host = CreateHost(config, state, supervisor);
        var forward = CreateForward("198.51.100.199:443", lease);

        host.Start(forward, lease, "contract-a", "config_sync");

        var bootstrapText = File.ReadAllText(GetBootstrapPath(temp.Path, forward.ForwardId));
        using var bootstrap = JsonDocument.Parse(bootstrapText);
        var root = bootstrap.RootElement;
        Assert.Equal(expectsUdpSettings, root.TryGetProperty("udp_prewarm", out var prewarm));
        Assert.Equal(expectsUdpSettings, root.TryGetProperty("udp_keepalive_interval_seconds", out var keepalive));
        if (expectsUdpSettings)
        {
            Assert.True(prewarm.GetBoolean());
            Assert.Equal(5, keepalive.GetInt32());
        }
        Assert.Equal(transport, root.GetProperty("transport").GetString());
        Assert.DoesNotContain("203.0.113.199", bootstrapText, StringComparison.Ordinal);
        Assert.DoesNotContain("line-user", bootstrapText, StringComparison.Ordinal);
        Assert.DoesNotContain("line-password", bootstrapText, StringComparison.Ordinal);
        Assert.All(lease.User, value => Assert.Equal(0, value));
        Assert.All(lease.Password, value => Assert.Equal(0, value));
        Assert.Equal(string.Empty, lease.ProviderIp);
        host.Stop(forward.ForwardId);
    }

    [Fact]
    public void StartWithCandidates_PreservesProbeAndUdpBootstrapSettings()
    {
        using var temp = new TempStateDirectory();
        var config = new AgentConfig
        {
            StateDir = temp.Path,
            UltraBin = Path.Combine(temp.Path, "bin", "ultra-client.exe"),
        };
        var state = new StateManager(temp.Path);
        var supervisor = new TestProcessSupervisor();
        var first = CreateLease("203.0.113.199", "tcp+udp");
        var second = CreateLease("203.0.113.200", "tcp+udp");
        var host = CreateHost(config, state, supervisor);
        var forward = CreateForward("198.51.100.199:443", first);

        host.Start(forward, new[] { first, second }, "contract-a", "config_sync");

        using var bootstrap = JsonDocument.Parse(File.ReadAllText(GetBootstrapPath(temp.Path, forward.ForwardId)));
        var root = bootstrap.RootElement;
        Assert.True(root.GetProperty("auto_select").GetBoolean());
        Assert.Equal("1.1.1.1:53", root.GetProperty("probe_target").GetString());
        Assert.Equal(30, root.GetProperty("probe_duration_seconds").GetInt32());
        Assert.Equal(800, root.GetProperty("probe_timeout_ms").GetInt32());
        Assert.Equal(30, root.GetProperty("probe_samples").GetInt32());
        Assert.Equal(1000, root.GetProperty("probe_interval_ms").GetInt32());
        Assert.True(root.GetProperty("udp_prewarm").GetBoolean());
        Assert.Equal(5, root.GetProperty("udp_keepalive_interval_seconds").GetInt32());
        host.Stop(forward.ForwardId);
    }

    private static WindowsUltraRuntimeHost CreateHost(
        AgentConfig config,
        StateManager state,
        TestProcessSupervisor supervisor)
    {
        return new WindowsUltraRuntimeHost(
            config,
            supervisor,
            state,
            binaryExists: _ => true,
            portListeningProbe: _ => true,
            leaseSender: (_, _, _) => Task.FromResult(true),
            leaseBatchSender: (_, _, _) => Task.FromResult(true));
    }

    private static string GetBootstrapPath(string stateDir, int forwardId)
    {
        return Path.Combine(stateDir, "runtime", $"ultra-forward-{forwardId}.json");
    }

    private static ForwardConfig CreateForward(string target, UltraLease lease)
    {
        return new ForwardConfig
        {
            ForwardId = 42,
            BackendMode = "ultra",
            Mode = "single",
            ClientMode = "single",
            ListenPort = 12042,
            Target = target,
            Transport = lease.Transport,
            RuntimeLease = lease,
            UltraAccess = new UltraPolicy
            {
                ProtocolValue = UltraPolicy.Protocol,
                ForwardId = 42,
                PolicyGeneration = lease.PolicyGeneration,
                SessionTicket = lease.SessionTicket,
                ExpiresAt = lease.ExpiresAt.AddMinutes(1),
                ServiceState = "active",
                Transport = lease.Transport,
                QuotaRemainingBytes = lease.QuotaRemainingBytes,
                TargetDigest = lease.TargetDigest,
            },
        };
    }

    private static UltraLease CreateLease(string providerIp, string transport)
    {
        return new UltraLease
        {
            LeaseId = "ul2_test",
            SessionTicket = "ticket",
            ForwardId = 42,
            PolicyGeneration = new string('a', 64),
            TargetDigest = new string('c', 64),
            ExpiresAt = DateTimeOffset.UtcNow.AddMinutes(3),
            Transport = transport,
            RateLimitMbps = 20,
            QuotaRemainingBytes = 1024,
            SelectionRef = "selection-reference-abcdefghijklmnop",
            NonceHash = new string('b', 64),
            ProviderIp = providerIp,
            ProviderPort = 5438,
            User = "line-user"u8.ToArray(),
            Password = "line-password"u8.ToArray(),
        };
    }
}
