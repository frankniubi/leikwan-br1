using System.Net;
using System.Net.Sockets;
using System.Text;
using System.Text.Json;
using System.Text.Json.Nodes;
using System.Collections.Concurrent;
using LeikwanBridge.Core.Helpers;
using LeikwanBridge.Core.Models;
using LeikwanBridge.Core.Services;
using Xunit;

namespace LeikwanBridge.Core.Tests.Runtime;

public sealed class ConfigPollingServiceTests
{
    [Theory]
    [InlineData(1)]
    [InlineData(3)]
    public async Task ColdUltraMissingPolicyRetriesFullPayloadOnNextPollWithoutRestart(int missingPolls)
    {
        using var tempDir = new TempStateDirectory();
        var complete = JsonNode.Parse(BuildUltraPullResponseJson())!.AsObject();
        complete["config_revision"] = "cold-policy-revision";
        var missing = complete.DeepClone().AsObject();
        missing["forwards"]![0]!.AsObject().Remove("ultra_access");
        using var server = new PullApiServer(missing.ToJsonString(), ultraLeaseStatus: 200)
        {
            SuccessfulUltraLease = true,
            NotModifiedRevision = "cold-policy-revision",
        };
        var config = new AgentConfig
        {
            MasterUrl = server.BaseUrl, AgentToken = "token", ClientId = 1, StateDir = tempDir.Path,
        };
        var state = new StateManager(tempDir.Path);
        var runtime = new RecordingForwardRuntimeManager(config, state, new TestProcessSupervisor());
        using var api = new MasterApiClient(server.BaseUrl, "token", "device-a");
        var service = new ConfigPollingService(api, runtime, state);

        // Broker material can be temporarily unavailable on a newly enabled route.
        for (var i = 0; i < missingPolls; i++)
            await service.SyncOnceAsync();
        Assert.Empty(runtime.AppliedForwardIds);
        Assert.Empty(server.UltraLeaseRequestForwardIds);
        Assert.Equal(missingPolls, server.PullRequestCount); // bounded: no immediate retry loop

        // The stable revision does not change when the broker becomes ready.
        server.SetPayload(complete.ToJsonString());
        await service.SyncOnceAsync();
        Assert.Equal([42], runtime.AppliedForwardIds);
        Assert.Equal(missingPolls + 1, server.PullRequestCount);
    }

    [Fact]
    public async Task RequestControlPlaneRecoveryTriggersAnImmediateFullPullAfterTransientFailure()
    {
        // After a failed pull the recovery request only queues a full pull; the
        // polling loop runs it once the (here shortened) backoff elapses.
        using var tempDir = new TempStateDirectory();
        using var server = new PullApiServer(BuildPullResponseJson()) { PullFailuresRemaining = 1 };
        var config = new AgentConfig
        {
            MasterUrl = server.BaseUrl,
            AgentToken = "token",
            ClientId = 1,
            StateDir = tempDir.Path,
        };
        var state = new StateManager(tempDir.Path);
        var runtime = new RecordingForwardRuntimeManager(config, state, new TestProcessSupervisor());
        using var api = new MasterApiClient(server.BaseUrl, "token");
        var service = new ConfigPollingService(api, runtime, state, null, TimeProvider.System,
            FastBackoff(), leaseBackoff: null);
        var firstFailure = new TaskCompletionSource<object?>(TaskCreationOptions.RunContinuationsAsynchronously);
        var recovered = new TaskCompletionSource<object?>(TaskCreationOptions.RunContinuationsAsynchronously);
        service.SyncError += _ => firstFailure.TrySetResult(null);
        service.ForwardsUpdated += _ => recovered.TrySetResult(null);

        service.Start(intervalSeconds: 60);
        await firstFailure.Task.WaitAsync(TimeSpan.FromSeconds(5));
        service.RequestControlPlaneRecovery();
        await recovered.Task.WaitAsync(TimeSpan.FromSeconds(5));
        await service.StopAsync();

        Assert.True(server.PullRequestCount >= 2);
        Assert.NotEmpty(service.LatestForwards);
    }

    [Fact]
    public async Task SyncOnceAsync_NeverAppliesRuntimeOnCallerUiContext()
    {
        using var temp = new TempStateDirectory();
        using var server = new PullApiServer(BuildTwoForwardPullResponseJson());
        var config = new AgentConfig { MasterUrl = server.BaseUrl, AgentToken = "token", ClientId = 1, StateDir = temp.Path };
        var state = new StateManager(temp.Path);
        var runtime = new RecordingForwardRuntimeManager(config, state, new TestProcessSupervisor());
        var seen = new ConcurrentBag<bool>();
        runtime.OnApply = _ => seen.Add(SynchronizationContext.Current is UiContext);
        using var api = new MasterApiClient(server.BaseUrl, "token", "device-a");
        var service = new ConfigPollingService(api, runtime, state);
        var previous = SynchronizationContext.Current;
        Task pending;
        try
        {
            SynchronizationContext.SetSynchronizationContext(new UiContext());
            pending = service.SyncOnceAsync();
        }
        finally { SynchronizationContext.SetSynchronizationContext(previous); }
        await pending.WaitAsync(TimeSpan.FromSeconds(5));
        Assert.Equal(2, seen.Count);
        Assert.Equal(2, seen.Count);
    }

    private sealed class UiContext : SynchronizationContext
    {
        public override void Post(SendOrPostCallback callback, object? state) => ThreadPool.QueueUserWorkItem(_ =>
        {
            var previous = Current;
            try { SetSynchronizationContext(this); callback(state); }
            finally { SetSynchronizationContext(previous); }
        });
    }

