import Foundation

/// Which in-process runtime an argv vector is meant for.
public enum IOSRuntimeRole: String, Equatable {
    /// leikwan-ingress (`client --listen ...`).
    case ingress
    /// leikwan-bond (`client --protocol ...`).
    case bond
    /// ultra-client (`--config <path>`).
    case ultra
}

/// One runtime start: the same argv Android passes to the child process, minus the binary path.
public struct RuntimeInvocation: Equatable {
    public let role: IOSRuntimeRole
    public let arguments: [String]
    /// Loopback TCP port that becomes ready when the runtime is up (ingress legs only).
    public let readyPort: Int?

    public init(role: IOSRuntimeRole, arguments: [String], readyPort: Int? = nil) {
        self.role = role
        self.arguments = arguments
        self.readyPort = readyPort
    }
}

/// Bond strategy file as data (Android `ForwardStrategyStore.ensureRuntimeFile`).
/// The NE writes `contents` to `path` before starting the bond runtime.
public struct BondStrategyFile: Equatable {
    public let path: String
    public let contents: String

    public init(path: String, contents: String) {
        self.path = path
        self.contents = contents
    }

    /// `<directory>/<panelHash16>-<clientId>-<forwardId>.strategy` containing `"<strategy>\n"`.
    public static func make(
        directory: String,
        panelId: String,
        clientId: Int64,
        forwardId: Int64,
        strategy: ForwardStrategy = .latencyBest
    ) -> BondStrategyFile {
        let panelHash = String(Hashing.sha256Hex(panelId.trimmingCharacters(in: .whitespacesAndNewlines).lowercased()).prefix(16))
        let name = "\(panelHash)-\(max(clientId, 0))-\(forwardId).strategy"
        let base = directory.hasSuffix("/") ? String(directory.dropLast()) : directory
        return BondStrategyFile(path: base + "/" + name, contents: strategy.rawValue + "\n")
    }
}

/// Context for allocating one ingress leg's loopback port.
public struct BondLegPortRequest: Equatable {
    public let forwardId: Int64
    /// "tcp" or "udp" bond segment.
    public let protocolName: String
    public let endpointIndex: Int
    public let endpoint: ForwardEndpoint
    /// The port Android would use: `20000 + ((forwardId*37 + portSalt) % 15000) + segmentOffset + index`.
    public let androidDefaultPort: Int
}

/// Injectable port allocation. The NE should return a free loopback port; tests use `AndroidDeterministicPortAllocator`.
public protocol BondLegPortAllocating {
    func allocatePort(for request: BondLegPortRequest) throws -> Int
}

public struct AndroidDeterministicPortAllocator: BondLegPortAllocating {
    public init() {}
    public func allocatePort(for request: BondLegPortRequest) throws -> Int {
        return request.androidDefaultPort
    }
}

/// Closure adapter for `BondLegPortAllocating`.
public struct ClosurePortAllocator: BondLegPortAllocating {
    private let body: (BondLegPortRequest) throws -> Int
    public init(_ body: @escaping (BondLegPortRequest) throws -> Int) {
        self.body = body
    }
    public func allocatePort(for request: BondLegPortRequest) throws -> Int {
        return try body(request)
    }
}

public struct BondPlanError: Error, Equatable, CustomStringConvertible {
    public let message: String
    public init(_ message: String) { self.message = message }
    public var description: String { return message }
}

public struct BondRuntimePlan: Equatable {
    public let forwardId: Int64
    /// Bond listener (`--listen 127.0.0.1:<port>`), the port user nodes point at in SERVICES mode.
    public let localListenPort: Int
    public let localLegPorts: [Int]
    /// Ingress invocations first, then one bond invocation per protocol (same order as Android).
    public let invocations: [RuntimeInvocation]
    public let bypassHosts: [String]
    public let protectedConnectivity: ProtectedConnectivitySet
    public let strategyFile: BondStrategyFile?
}

