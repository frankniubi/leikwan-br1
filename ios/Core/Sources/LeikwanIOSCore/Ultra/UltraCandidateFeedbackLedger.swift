import Foundation

/// One data-plane outcome about a leased candidate, as the panel heartbeat expects it
/// (`ultra_candidate_feedback[]`, `UltraClientStatusService::ingestFeedback`). The candidate and
/// lease are opaque hashes the panel issued; no endpoint or line id is ever reported.
public struct UltraCandidateFeedbackReport: Equatable {
    public var forwardId: Int64
    public var candidateKeyHash: String
    public var leaseHash: String
    public var protocolName: String
    public var outcome: String
    public var reason: String
    public var evidenceId: String
    public var observedAt: String

    public init(forwardId: Int64, candidateKeyHash: String, leaseHash: String, protocolName: String,
                outcome: String, reason: String, evidenceId: String, observedAt: String) {
        self.forwardId = forwardId
        self.candidateKeyHash = candidateKeyHash
        self.leaseHash = leaseHash
        self.protocolName = protocolName
        self.outcome = outcome
        self.reason = reason
        self.evidenceId = evidenceId
        self.observedAt = observedAt
    }

    public func jsonObject() -> [String: Any] {
        return [
            "forward_id": forwardId,
            "candidate_key_hash": candidateKeyHash,
            "lease_hash": leaseHash,
            "protocol": protocolName,
            "outcome": outcome,
            "reason": reason,
            "evidence_id": evidenceId,
            "observed_at": observedAt,
        ]
    }

    /// Forward and evidence id; entries without one fall back to what identifies the observation.
    public var dedupeKey: String {
        if !evidenceId.isEmpty { return "\(forwardId):\(evidenceId)" }
        return "\(forwardId):\(candidateKeyHash):\(reason):\(observedAt)"
    }
}

/// Forwards the runtime's rolling `candidate_feedback` list (in each `*.status.json`) to the
/// panel (port of Android `runtime/UltraCandidateFeedbackLedger.kt`, same contract as the desktop
/// HeartbeatService and the OpenWrt router):
///
/// * only fresh observations (`maxAgeSeconds` old at most, `futureSkewSeconds` ahead) in the
///   shape the panel accepts are sent, at most `maxEntries` per heartbeat;
/// * an entry is identified by forward and evidence id; once a heartbeat carrying it succeeds
///   its key joins a persisted acknowledged set (newest `maxAcknowledged` kept), so the runtime's
///   rolling list never reports the same evidence twice;
/// * feedback is read even when no bytes move, so setup failures reach the panel promptly.
///
/// State is one JSON file at a caller-supplied path holding dedupe keys only, never lease material.
public final class UltraCandidateFeedbackLedger {
    /// UltraCandidateReputationService::MAX_FEEDBACK.
    public static let maxEntries = 32
    public static let maxAcknowledged = 512
    /// UltraMode::FEEDBACK_MAX_AGE_SECONDS / FEEDBACK_FUTURE_SKEW_SECONDS.
    public static let maxAgeSeconds: TimeInterval = 300
    public static let futureSkewSeconds: TimeInterval = 60
    static let maxStatusFileBytes = 64 * 1024
    private static let protocols: Set<String> = ["tcp", "udp", "tcp+udp", "native_socks5"]
    private static let outcomes: Set<String> = ["failure", "success"]

    private static let lock = NSLock()

    public let statusDirectory: URL
    public let stateFile: URL

    public init(statusDirectory: URL, stateFile: URL) {
        self.statusDirectory = statusDirectory
        self.stateFile = stateFile
    }

    public func collect(now: Date = Date()) -> [UltraCandidateFeedbackReport] {
        UltraCandidateFeedbackLedger.lock.lock()
        defer { UltraCandidateFeedbackLedger.lock.unlock() }
        var seen = Set(load())
        var result: [UltraCandidateFeedbackReport] = []
        let manager = FileManager.default
        guard let names = try? manager.contentsOfDirectory(atPath: statusDirectory.path) else { return [] }
        for name in names.sorted() where name.hasSuffix(UltraUsageLedger.statusSuffix) {
            if result.count >= UltraCandidateFeedbackLedger.maxEntries { break }
            let url = statusDirectory.appendingPathComponent(name)
            var isDirectory: ObjCBool = false
            guard manager.fileExists(atPath: url.path, isDirectory: &isDirectory), !isDirectory.boolValue,
                  let data = manager.contents(atPath: url.path),
                  !data.isEmpty, data.count <= UltraCandidateFeedbackLedger.maxStatusFileBytes,
                  let root = JSONLenient.parseObject(data)
            else { continue }
            for item in UltraCandidateFeedbackLedger.feedback(of: root, now: now) {
                if result.count >= UltraCandidateFeedbackLedger.maxEntries { break }
                if !seen.insert(item.dedupeKey).inserted { continue }
                result.append(item)
            }
        }
        return result
    }

