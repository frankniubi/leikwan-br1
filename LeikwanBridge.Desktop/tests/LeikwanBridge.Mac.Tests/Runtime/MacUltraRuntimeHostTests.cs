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
        var staleRuntime = Path.Combine(temp.Path, "runtime", "ultra-42-stale");
        Directory.CreateDirectory(staleRuntime);
        File.WriteAllText(Path.Combine(staleRuntime, "status.json"), "{\"forward_id\":42}");
        string? sentSocket = null;
        var portProbeAttempts = 0;
        var host = new MacUltraRuntimeHost(
            config,
            supervisor,
            state,
            binaryExists: _ => true,
            socketExists: _ => true,
            portListeningProbe: _ => ++portProbeAttempts >= 3,
            leaseSender: (socketPath, forwardId, currentLease, _) =>
            {
                sentSocket = socketPath;
                Assert.Equal(canaryIp, currentLease.ProviderIp);
                return Task.FromResult(true);
            },
            socketRoot: socketRoot);
        var forward = CreateForward(canaryTarget, lease);

        host.Start(forward, lease, "contract-a", "config_sync");

        Assert.False(Directory.Exists(staleRuntime));
        var launch = Assert.Single(supervisor.Launches);
        Assert.Equal("--config-stdin", launch.Arguments);
        Assert.False(launch.LogArguments);
        var bootstrapText = Encoding.UTF8.GetString(launch.StandardInput);
        Assert.Contains(canaryTarget, bootstrapText);
        Assert.DoesNotContain(canaryIp, bootstrapText);
        Assert.DoesNotContain("line-user", bootstrapText);
        Assert.DoesNotContain("line-password", bootstrapText);
        Assert.Contains("location_cache_path", bootstrapText);
        Assert.Contains("location_lock_path", bootstrapText);
        Assert.Contains("\"auto_select\":false", bootstrapText, StringComparison.Ordinal);
        Assert.DoesNotContain("probe_target", bootstrapText, StringComparison.Ordinal);
        Assert.DoesNotContain("probe_samples", bootstrapText, StringComparison.Ordinal);
        Assert.DoesNotContain("udp_prewarm", bootstrapText, StringComparison.Ordinal);
        Assert.DoesNotContain("udp_keepalive_interval_seconds", bootstrapText, StringComparison.Ordinal);
        Assert.NotNull(sentSocket);
        Assert.Equal(3, portProbeAttempts);
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

    [Fact]
    public void StartWithTcpAndUdpLease_IncludesUdpPrewarmAndKeepalive()
    {
        using var temp = new TemporaryDirectory();
        var config = new AgentConfig
        {
            StateDir = temp.Path,
            UltraBin = Path.Combine(temp.Path, "bin", "ultra-client"),
        };
        var state = new StateManager(temp.Path);
        var supervisor = new RecordingProcessSupervisor();
        var lease = CreateLease("203.0.113.199", "tcp+udp");
        var host = new MacUltraRuntimeHost(
            config,
            supervisor,
            state,
            binaryExists: _ => true,
            socketExists: _ => true,
            portListeningProbe: _ => true,
            leaseSender: (_, _, _, _) => Task.FromResult(true),
            socketRoot: Path.Combine(temp.Path, "sockets"));
        var forward = CreateForward("198.51.100.199:443", lease);

        host.Start(forward, lease, "contract-a", "config_sync");

        var bootstrapText = Encoding.UTF8.GetString(Assert.Single(supervisor.Launches).StandardInput);
        Assert.Contains("\"udp_prewarm\":true", bootstrapText, StringComparison.Ordinal);
        Assert.Contains("\"udp_keepalive_interval_seconds\":5", bootstrapText, StringComparison.Ordinal);
        Assert.DoesNotContain("203.0.113.199", bootstrapText, StringComparison.Ordinal);
        Assert.All(lease.Password, value => Assert.Equal(0, value));
        host.Stop(42);
    }

    [Fact]
    public void StartWithCandidates_SendsBatchAndEnablesAutoSelectBeforeListenerReadiness()
    {
        using var temp = new TemporaryDirectory();
        var config = new AgentConfig
        {
            StateDir = temp.Path,
            UltraBin = Path.Combine(temp.Path, "bin", "ultra-client"),
        };
        var state = new StateManager(temp.Path);
        var supervisor = new RecordingProcessSupervisor();
        var first = CreateLease("203.0.113.199", "tcp+udp");
        var second = CreateLease("203.0.113.200", "tcp+udp");
        var forward = CreateForward("198.51.100.199:443", first);
        var host = new MacUltraRuntimeHost(
            config,
            supervisor,
            state,
            binaryExists: _ => true,
            socketExists: _ => true,
            portListeningProbe: _ => true,
            leaseSender: (_, _, _, _) => throw new InvalidOperationException("single sender should not be used"),
            leaseBatchSender: (_, _, leases, _) =>
            {
                Assert.Equal(2, leases.Count);
                return Task.FromResult(true);
            },
            socketRoot: Path.Combine(temp.Path, "sockets"));

        host.Start(forward, new[] { first, second }, "contract-a", "config_sync");

        var bootstrapText = Encoding.UTF8.GetString(Assert.Single(supervisor.Launches).StandardInput);
        Assert.Contains("\"auto_select\":true", bootstrapText, StringComparison.Ordinal);
        Assert.Contains("\"probe_duration_seconds\":30", bootstrapText, StringComparison.Ordinal);
        Assert.Contains("\"probe_samples\":30", bootstrapText, StringComparison.Ordinal);
        Assert.Contains("\"probe_target\":\"1.1.1.1:53\"", bootstrapText, StringComparison.Ordinal);
        Assert.Contains("\"probe_timeout_ms\":800", bootstrapText, StringComparison.Ordinal);
        Assert.Contains("\"probe_interval_ms\":1000", bootstrapText, StringComparison.Ordinal);
        Assert.Contains("\"probe_workers\":4", bootstrapText, StringComparison.Ordinal);
        Assert.Contains("\"udp_prewarm\":true", bootstrapText, StringComparison.Ordinal);
        Assert.Contains("\"udp_keepalive_interval_seconds\":5", bootstrapText, StringComparison.Ordinal);
        Assert.All(first.Password, value => Assert.Equal(0, value));
        Assert.All(second.Password, value => Assert.Equal(0, value));
        host.Stop(42);
    }

    [Fact]
    public void PayloadCeilingIsReadFromTheRuntimeStatusFile()
    {
        using var temp = new TemporaryDirectory();
        var config = new AgentConfig
        {
            StateDir = temp.Path,
            UltraBin = Path.Combine(temp.Path, "bin", "ultra-client"),
        };
        var state = new StateManager(temp.Path);
        var supervisor = new RecordingProcessSupervisor();
        var lease = CreateLease("203.0.113.199");
        var host = new MacUltraRuntimeHost(
            config,
            supervisor,
            state,
            binaryExists: _ => true,
            socketExists: _ => true,
            portListeningProbe: _ => true,
            leaseSender: (_, _, _, _) => Task.FromResult(true),
            socketRoot: Path.Combine(temp.Path, "sockets"));
        var forward = CreateForward("198.51.100.199:443", lease);

        host.Start(forward, lease, "contract-a", "config_sync");

        // The helper writes the status file the host handed it as "status_path".
        var runtimeDirectory = Assert.Single(Directory.EnumerateDirectories(
            Path.Combine(temp.Path, "runtime"),
            "ultra-42-*"));
        var statusPath = Path.Combine(runtimeDirectory, "status.json");
        Assert.False(File.Exists(statusPath));
        Assert.Equal(0, host.GetPayloadCeiling(42));
        Assert.Equal(0, host.GetPayloadCeiling(7));

        File.WriteAllText(statusPath, """{"forward_id":42,"udp_payload_ceiling":1472,"udp_oversize_drops":1}""");

        Assert.Equal(1472, host.GetPayloadCeiling(42));
        Assert.All(lease.Password, value => Assert.Equal(0, value));
        host.Stop(42);
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

    private static UltraLease CreateLease(string providerIp, string transport = "tcp")
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
