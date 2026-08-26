using System.Net;
using System.Net.Sockets;
using System.Text;
using System.Text.Json;
using LeikwanBridge.Core.Helpers;
using LeikwanBridge.Core.Models;
using LeikwanBridge.Core.Services;
using Xunit;

namespace LeikwanBridge.Core.Tests.Runtime;

public sealed class ConfigPollingServiceTests
{
    [Fact]
    public void ApplyAuthoritativeConfig_NullOrMissingSidepath_ClearsPreviousState()
    {
        var config = new AgentConfig
        {
            UdpUotSidepath = new UdpUotSidepathConfig { EnabledForwardIds = [26] },
        };

        config.ApplyAuthoritativeConfig(null);
        Assert.Null(config.UdpUotSidepath);

        config.UdpUotSidepath = new UdpUotSidepathConfig { EnabledForwardIds = [26] };
        using var document = System.Text.Json.JsonDocument.Parse("{}");
        config.ApplyAuthoritativeConfig(document.RootElement);
        Assert.Null(config.UdpUotSidepath);
    }

    [Fact]
    public async Task SyncOnceAsync_PublishesPullStateAndAppliesForwardsBeforePublishingSnapshot()
    {
        using var tempDir = new TempStateDirectory();
        using var server = new PullApiServer(BuildPullResponseJson());
        var config = new AgentConfig
        {
            MasterUrl = server.BaseUrl,
            AgentToken = "token",
            ClientId = 1,
            StateDir = tempDir.Path,
        };
        var stateManager = new StateManager(tempDir.Path);
        var runtimeManager = new RecordingForwardRuntimeManager(config, stateManager, new TestProcessSupervisor());
        using var api = new MasterApiClient(server.BaseUrl, "token");
        var service = new ConfigPollingService(api, runtimeManager, stateManager);
        var order = new List<string>();
        service.PullStateChanged += _ => order.Add("pull-state");
        service.ForwardsUpdated += _ => order.Add("forwards");
        runtimeManager.OnApply = forward => order.Add($"apply:{forward.ForwardId}");

        await service.SyncOnceAsync();

        Assert.Equal(["pull-state", "apply:26", "forwards"], order);
    }

    [Fact]
    public async Task SyncOnceAsync_PullsUdpSidepathConfig_AndStartsUdpAuxRuntime()
    {
        using var tempDir = new TempStateDirectory();
        using var server = new PullApiServer(BuildPullResponseJson());
        var config = new AgentConfig
        {
            MasterUrl = server.BaseUrl,
            AgentToken = "token",
            ClientId = 1,
            StateDir = tempDir.Path,
            BondBin = @"C:\leikwan\bin\leikwan-bond.exe",
            BondIngressBin = @"C:\leikwan\bin\leikwan-ingress.exe",
            WstunnelBin = @"C:\leikwan\bin\wstunnel.exe",
        };
        var stateManager = new StateManager(tempDir.Path);
        var supervisor = new TestProcessSupervisor();
        var runtimeManager = new ForwardRuntimeManager(
            config,
            supervisor,
            stateManager,
            binaryExists: _ => true,
            portListeningProbe: port => port is 20962 or 20963);
        using var api = new MasterApiClient(server.BaseUrl, "token");
        var service = new ConfigPollingService(api, runtimeManager, stateManager);

        await service.SyncOnceAsync();

        Assert.Contains("bond-udp-uot-runtime", supervisor.StartedRoles);

        var runtimeConfigPath = Path.Combine(tempDir.Path, "runtime", "uot-bond-forward-26.json");
        Assert.True(File.Exists(runtimeConfigPath));
        var payload = File.ReadAllText(runtimeConfigPath);
        Assert.Contains("\"listen_udp\": \"0.0.0.0:11024\"", payload);
        Assert.Contains("\"route_key\": \"forward-26.uot\"", payload);
        Assert.Contains("\"session_token\": \"udp-session\"", payload);
    }