    /// Records `sent` as delivered after a heartbeat carrying it succeeded.
    public func acknowledge(_ sent: [UltraCandidateFeedbackReport]) {
        if sent.isEmpty { return }
        UltraCandidateFeedbackLedger.lock.lock()
        defer { UltraCandidateFeedbackLedger.lock.unlock() }
        var keys = load()
        var seen = Set(keys)
        for item in sent where seen.insert(item.dedupeKey).inserted {
            keys.append(item.dedupeKey)
        }
        if keys.count > UltraCandidateFeedbackLedger.maxAcknowledged {
            keys.removeFirst(keys.count - UltraCandidateFeedbackLedger.maxAcknowledged)
        }
        save(keys)
    }

    /// The fresh, panel-shaped entries of one status document.
    public static func feedback(of root: [String: Any], now: Date) -> [UltraCandidateFeedbackReport] {
        guard let number = root["forward_id"] as? NSNumber, !JSONLenient.isBoolean(number),
              let forwardId = JSONLenient.strictInt64(number), forwardId > 0,
              let array = root["candidate_feedback"] as? [Any]
        else { return [] }
        var result: [UltraCandidateFeedbackReport] = []
        for element in array {
            guard let item = element as? [String: Any] else { continue }
            let candidateHash = string(item["candidate_key_hash"]).lowercased()
            let leaseHash = string(item["lease_hash"]).lowercased()
            let protocolName = string(item["protocol"]).lowercased()
            let outcome = string(item["outcome"]).lowercased()
            let reason = UltraStatusCollector.text(string(item["reason"]), limit: 64).lowercased()
            let evidenceId = string(item["evidence_id"])
            let observedAt = string(item["observed_at"])
            if !Hashing.isLowerHex64(candidateHash) || !Hashing.isLowerHex64(leaseHash)
                || !protocols.contains(protocolName) || !outcomes.contains(outcome)
                || reason.isEmpty || reason == "process_stderr"
                || (!evidenceId.isEmpty && !isEvidenceId(evidenceId))
                || !isFresh(observedAt, now: now) {
                continue
            }
            result.append(UltraCandidateFeedbackReport(
                forwardId: forwardId,
                candidateKeyHash: candidateHash,
                leaseHash: leaseHash,
                protocolName: protocolName,
                outcome: outcome,
                reason: reason,
                evidenceId: evidenceId,
                observedAt: observedAt
            ))
        }
        return result
    }

    // MARK: Validation

    private static func string(_ value: Any?) -> String {
        return (value as? String)?.trimmingCharacters(in: .whitespacesAndNewlines) ?? ""
    }

    /// The panel's `^[A-Za-z0-9._:-]{1,128}$`.
    private static func isEvidenceId(_ value: String) -> Bool {
        let bytes = value.utf8
        guard (1...128).contains(bytes.count) else { return false }
        return bytes.allSatisfy { byte in
            (byte >= 97 && byte <= 122) || (byte >= 65 && byte <= 90) || (byte >= 48 && byte <= 57)
                || byte == 46 || byte == 95 || byte == 58 || byte == 45
        }
    }

    /// An absent time is accepted (the panel does too); a malformed, non-UTC or stale one is not.
    private static func isFresh(_ observedAt: String, now: Date) -> Bool {
        if observedAt.isEmpty { return true }
        // The panel only parses `...Z` instants.
        guard observedAt.hasSuffix("Z"), let parsed = ISOInstant.parse(observedAt) else { return false }
        return parsed >= now.addingTimeInterval(-maxAgeSeconds) && parsed <= now.addingTimeInterval(futureSkewSeconds)
    }

    // MARK: State persistence

    private struct State: Codable {
        var v: Int = 1
        var acknowledged: [String] = []
    }

    private func load() -> [String] {
        guard let data = try? Data(contentsOf: stateFile),
              let state = try? JSONDecoder().decode(State.self, from: data)
        else { return [] }
        return state.acknowledged.filter { !$0.isEmpty }
    }

    private func save(_ keys: [String]) {
        let encoder = JSONEncoder()
        encoder.outputFormatting = [.sortedKeys]
        guard let data = try? encoder.encode(State(acknowledged: keys)) else { return }
        try? FileManager.default.createDirectory(at: stateFile.deletingLastPathComponent(), withIntermediateDirectories: true)
        try? data.write(to: stateFile, options: [.atomic])
    }
}
