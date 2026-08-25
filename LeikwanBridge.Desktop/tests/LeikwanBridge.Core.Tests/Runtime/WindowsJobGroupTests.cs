using System.Text.Json;
using LeikwanBridge.Core.Models;
using LeikwanBridge.Core.Services;
using Xunit;

namespace LeikwanBridge.Core.Tests.Runtime;

public sealed class WindowsJobGroupTests
{
    [Fact]
    public void JobGroupIsSafeToCreateAndDisposeOnCurrentPlatform()
    {
        using var group = WindowsJobGroup.Create(42);
        group.Terminate();
    }

    [Fact]
    public void UltraStaticConfigHasNoProviderMaterial()
    {
        var config = new AgentConfig { StateDir = Path.Combine(Path.GetTempPath(), "lw-ultra-test") };
        var state = new StateManager(config.GetEffectiveStateDir());
        var supervisor = new LeikwanBridge.Core.Tests.Runtime.TestProcessSupervisor();
        var policy = new UltraPolicy
        {
            ProtocolValue = UltraPolicy.Protocol,
            ForwardId = 42,
            PolicyGeneration = new string('a', 64),
            SessionTicket = "ticket",
            TargetDigest = UltraPolicy.ComputeTargetDigest("203.0.113.10:443"),
            ExpiresAt = DateTimeOffset.UtcNow.AddMinutes(1),
            Transport = "tcp",
            QuotaRemainingBytes = 1024,
        };
        using var lease = new UltraLease
        {
            LeaseId = "ul2_test",
            ForwardId = 42,
            SessionTicket = "ticket",
            PolicyGeneration = policy.PolicyGeneration,
            TargetDigest = policy.TargetDigest,
            ExpiresAt = policy.ExpiresAt,
            Transport = "tcp",
            QuotaRemainingBytes = 1024,
            SelectionRef = "selection-reference-abcdefghijklmnop",
            NonceHash = new string('b', 64),
            ProviderIp = "203.0.113.20",
            ProviderPort = 5438,
            User = "user"u8.ToArray(),
            Password = "password"u8.ToArray(),
        };
        var forward = new ForwardConfig
        {
            ForwardId = 42,
            BackendMode = "ultra",
            ListenPort = 11042,
            Target = "203.0.113.10:443",
            UltraAccess = policy,
            RuntimeLease = lease,
        };
        var manager = new ForwardRuntimeManager(
            config, supervisor, state, binaryExists: _ => true, portListeningProbe: _ => true,
            ultraLeaseSender: (_, _, _) => Task.FromResult(true));
        manager.ApplyForward(forward);
        Assert.Equal(["ultra-runtime"], supervisor.StartedRoles);
        Assert.DoesNotContain(lease.ProviderIp, supervisor.StartedCommands.Single().arguments);
        var staticFiles = Directory.GetFiles(Path.Combine(config.GetEffectiveStateDir(), "runtime"), "ultra-forward-42.json");
        Assert.Single(staticFiles);
        var staticJson = File.ReadAllText(staticFiles[0]);
        Assert.DoesNotContain(lease.ProviderIp, staticJson);
        Assert.DoesNotContain("password", staticJson, StringComparison.OrdinalIgnoreCase);
        Assert.DoesNotContain("credential", staticJson, StringComparison.OrdinalIgnoreCase);
    }
}
