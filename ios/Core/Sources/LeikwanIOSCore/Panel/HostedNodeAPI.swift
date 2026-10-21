import Foundation

/// One hosted node in a panel listing (`HostedNodeService::clientListing`). Credentials are
/// inside `uri` and `settings`; never log a DTO.
public struct HostedNodeDTO: Codable, Equatable, Identifiable {
    public var id: Int64
    public var name: String
    /// "shadowsocks" / "socks5" / "vless".
    public var nodeProtocol: String
    public var server: String
    public var port: Int
    /// Canonical share link (parsed like a pasted link).
    public var uri: String
    public var version: Int64
    /// "console" / "device".
    public var source: String
    public var updatedAt: String

    public init(id: Int64, name: String, nodeProtocol: String, server: String, port: Int, uri: String,
                version: Int64 = 1, source: String = "device", updatedAt: String = "") {
        self.id = id
        self.name = name
        self.nodeProtocol = nodeProtocol
        self.server = server
        self.port = port
        self.uri = uri
        self.version = version
        self.source = source
        self.updatedAt = updatedAt
    }

    static func parse(_ value: Any?) -> HostedNodeDTO? {
        guard let obj = value as? [String: Any], let id = JSONLenient.int64(obj["id"]), id > 0 else { return nil }
        return HostedNodeDTO(
            id: id,
            name: JSONLenient.stringOrEmpty(obj["name"]),
            nodeProtocol: JSONLenient.stringOrEmpty(obj["protocol"]).lowercased(),
            server: JSONLenient.stringOrEmpty(obj["server"]),
            port: JSONLenient.int(obj["port"]) ?? 0,
            uri: JSONLenient.stringOrEmpty(obj["uri"]),
            version: JSONLenient.int64(obj["version"]) ?? 1,
            source: JSONLenient.stringOrEmpty(obj["source"]),
            updatedAt: JSONLenient.stringOrEmpty(obj["updated_at"])
        )
    }
}

/// An effective forward -> hosted node pairing. `source` is "explicit" (console or a device)
/// or "target_match" (the forward's target equals the node's server:port).
public struct HostedNodePairingDTO: Codable, Equatable {
    public var forwardId: Int64
    public var nodeId: Int64
    public var source: String

    public init(forwardId: Int64, nodeId: Int64, source: String = "explicit") {
        self.forwardId = forwardId
        self.nodeId = nodeId
        self.source = source
    }

    public var isExplicit: Bool { return source != "target_match" }
}

public struct HostedNodeBatchResult: Equatable {
    public var ref: String
    public var ok: Bool
    public var nodeId: Int64?
    public var created: Bool?
    public var error: String?
    public var field: String?

    public init(ref: String, ok: Bool, nodeId: Int64? = nil, created: Bool? = nil, error: String? = nil, field: String? = nil) {
        self.ref = ref
        self.ok = ok
        self.nodeId = nodeId
        self.created = created
        self.error = error
        self.field = field
    }
}

public struct HostedNodeBatchEntry: Equatable {
    public var ref: String
    public var uri: String
    public var name: String?

    public init(ref: String, uri: String, name: String? = nil) {
        self.ref = ref
        self.uri = uri
        self.name = name
    }
}

/// Every hosted-node call answers with the full listing (plus call-specific fields).
public struct HostedNodesListing: Equatable {
    public var revision: String
    public var nodes: [HostedNodeDTO]
    public var pairings: [HostedNodePairingDTO]
    public var nodeId: Int64?
    public var created: Bool?
    public var results: [HostedNodeBatchResult]

    public init(revision: String = "", nodes: [HostedNodeDTO] = [], pairings: [HostedNodePairingDTO] = [],
                nodeId: Int64? = nil, created: Bool? = nil, results: [HostedNodeBatchResult] = []) {
        self.revision = revision
        self.nodes = nodes
        self.pairings = pairings
        self.nodeId = nodeId
        self.created = created
        self.results = results
    }

