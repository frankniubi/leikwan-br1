using LeikwanBridge.Core.Helpers;
using LeikwanBridge.Core.Models;
using Serilog;
using System.Text.Json;

namespace LeikwanBridge.Core.Services;

/// <summary>
/// Builds process arguments for bond mode forwards.
/// Port of install_forward_runtime.py bond mode (lines 216-324).
/// </summary>
public sealed class BondRuntimeBuilder
{
    private readonly AgentConfig _config;
    private const string PlainTcpMux = "plain_tcp_mux";

    public BondRuntimeBuilder(AgentConfig config) => _config = config;

    public record RuntimeProcess(
        string Role,
        string Label,
        string ExePath,
        string Arguments,
        bool RequiredForReadiness,
        bool CriticalForForwardHealth);

    public record GeneratedFile(string FilePath, string Content);

    public record RuntimeSpec(
        string Mode,
        string IngressMode,
        int ListenPort,
        List<int> LegPorts,
        List<RuntimeProcess> Processes,
        List<GeneratedFile>? GeneratedFiles = null);

    public RuntimeSpec Build(ForwardConfig forward) => BuildBondRuntime(forward, "");

    public RuntimeSpec BuildFrontend(ForwardConfig forward)
    {
        var frontendMode = (forward.Bonding?.FrontendMode ?? "").Trim().ToLowerInvariant();
        if (frontendMode is not ("single" or "race"))
            throw new InvalidOperationException("sidecar frontend requires bonding.frontend_mode=single|race");
        if (!string.Equals(ModeNormalizer.Normalize(forward.Mode), frontendMode, StringComparison.Ordinal))
            throw new InvalidOperationException("sidecar frontend mode must match forward mode");

        return BuildBondRuntime(forward, frontendMode);
    }

