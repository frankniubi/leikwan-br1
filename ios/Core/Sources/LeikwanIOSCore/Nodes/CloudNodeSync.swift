import Foundation

/// A panel pairing resolved against this device's node list.
public struct CloudPairing: Codable, Equatable {
    public var forwardId: Int64
    public var hostedNodeId: Int64
    /// The local ProxyNode id; nil when the paired hosted node is a type iOS cannot run (VLESS).
    public var localNodeId: Int64?
    /// "explicit" or "target_match".
    public var source: String

    public init(forwardId: Int64, hostedNodeId: Int64, localNodeId: Int64?, source: String) {
        self.forwardId = forwardId
        self.hostedNodeId = hostedNodeId
        self.localNodeId = localNodeId
        self.source = source
    }

    public var isExplicit: Bool { return source != "target_match" }
}

/// What the device last applied from the panel (no credentials except inside the cached
/// unsupported nodes' share links, which are stored with the node list).
public struct CloudNodeSyncState: Codable, Equatable {
    /// Client the state belongs to (0 = never synced).
    public var clientId: Int64
    /// `hosted_nodes_revision` of the last applied listing ("" = nothing hosted).
    public var revision: String
    public var syncedAt: Date?
    public var pairings: [CloudPairing]
    /// Hosted nodes iOS cannot run (e.g. VLESS): listed read-only, never changed or deleted here.
    public var unsupportedNodes: [HostedNodeDTO]

    public init(clientId: Int64 = 0, revision: String = "", syncedAt: Date? = nil,
                pairings: [CloudPairing] = [], unsupportedNodes: [HostedNodeDTO] = []) {
        self.clientId = clientId
        self.revision = revision
        self.syncedAt = syncedAt
        self.pairings = pairings
        self.unsupportedNodes = unsupportedNodes
    }

    public static let empty = CloudNodeSyncState()

    public func pairing(forwardId: Int64) -> CloudPairing? {
        return pairings.first { $0.forwardId == forwardId }
    }

    enum CodingKeys: String, CodingKey {
        case clientId
        case revision
        case syncedAt
        case pairings
        case unsupportedNodes
    }
}

extension CloudNodeSyncState {
    /// Tolerates missing keys (older/newer files). In an extension to keep the memberwise init.
    public init(from decoder: Decoder) throws {
        let container = try decoder.container(keyedBy: CodingKeys.self)
        clientId = try container.decodeIfPresent(Int64.self, forKey: .clientId) ?? 0
        revision = try container.decodeIfPresent(String.self, forKey: .revision) ?? ""
        syncedAt = try container.decodeIfPresent(Date.self, forKey: .syncedAt)
        pairings = try container.decodeIfPresent([CloudPairing].self, forKey: .pairings) ?? []
        unsupportedNodes = try container.decodeIfPresent([HostedNodeDTO].self, forKey: .unsupportedNodes) ?? []
    }
}

public struct CloudMergeResult: Equatable {
    public var nodes: [ProxyNode]
    public var state: CloudNodeSyncState
    /// Pairings that appeared or now point at another node since the previous state of the same
    /// client. The app applies only these to its per-forward node choice, so a local pick made on
    /// this device stays until the panel pairing changes again (Android / desktop rule).
    public var changedPairings: [CloudPairing]
    /// Local node ids deleted by the merge (hosted rows the panel no longer lists, duplicates).
    public var removedLocalIds: [Int64]
}

/// What a revision change means (`syncIfRevisionChanged`).
public enum CloudRevisionDecision: Equatable {
    /// Same client and revision (or an older panel without the field): nothing to do.
    case upToDate
    /// Nothing hosted on either side: record the empty revision without a fetch.
    case recordEmpty
    /// Fetch the listing and apply it.
    case fetch
}

/// Pure cloud <-> local node logic (port of desktop `HostedNodeSyncService` / Android
/// `HostedNodeSync` materialization). The app performs the network call and applies the
/// result to its *current* node list on the main actor, so local edits made during the call are
/// never lost.
///
/// Rules: the panel is the source of truth for hosted nodes; rows match by hosted id; a new
/// hosted node equal to a local node (`ProxyNodeIdentity`) adopts that row (keeps its id, so
/// bindings stay valid); hosted rows the panel no longer lists are deleted; local-only nodes are
/// never uploaded or changed by sync. Device-local fields (`enabled`, `forwardId`) are kept.
public enum CloudNodeMerge {
    /// Panel limit per batch (HostedNodeService::MAX_NODES_PER_CLIENT).
    public static let maxBatch = 32

