import Foundation

/// Tunables for running several bindings at once in the ~50 MB packet tunnel.
public enum BindingLimits {
    /// Bindings that start forward runtimes (bond: ingress legs + bond; ultra: one Go runtime).
    /// Direct-node bindings start no runtime and are not counted.
    public static let maxConcurrentForwardBindings = 4
}

/// Identifies a binding: a forward (its node is the forward's assigned node) or a node used
/// without a forward ("直连"). Encoded as one string ("f:12" / "n:3").
public struct BindingID: Hashable, Codable, Comparable, CustomStringConvertible {
    public let rawValue: String

    public init?(rawValue: String) {
        let parts = rawValue.split(separator: ":", maxSplits: 1).map(String.init)
        guard parts.count == 2, parts[0] == "f" || parts[0] == "n", let id = Int64(parts[1]), id > 0 else { return nil }
        self.rawValue = rawValue
    }

    private init(validated: String) {
        rawValue = validated
    }

    public static func forward(_ forwardId: Int64) -> BindingID {
        return BindingID(validated: "f:\(forwardId)")
    }

    public static func direct(nodeId: Int64) -> BindingID {
        return BindingID(validated: "n:\(nodeId)")
    }

    public var forwardId: Int64? {
        guard rawValue.hasPrefix("f:") else { return nil }
        return Int64(rawValue.dropFirst(2))
    }

    public var directNodeId: Int64? {
        guard rawValue.hasPrefix("n:") else { return nil }
        return Int64(rawValue.dropFirst(2))
    }

    public var description: String { return rawValue }

    public static func < (lhs: BindingID, rhs: BindingID) -> Bool { return lhs.rawValue < rhs.rawValue }

    public init(from decoder: Decoder) throws {
        let container = try decoder.singleValueContainer()
        let text = try container.decode(String.self)
        guard let parsed = BindingID(rawValue: text) else {
            throw DecodingError.dataCorruptedError(in: container, debugDescription: "invalid binding id")
        }
        self = parsed
    }

    public func encode(to encoder: Encoder) throws {
        var container = encoder.singleValueContainer()
        try container.encode(rawValue)
    }
}

/// The user's node choice for one forward on this device.
public struct ForwardNodeAssignment: Codable, Equatable {
    public var forwardId: Int64
    public var nodeId: Int64

    public init(forwardId: Int64, nodeId: Int64) {
        self.forwardId = forwardId
        self.nodeId = nodeId
    }
}

/// Persisted binding choices. Panel pairings are kept separately (`CloudNodeSyncState`); a panel
/// pairing that changes is copied into `nodeByForward` by the app (a local pick wins until then).
public struct BindingPreferences: Codable, Equatable {
    /// forward -> node chosen on this device.
    public var nodeByForward: [ForwardNodeAssignment]
    /// Bindings to run, in priority order. Empty = legacy single selection (selected forward +
    /// selected node), so existing installs keep working untouched.
    public var enabled: [BindingID]
    /// Route final / untargeted "proxy" rules. Nil or inactive = the first active binding.
    public var defaultBinding: BindingID?
    /// App preset key -> node id its TUNNEL rule is pinned to (absent = default binding).
    public var appPresetTargets: [String: Int64]
    /// ISO country code (upper-case) -> node id.
    public var countryTargets: [String: Int64]

    public init(
        nodeByForward: [ForwardNodeAssignment] = [],
        enabled: [BindingID] = [],
        defaultBinding: BindingID? = nil,
        appPresetTargets: [String: Int64] = [:],
        countryTargets: [String: Int64] = [:]
    ) {
        self.nodeByForward = nodeByForward
        self.enabled = enabled
        self.defaultBinding = defaultBinding
        self.appPresetTargets = appPresetTargets
        self.countryTargets = countryTargets
    }

    public func assignedNode(forwardId: Int64) -> Int64? {
        return nodeByForward.first { $0.forwardId == forwardId }?.nodeId
    }

    public mutating func assign(nodeId: Int64?, forwardId: Int64) {
        nodeByForward.removeAll { $0.forwardId == forwardId }
        if let nodeId = nodeId, nodeId > 0 {
            nodeByForward.append(ForwardNodeAssignment(forwardId: forwardId, nodeId: nodeId))
            nodeByForward.sort { $0.forwardId < $1.forwardId }
        }
    }

