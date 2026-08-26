using System.Diagnostics;
using System.Text;
using LeikwanBridge.Core.Models;
using LeikwanBridge.Core.Services;
using LeikwanBridge.Core.Services.Platform;
using LeikwanBridge.Mac.Services;
using Xunit;

namespace LeikwanBridge.Mac.Tests.Runtime;

public sealed class MacUltraRuntimeHostTests
{
    [Fact]
    public void Start_UsesMemoryOnlyBootstrapAndClearsProviderLease()
    {
        using var temp = new TemporaryDirectory();
        const string canaryTarget = "198.51.100.199:443";
        const string canaryIp = "203.0.113.199";
        var config = new AgentConfig
        {
            StateDir = temp.Path,
            UltraBin = Path.Combine(temp.Path, "bin", "ultra-client"),
        };
        var state = new StateManager(temp.Path);
        var supervisor = new RecordingProcessSupervisor();
        var lease = CreateLease(canaryIp);
        var socketRoot = Path.Combine(temp.Path, "sockets");
        string? sentSocket = null;
        var host = new MacUltraRuntimeHost(
            config,
            supervisor,
            state,
            binaryExists: _ => true,
            socketExists: _ => true,
            portListeningProbe: _ => true,
            leaseSender: (socketPath, forwardId, currentLease, _) =>
            {
                sentSocket = socketPath;
                Assert.Equal(canaryIp, currentLease.ProviderIp);
                return Task.FromResult(true);
            },
            socketRoot: socketRoot);
        var forward = CreateForward(canaryTarget, lease);

        host.Start(forward, lease, "contract-a", "config_sync");

        var launch = Assert.Single(supervisor.Launches);
        Assert.Equal("--config-stdin", launch.Arguments);
        Assert.False(launch.LogArguments);
        Assert.Contains(canaryTarget, Encoding.UTF8.GetString(launch.StandardInput));
        Assert.NotNull(sentSocket);
        Assert.StartsWith(socketRoot, sentSocket, StringComparison.Ordinal);
        var diskText = string.Concat(Directory.EnumerateFiles(temp.Path, "*", SearchOption.AllDirectories)
            .Select(path => File.ReadAllText(path)));
        Assert.DoesNotContain(canaryTarget, diskText);
        Assert.DoesNotContain(canaryIp, diskText);
        Assert.All(lease.User, value => Assert.Equal(0, value));
        Assert.All(lease.Password, value => Assert.Equal(0, value));
        Assert.Equal(string.Empty, lease.ProviderIp);
        Assert.Equal("ultra", state.LoadApplied()["42"].Mode);

        var refreshedLease = CreateLease(canaryIp);
        Assert.True(host.Refresh(forward, refreshedLease));
        Assert.All(refreshedLease.Password, value => Assert.Equal(0, value));
        Assert.Equal(string.Empty, refreshedLease.ProviderIp);

        host.Stop(42);
        Assert.Empty(Directory.EnumerateDirectories(Path.Combine(temp.Path, "runtime")));
        Assert.Empty(Directory.EnumerateDirectories(socketRoot));
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
            Transport = "tcp",
            RuntimeLease = lease,
            UltraAccess = new UltraPolicy
            {
                ProtocolValue = UltraPolicy.Protocol,
                ForwardId = 42,
                PolicyGeneration = lease.PolicyGeneration,
                SessionTicket = lease.SessionTicket,
                ExpiresAt = lease.ExpiresAt.AddMinutes(1),
                ServiceState = "active",
                Transport = "tcp",
                QuotaRemainingBytes = lease.QuotaRemainingBytes,
                TargetDigest = lease.TargetDigest,
            },
        };
    }

    private static UltraLease CreateLease(string providerIp)
    {
        return new UltraLease
        {
            LeaseId = "ul2_test",
            SessionTicket = "ticket",
            ForwardId = 42,
            PolicyGeneration = new string('a', 64),
            TargetDigest = new string('c', 64),
            ExpiresAt = DateTimeOffset.UtcNow.AddMinutes(3),
            Transport = "tcp",
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

    private sealed class RecordingProcessSupervisor : IProcessSupervisor
    {
        public sealed record Launch(string Arguments, bool LogArguments, byte[] StandardInput);

        public event Action<int, string>? ProcessExited
        {
            add { }
            remove { }
        }
        public List<Launch> Launches { get; } = [];

        public Process StartProcess(int forwardId, string role, string label, string exePath, string arguments, byte[]? standardInput = null, bool logArguments = true, string? workingDirectory = null)
        {
            Launches.Add(new Launch(arguments, logArguments, standardInput?.ToArray() ?? []));
            if (standardInput is not null)
                Array.Clear(standardInput);
            return new Process();
        }

        public void StopAll(int forwardId) { }
        public void StopAll() { }
        public bool IsRunning(int forwardId) => true;
        public IReadOnlyList<int> GetProcessIds(int forwardId) => [];
        public IReadOnlyList<string> GetRunningRoles(int forwardId) => ["ultra-runtime"];
        public void Dispose() { }
    }

    private sealed class TemporaryDirectory : IDisposable
    {
        public TemporaryDirectory()
        {
            Path = System.IO.Path.Combine(System.IO.Path.GetTempPath(), $"lw-mac-host-{Guid.NewGuid():N}");
            Directory.CreateDirectory(Path);
        }

        public string Path { get; }

        public void Dispose()
        {
            if (Directory.Exists(Path))
                Directory.Delete(Path, recursive: true);
        }
    }
}