    [Fact]
    public async Task SyncOnceAsync_DoesNotApply_WhenSignatureMatches_AndRuntimeIsRunning()
    {
        using var tempDir = new TempStateDirectory();
        using var server = new PullApiServer(BuildPullResponseJson());
        var config = new AgentConfig
        {
            MasterUrl = server.BaseUrl,
            AgentToken = "token",
            ClientId = 1,
            StateDir = tempDir.Path,
            UotBondBin = Path.Combine(tempDir.Path, "missing-uot-runtime.exe"),
            UdpUotSidepath = new UdpUotSidepathConfig
            {
                EnabledForwardIds = [26],
                ShadowRouteSuffix = ".uot",
                SidecarPort = 8999,
                ReorderWindowPackets = 4096,
                ReorderWindowBytes = 16_777_216,
                ReorderTimeoutMs = 25,
            },
        };
        var stateManager = new StateManager(tempDir.Path);
        var forward = BuildForwardFromPullResponse();
        var runtimeManager = new RecordingForwardRuntimeManager(config, stateManager, new AlwaysRunningProcessSupervisor());
        var signature = runtimeManager.ComputeContractSignature(EffectiveForwardConfigBuilder.Build(forward));
        stateManager.SaveApplied(new Dictionary<string, AppliedForwardState>
        {
            ["26"] = new()
            {
                ForwardId = 26,
                ContractSignature = signature,
                UserStopped = false,
                LastApplyReason = "config_sync",
            },
        });

        using var api = new MasterApiClient(server.BaseUrl, "token");
        var service = new ConfigPollingService(api, runtimeManager, stateManager);

        await service.SyncOnceAsync();

        Assert.Empty(runtimeManager.AppliedForwardIds);
    }

    [Fact]
    public async Task SyncOnceAsync_Applies_WhenSignatureChanges_EvenIfRuntimeIsRunning()
    {
        using var tempDir = new TempStateDirectory();
        using var server = new PullApiServer(BuildPullResponseJson());
        var config = new AgentConfig
        {
            MasterUrl = server.BaseUrl,
            AgentToken = "token",
            ClientId = 1,
            StateDir = tempDir.Path,
        };
        var stateManager = new StateManager(tempDir.Path);
        stateManager.SaveApplied(new Dictionary<string, AppliedForwardState>
        {
            ["26"] = new()
            {
                ForwardId = 26,
                ContractSignature = "different-signature",
                UserStopped = false,
                LastApplyReason = "config_sync",
            },
        });

        var runtimeManager = new RecordingForwardRuntimeManager(config, stateManager, new AlwaysRunningProcessSupervisor());

        using var api = new MasterApiClient(server.BaseUrl, "token");
        var service = new ConfigPollingService(api, runtimeManager, stateManager);

        await service.SyncOnceAsync();

        Assert.Equal([26], runtimeManager.AppliedForwardIds);
    }

    [Fact]
    public async Task SyncOnceAsync_StopsPreviouslyAppliedCfModeWithoutApplyingFallback()
    {
        using var tempDir = new TempStateDirectory();
        var payload = BuildPullResponseJson().Replace(
            "\"service_status\": \"active\",",
            "\"service_status\": \"active\",\n              \"backend_mode\": \"cf_mode\",",
            StringComparison.Ordinal);
        using var server = new PullApiServer(payload);
        var config = new AgentConfig
        {
            MasterUrl = server.BaseUrl,
            AgentToken = "token",
            ClientId = 1,
            StateDir = tempDir.Path,
        };
        var stateManager = new StateManager(tempDir.Path);
        stateManager.SaveApplied(new Dictionary<string, AppliedForwardState>
        {
            ["26"] = new()
            {
                ForwardId = 26,
                ContractSignature = "previous-cf-runtime",
                UserStopped = false,
            },
        });
        var runtimeManager = new RecordingForwardRuntimeManager(config, stateManager, new AlwaysRunningProcessSupervisor());
        using var api = new MasterApiClient(server.BaseUrl, "token");
        var service = new ConfigPollingService(api, runtimeManager, stateManager);

        await service.SyncOnceAsync();

        Assert.Equal([26], runtimeManager.StoppedForwardIds);
        Assert.Empty(runtimeManager.AppliedForwardIds);
        Assert.Empty(service.LatestForwards);
        Assert.Empty(stateManager.LoadPullState().AppliedForwardIds);
    }