    private RuntimeSpec BuildBondRuntime(ForwardConfig forward, string frontendMode)
    {
        var bond = forward.Bonding ?? throw new InvalidOperationException("bond mode missing bonding config");
        var endpoints = forward.Endpoints.Where(e => !string.IsNullOrEmpty(e.Host) && e.Port > 0).ToList();
        if (frontendMode == "single")
            endpoints = endpoints.Take(1).ToList();
        if (endpoints.Count == 0)
            throw new InvalidOperationException("bond mode requires at least one endpoint");
        if (string.IsNullOrWhiteSpace(bond.RemoteTarget))
            throw new InvalidOperationException("bond mode missing bonding.remote_target");

        var wstunnelBin = _config.GetEffectiveWstunnelBin();
        var bondBin = _config.GetEffectiveBondBin();
        var ingressBin = _config.GetEffectiveBondIngressBin();
        var uotBondBin = _config.GetEffectiveUotBondBin();
        var processes = new List<RuntimeProcess>();
        var generatedFiles = new List<GeneratedFile>();
        var legPorts = new List<int>();
        int forwardId = forward.ForwardId;
        string? strategyPath = null;
        if (frontendMode == "" || !bond.StrategyLocked)
        {
            strategyPath = new ForwardStrategyStore(
                _config.GetEffectiveStateDir(),
                _config.MasterUrl,
                _config.ClientId > 0 ? _config.ClientId : _config.ActiveClientId
            ).EnsureRuntimeFile(forwardId);
        }
        var strategyArgument = strategyPath is null ? "" : $"--strategy-file \"{strategyPath}\" ";
        int listenPort = forward.ListenPort;
        string transport = (forward.Transport ?? "tcp+udp").Trim().ToLowerInvariant();
        string sni = forward.Sni;
        string pathPrefix = forward.PathPrefix;
        string remoteTarget = bond.RemoteTarget;
        string ingressMode = string.IsNullOrWhiteSpace(bond.IngressMode)
            ? "wss_path_mux"
            : bond.IngressMode.Trim().ToLowerInvariant();

        int baseLeg = 20000 + ((forwardId * 37) % 15000);
        bool quicViaSlave = bond.QuicViaSlave;
        int wsPriority = bond.WsPriority > 0 ? bond.WsPriority : 10;
        var quicLegs = SelectActiveQuicLegs(endpoints, bond);
        int joinTimeoutMs = ComputeAdaptiveJoinTimeout(bond, endpoints, quicLegs.Count);
        bool useWssAsActive = quicLegs.Count == 0;
        var udpSidepath = ResolveUdpUotSidepath(forwardId, transport, ingressMode);

        if (transport is "tcp" or "tcp+udp")
        {
            string tcpToken = bond.SessionToken;
            if (string.IsNullOrEmpty(tcpToken))
                throw new InvalidOperationException("bond mode missing session_token for tcp");

            var tcpLegTargets = new List<string>(quicViaSlave ? quicLegs : []);

            for (int i = 0; i < endpoints.Count; i++)
            {
                var ep = endpoints[i];
                int tcpBack = baseLeg + i;
                legPorts.Add(tcpBack);
                int endpointPriority = ep.DynamicPriority > 0 ? ep.DynamicPriority : wsPriority;

                if (ingressMode == PlainTcpMux)
                {
                    string routeKey = FirstNonEmpty(ep.RouteKey, bond.RouteKey, pathPrefix);
                    string authToken = FirstNonEmpty(ep.IngressAuthToken);
                    if (string.IsNullOrWhiteSpace(authToken))
                        throw new InvalidOperationException("bond mode plain_tcp_mux requires ingress_auth_token");

                    var ingressArgs = $"client --listen 127.0.0.1:{tcpBack} " +
                        $"--remote {ep.Host}:{ep.Port} --route-key {routeKey} --auth-token {authToken}";
                    processes.Add(new RuntimeProcess(
                        $"bond-ingress-leg-{i}",
                        $"bond-ingress-leg-{i}",
                        ingressBin,
                        ingressArgs,
                        RequiredForReadiness: true,
                        CriticalForForwardHealth: true));
                    tcpLegTargets.Add(RenderTcpLeg($"127.0.0.1:{tcpBack}", endpointPriority, ep));
                    continue;
                }

                var wstArgs = $"client -L tcp://127.0.0.1:{tcpBack}:{remoteTarget} " +
                    $"--tls-sni-override {sni} --http-headers \"Host: {sni}\" " +
                    $"--http-upgrade-path-prefix \"{pathPrefix}\" wss://{ep.Host}:{ep.Port}";
                processes.Add(new RuntimeProcess(
                    $"bond-wss-leg-{i}",
                    $"bond-wss-leg-{i}",
                    wstunnelBin,
                    wstArgs,
                    RequiredForReadiness: false,
                    CriticalForForwardHealth: true));

                if (useWssAsActive || !bond.WssStandbyEnabled)
                    tcpLegTargets.Add(RenderTcpLeg($"127.0.0.1:{tcpBack}", endpointPriority, ep));
            }

            var bondArgs = $"client --protocol tcp --listen {forward.ListenHost}:{listenPort} " +
                $"--legs \"{string.Join(",", tcpLegTargets)}\" --session-token \"{tcpToken}\" " +
                $"{strategyArgument}--default-strategy latency_best " +
                $"--chunk-size {bond.ChunkSize} " +
                $"--max-window-bytes {bond.MaxWindowBytes} " +
                $"--retransmit-ms {bond.RetransmitMs} " +
                $"--connect-timeout-ms {bond.ConnectTimeoutMs} " +
                $"--join-timeout-ms {joinTimeoutMs} " +
                $"--interactive-chunk-bytes {bond.InteractiveChunkBytes} " +
                $"--latency-guard-bytes {bond.LatencyGuardBytes} " +
                $"--interactive-rtt-slack-ms {bond.InteractiveRttSlackMs} " +
                $"--warmup-trigger-bytes {bond.WarmupTriggerBytes} " +
                $"--warmup-slice-bytes {bond.WarmupSliceBytes} " +
                $"--warmup-leg-delay-ms {bond.WarmupLegDelayMs} " +
                $"--bulk-promote-bytes {bond.BulkPromoteBytes} " +
                $"--leg-cooldown-ms {bond.LegCooldownMs}";
            if (string.Equals(bond.PolicyMode, "latency_first", StringComparison.OrdinalIgnoreCase))
                bondArgs += " --latency-first";
            processes.Add(new RuntimeProcess(
                "bond-runtime",
                "bond-runtime",
                bondBin,
                bondArgs,
                RequiredForReadiness: false,
                CriticalForForwardHealth: true));
        }

        if (udpSidepath != null)
        {
            string routeKey = FirstNonEmpty(bond.RouteKey, endpoints.FirstOrDefault()?.RouteKey, pathPrefix);
            string udpToken = !string.IsNullOrEmpty(bond.UdpSessionToken)
                ? bond.UdpSessionToken : bond.SessionToken;
            if (string.IsNullOrEmpty(udpToken))
                throw new InvalidOperationException("bond mode missing udp_session_token for udp");

            var runtimeConfigPath = Path.Combine(_config.GetEffectiveStateDir(), "runtime", $"uot-bond-forward-{forwardId}.json");
            var statsPath = Path.Combine(_config.GetEffectiveStateDir(), "runtime", $"uot-bond-forward-{forwardId}.stats.json");
            var runtimePayload = BuildUdpUotRuntimePayload(
                forwardId,
                forward.ListenHost,
                listenPort,
                routeKey,
                udpToken,
                udpSidepath,
                endpoints,
                wsPriority,
                statsPath);

            generatedFiles.Add(new GeneratedFile(
                runtimeConfigPath,
                JsonSerializer.Serialize(runtimePayload, new JsonSerializerOptions { WriteIndented = true })));
            processes.Add(new RuntimeProcess(
                "bond-udp-uot-runtime",
                "bond-udp-uot-runtime",
                uotBondBin,
                $"--config \"{runtimeConfigPath}\"",
                RequiredForReadiness: false,
                CriticalForForwardHealth: true));
        }
        else if ((transport is "udp" or "tcp+udp") && ingressMode != PlainTcpMux)
        {
            string udpToken = !string.IsNullOrEmpty(bond.UdpSessionToken)
                ? bond.UdpSessionToken : bond.SessionToken;
            if (string.IsNullOrEmpty(udpToken))
                throw new InvalidOperationException("bond mode missing udp_session_token for udp");

            var udpLegTargets = new List<string>(quicViaSlave ? quicLegs : []);

            for (int i = 0; i < endpoints.Count; i++)
            {
                var ep = endpoints[i];
                int udpBack = baseLeg + 200 + i;
                int endpointPriority = ep.DynamicPriority > 0 ? ep.DynamicPriority : wsPriority;
                var wstArgs = $"client -L tcp://127.0.0.1:{udpBack}:{remoteTarget} " +
                    $"--tls-sni-override {sni} --http-headers \"Host: {sni}\" " +
                    $"--http-upgrade-path-prefix \"{pathPrefix}\" wss://{ep.Host}:{ep.Port}";
                processes.Add(new RuntimeProcess(
                    $"bond-udp-wss-leg-{i}",
                    $"bond-udp-wss-leg-{i}",
                    wstunnelBin,
                    wstArgs,
                    RequiredForReadiness: false,
                    CriticalForForwardHealth: true));

                if (useWssAsActive || !bond.WssStandbyEnabled)
                    udpLegTargets.Add(RenderTcpLeg($"127.0.0.1:{udpBack}", endpointPriority, ep));
            }

            var bondArgs = $"client --protocol udp --listen {forward.ListenHost}:{listenPort} " +
                $"--legs \"{string.Join(",", udpLegTargets)}\" --session-token \"{udpToken}\" " +
                $"{strategyArgument}--default-strategy latency_best " +
                $"--chunk-size {bond.ChunkSize} " +
                $"--max-window-bytes {bond.MaxWindowBytes} " +
                $"--retransmit-ms {bond.RetransmitMs} " +
                $"--connect-timeout-ms {bond.ConnectTimeoutMs} " +
                $"--join-timeout-ms {joinTimeoutMs} " +
                $"--interactive-chunk-bytes {bond.InteractiveChunkBytes} " +
                $"--latency-guard-bytes {bond.LatencyGuardBytes} " +
                $"--interactive-rtt-slack-ms {bond.InteractiveRttSlackMs} " +
                $"--warmup-trigger-bytes {bond.WarmupTriggerBytes} " +
                $"--warmup-slice-bytes {bond.WarmupSliceBytes} " +
                $"--warmup-leg-delay-ms {bond.WarmupLegDelayMs} " +
                $"--bulk-promote-bytes {bond.BulkPromoteBytes} " +
                $"--leg-cooldown-ms {bond.LegCooldownMs}";
            if (string.Equals(bond.PolicyMode, "latency_first", StringComparison.OrdinalIgnoreCase))
                bondArgs += " --latency-first";
            if (string.Equals(bond.PolicyMode, "latency_first", StringComparison.OrdinalIgnoreCase) && bond.UdpPrimaryOnly)
                bondArgs += " --udp-primary-only";
            processes.Add(new RuntimeProcess(
                "bond-udp-runtime",
                "bond-udp-runtime",
                bondBin,
                bondArgs,
                RequiredForReadiness: false,
                CriticalForForwardHealth: true));
        }

        return new RuntimeSpec(frontendMode == "" ? "bond" : frontendMode, ingressMode, listenPort, legPorts, processes, generatedFiles);
    }

