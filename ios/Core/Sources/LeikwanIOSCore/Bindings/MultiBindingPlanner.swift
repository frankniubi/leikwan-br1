import Foundation

/// Hands out loopback TCP ports without collisions across the runtimes of one plan. A port is
/// kept when it is free in the plan (so a single binding gets exactly the ports it had before
/// multi-binding, and a re-plan with the same inputs gets the same ports and does not restart
/// runtimes); a taken port moves to the next free one.
public final class LoopbackPortPlanner {
    public static let fallbackRange = 20000...60999
    public private(set) var claimed = Set<Int>()

    public init(reserved: Set<Int> = []) {
        claimed = reserved
    }

    public func claim(preferred: Int) throws -> Int {
        if (1...65535).contains(preferred) && !claimed.contains(preferred) {
            claimed.insert(preferred)
            return preferred
        }
        let start = (1024...65535).contains(preferred) ? preferred + 1 : LoopbackPortPlanner.fallbackRange.lowerBound
        var port = start
        var scanned = 0
        while scanned < 65535 {
            if port > 65535 { port = LoopbackPortPlanner.fallbackRange.lowerBound }
            if !claimed.contains(port) {
                claimed.insert(port)
                return port
            }
            port += 1
            scanned += 1
        }
        throw BondPlanError("no_free_loopback_port")
    }
}

/// Where the plan's files go and who it is for.
public struct MultiBindingEnvironment: Equatable {
    /// App-group runtime directory (bond strategy files, ultra sockets/status files).
    public var runtimeDirectory: String
    /// Panel URL (bond strategy file name hash).
    public var panelId: String
    public var clientId: Int64
    /// Ultra needs a signed-in panel session for its leases.
    public var hasPanelSession: Bool
    public var ultraMaxCandidates: Int
    /// Clock for validating Ultra policies (tests pin it).
    public var now: Date

    public init(runtimeDirectory: String, panelId: String, clientId: Int64, hasPanelSession: Bool,
                ultraMaxCandidates: Int = UltraConstants.maxLeaseCandidates, now: Date = Date()) {
        self.now = now
        self.runtimeDirectory = runtimeDirectory
        self.panelId = panelId
        self.clientId = clientId
        self.hasPanelSession = hasPanelSession
        self.ultraMaxCandidates = ultraMaxCandidates
    }
}

/// One runtime to start (maps 1:1 to the tunnel's RuntimeLaunch).
public struct PlannedRuntime: Equatable {
    public let name: String
    public let role: IOSRuntimeRole
    public let arguments: [String]
    public let configJSON: String?
    public let files: [BondStrategyFile]
    public let forwardId: Int64
    public let bindingId: BindingID
}

/// One Ultra runtime's lease inputs (maps 1:1 to the tunnel's UltraForwardLaunch).
public struct PlannedUltraForward: Equatable {
    public let bindingId: BindingID
    public let forwardId: Int64
    public let runtimeName: String
    public let target: String
    public let backendMode: String
    public let serviceStatus: String
    public let listenPort: Int
    public let ultraAccessJSON: String
    public let targetDigest: String
    public let transport: String
    public let controlSocketPath: String
    public let statusPath: String
    public let maxCandidates: Int
    public let autoSelect: Bool
}

/// A binding as it runs (for the tunnel status and the UI).
public struct PlannedBinding: Equatable {
    public let id: BindingID
    public let forwardId: Int64?
    public let nodeId: Int64?
    /// sing-box outbound tag of the node ("proxy-<id>"), nil for a node-less Ultra bridge.
    public let outboundTag: String?
    /// Loopback port the node dials (the forward runtime's listener); nil for direct nodes.
    public let localPort: Int?
    public let runtimeNames: [String]
    public let isDefault: Bool
}

public struct MultiBindingPlan {
    public let singbox: CompiledSingboxConfig
    public let runtimes: [PlannedRuntime]
    public let ultra: [PlannedUltraForward]
    /// Default first.
    public let bindings: [PlannedBinding]
    /// Bindings that will not run, with the reason (resolution and planning failures).
    public let skipped: [SkippedBinding]
}

