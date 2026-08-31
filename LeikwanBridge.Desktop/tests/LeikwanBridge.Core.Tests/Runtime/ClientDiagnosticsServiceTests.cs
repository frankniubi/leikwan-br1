using System.Diagnostics;
using System.Net;
using System.Net.Http;
using System.Text;
using System.Text.Json;
using LeikwanBridge.Core.Models;
using LeikwanBridge.Core.Services;
using LeikwanBridge.Core.Services.Platform;
using Xunit;

namespace LeikwanBridge.Core.Tests.Runtime;

public sealed class ClientDiagnosticsServiceTests
{
    [Fact]
    public void DebugLogRedactionRemovesCredentialMaterial()
    {
        var value = DebugLogUploader.Redact("Authorization: Bearer abc\npassword=hidden token: xyz\nhealthy=true");
        Assert.DoesNotContain("abc", value);
        Assert.DoesNotContain("hidden", value);
        Assert.DoesNotContain("xyz", value);
        Assert.Contains("healthy=true", value);
    }

    [Fact]
    public async Task DebugLogUploaderChunksAndRetries()
    {
        var root = Directory.CreateTempSubdirectory("leikwan-debug-");
        try
        {
            var logs = Path.Combine(root.FullName, "logs");
            var state = Path.Combine(root.FullName, "state");
            Directory.CreateDirectory(logs);
            await File.WriteAllTextAsync(Path.Combine(logs, "client.log"), "password=hidden\n" + new string('x', DebugLogUploader.ChunkBytes + 17));
            var calls = 0;
            using var api = new MasterApiClient(
                "https://panel.example.test",
                "agent-token",
                httpClientFactory: () => new HttpClient(new StubHandler(() =>
                {
                    calls++;
                    return calls == 1
                        ? new HttpResponseMessage(HttpStatusCode.ServiceUnavailable)
                        : new HttpResponseMessage(HttpStatusCode.OK) { Content = new StringContent("{\"ok\":true}", Encoding.UTF8, "application/json") };
                })));
            var uploader = new DebugLogUploader(logs, state);
            var result = await uploader.UploadAsync(new ClientMeta
            {
                DebugCollection = new DebugCollectionMeta
                {
                    Uuid = "123e4567-e89b-42d3-a456-426614174000",
                    Status = "active",
                    ExpiresAt = "2999-01-01T00:00:00Z",
                },
            }, api);
            Assert.Equal("complete", result.Status);
            Assert.True(calls >= 3);
            Assert.True(File.Exists(Path.Combine(state, "debug-upload-state.json")));
        }
        finally
        {
            try { root.Delete(recursive: true); } catch { }
        }
    }

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

    private sealed class StubHandler(Func<HttpResponseMessage> responseFactory) : HttpMessageHandler
    {
        protected override Task<HttpResponseMessage> SendAsync(HttpRequestMessage request, CancellationToken cancellationToken)
            => Task.FromResult(responseFactory());
    }
}
