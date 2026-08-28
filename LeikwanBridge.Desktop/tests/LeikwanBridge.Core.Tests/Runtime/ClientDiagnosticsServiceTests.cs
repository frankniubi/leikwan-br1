using System.Diagnostics;
using System.Text.Json;
using LeikwanBridge.Core.Models;
using LeikwanBridge.Core.Services;
using LeikwanBridge.Core.Services.Platform;
using Xunit;

namespace LeikwanBridge.Core.Tests.Runtime;

public sealed class ClientDiagnosticsServiceTests
{
    [Fact]
    public void BuildResultsNeverIncludesRemoteOrCredentialMaterial()
    {
        const string targetCanary = "198.51.100.250:443";
        const string providerCanary = "203.0.113.250";
        const string credentialCanary = "provider-password-canary";
        var config = new AgentConfig
        {
            UltraBin = @"C:\Users\tester\secret-home\ultra-client",
        };
        var supervisor = new DiagnosticSupervisor([4242]);
        var service = new ClientDiagnosticsService(supervisor, config, _ => false);
        var lease = new UltraLease
        {
            ForwardId = 42,
            ProviderIp = providerCanary,
            ProviderPort = 443,
            User = "user"u8.ToArray(),
            Password = System.Text.Encoding.UTF8.GetBytes(credentialCanary),
        };
        var forward = new ForwardConfig
        {
            ForwardId = 42,
            BackendMode = "ultra",
            Mode = "single",
            ListenPort = 12042,
            Target = targetCanary,
            Sni = "secret-sni.example",
            RuntimeLease = lease,
            Endpoints = [new EndpointConfig { Host = providerCanary, IngressAuthToken = credentialCanary }],
        };

        var json = JsonSerializer.Serialize(service.BuildResults([forward]));

        Assert.Contains("\"forward_id\":42", json);
        Assert.Contains("\"managed_process_count\":1", json);
        foreach (var forbidden in new[]
        {
            targetCanary, providerCanary, credentialCanary, "secret-sni", "secret-home",
            "target", "endpoint", "credential", "session_ticket", "provider",
        })
        {
            Assert.DoesNotContain(forbidden, json, StringComparison.OrdinalIgnoreCase);
        }
    }

    private sealed class DiagnosticSupervisor(IReadOnlyList<int> processIds) : IProcessSupervisor
    {
        public event Action<int, string>? ProcessExited { add { } remove { } }
        public Process StartProcess(int forwardId, string role, string label, string exePath, string arguments, byte[]? standardInput = null, bool logArguments = true, string? workingDirectory = null) => new();
        public void StopAll(int forwardId) { }
        public void StopAll() { }
        public bool IsRunning(int forwardId) => processIds.Count > 0;
        public IReadOnlyList<int> GetProcessIds(int forwardId) => processIds;
        public IReadOnlyList<string> GetRunningRoles(int forwardId) => [];
        public void Dispose() { }
    }
}
