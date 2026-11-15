import CryptoKit
import Foundation

public struct DebugCollectionMeta: Codable, Equatable, Sendable {
    public let uuid: String
    public let status: String
    public let expiresAt: String
    /// Absent/false on older panels: sequence numbers alone cannot safely identify a retry.
    public let supportsUploadID: Bool

    public init(uuid: String, status: String, expiresAt: String = "", supportsUploadID: Bool = false) {
        self.uuid = uuid.trimmingCharacters(in: .whitespacesAndNewlines).lowercased()
        self.status = status.trimmingCharacters(in: .whitespacesAndNewlines).lowercased()
        self.expiresAt = expiresAt
        self.supportsUploadID = supportsUploadID
    }

    private enum CodingKeys: String, CodingKey {
        case uuid, status
        case expiresAt = "expires_at"
        case supportsUploadID = "supports_upload_id"
    }

    public init(from decoder: Decoder) throws {
        let values = try decoder.container(keyedBy: CodingKeys.self)
        self.init(
            uuid: try values.decode(String.self, forKey: .uuid),
            status: try values.decode(String.self, forKey: .status),
            expiresAt: try values.decodeIfPresent(String.self, forKey: .expiresAt) ?? "",
            supportsUploadID: try values.decodeIfPresent(Bool.self, forKey: .supportsUploadID) ?? false
        )
    }

    public var isActive: Bool {
        status.lowercased() == "active" && DebugLogUploader.isValidCollectionUUID(uuid)
    }

    public func hasExpired(at now: Date = Date()) -> Bool {
        guard !expiresAt.isEmpty else { return false }
        let formatter = ISO8601DateFormatter()
        formatter.formatOptions = [.withInternetDateTime, .withFractionalSeconds]
        let expiry = formatter.date(from: expiresAt) ?? ISO8601DateFormatter().date(from: expiresAt)
        return expiry.map { $0 <= now } ?? false
    }
}

public struct DebugLogChunk: Equatable, Sendable {
    public let sequence: Int
    public let sha256: String
    public let chunkBase64: String
    public let byteCount: Int

    public init(sequence: Int, sha256: String, chunkBase64: String, byteCount: Int) {
        self.sequence = sequence
        self.sha256 = sha256
        self.chunkBase64 = chunkBase64
        self.byteCount = byteCount
    }
}

/// Redacts credentials and IP literals from iOS client logs and splits them into
/// 256 KiB SHA-256-verified chunks for `POST api/client/debug/log`.
public enum DebugLogUploader {
    public static let chunkBytes = 256 * 1024
    public static let maxBundleBytes = 4 * 1024 * 1024

    private static let uuidRegex = try! NSRegularExpression(
        pattern: "^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$",
        options: [.caseInsensitive]
    )
    private static let bearerRegex = try! NSRegularExpression(
        pattern: "(authorization\\s*:\\s*bearer\\s+)[^\\s,;]+",
        options: [.caseInsensitive]
    )
    private static let secretRegex = try! NSRegularExpression(
        pattern: "(\\b(?:password|passwd|secret|token|(?:agent|access|refresh)[_-]?token|credential|session[_-]?ticket|private[_-]?key|api[_-]?key)\\b[\"']?\\s*[:=]\\s*)(?:\"[^\"]*\"|'[^']*'|[^\\s,;&]+)",
        options: [.caseInsensitive]
    )
    private static let querySecretRegex = try! NSRegularExpression(
        pattern: "([?&](?:token|password|secret|credential|key)=)[^&\\s]+",
        options: [.caseInsensitive]
    )
    private static let ipv4Regex = try! NSRegularExpression(
        pattern: "(?<![A-Za-z0-9])(?:25[0-5]|2[0-4][0-9]|1[0-9]{2}|[1-9]?[0-9])(?:\\.(?:25[0-5]|2[0-4][0-9]|1[0-9]{2}|[1-9]?[0-9])){3}(?![A-Za-z0-9])",
        options: []
    )
    private static let ipv6CandidateRegex = try! NSRegularExpression(
        pattern: "(?<![A-Za-z0-9])(?:[0-9A-Fa-f]{0,4}:){2,}[0-9A-Fa-f:.]*(?![A-Za-z0-9])",
        options: []
    )

    public static func isValidCollectionUUID(_ uuid: String) -> Bool {
        let trimmed = uuid.trimmingCharacters(in: .whitespacesAndNewlines)
        let range = NSRange(trimmed.startIndex..<trimmed.endIndex, in: trimmed)
        return uuidRegex.firstMatch(in: trimmed, options: [], range: range) != nil
    }