    [Fact]
    public async Task SyncOnceAsync_StopsUltraWhenLeaseEndpointFailsWithoutAbortingSync()
    {
        using var tempDir = new TempStateDirectory();
        using var server = new PullApiServer(BuildUltraPullResponseJson(), ultraLeaseStatus: 409);
        var config = new AgentConfig
        {
            MasterUrl = server.BaseUrl,
            AgentToken = "token",
            ClientId = 1,
            StateDir = tempDir.Path,
        };
        var stateManager = new StateManager(tempDir.Path);
        var runtimeManager = new RecordingForwardRuntimeManager(config, stateManager, new AlwaysRunningProcessSupervisor());
        using var api = new MasterApiClient(server.BaseUrl, "token");
        var service = new ConfigPollingService(api, runtimeManager, stateManager);

        await service.SyncOnceAsync();

        Assert.Equal([42], runtimeManager.StoppedForwardIds);
        Assert.Empty(runtimeManager.AppliedForwardIds);
        Assert.Single(service.LatestForwards);
    }

    [Fact]
    public async Task SyncOnceAsync_NotModified_ShortCircuitsReconcile()
    {
        using var tempDir = new TempStateDirectory();
        var payload = BuildPullResponseJson().Replace(
            "\"ok\": true,",
            "\"ok\": true,\n  \"config_revision\": \"rev-1\",",
            StringComparison.Ordinal);
        using var server = new PullApiServer(payload) { NotModifiedRevision = "rev-1" };
        var config = new AgentConfig
        {
            MasterUrl = server.BaseUrl,
            AgentToken = "token",
            ClientId = 1,
            StateDir = tempDir.Path,
        };
        var stateManager = new StateManager(tempDir.Path);
        var runtimeManager = new RecordingForwardRuntimeManager(config, stateManager, new AlwaysRunningProcessSupervisor());
        using var api = new MasterApiClient(server.BaseUrl, "token");
        var service = new ConfigPollingService(api, runtimeManager, stateManager);

        await service.SyncOnceAsync();
        Assert.Equal([26], runtimeManager.AppliedForwardIds);

        await service.SyncOnceAsync();
        Assert.Equal([26], runtimeManager.AppliedForwardIds);
    }

    [Fact]
    public async Task SyncOnceAsync_NotModified_RefreshesRunningUltraLease()
    {
        using var tempDir = new TempStateDirectory();
        using var server = new PullApiServer(BuildUltraPullResponseJson(), ultraLeaseStatus: 200)
        {
            SuccessfulUltraLease = true,
            NotModifiedRevision = "rev-ultra",
        };
        var payload = BuildUltraPullResponseJson().Replace(
            "\"ok\": true,",
            "\"ok\": true,\n  \"config_revision\": \"rev-ultra\",",
            StringComparison.Ordinal);
        server.SetPayload(payload);
        var config = new AgentConfig
        {
            MasterUrl = server.BaseUrl,
            AgentToken = "token",
            ClientId = 1,
            StateDir = tempDir.Path,
        };
        var stateManager = new StateManager(tempDir.Path);
        var supervisor = new AlwaysRunningProcessSupervisor();
        var runtimeManager = new RecordingForwardRuntimeManager(config, stateManager, supervisor);
        using var api = new MasterApiClient(server.BaseUrl, "token", "device-a");
        var service = new ConfigPollingService(api, runtimeManager, stateManager);
        var updates = 0;
        service.ForwardsUpdated += _ => updates++;

        await service.SyncOnceAsync();
        Assert.Single(service.LatestForwards);
        Assert.Equal("ultra", service.LatestForwards[0].BackendMode);
        Assert.Equal([42], runtimeManager.AppliedForwardIds);
        stateManager.SaveApplied(new Dictionary<string, AppliedForwardState>
        {
            ["42"] = new AppliedForwardState
            {
                ForwardId = 42,
                Mode = "ultra",
                ListenPort = 12042,
                ProcessRoles = ["ultra-runtime"],
                ContractSignature = runtimeManager.ComputeContractSignature(service.LatestForwards[0]),
            },
        });

        await service.SyncOnceAsync();

        Assert.Equal([42], runtimeManager.RefreshedForwardIds);
        Assert.Equal([42], runtimeManager.AppliedForwardIds);
        Assert.Equal(2, updates);

        supervisor.Running = false;
        await service.SyncOnceAsync();

        Assert.Equal([42, 42], runtimeManager.AppliedForwardIds);
        Assert.Equal([42], runtimeManager.RefreshedForwardIds);
        Assert.Equal(3, updates);
    }