    public RuntimeSpec BuildRace(ForwardConfig forward)
    {
        var endpoints = forward.Endpoints.Where(e => !string.IsNullOrEmpty(e.Host) && e.Port > 0).ToList();
        if (endpoints.Count == 0)
            throw new InvalidOperationException("race mode requires endpoints");

        var wstunnelBin = _config.GetEffectiveWstunnelBin();
        var processes = new List<RuntimeProcess>();
        string transport = (forward.Transport ?? "tcp+udp").Trim().ToLowerInvariant();
        string sni = forward.Sni;
        string pathPrefix = forward.PathPrefix;
        string target = forward.Target;
        int listenPort = forward.ListenPort;

        // For race mode on Windows: start wstunnel for first endpoint (simple approach)
        // Full race logic would require a TCP race selector - for now use first endpoint
        var ep = endpoints[0];
        var listenFlags = new List<string>();
        if (transport is "tcp" or "tcp+udp")
            listenFlags.Add($"-L tcp://{forward.WstunnelListenHost}:{listenPort}:{target}");
        if (transport is "udp" or "tcp+udp")
            listenFlags.Add($"-L udp://{forward.WstunnelListenHost}:{listenPort}:{target}?timeout_sec=0");

        var args = $"client {string.Join(" ", listenFlags)} " +
            $"--tls-sni-override {sni} --http-headers \"Host: {sni}\" " +
            $"--http-upgrade-path-prefix \"{pathPrefix}\" wss://{ep.Host}:{ep.Port}";
        processes.Add(new RuntimeProcess(
            "race-runtime",
            "wstunnel-race",
            wstunnelBin,
            args,
            RequiredForReadiness: false,
            CriticalForForwardHealth: true));

        return new RuntimeSpec("race", "", listenPort, [], processes);
    }