    public static func redact(_ value: String) -> String {
        var text = value.replacingOccurrences(of: "\0", with: "")
        text = replace(bearerRegex, in: text, with: "$1[REDACTED]")
        text = replace(secretRegex, in: text, with: "$1[REDACTED]")
        text = replace(querySecretRegex, in: text, with: "$1[REDACTED]")
        text = replace(ipv4Regex, in: text, with: "[REDACTED]")

        let matches = ipv6CandidateRegex.matches(
            in: text,
            options: [],
            range: NSRange(text.startIndex..<text.endIndex, in: text)
        )
        for match in matches.reversed() {
            guard let swiftRange = Range(match.range, in: text) else { continue }
            let candidate = String(text[swiftRange]).trimmingCharacters(in: CharacterSet(charactersIn: "[]"))
            if IPLiteral.normalize(candidate)?.contains(":") == true {
                text.replaceSubrange(swiftRange, with: "[REDACTED]")
            }
        }
        return text
    }

    public static func sanitizedBundle(fromRawText rawText: String) -> Data {
        let sanitized = Data(redact(rawText).utf8)
        return sanitized.isEmpty ? Data("=== empty log ===\n".utf8) : Data(sanitized.prefix(maxBundleBytes))
    }

    public static func buildChunks(fromRawText rawText: String) -> [DebugLogChunk] {
        buildChunks(fromSanitizedBundle: sanitizedBundle(fromRawText: rawText))
    }

    /// Splits the already frozen bytes; retries must never re-read or re-redact live logs.
    public static func buildChunks(fromSanitizedBundle sanitized: Data) -> [DebugLogChunk] {
        var chunks: [DebugLogChunk] = []
        var offset = 0
        var seq = 0
        while offset < sanitized.count {
            let end = min(offset + chunkBytes, sanitized.count)
            let slice = sanitized.subdata(in: offset..<end)
            let sha = SHA256.hash(data: slice).map { String(format: "%02x", $0) }.joined()
            chunks.append(DebugLogChunk(
                sequence: seq,
                sha256: sha,
                chunkBase64: slice.base64EncodedString(),
                byteCount: slice.count
            ))
            seq += 1
            offset = end
        }
        return chunks
    }

    private static func replace(_ regex: NSRegularExpression, in text: String, with template: String) -> String {
        let range = NSRange(text.startIndex..<text.endIndex, in: text)
        return regex.stringByReplacingMatches(in: text, options: [], range: range, withTemplate: template)
    }
}

/// One redacted snapshot, persisted before the first HTTP request. All payload fields are
/// immutable; progress is deliberately not persisted because replay is idempotent by upload ID.
public struct PendingDebugLogBundle: Codable, Equatable, Sendable {
    public let scope: String
    public let collection: DebugCollectionMeta
    public let uploadID: String
    public let referenceCode: String
    public let source: String
    public let bytes: Data

    public init(scope: String, collection: DebugCollectionMeta, referenceCode: String, rawText: String) throws {
        self.scope = scope
        self.collection = collection
        self.uploadID = UUID().uuidString.lowercased()
        self.referenceCode = referenceCode
        self.source = "ios-client.log"
        self.bytes = DebugLogUploader.sanitizedBundle(fromRawText: rawText)
        try validate()
    }

    public func validate() throws {
        guard collection.supportsUploadID else { throw DebugLogUploadError.unsupportedPanel }
        guard !scope.isEmpty, collection.isActive,
              DebugLogUploader.isValidCollectionUUID(uploadID),
              !bytes.isEmpty, bytes.count <= DebugLogUploader.maxBundleBytes,
              source == "ios-client.log" else {
            throw DebugLogUploadError.invalidPendingBundle
        }
    }
}

public enum DebugLogUploadError: Error, LocalizedError {
    case unsupportedPanel
    case busy
    case pendingForAnotherAccount
    case invalidPendingBundle
    case terminal(String)

    public var errorDescription: String? {
        switch self {
        case .unsupportedPanel: return "unsupported_upload_protocol: This panel does not support safe diagnostic uploads. Update the panel before uploading logs."
        case .busy: return "A diagnostic upload is already in progress."
        case .pendingForAnotherAccount: return "A diagnostic upload is pending for another account. Sign in to that account to retry it."
        case .invalidPendingBundle: return "The saved diagnostic bundle is invalid."
        case .terminal(let reason): return "The diagnostic collection cannot accept this bundle (\(reason))."
        }
    }
}

