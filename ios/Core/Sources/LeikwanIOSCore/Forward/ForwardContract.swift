import Foundation

/// Port of `runtime/ForwardMode.kt`.
public enum ForwardMode: String, Equatable {
    case single
    case race
    case bond
    case mptcp
    case ultra
    case unknown

    public static func normalize(_ raw: String?) -> ForwardMode {
        switch (raw ?? "").trimmingCharacters(in: .whitespacesAndNewlines).lowercased() {
        case "single": return .single
        case "race", "failover", "multi-path", "lb": return .race
        case "bond", "bonding", "aggregate", "aggregated": return .bond
        case "mptcp", "mptcp-wireguard", "mptcp-ipsec", "multipath-tcp": return .mptcp
        case "ultra": return .ultra
        default: return .unknown
        }
    }
}

/// Port of `runtime/ForwardStrategy.kt`.
public enum ForwardStrategy: String, Equatable, CaseIterable {
    case speedMax = "speed_max"
    case latencyBest = "latency_best"
    case random = "random"

    public static func fromWireValue(_ value: String?) -> ForwardStrategy {
        let normalized = (value ?? "").trimmingCharacters(in: .whitespacesAndNewlines).lowercased()
        return ForwardStrategy(rawValue: normalized) ?? .latencyBest
    }
}

public struct ForwardEndpoint: Equatable {
    public var endpointId: Int
    public var node: String
    public var host: String
    public var port: Int
    public var cdnHost: String?
    public var priority: Int
    public var weight: Int
    public var healthStatus: String
    public var supportsQuicLeg: Bool
    public var ingressProtocol: String?
    public var ingressAuthToken: String?
    public var dynamicPriority: Int?
    public var nodeId: Int?

    public init(
        endpointId: Int,
        node: String = "",
        host: String,
        port: Int,
        cdnHost: String? = nil,
        priority: Int = 0,
        weight: Int = 0,
        healthStatus: String = "",
        supportsQuicLeg: Bool = false,
        ingressProtocol: String? = nil,
        ingressAuthToken: String? = nil,
        dynamicPriority: Int? = nil,
        nodeId: Int? = nil
    ) {
        self.endpointId = endpointId
        self.node = node
        self.host = host
        self.port = port
        self.cdnHost = cdnHost
        self.priority = priority
        self.weight = weight
        self.healthStatus = healthStatus
        self.supportsQuicLeg = supportsQuicLeg
        self.ingressProtocol = ingressProtocol
        self.ingressAuthToken = ingressAuthToken
        self.dynamicPriority = dynamicPriority
        self.nodeId = nodeId
    }
}

public struct BondingContract: Equatable {
    public var ingressMode: String?
    public var routeKey: String?
    public var sessionToken: String?
    public var udpSessionToken: String?
    public var chunkSize: Int?
    public var maxWindowBytes: Int?
    public var retransmitMs: Int?
    public var connectTimeoutMs: Int?
    public var joinTimeoutMs: Int?
    public var interactiveChunkBytes: Int?
    public var latencyGuardBytes: Int?
    public var interactiveRttSlackMs: Int?
    public var warmupTriggerBytes: Int?
    public var warmupSliceBytes: Int?
    public var warmupLegDelayMs: Int?
    public var bulkPromoteBytes: Int?
    public var legCooldownMs: Int?
    public var remoteTarget: String?
    public var adaptiveJoinTimeout: Bool?
    public var joinTimeoutFloorMs: Int?
    public var joinTimeoutCeilingMs: Int?
    public var joinTimeoutPerLegMs: Int?
    public var quicEnabled: Bool?
    public var quicViaSlave: Bool?
    public var activeLegLimit: Int?
    public var quicActiveLegLimit: Int?
    public var wssStandbyEnabled: Bool?
    public var disableLocalRateLimit: Bool?
    public var quicServerName: String?
    public var quicInsecure: Bool?
    public var quicPriority: Int?
    public var wsPriority: Int?

    /// All fields default to nil (panel omitted them).
    public init() {}