    public RuntimeSpec BuildSingle(ForwardConfig forward)
    {
        var endpoints = forward.Endpoints.Where(e => !string.IsNullOrEmpty(e.Host) && e.Port > 0).ToList();
        if (endpoints.Count == 0)
            throw new InvalidOperationException("single mode requires at least one endpoint");

        var wstunnelBin = _config.GetEffectiveWstunnelBin();
        var ep = endpoints[0];
        string transport = (forward.Transport ?? "tcp+udp").Trim().ToLowerInvariant();
        string sni = forward.Sni;
        string pathPrefix = forward.PathPrefix;
        string target = forward.Target;
        int listenPort = forward.ListenPort;

        var listenFlags = new List<string>();
        if (transport is "tcp" or "tcp+udp")
            listenFlags.Add($"-L tcp://{forward.WstunnelListenHost}:{listenPort}:{target}");
        if (transport is "udp" or "tcp+udp")
            listenFlags.Add($"-L udp://{forward.WstunnelListenHost}:{listenPort}:{target}?timeout_sec=0");

        var args = $"client {string.Join(" ", listenFlags)} " +
            $"--tls-sni-override {sni} --http-headers \"Host: {sni}\" " +
            $"--http-upgrade-path-prefix \"{pathPrefix}\" wss://{ep.Host}:{ep.Port}";

        return new RuntimeSpec(
            "single",
            "",
            listenPort,
            [],
            [
                new RuntimeProcess(
                    "single-runtime",
                    "wstunnel",
                    wstunnelBin,
                    args,
                    RequiredForReadiness: false,
                    CriticalForForwardHealth: true),
            ]);
    }

