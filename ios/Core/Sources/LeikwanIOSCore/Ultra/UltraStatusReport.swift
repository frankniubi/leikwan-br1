import Foundation

/// One forward's runtime health as the panel heartbeat expects it (`ultra_status[]`), port of
/// Android `runtime/UltraStatusCollector.kt`. Carries exactly the fields
/// `UltraClientStatusService::normalizeStatus` keeps: credentials, endpoints, selection tiers,
/// selection lease hashes and the runtime attestation are never included.
public struct UltraStatusReport: Equatable {
    public var forwardId: Int64
    public var state: String
    /// "" is omitted on the wire (the panel then records `unknown`).
    public var udpTransport: String
    public var detectedIngressZone: String
    public var latestTCP: UltraSelectionReport?
    public var activeUDP: UltraSelectionReport?
    public var haFailures: Int64
    public var udpQueueDrops: Int64
    public var udpSetupFailures: Int64
    public var bytesIn: Int64
    public var bytesOut: Int64
    public var haEvents: [UltraHAEventReport]
    public var updatedAt: String
    public var errorCode: String

    public init(
        forwardId: Int64,
        state: String,
        udpTransport: String = "",
        detectedIngressZone: String = "unknown",
        latestTCP: UltraSelectionReport? = nil,
        activeUDP: UltraSelectionReport? = nil,
        haFailures: Int64 = 0,
        udpQueueDrops: Int64 = 0,
        udpSetupFailures: Int64 = 0,
        bytesIn: Int64 = 0,
        bytesOut: Int64 = 0,
        haEvents: [UltraHAEventReport] = [],
        updatedAt: String = "",
        errorCode: String = ""
    ) {
        self.forwardId = forwardId
        self.state = state
        self.udpTransport = udpTransport
        self.detectedIngressZone = detectedIngressZone
        self.latestTCP = latestTCP
        self.activeUDP = activeUDP
        self.haFailures = haFailures
        self.udpQueueDrops = udpQueueDrops
        self.udpSetupFailures = udpSetupFailures
        self.bytesIn = bytesIn
        self.bytesOut = bytesOut
        self.haEvents = haEvents
        self.updatedAt = updatedAt
        self.errorCode = errorCode
    }

    public func jsonObject() -> [String: Any] {
        var obj: [String: Any] = [
            "forward_id": forwardId,
            "state": state,
            "detected_ingress_zone": detectedIngressZone,
            "ha_failures": haFailures,
            "udp_queue_drops": udpQueueDrops,
            "udp_setup_failures": udpSetupFailures,
            "bytes_in": bytesIn,
            "bytes_out": bytesOut,
            "ha_events": haEvents.map { $0.jsonObject() },
            "updated_at": updatedAt,
            "error_code": errorCode,
        ]
        if !udpTransport.isEmpty { obj["udp_transport"] = udpTransport }
        if let selection = latestTCP { obj["latest_tcp"] = selection.jsonObject() }
        if let selection = activeUDP { obj["active_udp"] = selection.jsonObject() }
        return obj
    }
}

public struct UltraSelectionReport: Equatable {
    public var selectionRef: String
    public var rttMs: Int64
    public var selectedAt: String
    /// Optional fields are omitted on the wire when empty / zero (leikwand `omitempty`).
    public var candidateKeyHash: String
    public var p95RttMs: Int64
    public var jitterMs: Int64
    public var lossPercent: Int64
    public var probeSent: Int64
    public var probeReceived: Int64

    public init(
        selectionRef: String,
        rttMs: Int64,
        selectedAt: String,
        candidateKeyHash: String = "",
        p95RttMs: Int64 = 0,
        jitterMs: Int64 = 0,
        lossPercent: Int64 = 0,
        probeSent: Int64 = 0,
        probeReceived: Int64 = 0
    ) {
        self.selectionRef = selectionRef
        self.rttMs = rttMs
        self.selectedAt = selectedAt
        self.candidateKeyHash = candidateKeyHash
        self.p95RttMs = p95RttMs
        self.jitterMs = jitterMs
        self.lossPercent = lossPercent
        self.probeSent = probeSent
        self.probeReceived = probeReceived
    }

    public func jsonObject() -> [String: Any] {
        var obj: [String: Any] = [
            "selection_ref": selectionRef,
            "rtt_ms": rttMs,
            "selected_at": selectedAt,
        ]
        if !candidateKeyHash.isEmpty { obj["candidate_key_hash"] = candidateKeyHash }
        if p95RttMs > 0 { obj["p95_rtt_ms"] = p95RttMs }
        if jitterMs > 0 { obj["jitter_ms"] = jitterMs }
        if lossPercent > 0 { obj["loss_percent"] = lossPercent }
        if probeSent > 0 { obj["probe_sent"] = probeSent }
        if probeReceived > 0 { obj["probe_received"] = probeReceived }
        return obj
    }
}