/// Turns the resolved bindings into one launch: a runtime set per bound forward with
/// non-colliding loopback ports and distinct files/sockets, and one sing-box config with an
/// outbound per bound node (`proxy-<nodeId>` -> 127.0.0.1:<its forward's port>, or the node's own
/// server for a direct binding). The default binding's node is the route final and the target of
/// untargeted "proxy" rules.
public enum MultiBindingPlanner {
    public static func plan(
        resolved: ResolvedBindings,
        environment: MultiBindingEnvironment,
        builder: SingboxConfigBuilder,
        routing: RoutingPolicySnapshot
    ) throws -> MultiBindingPlan {
        var skipped = resolved.skipped
        let ports = LoopbackPortPlanner()
        var runtimes: [PlannedRuntime] = []
        var ultra: [PlannedUltraForward] = []
        var planned: [PlannedBinding] = []
        var contexts: [SingboxRuntimeContext] = []
        var nodes: [ProxyNode] = []
        var directNodeIds = Set<Int64>()
        var runtimeOnlyUltra = false

        for binding in resolved.active {
            let isDefault = planned.isEmpty
            guard let forward = binding.forward else {
                guard var node = binding.node else { continue }
                node.forwardId = nil
                nodes.append(node)
                directNodeIds.insert(node.id)
                planned.append(PlannedBinding(id: binding.id, forwardId: nil, nodeId: node.id, outboundTag: node.outboundTag,
                                              localPort: nil, runtimeNames: [], isDefault: isDefault))
                continue
            }
            do {
                let unit = try planForward(forward, binding: binding, environment: environment, ports: ports)
                runtimes.append(contentsOf: unit.runtimes)
                if let entry = unit.ultra { ultra.append(entry) }
                var context = unit.context
                if var node = binding.node {
                    node.forwardId = nil
                    nodes.append(node)
                    context.nodeListenPorts[node.id] = unit.listenPort
                } else {
                    runtimeOnlyUltra = true
                }
                contexts.append(context)
                planned.append(PlannedBinding(id: binding.id, forwardId: forward.forwardId, nodeId: binding.node?.id,
                                              outboundTag: binding.node?.outboundTag, localPort: unit.listenPort,
                                              runtimeNames: unit.runtimes.map { $0.name }, isDefault: isDefault))
            } catch {
                skipped.append(SkippedBinding(id: binding.id, reason: .planFailed(describe(error))))
            }
        }
        if planned.isEmpty {
            let reason = skipped.first?.reason.message ?? "请先添加并选择一个节点"
            throw MultiBindingPlanError(reason)
        }

        var snapshot = routing
        if let defaultNode = planned.first?.nodeId {
            snapshot.globalDefaultNodeId = defaultNode
        }
        let compiled: CompiledSingboxConfig
        if contexts.isEmpty {
            compiled = try builder.build(proxyNodes: nodes, runtime: nil, routing: snapshot, connectionMode: .direct)
        } else {
            var merged = SingboxRuntimeContext.merged(primary: contexts[0], others: Array(contexts.dropFirst()))
            merged.directNodeIds = directNodeIds
            compiled = try builder.build(
                proxyNodes: nodes,
                runtime: merged,
                routing: snapshot,
                connectionMode: .services,
                runtimeOnlyProxy: runtimeOnlyUltra && nodes.isEmpty
            )
        }
        return MultiBindingPlan(singbox: compiled, runtimes: runtimes, ultra: ultra, bindings: planned, skipped: skipped)
    }

    // MARK: Per forward

    struct ForwardUnit {
        var runtimes: [PlannedRuntime]
        var ultra: PlannedUltraForward?
        var context: SingboxRuntimeContext
        var listenPort: Int
    }