    /// Drops every reference to deleted nodes (assignments, direct bindings, rule targets).
    public mutating func forgetNodes(_ ids: Set<Int64>) {
        if ids.isEmpty { return }
        nodeByForward.removeAll { ids.contains($0.nodeId) }
        enabled.removeAll { binding in binding.directNodeId.map { ids.contains($0) } ?? false }
        if let current = defaultBinding, let node = current.directNodeId, ids.contains(node) { defaultBinding = nil }
        appPresetTargets = appPresetTargets.filter { !ids.contains($0.value) }
        countryTargets = countryTargets.filter { !ids.contains($0.value) }
    }

    enum CodingKeys: String, CodingKey {
        case nodeByForward
        case enabled
        case defaultBinding
        case appPresetTargets
        case countryTargets
    }
}

extension BindingPreferences {
    /// Tolerates missing keys and drops unreadable binding ids. In an extension to keep the memberwise init.
    public init(from decoder: Decoder) throws {
        let container = try decoder.container(keyedBy: CodingKeys.self)
        nodeByForward = try container.decodeIfPresent([ForwardNodeAssignment].self, forKey: .nodeByForward) ?? []
        let rawEnabled = try container.decodeIfPresent([String].self, forKey: .enabled) ?? []
        enabled = rawEnabled.compactMap { BindingID(rawValue: $0) }
        let rawDefault = try container.decodeIfPresent(String.self, forKey: .defaultBinding)
        defaultBinding = rawDefault.flatMap { BindingID(rawValue: $0) }
        appPresetTargets = try container.decodeIfPresent([String: Int64].self, forKey: .appPresetTargets) ?? [:]
        countryTargets = try container.decodeIfPresent([String: Int64].self, forKey: .countryTargets) ?? [:]
    }
}

/// Where a forward's node comes from.
public enum BindingNodeSource: String, Equatable {
    /// Picked on this device.
    case local
    /// Paired on the panel explicitly (console or another device).
    case cloudExplicit = "cloud_explicit"
    /// Panel target match (the forward's target is the node's server:port).
    case cloudTargetMatch = "cloud_target_match"
    /// Legacy: the app's selected node (no per-forward choice yet).
    case selection
    /// No node chosen.
    case unset
}

public enum BindingSkipReason: Equatable {
    case forwardUnavailable
    case nodeMissing
    case nodeDisabled
    /// The paired cloud node is a type iOS cannot run (VLESS).
    case cloudNodeUnsupported
    /// The node already runs in an earlier binding (a node is one outbound, one route target).
    case nodeAlreadyUsed(BindingID)
    case overLimit(Int)
    case planFailed(String)

    public var message: String {
        switch self {
        case .forwardUnavailable: return "转发不可用（已删除或 iOS 不支持）"
        case .nodeMissing: return "未选择节点"
        case .nodeDisabled: return "节点已停用"
        case .cloudNodeUnsupported: return "云端绑定的节点类型 iOS 暂不支持"
        case .nodeAlreadyUsed: return "该节点已在其他绑定中使用"
        case .overLimit(let limit): return "同时运行的转发绑定最多 \(limit) 个"
        case .planFailed(let detail): return "无法启动：\(detail)"
        }
    }
}

public struct SkippedBinding: Equatable {
    public let id: BindingID
    public let reason: BindingSkipReason

    public init(id: BindingID, reason: BindingSkipReason) {
        self.id = id
        self.reason = reason
    }
}

/// A binding that will run.
public struct ActiveBinding {
    public let id: BindingID
    /// Nil for a direct-node binding.
    public let forward: IOSSupportedForward?
    /// Nil only for a lone Ultra forward without a node (all traffic is bridged to the runtime).
    public let node: ProxyNode?
    public let nodeSource: BindingNodeSource

    public init(id: BindingID, forward: IOSSupportedForward?, node: ProxyNode?, nodeSource: BindingNodeSource) {
        self.id = id
        self.forward = forward
        self.node = node
        self.nodeSource = nodeSource
    }
}

public struct ResolvedBindings {
    /// Default binding first, then the enabled order.
    public let active: [ActiveBinding]
    public let skipped: [SkippedBinding]

    public var defaultBinding: ActiveBinding? { return active.first }
}