public struct UltraHAEventReport: Equatable {
    public var protocolName: String
    public var reason: String
    public var generation: String
    public var at: String
    public var candidateKeyHash: String
    public var leaseHash: String
    public var upstreamBytes: Int64?
    public var remoteReadBytes: Int64?
    public var remoteReadReason: String
    public var remoteStoppedFirst: Bool?
    public var localPathAvoided: Bool?

    public init(
        protocolName: String,
        reason: String,
        generation: String = "",
        at: String = "",
        candidateKeyHash: String = "",
        leaseHash: String = "",
        upstreamBytes: Int64? = nil,
        remoteReadBytes: Int64? = nil,
        remoteReadReason: String = "",
        remoteStoppedFirst: Bool? = nil,
        localPathAvoided: Bool? = nil
    ) {
        self.protocolName = protocolName
        self.reason = reason
        self.generation = generation
        self.at = at
        self.candidateKeyHash = candidateKeyHash
        self.leaseHash = leaseHash
        self.upstreamBytes = upstreamBytes
        self.remoteReadBytes = remoteReadBytes
        self.remoteReadReason = remoteReadReason
        self.remoteStoppedFirst = remoteStoppedFirst
        self.localPathAvoided = localPathAvoided
    }

    public func jsonObject() -> [String: Any] {
        var obj: [String: Any] = [
            "protocol": protocolName,
            "reason": reason,
            "generation": generation,
            "at": at,
        ]
        if !candidateKeyHash.isEmpty { obj["candidate_key_hash"] = candidateKeyHash }
        if !leaseHash.isEmpty { obj["lease_hash"] = leaseHash }
        if let value = upstreamBytes { obj["upstream_bytes"] = value }
        if let value = remoteReadBytes { obj["remote_read_bytes"] = value }
        if !remoteReadReason.isEmpty { obj["remote_read_reason"] = remoteReadReason }
        if let value = remoteStoppedFirst { obj["remote_stopped_first"] = value }
        if let value = localPathAvoided { obj["local_path_avoided"] = value }
        return obj
    }
}

/// Builds the heartbeat's `ultra_status` list from the Ultra runtime status documents
/// (`*.status.json` in the runtime directory), shaped, bounded and redacted like the OpenWrt
/// router (`tools/leikwand/ultra_status.go`):
///
/// * the newest document per forward wins; stopped runtimes and documents not rewritten for
///   `maxAgeSeconds` (a runtime that is gone or wedged) are left out;
/// * at most `maxEntries` entries and `maxEvents` HA events each, newest first;
/// * the encoded list stays under `maxBytes`, well inside the panel's 64 KiB, which drops an
///   oversized list as a whole; event history is the first thing an entry gives up;
/// * every string is trimmed to printable ASCII and bounded, hashes must be 64 lowercase hex.
///
/// Usage rows are delta-based (`UltraUsageLedger`); status is a liveness signal and is sent on
/// every heartbeat, as an empty list when no runtime runs.
public enum UltraStatusCollector {
    /// UltraClientStatusService::MAX_STATUSES.
    public static let maxEntries = 32
    /// normalizeHAEvents keeps the last 8.
    public static let maxEvents = 8
    /// Keeps the encoded list well inside the panel's 64 KiB MAX_STATUS_BYTES.
    public static let maxBytes = 48 * 1024
    /// Same as the desktop and the router: older documents describe a dead runtime.
    public static let maxAgeSeconds: TimeInterval = 5 * 60
    static let maxStatusFileBytes = 64 * 1024

    /// A parsed status document and when its file was last written.
    public struct Document {
        public let root: [String: Any]
        public let modifiedAt: Date?

        public init(root: [String: Any], modifiedAt: Date?) {
            self.root = root
            self.modifiedAt = modifiedAt
        }
    }