    static func planForward(
        _ forward: IOSSupportedForward,
        binding: ActiveBinding,
        environment: MultiBindingEnvironment,
        ports: LoopbackPortPlanner
    ) throws -> ForwardUnit {
        switch forward.kind {
        case .ultra:
            guard environment.hasPanelSession else { throw MultiBindingPlanError("Ultra 转发需要先登录") }
            guard let policy = UltraPolicy.from(forward.contract, now: environment.now) else {
                throw MultiBindingPlanError("Ultra 授权不可用，请刷新后重试")
            }
            let paths = UltraRuntimePaths.compact(directory: environment.runtimeDirectory, forwardId: forward.forwardId)
            if paths.controlSocketPath.utf8.count > UltraRuntimePaths.maxSocketPathBytes {
                throw MultiBindingPlanError("Ultra 控制通道路径过长")
            }
            let maxCandidates = max(1, min(environment.ultraMaxCandidates, UltraConstants.maxLeaseCandidates))
            // iOS plans before the leases exist: always ask for candidates and let the runtime choose.
            let autoSelect = maxCandidates > 1
            let listenPort = try ports.claim(preferred: forward.contract.listenPort)
            let plan = try UltraRuntimeConfigBuilder.buildPlan(
                forward: forward.contract,
                policy: policy,
                paths: paths,
                autoSelect: autoSelect,
                listenPortOverride: listenPort
            )
            let name = "fwd-\(forward.forwardId)-ultra"
            let runtime = PlannedRuntime(name: name, role: .ultra, arguments: [], configJSON: plan.configJSON, files: [],
                                         forwardId: forward.forwardId, bindingId: binding.id)
            let entry = PlannedUltraForward(
                bindingId: binding.id,
                forwardId: forward.forwardId,
                runtimeName: name,
                target: forward.contract.target,
                backendMode: forward.contract.backendMode,
                serviceStatus: forward.contract.serviceStatus,
                listenPort: plan.localListenPort,
                ultraAccessJSON: try CanonicalJSON.string(forward.contract.ultraAccess ?? [:]),
                targetDigest: policy.targetDigest,
                transport: policy.transport,
                controlSocketPath: paths.controlSocketPath,
                statusPath: paths.statusPath,
                maxCandidates: maxCandidates,
                autoSelect: autoSelect
            )
            // Lease endpoints need no sing-box bypass: the tunnel process's own sockets skip the tunnel.
            return ForwardUnit(runtimes: [runtime], ultra: entry, context: .ultra(plan, leases: []), listenPort: plan.localListenPort)
        case .bondPlainTcpMux:
            let strategy = BondStrategyFile.make(
                directory: environment.runtimeDirectory,
                panelId: environment.panelId,
                clientId: environment.clientId,
                forwardId: forward.forwardId
            )
            let listenPort = try ports.claim(preferred: forward.contract.listenPort)
            let allocator = ClosurePortAllocator { request in
                try ports.claim(preferred: request.androidDefaultPort)
            }
            let plan = try BondPlanBuilder.buildPlan(
                forward: forward.contract,
                listenPortOverride: listenPort,
                strategyFile: strategy,
                portAllocator: allocator
            )
            var runtimes: [PlannedRuntime] = []
            for (index, invocation) in plan.invocations.enumerated() {
                runtimes.append(PlannedRuntime(
                    name: "fwd-\(forward.forwardId)-\(invocation.role.rawValue)-\(index)",
                    role: invocation.role,
                    arguments: invocation.arguments,
                    configJSON: nil,
                    files: invocation.role == .bond ? (plan.strategyFile.map { [$0] } ?? []) : [],
                    forwardId: forward.forwardId,
                    bindingId: binding.id
                ))
            }
            return ForwardUnit(runtimes: runtimes, ultra: nil, context: .bond(plan), listenPort: plan.localListenPort)
        }
    }

    static func describe(_ error: Error) -> String {
        if let error = error as? MultiBindingPlanError { return error.message }
        if let error = error as? BondPlanError { return error.message }
        if let error = error as? UltraLeaseError { return error.message }
        if let error = error as? SingboxConfigError { return error.message }
        return String(describing: error)
    }
}

public struct MultiBindingPlanError: Error, Equatable, CustomStringConvertible {
    public let message: String
    public init(_ message: String) { self.message = message }
    public var description: String { return message }
}
