using System.Net;
using System.Net.Sockets;
using System.Text;
using LeikwanBridge.Core.Models;
using LeikwanBridge.Core.Services;
using Xunit;

namespace LeikwanBridge.Core.Tests.Runtime;

public sealed class ConfigPollingServiceTests
{
    [Fact]
    public async Task SyncOnceAsync_PublishesPullStateBeforeApplyingForwards()
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
        var runtimeManager = new RecordingForwardRuntimeManager(config, stateManager);
        using var api = new MasterApiClient(server.BaseUrl, "token");
        var service = new ConfigPollingService(api, runtimeManager, stateManager);
        var order = new List<string>();
        service.PullStateChanged += _ => order.Add("pull-state");
        service.ForwardsUpdated += _ => order.Add("forwards");
        runtimeManager.OnApply = forward => order.Add($"apply:{forward.ForwardId}");

        await service.SyncOnceAsync();

        Assert.Equal(["pull-state", "forwards", "apply:26"], order);
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
                "leg_cooldown_ms": 3000,
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

    private sealed class RecordingForwardRuntimeManager : ForwardRuntimeManager
    {
        public RecordingForwardRuntimeManager(AgentConfig config, StateManager stateManager)
            : base(config, new TestProcessSupervisor(), stateManager, _ => true, _ => true)
        {
        }

        public Action<ForwardConfig>? OnApply { get; set; }

        public override void ApplyForward(ForwardConfig forward)
        {
            OnApply?.Invoke(forward);
        }
    }

    private sealed class PullApiServer : IDisposable
    {
        private readonly HttpListener _listener = new();
        private readonly CancellationTokenSource _cts = new();
        private readonly Task _serveTask;
        private readonly string _payload;

        public PullApiServer(string payload)
        {
            _payload = payload;
            var port = GetFreePort();
            BaseUrl = $"http://127.0.0.1:{port}";
            _listener.Prefixes.Add($"{BaseUrl}/");
            _listener.Start();
            _serveTask = Task.Run(ServeLoopAsync);
        }

        public string BaseUrl { get; }

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

                if (context.Request.Url?.AbsolutePath == "/api/client/configs/pull")
                {
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