    [Fact]
    public async Task SyncOnceAsync_ActivatesIndependentForwardsConcurrentlyAndIsolatesFailure()
    {
        using var tempDir = new TempStateDirectory();
        using var server = new PullApiServer(BuildTwoForwardPullResponseJson());
        var config = new AgentConfig
        {
            MasterUrl = server.BaseUrl,
            AgentToken = "token",
            ClientId = 1,
            StateDir = tempDir.Path,
        };
        var stateManager = new StateManager(tempDir.Path);
        var runtimeManager = new RecordingForwardRuntimeManager(config, stateManager, new TestProcessSupervisor());
        var started = new ConcurrentDictionary<int, DateTimeOffset>();
        runtimeManager.OnApply = forward =>
        {
            started[forward.ForwardId] = DateTimeOffset.UtcNow;
            Thread.Sleep(250);
            if (forward.ForwardId == 901)
                throw new InvalidOperationException("isolated failure");
        };
        using var api = new MasterApiClient(server.BaseUrl, "token", "device-a");
        var service = new ConfigPollingService(api, runtimeManager, stateManager);

        var stopwatch = System.Diagnostics.Stopwatch.StartNew();
        await service.SyncOnceAsync();
        stopwatch.Stop();

        Assert.Equal(2, started.Count);
        Assert.True(started.ContainsKey(901));
        Assert.True(started.ContainsKey(902));
        var startGap = (started[901] - started[902]).Duration();
        Assert.True(startGap < TimeSpan.FromMilliseconds(100), $"forwards were activated serially: elapsed={stopwatch.Elapsed}, gap={startGap}");
    }

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
    public async Task SyncOnceAsync_MacRejectsUnsupportedModeBeforeStateOrRuntimeSideEffects()
    {
        using var tempDir = new TempStateDirectory();
        var payload = BuildPullResponseJson()
            .Replace(
                "\"service_status\": \"active\",",
                "\"service_status\": \"active\",\n              \"backend_mode\": \"current_mode\",",
                StringComparison.Ordinal)
            .Replace("\"mode\": \"bond\"", "\"mode\": \"mptcp\"", StringComparison.Ordinal);
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
                ContractSignature = "previous-unsupported-runtime",
                UserStopped = false,
            },
        });
        var runtimeManager = new RecordingForwardRuntimeManager(config, stateManager, new AlwaysRunningProcessSupervisor());
        using var api = new MasterApiClient(server.BaseUrl, "token");
        var service = new ConfigPollingService(api, runtimeManager, stateManager, MacForwardPolicy.Instance);
        var rejected = new List<(int ForwardId, string Notice)>();
        service.ForwardRejected += (forwardId, notice) => rejected.Add((forwardId, notice));

        await service.SyncOnceAsync();

        Assert.Equal([(26, MacForwardPolicy.UnsupportedModeNotice)], rejected);
        Assert.Equal([26], runtimeManager.StoppedForwardIds);
        Assert.Empty(runtimeManager.AppliedForwardIds);
        Assert.Empty(service.LatestForwards);
        Assert.Empty(stateManager.LoadPullState().AppliedForwardIds);
        Assert.DoesNotContain("26", stateManager.LoadApplied());
    }

    [Fact]
    public async Task SyncOnceAsync_QueuesConfigRefreshWhenLeaseGrantRotatesWithoutAbortingSync()
    {
        // The panel re-mints the lease ticket whenever the relay material rotates, so a
        // 409 lease_scope_invalid must trigger a config refresh - never a teardown, which
        // looped the forward in production (user-visible "Starting routes" flashing).
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

        Assert.Empty(runtimeManager.StoppedForwardIds);
        Assert.Empty(runtimeManager.AppliedForwardIds);
        Assert.Single(service.LatestForwards);
    }

    [Fact]
    public async Task SyncOnceAsync_QueuesConfigRefreshWhenTheLeaseWasReplayed()
    {
        // lease_replayed means the panel already consumed the nonce this client
        // presented - two refreshes raced, or the first request timed out after
        // the panel committed it. Like the other grant rotations that is repaired
        // by the next config pull; tearing the forward down instead left the route
        // stopped until the client was restarted by hand.
        using var tempDir = new TempStateDirectory();
        using var server = new PullApiServer(BuildUltraPullResponseJson(), ultraLeaseStatus: 409)
        {
            UltraLeaseErrorCode = "lease_replayed",
        };
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

        Assert.Empty(runtimeManager.StoppedForwardIds);
        Assert.Empty(runtimeManager.AppliedForwardIds);
        Assert.Single(service.LatestForwards);
    }

    [Fact]
    public async Task SyncOnceAsync_StillStopsUltraWhenTheLeaseEndpointFailsForAnotherReason()
    {
        // A non-grant failure (for example a 500) keeps the old, safe behaviour: the
        // forward is stopped rather than left running against a stale lease.
        using var tempDir = new TempStateDirectory();
        using var server = new PullApiServer(BuildUltraPullResponseJson(), ultraLeaseStatus: 500);
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
    public async Task SyncOnceAsync_UsesCandidateBatchForDesktopUltra()
    {
        using var tempDir = new TempStateDirectory();
        var payload = BuildUltraPullResponseJson().Replace("\"transport\": \"tcp\"", "\"transport\": \"tcp+udp\"", StringComparison.Ordinal);
        using var server = new PullApiServer(payload, ultraLeaseStatus: 200)
        {
            SuccessfulUltraLease = true,
            SuccessfulUltraLeaseCount = 2,
        };
        var config = new AgentConfig { MasterUrl = server.BaseUrl, AgentToken = "token", ClientId = 1, StateDir = tempDir.Path };
        var stateManager = new StateManager(tempDir.Path);
        var runtimeManager = new RecordingForwardRuntimeManager(config, stateManager, new AlwaysRunningProcessSupervisor());
        ForwardConfig? applied = null;
        runtimeManager.OnApply = forward => applied = forward;
        using var api = new MasterApiClient(server.BaseUrl, "token", "device-a");
        var service = new ConfigPollingService(api, runtimeManager, stateManager);

        await service.SyncOnceAsync();

        Assert.NotNull(applied);
        Assert.Equal(2, applied!.RuntimeLeases.Count);
        Assert.Equal("tcp+udp", applied.RuntimeLeases[0].Transport);
        foreach (var lease in applied.RuntimeLeases)
            lease.Dispose();
    }

    [Fact]
    public async Task SyncOnceAsync_SkipsNativeOutboundForwardInOrdinaryUltraReconciliation()
    {
        using var tempDir = new TempStateDirectory();
        var payload = BuildUltraPullResponseJson()
            .Replace("\"forward_id\": 42", "\"forward_id\": 180", StringComparison.Ordinal)
            .Replace("\"listen_port\": 12042", "\"listen_port\": 54544", StringComparison.Ordinal)
            .Replace(
                "\"backend_mode\": \"ultra\",",
                "\"backend_mode\": \"ultra\",\n              \"native_outbound\": true,\n              \"native_direction\": \"Mainland China -> Hong Kong\",",
                StringComparison.Ordinal);
        using var server = new PullApiServer(payload, ultraLeaseStatus: 200)
        {
            SuccessfulUltraLease = true,
        };
        var config = new AgentConfig
        {
            MasterUrl = server.BaseUrl,
            AgentToken = "token",
            ClientId = 1,
            StateDir = tempDir.Path,
        };
        var state = new StateManager(tempDir.Path);
        state.SaveApplied(new Dictionary<string, AppliedForwardState>
        {
            ["180"] = new()
            {
                ForwardId = 180,
                Mode = "ultra",
                ListenPort = 54544,
                ProcessRoles = ["ultra-runtime"],
                UserStopped = false,
            },
        });
        var runtime = new RecordingForwardRuntimeManager(config, state, new AlwaysRunningProcessSupervisor());
        using var api = new MasterApiClient(server.BaseUrl, "token", "device-a");
        var service = new ConfigPollingService(api, runtime, state);

        await service.SyncOnceAsync();

        Assert.Empty(runtime.AppliedForwardIds);
        Assert.Empty(runtime.RefreshedForwardIds);
        Assert.Equal([180], runtime.StoppedForwardIds);
        Assert.Empty(server.UltraLeaseRequestForwardIds);
        Assert.DoesNotContain("180", state.LoadApplied().Keys);
        Assert.True(service.LatestForwards.Single(forward => forward.ForwardId == 180).NativeOutboundEnabled);
    }

    [Fact]
    public async Task SyncOnceAsync_PreservesRunningUltraWhenLeaseScopeRotates()
    {
        using var tempDir = new TempStateDirectory();
        using var server = new PullApiServer(BuildUltraPullResponseJson(), ultraLeaseStatus: 200)
        {
            SuccessfulUltraLease = true,
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
        var signature = runtimeManager.ComputeContractSignature(service.LatestForwards[0]);
        stateManager.SaveApplied(new Dictionary<string, AppliedForwardState>
        {
            ["42"] = new()
            {
                ForwardId = 42,
                Mode = "ultra",
                ListenPort = 12042,
                ProcessRoles = ["ultra-runtime"],
                ContractSignature = signature,
            },
        });

        server.SuccessfulUltraLease = false;
        server.UltraLeaseStatus = 409;
        await service.SyncOnceAsync();

        Assert.Empty(runtimeManager.StoppedForwardIds);
        Assert.Equal([42], runtimeManager.AppliedForwardIds);

        server.SuccessfulUltraLease = true;
        server.UltraLeaseStatus = 200;
        await service.SyncOnceAsync();

        Assert.Equal([42], runtimeManager.RefreshedForwardIds);
        Assert.Empty(runtimeManager.StoppedForwardIds);
    }

    [Fact]
    public async Task SyncOnceAsync_ReusesLastKnownUltraPolicyWhenPullOmitsIt()
    {
        using var tempDir = new TempStateDirectory();
        var payload = BuildUltraPullResponseJson();
        using var server = new PullApiServer(payload, ultraLeaseStatus: 200)
        {
            SuccessfulUltraLease = true,
        };
        var config = new AgentConfig
        {
            MasterUrl = server.BaseUrl,
            AgentToken = "token",
            ClientId = 1,
            StateDir = tempDir.Path,
        };
        var stateManager = new StateManager(tempDir.Path);
        var runtimeManager = new RecordingForwardRuntimeManager(config, stateManager, new AlwaysRunningProcessSupervisor());
        using var api = new MasterApiClient(server.BaseUrl, "token", "device-a");
        var service = new ConfigPollingService(api, runtimeManager, stateManager);

        await service.SyncOnceAsync();
        stateManager.SaveApplied(new Dictionary<string, AppliedForwardState>
        {
            ["42"] = new()
            {
                ForwardId = 42,
                Mode = "ultra",
                ListenPort = 12042,
                ProcessRoles = ["ultra-runtime"],
                ContractSignature = runtimeManager.ComputeContractSignature(service.LatestForwards[0]),
            },
        });

        var missingPolicy = JsonNode.Parse(payload)!.AsObject();
        missingPolicy["forwards"]![0]!.AsObject().Remove("ultra_access");
        server.SetPayload(missingPolicy.ToJsonString());

        await service.SyncOnceAsync();

        Assert.NotNull(service.LatestForwards.Single().UltraAccess);
        Assert.Equal([42], runtimeManager.RefreshedForwardIds);
        Assert.Empty(runtimeManager.StoppedForwardIds);
    }

    [Fact]
    public async Task SyncOnceAsync_NeverReusesAPolicyGenerationThePanelRefused()
    {
        // The panel derives policy_generation from the request alone, so once a
        // generation is refused it is refused on every retry. Recycling it made
        // the agent answer the same 409 for ever while the forward stayed enabled
        // with no lease - an outage that needed a restart to clear.
        using var tempDir = new TempStateDirectory();
        var payload = BuildUltraPullResponseJson();
        using var server = new PullApiServer(payload, ultraLeaseStatus: 200)
        {
            SuccessfulUltraLease = true,
        };
        var config = new AgentConfig
        {
            MasterUrl = server.BaseUrl,
            AgentToken = "token",
            ClientId = 1,
            StateDir = tempDir.Path,
        };
        var stateManager = new StateManager(tempDir.Path);
        var runtimeManager = new RecordingForwardRuntimeManager(config, stateManager, new AlwaysRunningProcessSupervisor());
        using var api = new MasterApiClient(server.BaseUrl, "token", "device-a");
        var service = new ConfigPollingService(api, runtimeManager, stateManager);

        await service.SyncOnceAsync();
        var refusedGeneration = service.LatestForwards.Single().UltraAccess!.PolicyGeneration;
        Assert.Equal(new string('a', 64), refusedGeneration);

        stateManager.SaveApplied(new Dictionary<string, AppliedForwardState>
        {
            ["42"] = new()
            {
                ForwardId = 42,
                Mode = "ultra",
                ListenPort = 12042,
                ProcessRoles = ["ultra-runtime"],
                ContractSignature = runtimeManager.ComputeContractSignature(service.LatestForwards[0]),
            },
        });

        // The panel now refuses this generation and the pull arrives without a policy.
        server.SuccessfulUltraLease = false;
        server.UltraLeaseStatus = 409;
        server.UltraLeaseErrorCode = "policy_generation_invalid";
        var missingPolicy = JsonNode.Parse(payload)!.AsObject();
        missingPolicy["forwards"]![0]!.AsObject().Remove("ultra_access");
        server.SetPayload(missingPolicy.ToJsonString());

        await service.SyncOnceAsync();

        // Precondition: the last known policy was reused for this round - that is
        // what made the refusal observable at all - and the lease endpoint was
        // really asked, so the refusal was recorded by the failure path.
        Assert.NotNull(service.LatestForwards.Single().UltraAccess);
        Assert.Contains(42, server.UltraLeaseRequestForwardIds);

        // The panel is healthy again but still publishes no policy for this
        // forward. The refused generation must not come back: replaying it would
        // re-enter the same 409 loop for ever.
        server.SuccessfulUltraLease = true;
        server.UltraLeaseStatus = 200;
        await service.SyncOnceAsync();

        Assert.Null(service.LatestForwards.Single().UltraAccess);
        Assert.Empty(runtimeManager.StoppedForwardIds);
        Assert.Equal([42], runtimeManager.AppliedForwardIds);
    }

    [Fact]
    public async Task SyncOnceAsync_PreservesRunningUltraWhenLeaseEndpointHasTransientServerError()
    {
        using var tempDir = new TempStateDirectory();
        using var server = new PullApiServer(BuildUltraPullResponseJson(), ultraLeaseStatus: 200)
        {
            SuccessfulUltraLease = true,
        };
        var config = new AgentConfig
        {
            MasterUrl = server.BaseUrl,
            AgentToken = "token",
            ClientId = 1,
            StateDir = tempDir.Path,
        };
        var stateManager = new StateManager(tempDir.Path);
        var runtimeManager = new RecordingForwardRuntimeManager(config, stateManager, new AlwaysRunningProcessSupervisor());
        using var api = new MasterApiClient(server.BaseUrl, "token", "device-a");
        var service = new ConfigPollingService(api, runtimeManager, stateManager);

        await service.SyncOnceAsync();
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

        server.SuccessfulUltraLease = false;
        server.UltraLeaseStatus = 502;
        await service.SyncOnceAsync();

        Assert.Empty(runtimeManager.StoppedForwardIds);
        Assert.Equal([42], runtimeManager.AppliedForwardIds);
    }

    [Fact]
    public async Task SyncOnceAsync_PreservesRunningUltraWhenLeaseDeliveryFails()
    {
        using var tempDir = new TempStateDirectory();
        using var server = new PullApiServer(BuildUltraPullResponseJson(), ultraLeaseStatus: 200)
        {
            SuccessfulUltraLease = true,
        };
        var config = new AgentConfig
        {
            MasterUrl = server.BaseUrl,
            AgentToken = "token",
            ClientId = 1,
            StateDir = tempDir.Path,
        };
        var stateManager = new StateManager(tempDir.Path);
        var runtimeManager = new RecordingForwardRuntimeManager(config, stateManager, new AlwaysRunningProcessSupervisor())
        {
            RefreshUltraLeaseResult = false,
        };
        using var api = new MasterApiClient(server.BaseUrl, "token", "device-a");
        var service = new ConfigPollingService(api, runtimeManager, stateManager);

        await service.SyncOnceAsync();
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

        Assert.Empty(runtimeManager.StoppedForwardIds);
        Assert.Equal([42], runtimeManager.RefreshedForwardIds);
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
    public async Task SyncOnceAsync_NotModified_WaitsForRenewAfterBeforeRefreshingUltraLease()
    {
        using var tempDir = new TempStateDirectory();
        var renewAfter = DateTimeOffset.UtcNow.AddMinutes(3).ToString("yyyy-MM-dd'T'HH:mm:ss'Z'");
        var payload = BuildUltraPullResponseJson().Replace(
            "\"expires_at\": \"",
            $"\"renew_after\": \"{renewAfter}\",\n                \"expires_at\": \"",
            StringComparison.Ordinal).Replace(
            "\"ok\": true,",
            "\"ok\": true,\n  \"config_revision\": \"rev-ultra\",",
            StringComparison.Ordinal);
        using var server = new PullApiServer(payload, ultraLeaseStatus: 200)
        {
            SuccessfulUltraLease = true,
            NotModifiedRevision = "rev-ultra",
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
        stateManager.SaveApplied(new Dictionary<string, AppliedForwardState>
        {
            ["42"] = new()
            {
                ForwardId = 42,
                Mode = "ultra",
                ListenPort = 12042,
                ProcessRoles = ["ultra-runtime"],
                ContractSignature = runtimeManager.ComputeContractSignature(service.LatestForwards[0]),
            },
        });

        await service.SyncOnceAsync();

        Assert.Equal([42], runtimeManager.AppliedForwardIds);
        Assert.Empty(runtimeManager.RefreshedForwardIds);
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

    [Fact]
    public async Task SyncOnceAsync_NotModified_ForcesFullPullWhenUltraPolicyProtocolChanges()
    {
        using var tempDir = new TempStateDirectory();
        var legacyPayload = BuildUltraPullResponseJson().Replace(
            "\"protocol\": \"leikwan-ultra-policy-v2\"",
            "\"protocol\": \"legacy-ultra\"",
            StringComparison.Ordinal).Replace(
            "\"ok\": true,",
            "\"ok\": true,\n  \"config_revision\": \"rev-ultra\",",
            StringComparison.Ordinal);
        using var server = new PullApiServer(legacyPayload, ultraLeaseStatus: 200)
        {
            SuccessfulUltraLease = true,
            NotModifiedRevision = "rev-ultra",
        };
        var config = new AgentConfig
        {
            MasterUrl = server.BaseUrl,
            AgentToken = "token",
            ClientId = 1,
            StateDir = tempDir.Path,
        };
        var stateManager = new StateManager(tempDir.Path);
        var runtimeManager = new RecordingForwardRuntimeManager(config, stateManager, new AlwaysRunningProcessSupervisor());
        using var api = new MasterApiClient(server.BaseUrl, "token", "device-a");
        var service = new ConfigPollingService(api, runtimeManager, stateManager);

        await service.SyncOnceAsync();
        Assert.Empty(runtimeManager.AppliedForwardIds);
        Assert.Equal([42], runtimeManager.StoppedForwardIds);

        server.SetPayload(BuildUltraPullResponseJson().Replace(
            "\"ok\": true,",
            "\"ok\": true,\n  \"config_revision\": \"rev-ultra\",",
            StringComparison.Ordinal));
        await service.SyncOnceAsync();
        Assert.Empty(runtimeManager.AppliedForwardIds);

        await service.SyncOnceAsync();
        Assert.Equal([42], runtimeManager.AppliedForwardIds);
    }

    [Fact]
    public async Task SyncOnceAsync_HotRefreshesLeaseWhenOnlyUltraPolicyGenerationChanges()
    {
        using var tempDir = new TempStateDirectory();
        using var server = new PullApiServer(BuildUltraPullResponseJson(), ultraLeaseStatus: 200)
        {
            SuccessfulUltraLease = true,
        };
        var config = new AgentConfig
        {
            MasterUrl = server.BaseUrl,
            AgentToken = "token",
            ClientId = 1,
            StateDir = tempDir.Path,
        };
        var stateManager = new StateManager(tempDir.Path);
        var runtimeManager = new RecordingForwardRuntimeManager(config, stateManager, new AlwaysRunningProcessSupervisor());
        using var api = new MasterApiClient(server.BaseUrl, "token", "device-a");
        var service = new ConfigPollingService(api, runtimeManager, stateManager);

        await service.SyncOnceAsync();
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
        server.SetPayload(BuildUltraPullResponseJson(generationCharacter: 'b'));

        await service.SyncOnceAsync();

        Assert.Equal([42], runtimeManager.AppliedForwardIds);
        Assert.Equal([42], runtimeManager.RefreshedForwardIds);
    }

    [Theory]
    [InlineData(false)]
    [InlineData(true)]
    public async Task SyncOnceAsync_TargetedGrantRefreshDoesNotStarveDueSibling(bool renewalDue)
    {
        using var tempDir = new TempStateDirectory();
        var payloadNode = JsonNode.Parse(BuildTwoUltraPullResponseJson())!.AsObject();
        payloadNode["config_revision"] = "rev-ultra-two";
        var payload = payloadNode.ToJsonString();
        using var server = new PullApiServer(payload, ultraLeaseStatus: 200)
        {
            SuccessfulUltraLease = true,
            NotModifiedRevision = "rev-ultra-two",
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
        var clock = new LeaseTestClock();
        var service = new ConfigPollingService(api, runtimeManager, stateManager, forwardPolicy: null, timeProvider: clock);

        await service.SyncOnceAsync();
        stateManager.SaveApplied(service.LatestForwards.ToDictionary(
            forward => forward.ForwardId.ToString(),
            forward => new AppliedForwardState
            {
                ForwardId = forward.ForwardId,
                Mode = "ultra",
                ListenPort = forward.ListenPort,
                ProcessRoles = ["ultra-runtime"],
                ContractSignature = runtimeManager.ComputeContractSignature(forward),
            }));

        server.FailingUltraForwardIds.Add(42);
        await service.SyncOnceAsync();
        server.FailingUltraForwardIds.Clear();

        if (renewalDue) clock.Advance(TimeSpan.FromMinutes(20));

        var requestsBeforeTargetedPull = server.UltraLeaseRequestForwardIds.Count;
        var refreshesBeforeTargetedPull = runtimeManager.RefreshedForwardIds.Count;
        await service.SyncOnceAsync();

        var targetedRequestIds = server.UltraLeaseRequestForwardIds
            .Skip(requestsBeforeTargetedPull)
            .OrderBy(id => id)
            .ToArray();
        Assert.Equal(renewalDue ? new[] { 42, 43 } : new[] { 42 }, targetedRequestIds);
        Assert.Equal(refreshesBeforeTargetedPull + (renewalDue ? 2 : 1), runtimeManager.RefreshedForwardIds.Count);
        Assert.Equal(renewalDue ? new[] { 42, 43 } : new[] { 42 }, runtimeManager.RefreshedForwardIds.Skip(refreshesBeforeTargetedPull).OrderBy(id => id));
    }

    private static ControlPlaneBackoff FastBackoff()
        => new(initial: TimeSpan.FromMilliseconds(200), cap: TimeSpan.FromMilliseconds(400),
            minimumDelay: TimeSpan.FromMilliseconds(50));

    [Fact]
    public async Task PullFailure_BacksOffExponentiallyAndDefersImmediateResyncs()
    {
        using var tempDir = new TempStateDirectory();
        using var server = new PullApiServer(BuildPullResponseJson()) { PullFailuresRemaining = 100 };
        var config = new AgentConfig { MasterUrl = server.BaseUrl, AgentToken = "token", ClientId = 1, StateDir = tempDir.Path };
        var state = new StateManager(tempDir.Path);
        var runtime = new RecordingForwardRuntimeManager(config, state, new TestProcessSupervisor());
        using var api = new MasterApiClient(server.BaseUrl, "token");
        var clock = new LeaseTestClock();
        var pullBackoff = new ControlPlaneBackoff(clock, random: () => 1.0);
        var service = new ConfigPollingService(api, runtime, state, null, clock, pullBackoff, new ControlPlaneBackoff(clock));

        await Assert.ThrowsAnyAsync<Exception>(() => service.SyncOnceAsync());
        Assert.True(service.IsControlPlaneBackingOff);
        Assert.Equal(TimeSpan.FromSeconds(5), service.NextLoopDelay(TimeSpan.FromSeconds(5), out var backingOff));
        Assert.True(backingOff);

        // Health checks / sync errors ask for an immediate re-sync: while the
        // panel is failing that only queues the next (backed-off) pull.
        var pullsBefore = server.PullRequestCount;
        service.TriggerImmediateSync();
        service.RequestControlPlaneRecovery(42);
        await Task.Delay(300);
        Assert.Equal(pullsBefore, server.PullRequestCount);

        await Assert.ThrowsAnyAsync<Exception>(() => service.SyncOnceAsync());
        Assert.Equal(TimeSpan.FromSeconds(10), service.NextLoopDelay(TimeSpan.FromSeconds(5), out _));
        await Assert.ThrowsAnyAsync<Exception>(() => service.SyncOnceAsync());
        await Assert.ThrowsAnyAsync<Exception>(() => service.SyncOnceAsync());
        await Assert.ThrowsAnyAsync<Exception>(() => service.SyncOnceAsync());
        // 5, 10, 20, 40, then capped at 60 s.
        Assert.Equal(TimeSpan.FromSeconds(60), service.NextLoopDelay(TimeSpan.FromSeconds(5), out _));

        // One success resets the sequence and restores the fast re-arm.
        server.PullFailuresRemaining = 0;
        clock.Advance(TimeSpan.FromSeconds(61));
        await service.SyncOnceAsync();
        Assert.False(service.IsControlPlaneBackingOff);
        Assert.Equal(0, pullBackoff.ConsecutiveFailures);
        Assert.Equal(TimeSpan.FromSeconds(5), service.NextLoopDelay(TimeSpan.FromSeconds(5), out backingOff));
        Assert.False(backingOff);
    }

    [Fact]
    public async Task PollingLoop_WaitsTheBackoffInsteadOfTheFixedReArm()
    {
        using var tempDir = new TempStateDirectory();
        using var server = new PullApiServer(BuildPullResponseJson()) { PullFailuresRemaining = 100 };
        var config = new AgentConfig { MasterUrl = server.BaseUrl, AgentToken = "token", ClientId = 1, StateDir = tempDir.Path };
        var state = new StateManager(tempDir.Path);
        var runtime = new RecordingForwardRuntimeManager(config, state, new TestProcessSupervisor());
        using var api = new MasterApiClient(server.BaseUrl, "token");
        // Initial 1.5 s, full draw: an un-backed-off loop would retry after the
        // 1 s interval configured below.
        var service = new ConfigPollingService(api, runtime, state, null, TimeProvider.System,
            new ControlPlaneBackoff(random: () => 1.0, initial: TimeSpan.FromMilliseconds(1500),
                cap: TimeSpan.FromSeconds(3), minimumDelay: TimeSpan.FromMilliseconds(100)),
            leaseBackoff: null);

        service.Start(intervalSeconds: 1);
        await Task.Delay(2200);
        await service.StopAsync();

        // t=0 first pull, t≈1.5 s second pull, next one not before t≈4.5 s.
        Assert.InRange(server.PullRequestCount, 1, 2);
    }

    [Fact]
    public async Task LeaseControlPlaneFailure_BacksOffLeaseRetriesAndPreservesRunningUltra()
    {
        using var tempDir = new TempStateDirectory();
        using var server = new PullApiServer(BuildUltraPullResponseJson(), ultraLeaseStatus: 200)
        {
            SuccessfulUltraLease = true,
        };
        var config = new AgentConfig { MasterUrl = server.BaseUrl, AgentToken = "token", ClientId = 1, StateDir = tempDir.Path };
        var stateManager = new StateManager(tempDir.Path);
        var runtimeManager = new RecordingForwardRuntimeManager(config, stateManager, new AlwaysRunningProcessSupervisor());
        using var api = new MasterApiClient(server.BaseUrl, "token", "device-a");
        var clock = new LeaseTestClock();
        var leaseBackoff = new ControlPlaneBackoff(clock, random: () => 1.0);
        var service = new ConfigPollingService(api, runtimeManager, stateManager, null, clock,
            new ControlPlaneBackoff(clock), leaseBackoff);

        await service.SyncOnceAsync();
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

        server.SuccessfulUltraLease = false;
        server.UltraLeaseStatus = 502;
        await service.SyncOnceAsync();
        var leaseRequests = server.UltraLeaseRequestForwardIds.Count;
        Assert.Equal(1, leaseBackoff.ConsecutiveFailures);
        Assert.True(service.IsControlPlaneBackingOff);
        // The recovery pull is paced by the lease backoff, not the 5 s re-arm.
        Assert.Equal(TimeSpan.FromSeconds(5), service.NextLoopDelay(TimeSpan.FromSeconds(1), out _));

        // Within the backoff window the next pull does not retry the lease.
        await service.SyncOnceAsync();
        Assert.Equal(leaseRequests, server.UltraLeaseRequestForwardIds.Count);
        Assert.Empty(runtimeManager.StoppedForwardIds);

        // After it the lease is retried; a success resets the sequence.
        clock.Advance(TimeSpan.FromSeconds(6));
        server.SuccessfulUltraLease = true;
        server.UltraLeaseStatus = 200;
        await service.SyncOnceAsync();
        Assert.True(server.UltraLeaseRequestForwardIds.Count > leaseRequests);
        Assert.Equal(0, leaseBackoff.ConsecutiveFailures);
        Assert.Empty(runtimeManager.StoppedForwardIds);
    }

    [Fact]
    public async Task IdleRuntimeLeaseRefresh_DeliversALeaseWithoutRestartingTheRuntime()
    {
        using var tempDir = new TempStateDirectory();
        using var server = new PullApiServer(BuildUltraPullResponseJson(), ultraLeaseStatus: 200)
        {
            SuccessfulUltraLease = true,
        };
        var config = new AgentConfig { MasterUrl = server.BaseUrl, AgentToken = "token", ClientId = 1, StateDir = tempDir.Path };
        var stateManager = new StateManager(tempDir.Path);
        var runtimeManager = new RecordingForwardRuntimeManager(config, stateManager, new AlwaysRunningProcessSupervisor());
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

        runtimeManager.RequestUltraLeaseRefresh(42, "idle_policy");
        await service.SyncOnceAsync();

        Assert.Equal([42], runtimeManager.AppliedForwardIds);
        Assert.Contains(42, runtimeManager.RefreshedForwardIds);
        Assert.Empty(runtimeManager.StoppedForwardIds);
    }

    // Windows combo 54, 2026-09-24: a line re-acquisition killed the credential
    // and every stream on forward 243 ended like this for minutes.
    private const string RelayAbortLine =
        "2026/09/24 17:02:22 tcp stream ended forward_id=42 direction=upstream stage=remote_write reason=aborted generation=g selection_ref=r duration_ms=21 bytes=32200";

    [Fact]
    public async Task CredentialSuspectFailures_RenewTheLeaseOncePerBurstWithoutRestart()
    {
        using var tempDir = new TempStateDirectory();
        using var server = new PullApiServer(BuildUltraPullResponseJson(), ultraLeaseStatus: 200)
        {
            SuccessfulUltraLease = true,
        };
        var config = new AgentConfig { MasterUrl = server.BaseUrl, AgentToken = "token", ClientId = 1, StateDir = tempDir.Path };
        var stateManager = new StateManager(tempDir.Path);
        var supervisor = new AlwaysRunningProcessSupervisor();
        var runtimeManager = new RecordingForwardRuntimeManager(config, stateManager, supervisor);
        using var api = new MasterApiClient(server.BaseUrl, "token", "device-a");
        var clock = new LeaseTestClock();
        var service = new ConfigPollingService(api, runtimeManager, stateManager, null, clock,
            new ControlPlaneBackoff(clock), new ControlPlaneBackoff(clock));

        // The loop pulls once, then sleeps its 30 s interval: any further lease
        // request inside this test comes from the failure-driven path.
        service.Start(intervalSeconds: 30);
        try
        {
            Assert.True(SpinWait.SpinUntil(
                () => server.UltraLeaseRequestForwardIds.Count == 1 && service.LatestForwards.Count == 1,
                TimeSpan.FromSeconds(10)));
            Assert.True(SpinWait.SpinUntil(() => { lock (runtimeManager.AppliedForwardIds) return runtimeManager.AppliedForwardIds.Count == 1; },
                TimeSpan.FromSeconds(5)));
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

            // Every stream on the line fails at once: one renewal.
            for (var i = 0; i < 50; i++)
                supervisor.EmitUltraRuntimeStderr(42, RelayAbortLine);
            Assert.Equal(1, service.FailureDrivenRenewalCount);
            Assert.True(SpinWait.SpinUntil(() => { lock (runtimeManager.RefreshedForwardIds) return runtimeManager.RefreshedForwardIds.Contains(42); },
                TimeSpan.FromSeconds(10)));
            Assert.Equal(2, server.UltraLeaseRequestForwardIds.Count);
            // Delivered on the control channel: no re-apply, no stop.
            Assert.Equal([42], runtimeManager.AppliedForwardIds);
            Assert.Empty(runtimeManager.StoppedForwardIds);

            // More failures inside the 20 s window do not renew again.
            for (var i = 0; i < 50; i++)
                supervisor.EmitUltraRuntimeStderr(42, RelayAbortLine);
            // Ordinary telemetry and local-side closes never renew.
            supervisor.EmitUltraRuntimeStderr(42, "2026/09/24 17:02:22 tcp stream ended forward_id=42 direction=upstream stage=local_read reason=reset generation=g");
            supervisor.EmitUltraRuntimeStderr(42, "2026/09/24 17:02:22 active candidate forwarding test succeeded protocol=tcp forward_id=42");
            await Task.Delay(500);
            Assert.Equal(1, service.FailureDrivenRenewalCount);
            Assert.Equal(2, server.UltraLeaseRequestForwardIds.Count);

            // Still failing after the window (the first renewal raced the upload).
            clock.Advance(TimeSpan.FromSeconds(21));
            supervisor.EmitUltraRuntimeStderr(42, "2026/09/24 17:02:43 tcp stream setup failed forward_id=42: provider handshake failed");
            Assert.Equal(2, service.FailureDrivenRenewalCount);
            Assert.True(SpinWait.SpinUntil(() => server.UltraLeaseRequestForwardIds.Count == 3, TimeSpan.FromSeconds(10)));
            Assert.Equal([42], runtimeManager.AppliedForwardIds);
            Assert.Empty(runtimeManager.StoppedForwardIds);
        }
        finally
        {
            await service.StopAsync();
        }
    }

    [Fact]
    public async Task CredentialSuspectFailures_WaitForTheControlPlaneBackoff()
    {
        using var tempDir = new TempStateDirectory();
        using var server = new PullApiServer(BuildUltraPullResponseJson(), ultraLeaseStatus: 200)
        {
            SuccessfulUltraLease = true,
            PullFailuresRemaining = 1,
        };
        var config = new AgentConfig { MasterUrl = server.BaseUrl, AgentToken = "token", ClientId = 1, StateDir = tempDir.Path };
        var stateManager = new StateManager(tempDir.Path);
        var supervisor = new AlwaysRunningProcessSupervisor();
        var runtimeManager = new RecordingForwardRuntimeManager(config, stateManager, supervisor);
        using var api = new MasterApiClient(server.BaseUrl, "token", "device-a");
        var clock = new LeaseTestClock();
        // The frozen clock keeps the first failure's backoff in effect.
        var service = new ConfigPollingService(api, runtimeManager, stateManager, null, clock,
            new ControlPlaneBackoff(clock, random: () => 1.0), new ControlPlaneBackoff(clock));

        service.Start(intervalSeconds: 30);
        try
        {
            Assert.True(SpinWait.SpinUntil(() => service.IsControlPlaneBackingOff, TimeSpan.FromSeconds(10)));
            var pulls = server.PullRequestCount;

            for (var i = 0; i < 20; i++)
                supervisor.EmitUltraRuntimeStderr(42, RelayAbortLine);
            await Task.Delay(700);

            // The renewal is queued (one), but the panel is not hit during the backoff.
            Assert.Equal(1, service.FailureDrivenRenewalCount);
            Assert.Equal(pulls, server.PullRequestCount);
            Assert.Empty(server.UltraLeaseRequestForwardIds);
        }
        finally
        {
            await service.StopAsync();
        }
    }

    [Fact]
    public async Task LongOfflineValidityPolicy_IsReusedWhileThePullOmitsIt()
    {
        // With the offline-validity header the panel may grant 30 min. The
        // last-known-policy fallback must keep using such a policy (it used to
        // reject anything beyond 5m30 as "expiry_window").
        using var tempDir = new TempStateDirectory();
        var complete = JsonNode.Parse(BuildUltraPullResponseJson(expiryMinutes: 30))!.AsObject();
        using var server = new PullApiServer(complete.ToJsonString(), ultraLeaseStatus: 200)
        {
            SuccessfulUltraLease = true,
            SuccessfulUltraLeaseExpiryMinutes = 29,
        };
        var config = new AgentConfig { MasterUrl = server.BaseUrl, AgentToken = "token", ClientId = 1, StateDir = tempDir.Path };
        var stateManager = new StateManager(tempDir.Path);
        var runtimeManager = new RecordingForwardRuntimeManager(config, stateManager, new AlwaysRunningProcessSupervisor());
        using var api = new MasterApiClient(server.BaseUrl, "token", "device-a");
        var service = new ConfigPollingService(api, runtimeManager, stateManager);

        await service.SyncOnceAsync();
        Assert.Equal([42], runtimeManager.AppliedForwardIds);
        var policy = service.LatestForwards[0].UltraAccess;
        Assert.NotNull(policy);

        var missing = complete.DeepClone().AsObject();
        missing["forwards"]![0]!.AsObject().Remove("ultra_access");
        server.SetPayload(missing.ToJsonString());
        await service.SyncOnceAsync();

        Assert.Same(policy, service.LatestForwards[0].UltraAccess);
        Assert.Empty(runtimeManager.StoppedForwardIds);
    }

    private sealed class LeaseTestClock : TimeProvider
    {
        private DateTimeOffset _now = DateTimeOffset.UtcNow;
        public override DateTimeOffset GetUtcNow() => _now;
        public void Advance(TimeSpan duration) => _now += duration;
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

    private static string BuildTwoForwardPullResponseJson()
    {
        return """
        {
          "ok": true,
          "client": {"id": 1, "name": "desktop", "display_host": "desktop", "group_id": null, "agent_config_json": {}},
          "forwards": [
            {"forward_id": 901, "service_status": "active", "backend_mode": "single", "mode": "single", "listen_port": 19001, "target": "198.51.100.1:443", "transport": "tcp", "endpoints": []},
            {"forward_id": 902, "service_status": "active", "backend_mode": "single", "mode": "single", "listen_port": 19002, "target": "198.51.100.2:443", "transport": "tcp", "endpoints": []}
          ]
        }
        """;
    }

    private static string BuildUltraPullResponseJson(int expiryMinutes = 4, char generationCharacter = 'a')
    {
        const string target = "198.51.100.10:443";
        var generation = new string(generationCharacter, 64);
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

    private static string BuildTwoUltraPullResponseJson()
    {
        var root = JsonNode.Parse(BuildUltraPullResponseJson())!.AsObject();
        var forwards = root["forwards"]!.AsArray();
        var second = forwards[0]!.DeepClone()!.AsObject();
        second["forward_id"] = 43;
        second["listen_port"] = 12043;
        second["ultra_access"]!["forward_id"] = 43;
        forwards.Add(second);
        return root.ToJsonString();
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
            : base(config, supervisor, stateManager, binaryExists: _ => true, portListeningProbe: _ => true)
        {
        }

        public Action<ForwardConfig>? OnApply { get; set; }
        public List<int> AppliedForwardIds { get; } = [];
        public List<int> StoppedForwardIds { get; } = [];
        public List<int> RefreshedForwardIds { get; } = [];

        public bool RefreshUltraLeaseResult { get; set; } = true;

        public override bool RefreshUltraLease(ForwardConfig forward)
        {
            // Reconcile runs sibling forwards concurrently. Keep the test
            // recorder from losing writes while retaining List-based assertions.
            lock (RefreshedForwardIds)
                RefreshedForwardIds.Add(forward.ForwardId);
            forward.RuntimeLease?.Dispose();
            forward.RuntimeLease = null;
            return RefreshUltraLeaseResult;
        }

        public override void ApplyForward(ForwardConfig forward)
        {
            ApplyForward(forward, contractSignature: null, applyReason: "manual_apply");
        }

        public override void ApplyForward(ForwardConfig forward, string? contractSignature, string applyReason)
        {
            lock (AppliedForwardIds)
                AppliedForwardIds.Add(forward.ForwardId);
            OnApply?.Invoke(forward);
        }

        public override void StopForward(int forwardId)
        {
            lock (StoppedForwardIds)
                StoppedForwardIds.Add(forwardId);
        }
    }

    private sealed class AlwaysRunningProcessSupervisor : ProcessSupervisor
    {
        public bool Running { get; set; } = true;

        public override bool IsRunning(int forwardId) => Running;

        /// <summary>Feeds a line through the real Ultra runtime stderr path.</summary>
        public void EmitUltraRuntimeStderr(int forwardId, string line) => ObserveUltraRuntimeStderr(forwardId, line);
    }

    private sealed class PullApiServer : IDisposable
    {
        private readonly HttpListener _listener = new();
        private readonly CancellationTokenSource _cts = new();
        private readonly Task _serveTask;
        private string _payload;

        public PullApiServer(string payload, int ultraLeaseStatus = 404)
        {
            _payload = payload;
            UltraLeaseStatus = ultraLeaseStatus;
            var port = GetFreePort();
            BaseUrl = $"http://127.0.0.1:{port}";
            _listener.Prefixes.Add($"{BaseUrl}/");
            _listener.Start();
            _serveTask = Task.Run(ServeLoopAsync);
        }

        public string BaseUrl { get; }

        public string NotModifiedRevision { get; set; } = "";

        public bool SuccessfulUltraLease { get; set; }

        public int SuccessfulUltraLeaseCount { get; set; } = 1;

        public int UltraLeaseStatus { get; set; }
        /// <summary>Error code the lease endpoint answers with when it is not succeeding.</summary>
        public string UltraLeaseErrorCode { get; set; } = "quota_exhausted";

        public int PullFailuresRemaining { get; set; }

        public int PullRequestCount { get; private set; }

        public int SuccessfulUltraLeaseExpiryMinutes { get; set; } = 3;

        public HashSet<int> FailingUltraForwardIds { get; } = [];

        public ConcurrentQueue<int> UltraLeaseRequestForwardIds { get; } = new();

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
                    using var document = JsonDocument.Parse(context.Request.InputStream);
                    var forwardId = document.RootElement.GetProperty("forwards")[0].GetProperty("forward_id").GetInt32();
                    UltraLeaseRequestForwardIds.Enqueue(forwardId);
                    var failing = FailingUltraForwardIds.Contains(forwardId);
                    var body = failing
                        ? "{\"ok\":false,\"error\":\"lease_scope_invalid\"}"
                        : SuccessfulUltraLease
                            ? BuildSuccessfulUltraLease(document, SuccessfulUltraLeaseExpiryMinutes, SuccessfulUltraLeaseCount)
                            : $"{{\"ok\":false,\"error\":\"{UltraLeaseErrorCode}\"}}";
                    var bytes = Encoding.UTF8.GetBytes(body);
                    context.Response.StatusCode = failing ? 409 : UltraLeaseStatus;
                    context.Response.ContentType = "application/json";
                    context.Response.OutputStream.Write(bytes, 0, bytes.Length);
                    context.Response.OutputStream.Close();
                    continue;
                }

                if (context.Request.Url?.AbsolutePath == "/api/client/configs/pull")
                {
                    PullRequestCount++;
                    if (PullFailuresRemaining > 0)
                    {
                        PullFailuresRemaining--;
                        context.Response.StatusCode = 503;
                        context.Response.OutputStream.Close();
                        continue;
                    }
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

        private static string BuildSuccessfulUltraLease(JsonDocument document, int expiryMinutes, int leaseCount)
        {
            var item = document.RootElement.GetProperty("forwards")[0];
            var forwardId = item.GetProperty("forward_id").GetInt32();
            var ticket = item.GetProperty("session_ticket").GetString() ?? "";
            var generation = item.GetProperty("policy_generation").GetString() ?? "";
            var targetDigest = item.GetProperty("target_digest").GetString() ?? "";
            var transport = item.GetProperty("transport").GetString() ?? "tcp";
            var expiresAt = DateTimeOffset.UtcNow.AddMinutes(expiryMinutes).ToString("yyyy-MM-dd'T'HH:mm:ss'Z'");
            var leases = Enumerable.Range(0, Math.Max(1, Math.Min(4, leaseCount))).Select(index => new
            {
                protocol = "leikwan-ultra-policy-v2",
                forward_id = forwardId,
                session_ticket = ticket,
                lease_id = $"ul2_test_{index}",
                policy_generation = generation,
                expires_at = expiresAt,
                transport,
                rate_limit_mbps = 20,
                quota_remaining_bytes = 1048576,
                target_digest = targetDigest,
                selection_ref = $"selection-reference-{index:000}-abcdefghijklmnop",
                nonce_hash = new string((char)('b' + index), 64),
                endpoint = new { ip = $"203.0.113.{10 + index}", port = 443 + index },
                credential = new { user = "user", password = "password" },
            }).ToArray();
            return JsonSerializer.Serialize(new
            {
                ok = true,
                leases,
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