/// Diagnostics keeps the application error code separately from the human message.
/// A proxy's generic 404/409 is not proof that a collection or chunk is invalid.
public struct DebugLogHTTPError: Error, LocalizedError {
    public let status: Int
    public let code: String?

    public init(status: Int, body: Data) {
        self.status = status
        let object = JSONLenient.parseObject(body)
        let errorObject = object?["error"] as? [String: Any]
        self.code = (object?["error"] as? String) ?? (errorObject?["code"] as? String)
    }

    public var errorDescription: String? { "Diagnostic upload failed (HTTP \(status))." }
}

public enum DebugLogUploadFailure: String, Codable, Sendable {
    case retry, paused, terminal, cancelled

    public static func classify(_ error: Error) -> Self {
        if error is CancellationError || (error as? URLError)?.code == .cancelled { return .cancelled }
        if let error = error as? DebugLogHTTPError {
            return classify(status: error.status, code: error.code)
        }
        if let error = error as? PanelError {
            switch error {
            case .transport(let underlying):
                return classify(underlying)
            case .http(let status, _):
                return classify(status: status, code: nil)
            case .deviceLimitReached: return .retry
            case .terminalControl: return .paused
            case .invalidResponse: return .retry
            }
        }
        return .retry
    }

    private static func classify(status: Int, code: String?) -> Self {
        if status == 401 || status == 403 { return .paused }
        if status == 410 || status == 413 || status == 422 { return .terminal }
        if status == 404 && code == "debug_collection_not_found" { return .terminal }
        if status == 409 && code == "debug_chunk_replay" { return .terminal }
        return .retry
    }

    /// Persist only a fixed reason/status, never a server message which may contain secrets.
    static func reason(_ error: Error) -> String {
        if let httpError = error as? DebugLogHTTPError { return "http_\(httpError.status)" }
        if let panelError = error as? PanelError, case .http(let status, _) = panelError {
            return "http_\(status)"
        }
        return classify(error).rawValue
    }
}

public struct DebugLogUploadRecord: Codable, Equatable, Sendable {
    public let scope: String
    public let collectionUUID: String
    public let reason: String
}

/// At most one 4 MiB snapshot and 32 small completion/terminal records on disk.
public struct DebugLogUploadState: Codable, Equatable, Sendable {
    public var pending: PendingDebugLogBundle?
    public var lastFailure: DebugLogUploadFailure?
    public var nextAttemptAt: Date?
    public var records: [DebugLogUploadRecord] = []

    public init() {}

    mutating func finish(_ bundle: PendingDebugLogBundle, reason: String) {
        records.removeAll { $0.scope == bundle.scope && $0.collectionUUID == bundle.collection.uuid }
        records.append(DebugLogUploadRecord(scope: bundle.scope, collectionUUID: bundle.collection.uuid, reason: reason))
        records = Array(records.suffix(32))
        pending = nil
        lastFailure = nil
        nextAttemptAt = nil
    }
}

public struct DebugLogUploadResult: Sendable {
    public let referenceCode: String
    public let bytes: Int
}

/// Shared by manual and passive uploads. Main-actor admission is set BEFORE the first await;
/// actor reentrancy therefore cannot create two snapshots or interleave their requests.
/// Disk writes throw: a snapshot must be durable before it can be sent or marked complete.
@MainActor
public final class DebugLogUploadCoordinator {
    private let load: () async throws -> DebugLogUploadState
    private let save: (DebugLogUploadState) async throws -> Void
    private var busy = false

    public init(load: @escaping () async throws -> DebugLogUploadState, save: @escaping (DebugLogUploadState) async throws -> Void) {
        self.load = load
        self.save = save
    }