    [Fact]
    public async Task SyncOnceAsync_NotModified_ForcesFullPullWhenUltraPolicyNearExpiry()
    {
        using var tempDir = new TempStateDirectory();
        var initialPayload = BuildUltraPullResponseJson(expiryMinutes: 1).Replace(
            "\"ok\": true,",
            "\"ok\": true,\n  \"config_revision\": \"rev-ultra\",",
            StringComparison.Ordinal);
        using var server = new PullApiServer(initialPayload, ultraLeaseStatus: 200)
        {
            SuccessfulUltraLease = true,
            NotModifiedRevision = "rev-ultra",
            SuccessfulUltraLeaseExpiryMinutes = 1,
        };
        var config = new AgentConfig
        {
            MasterUrl = server.BaseUrl,
            AgentToken = "token",
            ClientId = 1,
            StateDir = tempDir.Path,
        };
        var stateManager = new StateManager(tempDir.Path);
        var supervisor = new AlwaysRunningProcessSupervisor();
        var runtimeManager = new RecordingForwardRuntimeManager(config, stateManager, supervisor);
        using var api = new MasterApiClient(server.BaseUrl, "token", "device-a");
        var service = new ConfigPollingService(api, runtimeManager, stateManager);

        await service.SyncOnceAsync();
        Assert.Equal([42], runtimeManager.AppliedForwardIds);
        stateManager.SaveApplied(new Dictionary<string, AppliedForwardState>
        {
            ["42"] = new AppliedForwardState
            {
                ForwardId = 42,
                Mode = "ultra",
                ListenPort = 12042,
                ProcessRoles = ["ultra-runtime"],
                ContractSignature = runtimeManager.ComputeContractSignature(service.LatestForwards[0]),
            },
        });

        server.SetPayload(BuildUltraPullResponseJson(expiryMinutes: 4).Replace(
            "\"ok\": true,",
            "\"ok\": true,\n  \"config_revision\": \"rev-ultra\",",
            StringComparison.Ordinal));
        await service.SyncOnceAsync();

        server.SuccessfulUltraLeaseExpiryMinutes = 3;
        await service.SyncOnceAsync();

        Assert.Equal([42], runtimeManager.AppliedForwardIds);
        Assert.Equal([42], runtimeManager.RefreshedForwardIds);
        Assert.True(service.LatestForwards[0].UltraAccess!.ExpiresAt > DateTimeOffset.UtcNow.AddMinutes(2));
    }