    public init(json obj: [String: Any], topLevelIngressMode: String?) {
        ingressMode = JSONLenient.string(obj["ingress_mode"]) ?? topLevelIngressMode
        routeKey = JSONLenient.string(obj["route_key"])
        sessionToken = JSONLenient.string(obj["session_token"])
        udpSessionToken = JSONLenient.string(obj["udp_session_token"])
        chunkSize = JSONLenient.int(obj["chunk_size"])
        maxWindowBytes = JSONLenient.int(obj["max_window_bytes"])
        retransmitMs = JSONLenient.int(obj["retransmit_ms"])
        connectTimeoutMs = JSONLenient.int(obj["connect_timeout_ms"])
        joinTimeoutMs = JSONLenient.int(obj["join_timeout_ms"])
        interactiveChunkBytes = JSONLenient.int(obj["interactive_chunk_bytes"])
        latencyGuardBytes = JSONLenient.int(obj["latency_guard_bytes"])
        interactiveRttSlackMs = JSONLenient.int(obj["interactive_rtt_slack_ms"])
        warmupTriggerBytes = JSONLenient.int(obj["warmup_trigger_bytes"])
        warmupSliceBytes = JSONLenient.int(obj["warmup_slice_bytes"])
        warmupLegDelayMs = JSONLenient.int(obj["warmup_leg_delay_ms"])
        bulkPromoteBytes = JSONLenient.int(obj["bulk_promote_bytes"])
        legCooldownMs = JSONLenient.int(obj["leg_cooldown_ms"])
        remoteTarget = JSONLenient.string(obj["remote_target"])
        adaptiveJoinTimeout = JSONLenient.bool(obj["adaptive_join_timeout"])
        joinTimeoutFloorMs = JSONLenient.int(obj["join_timeout_floor_ms"])
        joinTimeoutCeilingMs = JSONLenient.int(obj["join_timeout_ceiling_ms"])
        joinTimeoutPerLegMs = JSONLenient.int(obj["join_timeout_per_leg_ms"])
        quicEnabled = JSONLenient.bool(obj["quic_enabled"])
        quicViaSlave = JSONLenient.bool(obj["quic_via_slave"])
        activeLegLimit = JSONLenient.int(obj["active_leg_limit"])
        quicActiveLegLimit = JSONLenient.int(obj["quic_active_leg_limit"])
        wssStandbyEnabled = JSONLenient.bool(obj["wss_standby_enabled"])
        disableLocalRateLimit = JSONLenient.bool(obj["disable_local_rate_limit"])
        quicServerName = JSONLenient.string(obj["quic_server_name"])
        quicInsecure = JSONLenient.bool(obj["quic_insecure"])
        quicPriority = JSONLenient.int(obj["quic_priority"])
        wsPriority = JSONLenient.int(obj["ws_priority"])
    }
}

public struct MptcpContract: Equatable {
    public var sidecarHost: String?
    public var sidecarPort: Int?

    public init(sidecarHost: String?, sidecarPort: Int?) {
        self.sidecarHost = sidecarHost
        self.sidecarPort = sidecarPort
    }
}

/// One forward from `configs/pull` (port of `runtime/ForwardContract.kt`).
/// `ultraAccess` keeps the raw JSON object so `UltraPolicy` can validate it.
public struct ForwardContract {
    public var forwardId: Int64
    public var serviceName: String
    public var direction: String
    public var target: String
    public var listenPort: Int
    public var mode: ForwardMode
    public var rawMode: String
    public var backendMode: String
    public var contractVersion: Int
    public var clientMode: String
    public var runtimePreference: String
    public var bondIngressMode: String
    public var activeFamily: String
    public var aggregationEnabled: Bool
    public var targetContract: String
    public var sidecarRequired: Bool
    public var bondSidecarPointId: Int64?
    public var transport: String
    public var serviceStatus: String
    public var endpointMode: String
    public var sni: String
    public var pathPrefix: String
    public var speedLimitMbps: Int?
    public var endpoints: [ForwardEndpoint]
    public var bonding: BondingContract?
    public var mptcp: MptcpContract?
    public var ultraAccess: [String: Any]?
    public var routeRuleHelperOnly: Bool
    /// Panel `native_outbound`: on an Ultra forward this is a native outbound rule, which is
    /// configuration for the native outbound relay only (Windows `IsNativeOutboundRule`).
    public var nativeOutbound: Bool

    public init(
        forwardId: Int64,
        serviceName: String = "",
        direction: String = "",
        target: String = "",
        listenPort: Int = 0,
        mode: ForwardMode = .unknown,
        rawMode: String = "",
        backendMode: String = "",
        contractVersion: Int = 0,
        clientMode: String = "",
        runtimePreference: String = "",
        bondIngressMode: String = "",
        activeFamily: String = "",
        aggregationEnabled: Bool = false,
        targetContract: String = "",
        sidecarRequired: Bool = false,
        bondSidecarPointId: Int64? = nil,
        transport: String = "",
        serviceStatus: String = "",
        endpointMode: String = "",
        sni: String = "",
        pathPrefix: String = "",
        speedLimitMbps: Int? = nil,
        endpoints: [ForwardEndpoint] = [],
        bonding: BondingContract? = nil,
        mptcp: MptcpContract? = nil,
        ultraAccess: [String: Any]? = nil,
        routeRuleHelperOnly: Bool = false,
        nativeOutbound: Bool = false
    ) {
        self.forwardId = forwardId
        self.serviceName = serviceName
        self.direction = direction
        self.target = target
        self.listenPort = listenPort
        self.mode = mode
        self.rawMode = rawMode
        self.backendMode = backendMode
        self.contractVersion = contractVersion
        self.clientMode = clientMode
        self.runtimePreference = runtimePreference
        self.bondIngressMode = bondIngressMode
        self.activeFamily = activeFamily
        self.aggregationEnabled = aggregationEnabled
        self.targetContract = targetContract
        self.sidecarRequired = sidecarRequired
        self.bondSidecarPointId = bondSidecarPointId
        self.transport = transport
        self.serviceStatus = serviceStatus
        self.endpointMode = endpointMode
        self.sni = sni
        self.pathPrefix = pathPrefix
        self.speedLimitMbps = speedLimitMbps
        self.endpoints = endpoints
        self.bonding = bonding
        self.mptcp = mptcp
        self.ultraAccess = ultraAccess
        self.routeRuleHelperOnly = routeRuleHelperOnly
        self.nativeOutbound = nativeOutbound
    }

