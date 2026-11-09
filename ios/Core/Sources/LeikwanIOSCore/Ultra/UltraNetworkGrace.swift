import Foundation

public enum UltraNetworkGrace {
    public static let seconds: TimeInterval = 600
    public static func deadline(issuedExpiry: Date, now: Date = Date()) -> Date? {
        guard now >= issuedExpiry.addingTimeInterval(-60),
              now < issuedExpiry.addingTimeInterval(seconds) else { return nil }
        return issuedExpiry.addingTimeInterval(seconds)
    }
    public static func command(generation: String, until: Date?) throws -> Data {
        var payload: [String: Any] = ["op": until == nil ? "stop_grace" : "extend_grace", "policy_generation": generation]
        if let until = until { payload["grace_until"] = ISO8601DateFormatter().string(from: until) }
        let body = try CanonicalJSON.data(payload)
        var length = UInt32(body.count).bigEndian
        var frame = withUnsafeBytes(of: &length) { Data($0) }
        frame.append(body)
        return frame
    }
}