    private static string BuildPullResponseJson()
    {
        return """
        {
          "ok": true,
          "client": {
            "id": 1,
            "name": "desktop",
            "display_host": "desktop",
            "group_id": null,
            "diag_request_token": "",
            "diag_status": "ok",
            "agent_config_json": {
              "udp_uot_sidepath": {
                "enabled_forward_ids": [26],
                "shadow_route_suffix": ".uot",
                "sidecar_port": 8999,
                "reorder_window_packets": 4096,
                "reorder_window_bytes": 16777216,
                "reorder_timeout_ms": 25
              }
            }
          },
          "forwards": [
            {
              "forward_id": 26,
              "service_status": "active",
              "mode": "bond",
              "listen_port": 11024,
              "target": "193.227.109.82:5201",
              "sni": "v3.leikwanhost.com",
              "path_prefix": "forward-26",
              "transport": "tcp+udp",
              "endpoints": [
                {
                  "endpoint_id": 184,
                  "host": "8.163.90.132",
                  "port": 7001,
                  "route_key": "forward-26",
                  "ingress_auth_token": "token-a",
                  "ingress_protocol": "plain_tcp_mux",
                  "dynamic_priority": 9
                },
                {
                  "endpoint_id": 185,
                  "host": "8.163.90.235",
                  "port": 7001,
                  "route_key": "forward-26",
                  "ingress_auth_token": "token-b",
                  "ingress_protocol": "plain_tcp_mux",
                  "dynamic_priority": 10
                }
              ],
              "bonding": {
                "remote_target": "138.252.162.120:9000",
                "session_token": "tcp-session",
                "udp_session_token": "udp-session",
                "ingress_mode": "plain_tcp_mux",
                "route_key": "forward-26",
                "chunk_size": 131072,
                "max_window_bytes": 16777216,
                "retransmit_ms": 300,
                "connect_timeout_ms": 3000,
                "join_timeout_ms": 400,
                "interactive_chunk_bytes": 16384,
                "latency_guard_bytes": 262144,
                "interactive_rtt_slack_ms": 30,
                "warmup_trigger_bytes": 65536,
                "warmup_slice_bytes": 65536,
                "warmup_leg_delay_ms": 80,
                "bulk_promote_bytes": 262144,
                "leg_cooldown_ms": 1800000,
                "disable_local_rate_limit": true,
                "quic_enabled": false,
                "quic_via_slave": false,
                "wss_standby_enabled": false,
                "ws_priority": 10
              }
            }
          ]
        }
        """;
    }

    private static string BuildUltraPullResponseJson(int expiryMinutes = 4)
    {
        const string target = "198.51.100.10:443";
        var generation = new string('a', 64);
        var targetDigest = UltraPolicy.ComputeTargetDigest(target);
        var expiresAt = DateTimeOffset.UtcNow.AddMinutes(expiryMinutes).ToString("yyyy-MM-dd'T'HH:mm:ss'Z'");
        return $$"""
        {
          "ok": true,
          "client": {
            "id": 1,
            "name": "desktop",
            "display_host": "desktop",
            "group_id": null,
            "current_device_id": "device-a",
            "agent_config_json": {}
          },
          "forwards": [
            {
              "forward_id": 42,
              "service_status": "active",
              "backend_mode": "ultra",
              "mode": "single",
              "listen_port": 12042,
              "target": "{{target}}",
              "transport": "tcp",
              "endpoints": [],
              "ultra_access": {
                "protocol": "leikwan-ultra-policy-v2",
                "forward_id": 42,
                "policy_generation": "{{generation}}",
                "session_ticket": "ticket",
                "expires_at": "{{expiresAt}}",
                "service_state": "active",
                "transport": "tcp",
                "rate_limit_mbps": 20,
                "quota_remaining_bytes": 1048576,
                "target_digest": "{{targetDigest}}"
              }
            }
          ]
        }
        """;
    }