    public func withListenPort(_ port: Int) -> ForwardContract {
        var copy = self
        copy.listenPort = port
        return copy
    }
}

/// Port of `ForwardContractParser`.
public enum ForwardContractParser {
    /// Returns nil when the element is not an object or lacks `forward_id`.
    public static func parse(_ raw: Any) -> ForwardContract? {
        guard let obj = raw as? [String: Any] else { return nil }
        guard let forwardId = JSONLenient.int64(obj["forward_id"]) else { return nil }
        let rawMode = JSONLenient.stringOrEmpty(obj["mode"])
        var endpoints: [ForwardEndpoint] = []
        for element in JSONLenient.array(obj["endpoints"]) {
            guard let item = element as? [String: Any] else { continue }
            let host = JSONLenient.stringOrEmpty(item["host"])
            let port = JSONLenient.int(item["port"]) ?? 0
            if host.isEmpty || port <= 0 { continue }
            endpoints.append(ForwardEndpoint(
                endpointId: JSONLenient.int(item["endpoint_id"]) ?? JSONLenient.int(item["id"]) ?? 0,
                node: JSONLenient.stringOrEmpty(item["node"]),
                host: host,
                port: port,
                cdnHost: JSONLenient.string(item["cdn_host"]),
                priority: JSONLenient.int(item["priority"]) ?? 0,
                weight: JSONLenient.int(item["weight"]) ?? 0,
                healthStatus: JSONLenient.stringOrEmpty(item["health_status"]),
                supportsQuicLeg: JSONLenient.bool(item["supports_quic_leg"]) ?? false,
                ingressProtocol: JSONLenient.string(item["ingress_protocol"]),
                ingressAuthToken: JSONLenient.string(item["ingress_auth_token"]),
                dynamicPriority: JSONLenient.int(item["dynamic_priority"]),
                nodeId: JSONLenient.int(item["node_id"])
            ))
        }
        let topLevelIngress = JSONLenient.string(obj["bond_ingress_mode"])
        var bonding: BondingContract?
        if let bondingObj = obj["bonding"] as? [String: Any] {
            bonding = BondingContract(json: bondingObj, topLevelIngressMode: topLevelIngress)
        }
        var mptcp: MptcpContract?
        if let mptcpObj = obj["mptcp"] as? [String: Any] {
            mptcp = MptcpContract(
                sidecarHost: JSONLenient.string(mptcpObj["sidecar_host"]),
                sidecarPort: JSONLenient.int(mptcpObj["sidecar_port"])
            )
        }
        return ForwardContract(
            forwardId: forwardId,
            serviceName: JSONLenient.stringOrEmpty(obj["service_name"]),
            direction: JSONLenient.stringOrEmpty(obj["forward_combination_direction"]),
            target: JSONLenient.stringOrEmpty(obj["target"]),
            listenPort: JSONLenient.int(obj["listen_port"]) ?? 0,
            mode: ForwardMode.normalize(rawMode),
            rawMode: rawMode,
            backendMode: JSONLenient.stringOrEmpty(obj["backend_mode"]),
            contractVersion: JSONLenient.int(obj["contract_version"]) ?? 0,
            clientMode: JSONLenient.stringOrEmpty(obj["client_mode"]),
            runtimePreference: JSONLenient.stringOrEmpty(obj["runtime_preference"]),
            bondIngressMode: topLevelIngress ?? "",
            activeFamily: JSONLenient.stringOrEmpty(obj["active_family"]),
            aggregationEnabled: JSONLenient.bool(obj["aggregation_enabled"]) ?? false,
            targetContract: JSONLenient.stringOrEmpty(obj["target_contract"]),
            sidecarRequired: JSONLenient.bool(obj["sidecar_required"]) ?? false,
            bondSidecarPointId: JSONLenient.int64(obj["bond_sidecar_point_id"]),
            transport: JSONLenient.stringOrEmpty(obj["transport"]),
            serviceStatus: JSONLenient.stringOrEmpty(obj["service_status"]),
            endpointMode: JSONLenient.stringOrEmpty(obj["endpoint_mode"]),
            sni: JSONLenient.stringOrEmpty(obj["sni"]),
            pathPrefix: JSONLenient.stringOrEmpty(obj["path_prefix"]),
            speedLimitMbps: JSONLenient.int(obj["speed_limit_mbps"]),
            endpoints: endpoints,
            bonding: bonding,
            mptcp: mptcp,
            ultraAccess: obj["ultra_access"] as? [String: Any],
            routeRuleHelperOnly: JSONLenient.bool(obj["route_rule_helper_only"]) ?? false,
            nativeOutbound: JSONLenient.bool(obj["native_outbound"]) ?? false
        )
    }
}