    /// Reads every status document in `statusDirectory`; never throws, an unreadable file is skipped.
    public static func collect(statusDirectory: URL, now: Date = Date()) -> [UltraStatusReport] {
        let manager = FileManager.default
        guard let names = try? manager.contentsOfDirectory(atPath: statusDirectory.path) else { return [] }
        var documents: [Document] = []
        for name in names.sorted() where name.hasSuffix(UltraUsageLedger.statusSuffix) {
            let url = statusDirectory.appendingPathComponent(name)
            var isDirectory: ObjCBool = false
            guard manager.fileExists(atPath: url.path, isDirectory: &isDirectory), !isDirectory.boolValue,
                  let data = manager.contents(atPath: url.path),
                  !data.isEmpty, data.count <= maxStatusFileBytes,
                  let root = JSONLenient.parseObject(data)
            else { continue }
            let modifiedAt = (try? manager.attributesOfItem(atPath: url.path))?[.modificationDate] as? Date
            documents.append(Document(root: root, modifiedAt: modifiedAt))
        }
        return build(documents: documents, now: now)
    }

    public static func build(documents: [Document], now: Date) -> [UltraStatusReport] {
        let oldest = now.addingTimeInterval(-maxAgeSeconds)
        var latest: [Int64: (report: UltraStatusReport, observedAt: Date?)] = [:]
        for document in documents {
            let root = document.root
            let forwardId = number(root["forward_id"])
            if forwardId <= 0 { continue }
            let rawState = string(root["state"]).trimmingCharacters(in: .whitespacesAndNewlines).lowercased()
            let rawCode = string(root["error_code"]).trimmingCharacters(in: .whitespacesAndNewlines)
            if rawState == "stopped" || rawCode == "runtime_stopped" { continue }
            let observedAt = ISOInstant.parse(string(root["updated_at"])) ?? document.modifiedAt
            if let observedAt = observedAt, observedAt < oldest { continue }
            if let previous = latest[forwardId], !isAfter(observedAt, previous.observedAt) { continue }
            latest[forwardId] = (report: report(forwardId: forwardId, root: root), observedAt: observedAt)
        }

        let ordered = latest.values.sorted { left, right in
            let byTime = compare(left.observedAt, right.observedAt)
            if byTime != 0 { return byTime > 0 }
            return left.report.forwardId < right.report.forwardId
        }
        var reports: [UltraStatusReport] = []
        var budget = maxBytes
        for item in ordered {
            if reports.count >= maxEntries { break }
            var report = item.report
            var size = encodedSize(report)
            if size > budget {
                // Keep the health verdict and counters; the event history is the bulk.
                report.haEvents = []
                size = encodedSize(report)
            }
            if size > budget { break }
            budget -= size
            reports.append(report)
        }
        return reports
    }

    // MARK: Shaping

    private static func report(forwardId: Int64, root: [String: Any]) -> UltraStatusReport {
        var state = string(root["state"]).trimmingCharacters(in: .whitespacesAndNewlines).lowercased()
        switch state {
        case "":
            state = "ready"
        case "ready", "degraded", "error", "stopped":
            break
        default:
            state = "error"
        }
        let zone = string(root["detected_ingress_zone"]).trimmingCharacters(in: .whitespacesAndNewlines).lowercased()
        let transport = string(root["udp_transport"])
        return UltraStatusReport(
            forwardId: forwardId,
            state: state,
            udpTransport: ["disabled", "native_udp", "external_uot"].contains(transport) ? transport : "",
            detectedIngressZone: isZone(zone) ? zone : "unknown",
            latestTCP: selection(root["latest_tcp"]),
            activeUDP: selection(root["active_udp"]),
            haFailures: number(root["ha_failures"]),
            udpQueueDrops: number(root["udp_queue_drops"]),
            udpSetupFailures: number(root["udp_setup_failures"]),
            bytesIn: number(root["bytes_in"]),
            bytesOut: number(root["bytes_out"]),
            haEvents: events(root["ha_events"]),
            updatedAt: text(string(root["updated_at"]), limit: 40),
            errorCode: text(string(root["error_code"]), limit: 64)
        )
    }

    private static func selection(_ value: Any?) -> UltraSelectionReport? {
        guard let selection = value as? [String: Any] else { return nil }
        let reference = string(selection["selection_ref"]).trimmingCharacters(in: .whitespacesAndNewlines)
        if !isSelectionRef(reference) { return nil }
        return UltraSelectionReport(
            selectionRef: reference,
            rttMs: number(selection["rtt_ms"]),
            selectedAt: text(string(selection["selected_at"]), limit: 40),
            candidateKeyHash: hash(selection["candidate_key_hash"]),
            p95RttMs: number(selection["p95_rtt_ms"]),
            jitterMs: number(selection["jitter_ms"]),
            lossPercent: number(selection["loss_percent"]),
            probeSent: number(selection["probe_sent"]),
            probeReceived: number(selection["probe_received"])
        )
    }