    private static ForwardConfig BuildForwardFromPullResponse()
    {
        return new ForwardConfig
        {
            ForwardId = 26,
            ServiceStatus = "active",
            Mode = "bond",
            ListenPort = 11024,
            Target = "193.227.109.82:5201",
            Sni = "v3.leikwanhost.com",
            PathPrefix = "forward-26",
            Transport = "tcp+udp",
            Endpoints =
            [
                new EndpointConfig
                {
                    EndpointId = 184,
                    Host = "8.163.90.132",
                    Port = 7001,
                    RouteKey = "forward-26",
                    IngressAuthToken = "token-a",
                    IngressProtocol = "plain_tcp_mux",
                    DynamicPriority = 9,
                    HealthStatus = "active",
                },
                new EndpointConfig
                {
                    EndpointId = 185,
                    Host = "8.163.90.235",
                    Port = 7001,
                    RouteKey = "forward-26",
                    IngressAuthToken = "token-b",
                    IngressProtocol = "plain_tcp_mux",
                    DynamicPriority = 10,
                    HealthStatus = "active",
                },
            ],
            Bonding = new BondingConfig
            {
                RemoteTarget = "138.252.162.120:9000",
                SessionToken = "tcp-session",
                UdpSessionToken = "udp-session",
                IngressMode = "plain_tcp_mux",
                RouteKey = "forward-26",
                ChunkSize = 131072,
                MaxWindowBytes = 16777216,
                RetransmitMs = 300,
                ConnectTimeoutMs = 3000,
                JoinTimeoutMs = 400,
                InteractiveChunkBytes = 16384,
                LatencyGuardBytes = 262144,
                InteractiveRttSlackMs = 30,
                WarmupTriggerBytes = 65536,
                WarmupSliceBytes = 65536,
                WarmupLegDelayMs = 80,
                BulkPromoteBytes = 262144,
                LegCooldownMs = 1800000,
                DisableLocalRateLimit = true,
                QuicEnabled = false,
                QuicViaSlave = false,
                WssStandbyEnabled = false,
                WsPriority = 10,
            },
        };
    }

    private sealed class RecordingForwardRuntimeManager : ForwardRuntimeManager
    {
        public RecordingForwardRuntimeManager(AgentConfig config, StateManager stateManager, ProcessSupervisor supervisor)
            : base(config, supervisor, stateManager, _ => true, _ => true)
        {
        }

        public Action<ForwardConfig>? OnApply { get; set; }
        public List<int> AppliedForwardIds { get; } = [];
        public List<int> StoppedForwardIds { get; } = [];
        public List<int> RefreshedForwardIds { get; } = [];

        public override bool RefreshUltraLease(ForwardConfig forward)
        {
            RefreshedForwardIds.Add(forward.ForwardId);
            forward.RuntimeLease?.Dispose();
            forward.RuntimeLease = null;
            return true;
        }

        public override void ApplyForward(ForwardConfig forward)
        {
            ApplyForward(forward, contractSignature: null, applyReason: "manual_apply");
        }

        public override void ApplyForward(ForwardConfig forward, string? contractSignature, string applyReason)
        {
            AppliedForwardIds.Add(forward.ForwardId);
            OnApply?.Invoke(forward);
        }

        public override void StopForward(int forwardId)
        {
            StoppedForwardIds.Add(forwardId);
        }
    }

    private sealed class AlwaysRunningProcessSupervisor : ProcessSupervisor
    {
        public bool Running { get; set; } = true;

        public override bool IsRunning(int forwardId) => Running;
    }

    private sealed class PullApiServer : IDisposable
    {
        private readonly HttpListener _listener = new();
        private readonly CancellationTokenSource _cts = new();
        private readonly Task _serveTask;
        private string _payload;
        private readonly int _ultraLeaseStatus;

        public PullApiServer(string payload, int ultraLeaseStatus = 404)
        {
            _payload = payload;
            _ultraLeaseStatus = ultraLeaseStatus;
            var port = GetFreePort();
            BaseUrl = $"http://127.0.0.1:{port}";
            _listener.Prefixes.Add($"{BaseUrl}/");
            _listener.Start();
            _serveTask = Task.Run(ServeLoopAsync);
        }

        public string BaseUrl { get; }

        public string NotModifiedRevision { get; set; } = "";

        public bool SuccessfulUltraLease { get; set; }

