import Foundation

/// Memory-only sidecar bootstrap. Only the fingerprint may enter applied-state metadata.
public struct ExternalUotGrant: Equatable, CustomStringConvertible {
    private let canonical: String
    public var description: String { "ExternalUotGrant([REDACTED])" }
    public var fingerprint: String { Hashing.sha256Hex(canonical) }
    public func bootstrapJSON() -> [String: Any] { JSONLenient.parseObject(canonical) ?? [:] }

    public static func from(_ raw: [String: Any]) throws -> ExternalUotGrant {
        guard JSONLenient.isBoolean(raw["enabled"]), raw["enabled"] as? Bool == true,
              let target = JSONLenient.requiredString(raw["sidecar_target"]),
              let token = JSONLenient.requiredString(raw["token"]),
              target.utf8.count <= 512, token.utf8.count <= 8192,
              !token.contains(where: { $0.isWhitespace }),
              let colon = target.lastIndex(of: ":"),
              let port = Int(target[target.index(after: colon)...]), (1...65535).contains(port)
        else { throw UltraLeaseError("external_uot_invalid") }
        let host = String(target[..<colon])
        let bracketed = host.hasPrefix("[") && host.hasSuffix("]") && host.count > 2
        let ordinary = !host.isEmpty && !host.contains(":") && !host.contains("[") && !host.contains("]")
        guard (bracketed || ordinary), !host.contains(where: { $0.isWhitespace }) else {
            throw UltraLeaseError("external_uot_invalid")
        }
        let mode = JSONLenient.requiredString(raw["delivery_mode"]) ?? "raw"
        guard mode == "raw" || mode == "reliable_datagram_v1" else {
            throw UltraLeaseError("external_uot_delivery_mode_invalid")
        }
        var payload: [String: Any] = ["enabled": true, "sidecar_target": target, "token": token]
        for (key, range) in [
            ("queue_packets", 1...4096), ("keepalive_seconds", 5...30),
            ("reconnect_grace_seconds", 5...300), ("frame_payload_max", 256...65507)
        ] {
            let value = JSONLenient.strictInt64(raw[key]) ?? 0
            payload[key] = range.contains(Int(clamping: value)) ? value : 0
        }
        if mode != "raw" { payload["delivery_mode"] = mode }
        return ExternalUotGrant(canonical: try CanonicalJSON.string(payload))
    }
}