/// Port of `runtime/BondForwardRunner.kt` restricted to `plain_tcp_mux` ingress.
public enum BondPlanBuilder {
    public static func buildPlan(
        forward: ForwardContract,
        portSalt: Int = 0,
        listenPortOverride: Int? = nil,
        strategyFile: BondStrategyFile? = nil,
        portAllocator: BondLegPortAllocating = AndroidDeterministicPortAllocator()
    ) throws -> BondRuntimePlan {
        guard let bonding = forward.bonding else {
            throw BondPlanError("bond mode missing bonding config")
        }
        if forward.endpoints.isEmpty {
            throw BondPlanError("bond mode requires at least one usable endpoint")
        }
        let ingressMode = nonBlank(bonding.ingressMode) ?? IOSForwardFilter.defaultBondIngressMode
        let routeKey = nonBlank(bonding.routeKey) ?? forward.pathPrefix
        let selectedQuicLegs = selectActiveQuicLegs(forward.endpoints, bonding)
        let quicViaSlave = bonding.quicViaSlave ?? true
        let standbyWssEnabled = bonding.wssStandbyEnabled ?? true
        let useWssAsActiveLegs = selectedQuicLegs.isEmpty
        let wsPriority = bonding.wsPriority ?? 10
        let joinTimeoutMs = adaptiveJoinTimeoutMs(bonding, forward.endpoints)
        let listenPort = listenPortOverride ?? forward.listenPort
        if listenPort < 1 || listenPort > 65535 {
            throw BondPlanError("bond_listen_port_invalid")
        }

        var bypassHosts: [String] = []
        func addBypass(_ value: String?) {
            guard let value = nonBlank(value) else { return }
            if !bypassHosts.contains(value) { bypassHosts.append(value) }
        }
        addBypass(forward.sni)
        addBypass(bonding.quicServerName)
        for endpoint in forward.endpoints {
            addBypass(endpoint.host)
            addBypass(endpoint.cdnHost)
        }

        let basePort = 20000 + Int(((forward.forwardId * 37) + Int64(portSalt)) % 15000)
        var localLegPorts: [Int] = []
        var ingressInvocations: [RuntimeInvocation] = []
        var protocolPlans: [(protocolName: String, sessionToken: String, legs: [String])] = []

        for segment in try protocolSegments(forward, bonding) {
            var legTargets: [String] = []
            if quicViaSlave {
                legTargets.append(contentsOf: selectedQuicLegs)
            }
            for (index, endpoint) in forward.endpoints.enumerated() {
                let request = BondLegPortRequest(
                    forwardId: forward.forwardId,
                    protocolName: segment.protocolName,
                    endpointIndex: index,
                    endpoint: endpoint,
                    androidDefaultPort: basePort + segment.portOffset + index
                )
                let localPort = try portAllocator.allocatePort(for: request)
                localLegPorts.append(localPort)
                let ingressArguments = try buildIngressArguments(
                    endpoint: endpoint,
                    localPort: localPort,
                    ingressMode: ingressMode,
                    routeKey: routeKey
                )
                ingressInvocations.append(RuntimeInvocation(role: .ingress, arguments: ingressArguments, readyPort: localPort))
                let endpointPriority = endpoint.dynamicPriority ?? wsPriority
                if useWssAsActiveLegs || !standbyWssEnabled {
                    legTargets.append(renderTcpLeg("127.0.0.1:\(localPort)", endpointPriority, endpoint))
                }
            }
            protocolPlans.append((protocolName: segment.protocolName, sessionToken: segment.sessionToken, legs: legTargets))
        }

        var invocations = ingressInvocations
        for plan in protocolPlans {
            invocations.append(RuntimeInvocation(
                role: .bond,
                arguments: buildBondArguments(
                    protocolName: plan.protocolName,
                    listenPort: listenPort,
                    legTargets: plan.legs,
                    sessionToken: plan.sessionToken,
                    bonding: bonding,
                    joinTimeoutMs: joinTimeoutMs,
                    strategyFilePath: strategyFile?.path
                )
            ))
        }

        return BondRuntimePlan(
            forwardId: forward.forwardId,
            localListenPort: listenPort,
            localLegPorts: localLegPorts,
            invocations: invocations,
            bypassHosts: bypassHosts,
            protectedConnectivity: ProtectedConnectivitySet.fromEndpoints(bypassHosts),
            strategyFile: strategyFile
        )
    }