        public int SuccessfulUltraLeaseExpiryMinutes { get; set; } = 3;

        public void SetPayload(string payload) => _payload = payload;

        private async Task ServeLoopAsync()
        {
            while (!_cts.IsCancellationRequested)
            {
                HttpListenerContext context;
                try
                {
                    context = await _listener.GetContextAsync();
                }
                catch (HttpListenerException)
                {
                    break;
                }
                catch (ObjectDisposedException)
                {
                    break;
                }

                if (context.Request.Url?.AbsolutePath == "/api/client/ultra/lease")
                {
                    var body = SuccessfulUltraLease
                        ? BuildSuccessfulUltraLease(context.Request, SuccessfulUltraLeaseExpiryMinutes)
                        : "{\"ok\":false,\"error\":\"quota_exhausted\"}";
                    var bytes = Encoding.UTF8.GetBytes(body);
                    context.Response.StatusCode = _ultraLeaseStatus;
                    context.Response.ContentType = "application/json";
                    context.Response.OutputStream.Write(bytes, 0, bytes.Length);
                    context.Response.OutputStream.Close();
                    continue;
                }

                if (context.Request.Url?.AbsolutePath == "/api/client/configs/pull")
                {
                    if (NotModifiedRevision != ""
                        && (context.Request.Url.Query ?? "").Contains($"since_revision={Uri.EscapeDataString(NotModifiedRevision)}"))
                    {
                        context.Response.StatusCode = 304;
                        context.Response.OutputStream.Close();
                        continue;
                    }
                    var bytes = Encoding.UTF8.GetBytes(_payload);
                    context.Response.StatusCode = 200;
                    context.Response.ContentType = "application/json";
                    context.Response.OutputStream.Write(bytes, 0, bytes.Length);
                    context.Response.OutputStream.Close();
                    continue;
                }

                context.Response.StatusCode = 404;
                context.Response.OutputStream.Close();
            }
        }

        private static string BuildSuccessfulUltraLease(HttpListenerRequest request, int expiryMinutes)
        {
            using var document = JsonDocument.Parse(request.InputStream);
            var item = document.RootElement.GetProperty("forwards")[0];
            var forwardId = item.GetProperty("forward_id").GetInt32();
            var ticket = item.GetProperty("session_ticket").GetString() ?? "";
            var generation = item.GetProperty("policy_generation").GetString() ?? "";
            var targetDigest = item.GetProperty("target_digest").GetString() ?? "";
            var transport = item.GetProperty("transport").GetString() ?? "tcp";
            var expiresAt = DateTimeOffset.UtcNow.AddMinutes(expiryMinutes).ToString("yyyy-MM-dd'T'HH:mm:ss'Z'");
            return JsonSerializer.Serialize(new
            {
                ok = true,
                leases = new[]
                {
                    new
                    {
                        protocol = "leikwan-ultra-policy-v2",
                        forward_id = forwardId,
                        session_ticket = ticket,
                        lease_id = "ul2_test",
                        policy_generation = generation,
                        expires_at = expiresAt,
                        transport,
                        rate_limit_mbps = 20,
                        quota_remaining_bytes = 1048576,
                        target_digest = targetDigest,
                        selection_ref = "selection-reference-abcdefghijklmnop",
                        nonce_hash = new string('b', 64),
                        endpoint = new { ip = "203.0.113.10", port = 443 },
                        credential = new { user = "user", password = "password" },
                    },
                },
            });
        }

        private static int GetFreePort()
        {
            using var listener = new TcpListener(IPAddress.Loopback, 0);
            listener.Start();
            return ((IPEndPoint)listener.LocalEndpoint).Port;
        }

        public void Dispose()
        {
            _cts.Cancel();
            _listener.Close();
            try
            {
                _serveTask.Wait(TimeSpan.FromSeconds(5));
            }
            catch
            {
                // ignore
            }
            _cts.Dispose();
        }
    }
}