    private UdpUotSidepathConfig? ResolveUdpUotSidepath(int forwardId, string transport, string ingressMode)
    {
        if (!string.Equals(transport, "tcp+udp", StringComparison.OrdinalIgnoreCase))
            return null;

        if (!string.Equals(ingressMode, PlainTcpMux, StringComparison.OrdinalIgnoreCase))
            return null;

        var sidepath = _config.UdpUotSidepath;
        if (sidepath == null)
            return null;

        if (!sidepath.EnabledForwardIds.Contains(forwardId))
            return null;

        sidepath.Validate();
        return sidepath;
    }

    private static Dictionary<string, object?> BuildUdpUotRuntimePayload(
        int forwardId,
        string listenHost,
        int listenPort,
        string routeKey,
        string sessionToken,
        UdpUotSidepathConfig settings,
        List<EndpointConfig> endpoints,
        int defaultPriority,
        string statsPath)
    {
        return new Dictionary<string, object?>
        {
            ["forward_id"] = forwardId,
            ["listen_udp"] = $"{listenHost}:{listenPort}",
            ["route_key"] = routeKey + (string.IsNullOrWhiteSpace(settings.ShadowRouteSuffix) ? ".uot" : settings.ShadowRouteSuffix),
            ["session_token"] = sessionToken,
            ["reorder_window_packets"] = settings.ReorderWindowPackets,
            ["reorder_window_bytes"] = settings.ReorderWindowBytes,
            ["reorder_timeout_ms"] = settings.ReorderTimeoutMs,
            ["delivery_mode"] = settings.DeliveryMode,
            ["wireguard_aware"] = settings.WireguardAware,
            ["scheduler_mode"] = settings.SchedulerMode,
            ["aggregate_rate_bps"] = settings.AggregateRateBps,
            ["queue_target_ms"] = settings.QueueTargetMs,
            ["queue_deadline_ms"] = settings.QueueDeadlineMs,
            ["global_queue_bytes"] = settings.GlobalQueueBytes,
            ["global_queue_packets"] = settings.GlobalQueuePackets,
            ["per_session_queue_bytes"] = settings.PerSessionQueueBytes,
            ["per_session_queue_packets"] = settings.PerSessionQueuePackets,
            ["drr_quantum_bytes"] = settings.DrrQuantumBytes,
            ["chunk_min_bytes"] = settings.ChunkMinBytes,
            ["chunk_target_bytes"] = settings.ChunkTargetBytes,
            ["chunk_max_bytes"] = settings.ChunkMaxBytes,
            ["min_leg_window_bytes"] = settings.MinLegWindowBytes,
            ["max_leg_window_bytes"] = settings.MaxLegWindowBytes,
            ["max_total_outstanding_bytes"] = settings.MaxTotalOutstandingBytes,
            ["progress_interval_bytes"] = settings.ProgressIntervalBytes,
            ["progress_interval_frames"] = settings.ProgressIntervalFrames,
            ["progress_interval_ms"] = settings.ProgressIntervalMs,
            ["finish_skew_budget_ms"] = settings.FinishSkewBudgetMs,
            ["probe_interval_ms"] = settings.ProbeIntervalMs,
            ["max_sessions"] = settings.MaxSessions,
            ["control_queue_packets"] = settings.ControlQueuePackets,
            ["stats_path"] = statsPath,
            ["legs"] = endpoints.Select(endpoint => new Dictionary<string, object?>
            {
                ["endpoint_id"] = endpoint.EndpointId,
                ["host"] = endpoint.Host,
                ["port"] = endpoint.Port,
                ["auth_token"] = endpoint.IngressAuthToken,
                ["dynamic_priority"] = endpoint.DynamicPriority > 0 ? endpoint.DynamicPriority : defaultPriority,
                ["health_role"] = "healthy",
                ["weight"] = ComputeUdpLegWeight(endpoint, defaultPriority),
            }).ToList(),
        };
    }