    /// The newest events the panel would keep, restricted to the fields it accepts.
    private static func events(_ value: Any?) -> [UltraHAEventReport] {
        guard let array = value as? [Any] else { return [] }
        var result: [UltraHAEventReport] = []
        for item in array.suffix(maxEvents) {
            guard let event = item as? [String: Any] else { continue }
            let protocolName = string(event["protocol"]).trimmingCharacters(in: .whitespacesAndNewlines).lowercased()
            let reason = text(string(event["reason"]), limit: 64).lowercased()
            if (protocolName != "tcp" && protocolName != "udp") || reason.isEmpty { continue }
            let readReason = string(event["remote_read_reason"])
            result.append(UltraHAEventReport(
                protocolName: protocolName,
                reason: reason,
                generation: text(string(event["generation"]), limit: 64),
                at: text(string(event["at"]), limit: 40),
                candidateKeyHash: hash(event["candidate_key_hash"]),
                leaseHash: hash(event["lease_hash"]),
                upstreamBytes: optionalNumber(event["upstream_bytes"]),
                remoteReadBytes: optionalNumber(event["remote_read_bytes"]),
                remoteReadReason: isCode(readReason) ? readReason : "",
                remoteStoppedFirst: boolean(event["remote_stopped_first"]),
                localPathAvoided: boolean(event["local_path_avoided"])
            ))
        }
        return result
    }

    // MARK: Bounded readers

    /// Trims and bounds a runtime string to printable ASCII, so nothing can smuggle control bytes.
    static func text(_ value: String, limit: Int) -> String {
        var scalars = String.UnicodeScalarView()
        for scalar in value.trimmingCharacters(in: .whitespacesAndNewlines).unicodeScalars {
            if scalars.count >= limit { break }
            if scalar.value > 0x20 && scalar.value < 0x7f { scalars.append(scalar) }
        }
        return String(scalars)
    }

    private static func string(_ value: Any?) -> String {
        return value as? String ?? ""
    }

    /// JSON number (not a boolean) clamped at zero; anything else is absent.
    private static func optionalNumber(_ value: Any?) -> Int64? {
        guard let number = value as? NSNumber, !JSONLenient.isBoolean(number) else { return nil }
        guard let wide = JSONLenient.strictInt64(number) else { return nil }
        return max(wide, 0)
    }

    private static func number(_ value: Any?) -> Int64 {
        return optionalNumber(value) ?? 0
    }

    private static func boolean(_ value: Any?) -> Bool? {
        guard JSONLenient.isBoolean(value), let number = value as? NSNumber else { return nil }
        return number.boolValue
    }

    private static func hash(_ value: Any?) -> String {
        let candidate = string(value).trimmingCharacters(in: .whitespacesAndNewlines).lowercased()
        return Hashing.isLowerHex64(candidate) ? candidate : ""
    }

    private static func isZone(_ value: String) -> Bool {
        return matches(value, lengths: 1...32) { byte in
            (byte >= 97 && byte <= 122) || (byte >= 48 && byte <= 57) || byte == 45
        }
    }

    private static func isSelectionRef(_ value: String) -> Bool {
        return matches(value, lengths: 16...128) { byte in
            (byte >= 97 && byte <= 122) || (byte >= 65 && byte <= 90) || (byte >= 48 && byte <= 57)
                || byte == 45 || byte == 95
        }
    }

    private static func isCode(_ value: String) -> Bool {
        return matches(value, lengths: 1...64) { byte in
            (byte >= 97 && byte <= 122) || (byte >= 48 && byte <= 57) || byte == 95
        }
    }

    private static func matches(_ value: String, lengths: ClosedRange<Int>, allowed: (UInt8) -> Bool) -> Bool {
        let bytes = value.utf8
        guard lengths.contains(bytes.count) else { return false }
        return bytes.allSatisfy(allowed)
    }

    private static func isAfter(_ left: Date?, _ right: Date?) -> Bool {
        return compare(left, right) > 0
    }

    /// Nil (unknown time) sorts as the oldest.
    private static func compare(_ left: Date?, _ right: Date?) -> Int {
        switch (left, right) {
        case (nil, nil): return 0
        case (nil, _): return -1
        case (_, nil): return 1
        case let (left?, right?):
            if left == right { return 0 }
            return left > right ? 1 : -1
        }
    }

    private static func encodedSize(_ report: UltraStatusReport) -> Int {
        guard let data = try? CanonicalJSON.data(report.jsonObject()) else { return maxBytes + 1 }
        return data.count + 1 // the separating comma
    }
}