    public static func parse(_ obj: [String: Any]) -> HostedNodesListing {
        var listing = HostedNodesListing()
        listing.revision = JSONLenient.stringOrEmpty(obj["revision"])
        listing.nodes = JSONLenient.array(obj["nodes"]).compactMap { HostedNodeDTO.parse($0) }
        for raw in JSONLenient.array(obj["pairings"]) {
            guard let item = raw as? [String: Any],
                  let forwardId = JSONLenient.int64(item["forward_id"]), forwardId > 0,
                  let nodeId = JSONLenient.int64(item["node_id"]), nodeId > 0
            else { continue }
            let source = JSONLenient.stringOrEmpty(item["source"])
            listing.pairings.append(HostedNodePairingDTO(forwardId: forwardId, nodeId: nodeId, source: source.isEmpty ? "explicit" : source))
        }
        if let nodeId = JSONLenient.int64(obj["node_id"]), nodeId > 0 { listing.nodeId = nodeId }
        listing.created = JSONLenient.bool(obj["created"])
        for raw in JSONLenient.array(obj["results"]) {
            guard let item = raw as? [String: Any] else { continue }
            listing.results.append(HostedNodeBatchResult(
                ref: JSONLenient.stringOrEmpty(item["ref"]),
                ok: JSONLenient.bool(item["ok"]) ?? false,
                nodeId: JSONLenient.int64(item["node_id"]),
                created: JSONLenient.bool(item["created"]),
                error: JSONLenient.string(item["error"]),
                field: JSONLenient.string(item["field"])
            ))
        }
        return listing
    }
}

/// A hosted-node call the panel refused (or could not make): `code` is the panel error
/// (`hosted_node_invalid`, `hosted_node_limit_reached`, `device_untrusted`, ...) or a local code
/// (`hosted_nodes_unsupported` = panel without the API, `hosted_nodes_offline`, `hosted_nodes_network`).
/// Never carries node content.
public struct HostedNodeError: Error, Equatable, CustomStringConvertible {
    public let code: String
    public let field: String?
    public let status: Int

    public init(_ code: String, field: String? = nil, status: Int = 0) {
        self.code = code
        self.field = field
        self.status = status
    }

    public var description: String { return code }

    /// Stable, secret-free code for any error a cloud-node operation can throw
    /// (desktop `HostedNodeSyncService.ErrorCode`).
    public static func code(for error: Error) -> String {
        if let hosted = error as? HostedNodeError { return hosted.code }
        if let panel = error as? PanelError {
            switch panel {
            case .deviceLimitReached: return PanelError.deviceLimitCode
            case .terminalControl(let code, _): return code
            case .transport: return "hosted_nodes_network"
            case .http(let status, _): return "hosted_nodes_http_\(status)"
            case .invalidResponse: return "hosted_nodes_failed"
            }
        }
        return "hosted_nodes_failed"
    }

    /// User-facing text for a code (codes only, never node content).
    public static func userMessage(_ code: String) -> String {
        switch code {
        case "hosted_nodes_offline": return L("Not signed in; cloud nodes cannot sync")
        case "device_untrusted": return L("The server does not trust this device; cloud nodes are unavailable")
        case "hosted_nodes_unsupported", "hosted_nodes_unavailable": return L("This server does not support cloud nodes")
        case PanelError.deviceLimitCode: return L("Online device limit reached")
        case "rate_limited": return L("Too many requests. Try again later")
        case "hosted_node_limit_reached": return L("Cloud node limit reached (32)")
        case "hosted_node_duplicate": return L("The same node is already in the cloud")
        case "hosted_node_not_found": return L("Cloud node not found (it may have been deleted on another device)")
        case "hosted_node_forward_not_found": return L("The forward does not exist or belongs to another account")
        case "hosted_node_invalid": return L("The server rejected the node settings")
        case "hosted_node_unsupported_on_ios": return L("iOS does not support this node type yet; edit it on another device or in the console")
        case "hosted_nodes_network": return L("Network error; retrying automatically")
        default: return L("Cloud sync failed (%@)", String(describing: code))
        }
    }
}

/// The panel's hosted-node API (`/api/client/hosted-nodes*`, `/api/client/hosted-node-pairings/{id}`).
/// Every call returns the full listing. Device-limit / terminal refusals surface as `PanelError`,
/// everything else as `HostedNodeError`.
public protocol HostedNodeAPI {
    func listHostedNodes() async throws -> HostedNodesListing
    func createHostedNode(uri: String, name: String?, pairForwardId: Int64?) async throws -> HostedNodesListing
    func batchCreateHostedNodes(_ entries: [HostedNodeBatchEntry]) async throws -> HostedNodesListing
    func updateHostedNode(id: Int64, uri: String, name: String?) async throws -> HostedNodesListing
    func deleteHostedNode(id: Int64) async throws -> HostedNodesListing
    /// nil `nodeId` clears the explicit pairing (the panel falls back to a target match).
    func pairHostedNode(forwardId: Int64, nodeId: Int64?) async throws -> HostedNodesListing
}

extension PanelClient: HostedNodeAPI {
    public static let hostedNodesPath = "api/client/hosted-nodes"
    public static let hostedNodePairingsPath = "api/client/hosted-node-pairings"