/// Decides which bindings run (pure; the app and tests call it with the same inputs).
public enum BindingResolver {
    /// The node a forward uses: this device's pick, else the panel pairing, else `fallbackNodeId`.
    /// `unsupportedCloud` is true when the panel pairs a node iOS cannot run (and nothing else applies).
    public static func effectiveNode(
        forwardId: Int64,
        preferences: BindingPreferences,
        cloud: CloudNodeSyncState,
        fallbackNodeId: Int64? = nil
    ) -> (nodeId: Int64?, source: BindingNodeSource, unsupportedCloud: Bool) {
        if let local = preferences.assignedNode(forwardId: forwardId) {
            return (local, .local, false)
        }
        if let pairing = cloud.pairing(forwardId: forwardId) {
            if let local = pairing.localNodeId {
                return (local, pairing.isExplicit ? .cloudExplicit : .cloudTargetMatch, false)
            }
            if fallbackNodeId == nil { return (nil, .unset, true) }
        }
        if let fallback = fallbackNodeId {
            return (fallback, .selection, false)
        }
        return (nil, .unset, false)
    }

    /// The bindings to run. With no enabled bindings the legacy selection is used: the selected
    /// forward with its effective node (falling back to the selected node), or the selected node
    /// directly when no forward is selected.
    public static func resolve(
        preferences: BindingPreferences,
        forwards: [IOSSupportedForward],
        nodes: [ProxyNode],
        cloud: CloudNodeSyncState,
        legacyForwardId: Int64? = nil,
        legacyNodeId: Int64? = nil,
        maxForwardBindings: Int = BindingLimits.maxConcurrentForwardBindings
    ) -> ResolvedBindings {
        var requested = preferences.enabled
        var fallbackNode: Int64?
        if requested.isEmpty {
            if let forwardId = legacyForwardId {
                requested = [.forward(forwardId)]
                fallbackNode = legacyNodeId
            } else if let nodeId = legacyNodeId {
                requested = [.direct(nodeId: nodeId)]
            }
        }
        // De-duplicate, default first.
        var ordered: [BindingID] = []
        if let preferred = preferences.defaultBinding, requested.contains(preferred) {
            ordered.append(preferred)
        }
        for id in requested where !ordered.contains(id) {
            ordered.append(id)
        }

        var active: [ActiveBinding] = []
        var skipped: [SkippedBinding] = []
        var usedNodes: [Int64: BindingID] = [:]
        var forwardCount = 0
        let lone = ordered.count == 1

        for id in ordered {
            if let forwardId = id.forwardId {
                guard let forward = forwards.first(where: { $0.forwardId == forwardId }) else {
                    skipped.append(SkippedBinding(id: id, reason: .forwardUnavailable))
                    continue
                }
                let choice = effectiveNode(forwardId: forwardId, preferences: preferences, cloud: cloud, fallbackNodeId: fallbackNode)
                var node: ProxyNode?
                if let nodeId = choice.nodeId {
                    guard let found = nodes.first(where: { $0.id == nodeId }) else {
                        skipped.append(SkippedBinding(id: id, reason: .nodeMissing))
                        continue
                    }
                    guard found.enabled else {
                        skipped.append(SkippedBinding(id: id, reason: .nodeDisabled))
                        continue
                    }
                    if let owner = usedNodes[nodeId] {
                        skipped.append(SkippedBinding(id: id, reason: .nodeAlreadyUsed(owner)))
                        continue
                    }
                    node = found
                } else if !(lone && forward.kind == .ultra) {
                    skipped.append(SkippedBinding(id: id, reason: choice.unsupportedCloud ? .cloudNodeUnsupported : .nodeMissing))
                    continue
                }
                if forwardCount >= maxForwardBindings {
                    skipped.append(SkippedBinding(id: id, reason: .overLimit(maxForwardBindings)))
                    continue
                }
                forwardCount += 1
                if let node = node { usedNodes[node.id] = id }
                active.append(ActiveBinding(id: id, forward: forward, node: node, nodeSource: node == nil ? .unset : choice.source))
            } else if let nodeId = id.directNodeId {
                guard let node = nodes.first(where: { $0.id == nodeId }) else {
                    skipped.append(SkippedBinding(id: id, reason: .nodeMissing))
                    continue
                }
                guard node.enabled else {
                    skipped.append(SkippedBinding(id: id, reason: .nodeDisabled))
                    continue
                }
                if let owner = usedNodes[nodeId] {
                    skipped.append(SkippedBinding(id: id, reason: .nodeAlreadyUsed(owner)))
                    continue
                }
                usedNodes[nodeId] = id
                active.append(ActiveBinding(id: id, forward: nil, node: node, nodeSource: .local))
            }
        }
        return ResolvedBindings(active: active, skipped: skipped)
    }
}