    private static int ComputeAdaptiveJoinTimeout(BondingConfig bond, List<EndpointConfig> endpoints, int quicLegCount)
    {
        int configured = Math.Max(40, bond.JoinTimeoutMs > 0 ? bond.JoinTimeoutMs : 400);
        int floor = Math.Max(20, bond.JoinTimeoutFloorMs > 0 ? bond.JoinTimeoutFloorMs : 35);
        int perLeg = Math.Max(0, bond.JoinTimeoutPerLegMs > 0 ? bond.JoinTimeoutPerLegMs : 20);
        int ceiling = Math.Max(floor, bond.JoinTimeoutCeilingMs > 0
            ? bond.JoinTimeoutCeilingMs : Math.Min(configured, 220));

        if (!bond.AdaptiveJoinTimeout)
            return Math.Clamp(configured, floor, ceiling);

        int wssLegs = Math.Max(1, endpoints.Count);
        if (quicLegCount == 0)
        {
            int wssOnlyCeiling = Math.Max(ceiling, configured);
            return Math.Clamp(configured, floor, wssOnlyCeiling);
        }

        int totalLegs = Math.Max(1, wssLegs + quicLegCount);
        int adaptive = floor + Math.Max(0, totalLegs - 1) * perLeg;
        return Math.Clamp(adaptive, floor, ceiling);
    }

    private static List<string> SelectActiveQuicLegs(List<EndpointConfig> endpoints, BondingConfig bond)
    {
        if (!bond.QuicEnabled && !bond.QuicViaSlave) return [];
        int limit = Math.Max(1, bond.ActiveLegLimit > 0 ? bond.ActiveLegLimit : 2);
        var selected = new List<string>();
        foreach (var ep in endpoints)
        {
            if (!ep.SupportsQuicLeg) continue;
            if (string.IsNullOrEmpty(ep.Host) || ep.Port <= 0) continue;
            string serverName = !string.IsNullOrEmpty(bond.QuicServerName) ? bond.QuicServerName : ep.Host;
            string insecure = bond.QuicInsecure ? "&insecure=1" : "";
            selected.Add($"quic://{ep.Host}:{ep.Port}?priority={bond.QuicPriority}&server_name={Uri.EscapeDataString(serverName)}{insecure}");
            if (selected.Count >= limit) break;
        }
        return selected;
    }

    private static int ComputeUdpLegWeight(EndpointConfig endpoint, int defaultPriority)
    {
        int priority = endpoint.DynamicPriority > 0 ? endpoint.DynamicPriority : defaultPriority;
        int delta = Math.Max(0, priority - defaultPriority);
        if (delta >= 30) return 1;
        if (delta >= 15) return 2;
        return 8;
    }

    private static string RenderTcpLeg(string target, int priority, EndpointConfig endpoint)
    {
        var queryParams = new List<string> { $"priority={priority}" };
        if (endpoint.EndpointId > 0)
            queryParams.Add($"endpoint_id={endpoint.EndpointId}");
        if (!string.IsNullOrWhiteSpace(endpoint.Host))
            queryParams.Add($"display_host={Uri.EscapeDataString(endpoint.Host)}");
        if (endpoint.LegRole is "primary" or "helper")
            queryParams.Add($"role={Uri.EscapeDataString(endpoint.LegRole)}");
        return $"tcp://{target}?{string.Join("&", queryParams)}";
    }

    private static string FirstNonEmpty(params string?[] values)
    {
        foreach (var value in values)
        {
            if (!string.IsNullOrWhiteSpace(value))
                return value.Trim();
        }

        return "";
    }
}