    /// `collection == nil` means a manual request. It resumes the pending snapshot first;
    /// only when none exists may it start a new collection and capture current logs.
    public func run(
        scope: String,
        collection: DebugCollectionMeta? = nil,
        lastCompletedCollectionUUID: String? = nil,
        start: () async throws -> (collection: DebugCollectionMeta, referenceCode: String),
        collect: () async throws -> String,
        send: (PendingDebugLogBundle) async throws -> Void,
        completed: (PendingDebugLogBundle) -> Void
    ) async throws -> DebugLogUploadResult? {
        let manual = collection == nil
        guard !busy else {
            if manual { throw DebugLogUploadError.busy }
            return nil
        }
        busy = true
        defer { busy = false }
        try Task.checkCancellation()
        var state = try await load()
        try Task.checkCancellation()
        // Expiry belongs to the saved collection, not the current account. Retire it
        // before checking scope so a removed/signed-out account cannot hold this slot
        // forever. No current-account metadata is used to retire another account's bundle.
        if let pending = state.pending, pending.collection.hasExpired() {
            state.finish(pending, reason: "expired")
            try await save(state)
            try Task.checkCancellation()
        }
        if let pending = state.pending {
            guard pending.scope == scope else {
                if manual { throw DebugLogUploadError.pendingForAnotherAccount }
                return nil
            }
            let superseded = collection.map { $0.uuid != pending.collection.uuid } ?? false
            let inactive = collection.map { !$0.isActive || $0.hasExpired() } ?? false
            if superseded || inactive {
                state.finish(pending, reason: superseded ? "superseded" : "expired")
                try await save(state)
            }
        }
        // An old panel must never receive the new protocol, even for a saved bundle.
        if let collection, !collection.supportsUploadID || !collection.isActive || collection.hasExpired() { return nil }
        if state.pending != nil {
            // An authenticated pull can unpause a 401/403. Retryable errors are throttled.
            if !manual, state.lastFailure == .retry, let next = state.nextAttemptAt, next > Date() { return nil }
        } else {
            let target: DebugCollectionMeta
            let reference: String
            if let collection {
                guard collection.isActive else { return nil }
                if lastCompletedCollectionUUID?.lowercased() == collection.uuid { return nil }
                if state.records.contains(where: { $0.scope == scope && $0.collectionUUID == collection.uuid }) { return nil }
                target = collection
                reference = String(collection.uuid.prefix(8))
            } else {
                let started = try await start()
                target = started.collection
                reference = started.referenceCode
                if let record = state.records.last(where: { $0.scope == scope && $0.collectionUUID == target.uuid }), record.reason != "completed" {
                    throw DebugLogUploadError.terminal(record.reason)
                }
            }
            guard target.supportsUploadID else { throw DebugLogUploadError.unsupportedPanel }
            let raw = try await collect()
            try Task.checkCancellation()
            state.pending = try await Task.detached(priority: .utility) {
                try PendingDebugLogBundle(scope: scope, collection: target, referenceCode: reference, rawText: raw)
            }.value
            try Task.checkCancellation()
            state.lastFailure = nil
            state.nextAttemptAt = nil
            try await save(state)
        }
        guard let pending = state.pending else { throw DebugLogUploadError.invalidPendingBundle }
        try pending.validate()
        do {
            try Task.checkCancellation()
            try await send(pending)
            try Task.checkCancellation()
        } catch {
            if Task.isCancelled { throw CancellationError() }
            let disposition = DebugLogUploadFailure.classify(error)
            if disposition == .cancelled { throw error }
            if disposition == .terminal {
                state.finish(pending, reason: DebugLogUploadFailure.reason(error))
            } else {
                state.lastFailure = disposition
                state.nextAttemptAt = disposition == .retry ? Date().addingTimeInterval(30) : nil
            }
            try await save(state)
            throw error
        }
        let unfinished = state
        state.finish(pending, reason: "completed")
        try await save(state)
        if Task.isCancelled {
            // Cancellation during the atomic completion write still leaves an idempotently
            // replayable snapshot, never a local "uploaded" mark.
            try await save(unfinished)
            throw CancellationError()
        }
        completed(pending)
        return DebugLogUploadResult(referenceCode: pending.referenceCode, bytes: pending.bytes.count)
    }
}

public extension PullConfigsResponse {
    var debugCollection: DebugCollectionMeta? {
        guard let clientObj = client as? [String: Any],
              let coll = clientObj["debug_collection"] as? [String: Any]
        else { return nil }
        let uuid = (coll["uuid"] as? String ?? "").trimmingCharacters(in: .whitespacesAndNewlines).lowercased()
        let status = (coll["status"] as? String ?? "").trimmingCharacters(in: .whitespacesAndNewlines).lowercased()
        let expiresAt = coll["expires_at"] as? String ?? ""
        guard !uuid.isEmpty else { return nil }
        return DebugCollectionMeta(
            uuid: uuid, status: status, expiresAt: expiresAt,
            supportsUploadID: coll["supports_upload_id"] as? Bool == true
        )
    }
}