    // MARK: - Internals (mirroring BondForwardRunner.kt)

    struct ProtocolSegment {
        let protocolName: String
        let portOffset: Int
        let sessionToken: String
    }

    static func protocolSegments(_ forward: ForwardContract, _ bonding: BondingContract) throws -> [ProtocolSegment] {
        let tcpToken = nonBlank(bonding.sessionToken)
        let udpToken = nonBlank(bonding.udpSessionToken) ?? tcpToken
        func tcp() throws -> ProtocolSegment {
            guard let token = tcpToken else { throw BondPlanError("bond mode missing bonding.session_token for tcp transport") }
            return ProtocolSegment(protocolName: "tcp", portOffset: 0, sessionToken: token)
        }
        func udp() throws -> ProtocolSegment {
            guard let token = udpToken else { throw BondPlanError("bond mode missing bonding.udp_session_token for udp transport") }
            return ProtocolSegment(protocolName: "udp", portOffset: 200, sessionToken: token)
        }
        switch forward.transport.trimmingCharacters(in: .whitespacesAndNewlines).lowercased() {
        case "tcp": return try [tcp()]
        case "udp": return try [udp()]
        case "tcp+udp", "tcp_udp": return try [tcp(), udp()]
        default: throw BondPlanError("bond mode transport \(forward.transport) is not supported on iOS")
        }
    }

    static func buildIngressArguments(endpoint: ForwardEndpoint, localPort: Int, ingressMode: String, routeKey: String) throws -> [String] {
        guard ingressMode == IOSForwardFilter.plainTcpMux else {
            throw BondPlanError("bond ingress mode \(ingressMode) is not supported on iOS (wstunnel unavailable)")
        }
        guard let authToken = nonBlank(endpoint.ingressAuthToken) else {
            throw BondPlanError("bond plain_tcp_mux requires ingress auth token for endpoint \(endpoint.endpointId)")
        }
        if routeKey.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty {
            throw BondPlanError("bond plain_tcp_mux requires bonding.route_key or path_prefix")
        }
        return [
            "client",
            "--listen", "127.0.0.1:\(localPort)",
            "--remote", "\(endpoint.host):\(endpoint.port)",
            "--route-key", routeKey,
            "--auth-token", authToken,
        ]
    }

    static func buildBondArguments(
        protocolName: String,
        listenPort: Int,
        legTargets: [String],
        sessionToken: String,
        bonding: BondingContract,
        joinTimeoutMs: Int,
        strategyFilePath: String?
    ) -> [String] {
        var args: [String] = [
            "client",
            "--protocol", protocolName,
            "--listen", "127.0.0.1:\(listenPort)",
            "--legs", legTargets.joined(separator: ","),
            "--session-token", sessionToken,
        ]
        if let path = strategyFilePath, !path.trimmingCharacters(in: .whitespaces).isEmpty {
            args.append(contentsOf: ["--strategy-file", path, "--default-strategy", ForwardStrategy.latencyBest.rawValue])
        }
        // One flag per statement: a single literal of this size exceeds the
        // type checker's time budget.
        func flag(_ name: String, _ value: Int) {
            args.append(name)
            args.append(String(value))
        }
        flag("--chunk-size", bonding.chunkSize ?? 65536)
        flag("--max-window-bytes", bonding.maxWindowBytes ?? 8388608)
        flag("--retransmit-ms", bonding.retransmitMs ?? 700)
        flag("--connect-timeout-ms", bonding.connectTimeoutMs ?? 3000)
        flag("--join-timeout-ms", joinTimeoutMs)
        flag("--interactive-chunk-bytes", bonding.interactiveChunkBytes ?? 16384)
        flag("--latency-guard-bytes", bonding.latencyGuardBytes ?? 262144)
        flag("--interactive-rtt-slack-ms", bonding.interactiveRttSlackMs ?? 30)
        flag("--warmup-trigger-bytes", bonding.warmupTriggerBytes ?? 65536)
        flag("--warmup-slice-bytes", bonding.warmupSliceBytes ?? 65536)
        flag("--warmup-leg-delay-ms", bonding.warmupLegDelayMs ?? 80)
        flag("--bulk-promote-bytes", bonding.bulkPromoteBytes ?? 262144)
        flag("--leg-cooldown-ms", bonding.legCooldownMs ?? 3000)
        return args
    }