    public static func decide(revision: String?, clientId: Int64, state: CloudNodeSyncState, nodes: [ProxyNode]) -> CloudRevisionDecision {
        guard let revision = revision, clientId > 0 else { return .upToDate }
        if state.clientId == clientId && state.revision == revision { return .upToDate }
        if revision.isEmpty && !nodes.contains(where: { $0.isHosted }) && (state.clientId != clientId || state.unsupportedNodes.isEmpty) {
            return .recordEmpty
        }
        return .fetch
    }

    /// Materializes a listing into the node list and the pairing state.
    public static func apply(
        _ listing: HostedNodesListing,
        to nodes: [ProxyNode],
        previous: CloudNodeSyncState,
        clientId: Int64,
        now: Date = Date()
    ) -> CloudMergeResult {
        var result = nodes
        var removed: [Int64] = []

        // One row per hosted id: the oldest (lowest id) row wins, later copies are duplicates.
        var hostedRowIds: [Int64: Int64] = [:]
        for node in result.sorted(by: { $0.id < $1.id }) where node.isHosted {
            let hostedId = node.hostedId ?? 0
            if hostedRowIds[hostedId] == nil {
                hostedRowIds[hostedId] = node.id
            } else {
                removed.append(node.id)
            }
        }
        if !removed.isEmpty {
            let drop = Set(removed)
            result.removeAll { drop.contains($0.id) }
        }

        var adoptable = result.filter { !$0.isHosted }.map { $0.id }
        var nextId = (result.map { $0.id }.max() ?? 0) + 1
        var seen = Set<Int64>()
        var serverToLocal: [Int64: Int64] = [:]
        var unsupported: [HostedNodeDTO] = []

        for dto in listing.nodes where dto.id > 0 {
            guard var incoming = ProxyUriParser.parse(dto.uri) else {
                unsupported.append(dto)
                continue
            }
            let name = dto.name.trimmingCharacters(in: .whitespacesAndNewlines)
            if !name.isEmpty { incoming.name = name }
            seen.insert(dto.id)
            if let rowId = hostedRowIds[dto.id], let index = result.firstIndex(where: { $0.id == rowId }) {
                result[index] = withConnection(of: incoming, onto: result[index], hostedId: dto.id)
                serverToLocal[dto.id] = rowId
                continue
            }
            let key = ProxyNodeIdentity.key(incoming)
            if let matchPosition = adoptable.firstIndex(where: { id in
                result.first(where: { $0.id == id }).map { ProxyNodeIdentity.key($0) == key } ?? false
            }) {
                let rowId = adoptable.remove(at: matchPosition)
                if let index = result.firstIndex(where: { $0.id == rowId }) {
                    result[index] = withConnection(of: incoming, onto: result[index], hostedId: dto.id)
                }
                serverToLocal[dto.id] = rowId
                continue
            }
            incoming.id = nextId
            incoming.hostedId = dto.id
            incoming.subscriptionId = nil
            incoming.forwardId = nil
            incoming.enabled = true
            nextId += 1
            result.append(incoming)
            serverToLocal[dto.id] = incoming.id
        }

        // Hosted rows the panel no longer lists (deleted elsewhere, or now a type iOS cannot run).
        for (hostedId, rowId) in hostedRowIds where !seen.contains(hostedId) {
            removed.append(rowId)
        }
        let removedSet = Set(removed)
        result.removeAll { removedSet.contains($0.id) }

        let unsupportedIds = Set(unsupported.map { $0.id })
        var pairings: [CloudPairing] = []
        var pairedForwards = Set<Int64>()
        for pairing in listing.pairings where pairing.forwardId > 0 {
            guard !pairedForwards.contains(pairing.forwardId) else { continue }
            if let local = serverToLocal[pairing.nodeId] {
                pairings.append(CloudPairing(forwardId: pairing.forwardId, hostedNodeId: pairing.nodeId, localNodeId: local, source: pairing.source))
            } else if unsupportedIds.contains(pairing.nodeId) {
                pairings.append(CloudPairing(forwardId: pairing.forwardId, hostedNodeId: pairing.nodeId, localNodeId: nil, source: pairing.source))
            } else {
                continue
            }
            pairedForwards.insert(pairing.forwardId)
        }

        let previousPairings = previous.clientId == clientId ? previous.pairings : []
        let changed = pairings.filter { pairing in
            !previousPairings.contains { $0.forwardId == pairing.forwardId && $0.hostedNodeId == pairing.hostedNodeId && $0.localNodeId == pairing.localNodeId }
        }
        let state = CloudNodeSyncState(
            clientId: clientId,
            revision: listing.revision,
            syncedAt: now,
            pairings: pairings,
            unsupportedNodes: unsupported
        )
        return CloudMergeResult(nodes: result, state: state, changedPairings: changed, removedLocalIds: removed.sorted())
    }