    public func listHostedNodes() async throws -> HostedNodesListing {
        return try await hostedNodeCall(path: PanelClient.hostedNodesPath, method: "GET", body: nil)
    }

    public func createHostedNode(uri: String, name: String?, pairForwardId: Int64?) async throws -> HostedNodesListing {
        var body: [String: Any] = ["uri": uri]
        if let name = name, !name.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty { body["name"] = name }
        if let forwardId = pairForwardId, forwardId > 0 { body["pair_forward_id"] = forwardId }
        return try await hostedNodeCall(path: PanelClient.hostedNodesPath, method: "POST", body: body)
    }

    public func batchCreateHostedNodes(_ entries: [HostedNodeBatchEntry]) async throws -> HostedNodesListing {
        let nodes: [[String: Any]] = entries.map { entry in
            var item: [String: Any] = ["ref": entry.ref, "uri": entry.uri]
            if let name = entry.name, !name.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty { item["name"] = name }
            return item
        }
        return try await hostedNodeCall(path: PanelClient.hostedNodesPath + "/batch", method: "POST", body: ["nodes": nodes])
    }

    public func updateHostedNode(id: Int64, uri: String, name: String?) async throws -> HostedNodesListing {
        var body: [String: Any] = ["uri": uri]
        if let name = name, !name.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty { body["name"] = name }
        return try await hostedNodeCall(path: PanelClient.hostedNodesPath + "/\(id)", method: "PUT", body: body)
    }

    public func deleteHostedNode(id: Int64) async throws -> HostedNodesListing {
        return try await hostedNodeCall(path: PanelClient.hostedNodesPath + "/\(id)", method: "DELETE", body: nil)
    }

    public func pairHostedNode(forwardId: Int64, nodeId: Int64?) async throws -> HostedNodesListing {
        var body: [String: Any] = [:]
        if let nodeId = nodeId, nodeId > 0 { body["node_id"] = nodeId }
        return try await hostedNodeCall(path: PanelClient.hostedNodePairingsPath + "/\(forwardId)", method: "PUT", body: body)
    }

    private func hostedNodeCall(path: String, method: String, body: [String: Any]?) async throws -> HostedNodesListing {
        let data: Data
        do {
            let answer = try await perform(path: path, method: method, jsonBody: body, extraHeaders: [:])
            data = answer.0
        } catch let error as PanelError {
            throw PanelClient.hostedNodeError(from: error)
        }
        guard let obj = JSONLenient.parseObject(data) else {
            throw HostedNodeError("hosted_nodes_failed")
        }
        if JSONLenient.bool(obj["ok"]) != true {
            throw HostedNodeError(JSONLenient.string(obj["error"]) ?? "hosted_nodes_failed", field: JSONLenient.string(obj["field"]))
        }
        return HostedNodesListing.parse(obj)
    }

    /// Every refusal becomes a `HostedNodeError` code. A 403 `device_untrusted` from these
    /// endpoints means "this platform may not use hosted nodes", not a revoked session, so it is
    /// never treated as terminal here (the heartbeat / control loop owns real kicks). A 404
    /// without a code means the panel predates the API.
    static func hostedNodeError(from error: PanelError) -> HostedNodeError {
        switch error {
        case .deviceLimitReached:
            return HostedNodeError(PanelError.deviceLimitCode, status: 429)
        case .terminalControl(let code, _):
            return HostedNodeError(code, status: 403)
        case .transport:
            return HostedNodeError("hosted_nodes_network")
        case .http(let status, let message):
            let code = message.trimmingCharacters(in: .whitespacesAndNewlines)
            if code.hasPrefix("HTTP ") || code.isEmpty || code.contains(" ") {
                return HostedNodeError(status == 404 ? "hosted_nodes_unsupported" : "hosted_nodes_http_\(status)", status: status)
            }
            return HostedNodeError(code, status: status)
        case .invalidResponse:
            return HostedNodeError("hosted_nodes_failed")
        }
    }
}

extension PullConfigsResponse {
    /// `client.hosted_nodes_revision`: nil when the panel predates hosted nodes (do nothing),
    /// "" when the client hosts no nodes, otherwise a digest that moves on every change.
    public var hostedNodesRevision: String? {
        guard let client = client as? [String: Any], let value = client["hosted_nodes_revision"] else { return nil }
        if value is NSNull { return nil }
        // JSONLenient.string maps "" to nil; here "" is meaningful (nothing hosted).
        if let text = value as? String { return text.trimmingCharacters(in: .whitespacesAndNewlines) }
        return JSONLenient.string(value)
    }
}