    static func adaptiveJoinTimeoutMs(_ bonding: BondingContract, _ endpoints: [ForwardEndpoint]) -> Int {
        let configured = max(40, bonding.joinTimeoutMs ?? 400)
        let adaptiveEnabled = bonding.adaptiveJoinTimeout ?? true
        let floor = max(20, bonding.joinTimeoutFloorMs ?? 35)
        let perLeg = max(0, bonding.joinTimeoutPerLegMs ?? 20)
        let ceiling = max(floor, bonding.joinTimeoutCeilingMs ?? min(configured, 220))
        if !adaptiveEnabled {
            return max(floor, min(ceiling, configured))
        }
        let wssLegs = max(1, endpoints.count)
        let quicLegs = endpoints.filter { renderQuicLegForEndpoint($0, bonding) != nil }.count
        if quicLegs == 0 {
            let wssOnlyCeiling = max(ceiling, configured)
            return max(floor, min(wssOnlyCeiling, configured))
        }
        let totalLegs = max(1, wssLegs + quicLegs)
        let adaptive = floor + max(0, totalLegs - 1) * perLeg
        return max(floor, min(ceiling, adaptive))
    }

    static func selectActiveQuicLegs(_ endpoints: [ForwardEndpoint], _ bonding: BondingContract) -> [String] {
        let limit = max(0, bonding.activeLegLimit ?? bonding.quicActiveLegLimit ?? 0)
        var selected: [String] = []
        for endpoint in endpoints {
            guard let leg = renderQuicLegForEndpoint(endpoint, bonding) else { continue }
            selected.append(leg)
            if limit > 0 && selected.count >= limit { return selected }
        }
        return selected
    }

    static func renderQuicLegForEndpoint(_ endpoint: ForwardEndpoint, _ bonding: BondingContract) -> String? {
        let quicEnabled = bonding.quicEnabled ?? true
        // Android deliberately defaults this one to false (buildPlan defaults it to true).
        let quicViaSlave = bonding.quicViaSlave ?? false
        if !quicEnabled && !quicViaSlave { return nil }
        if !endpoint.supportsQuicLeg { return nil }
        if endpoint.host.trimmingCharacters(in: .whitespaces).isEmpty || endpoint.port <= 0 { return nil }
        var params = renderLegQueryParams(bonding.quicPriority ?? 0, endpoint)
        let serverName = nonBlank(bonding.quicServerName) ?? endpoint.host
        if !serverName.trimmingCharacters(in: .whitespaces).isEmpty {
            params.append("server_name=\(PercentCoding.queryValueEncode(serverName))")
        }
        if bonding.quicInsecure ?? true {
            params.append("insecure=1")
        }
        return "quic://\(endpoint.host):\(endpoint.port)?\(params.joined(separator: "&"))"
    }

    static func renderTcpLeg(_ target: String, _ priority: Int, _ endpoint: ForwardEndpoint) -> String {
        return "tcp://\(target)?\(renderLegQueryParams(priority, endpoint).joined(separator: "&"))"
    }

    static func renderLegQueryParams(_ priority: Int, _ endpoint: ForwardEndpoint) -> [String] {
        var params = ["priority=\(priority)"]
        if endpoint.endpointId > 0 {
            params.append("endpoint_id=\(endpoint.endpointId)")
        }
        if let nodeId = endpoint.nodeId, nodeId > 0 {
            params.append("node_id=\(nodeId)")
        }
        if !endpoint.host.trimmingCharacters(in: .whitespaces).isEmpty {
            params.append("display_host=\(PercentCoding.queryValueEncode(endpoint.host))")
        }
        return params
    }

    static func nonBlank(_ value: String?) -> String? {
        guard let value = value else { return nil }
        return value.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty ? nil : value
    }
}