    /// After an upload: the local row becomes the hosted row. If another row already holds that
    /// hosted id, the uploaded row was a duplicate and is removed.
    public static func linkUploaded(localId: Int64, hostedId: Int64, in nodes: [ProxyNode]) -> [ProxyNode] {
        guard hostedId > 0, let index = nodes.firstIndex(where: { $0.id == localId }) else { return nodes }
        if nodes[index].hostedId == hostedId { return nodes }
        var result = nodes
        if result.contains(where: { $0.hostedId == hostedId && $0.id != localId }) {
            result.remove(at: index)
            return result
        }
        result[index].hostedId = hostedId
        // Detach from its subscription so a subscription refresh cannot delete the hosted row.
        result[index].subscriptionId = nil
        return result
    }

    /// "Sync all local nodes to cloud": batches of local, uploadable nodes (ref = local id) and
    /// the number of local nodes the panel would refuse.
    public static func bulkUploadBatches(_ nodes: [ProxyNode]) -> (batches: [[HostedNodeBatchEntry]], unsupported: Int) {
        let local = nodes.filter { !$0.isHosted }
        var entries: [HostedNodeBatchEntry] = []
        var unsupported = 0
        for node in local {
            guard ProxyShareLink.isCloudUploadable(node), let uri = ProxyShareLink.format(node) else {
                unsupported += 1
                continue
            }
            entries.append(HostedNodeBatchEntry(ref: String(node.id), uri: uri, name: node.name))
        }
        var batches: [[HostedNodeBatchEntry]] = []
        var start = 0
        while start < entries.count {
            let end = min(start + maxBatch, entries.count)
            batches.append(Array(entries[start..<end]))
            start = end
        }
        return (batches, unsupported)
    }

    /// The incoming node's connection (name, server, port, settings) on an existing row; keeps
    /// the row's id, `enabled` and `forwardId`.
    static func withConnection(of incoming: ProxyNode, onto row: ProxyNode, hostedId: Int64) -> ProxyNode {
        var updated = row
        updated.name = incoming.name
        updated.server = incoming.server
        updated.port = incoming.port
        updated.settings = incoming.settings
        updated.hostedId = hostedId
        updated.subscriptionId = nil
        return updated
    }
}

/// Outcome of "sync all local nodes to cloud".
public struct CloudBulkUploadResult: Equatable {
    public var uploaded: Int
    public var alreadyHosted: Int
    public var unsupported: Int
    public var failed: Int
    /// (local node id, error code) for every failed entry.
    public var failures: [CloudUploadFailure]

    public init(uploaded: Int = 0, alreadyHosted: Int = 0, unsupported: Int = 0, failed: Int = 0, failures: [CloudUploadFailure] = []) {
        self.uploaded = uploaded
        self.alreadyHosted = alreadyHosted
        self.unsupported = unsupported
        self.failed = failed
        self.failures = failures
    }

    /// e.g. "已上传 3 个，云端已有 1 个，不支持 0 个，失败 0 个".
    public var summary: String {
        return L("Uploaded %@, already in cloud %@, unsupported %@, failed %@", String(describing: uploaded), String(describing: alreadyHosted), String(describing: unsupported), String(describing: failed))
    }
}

public struct CloudUploadFailure: Equatable {
    public var localNodeId: Int64
    public var code: String
    public var field: String?

    public init(localNodeId: Int64, code: String, field: String? = nil) {
        self.localNodeId = localNodeId
        self.code = code
        self.field = field
    }
}
