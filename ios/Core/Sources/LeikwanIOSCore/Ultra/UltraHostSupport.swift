import Foundation

/// Policy state the Go runtime reports in its status file (`policy_state`), port of
/// Android `UltraPolicyState`. AWAITING, LAPSED and REVOKED are idle on purpose: the runtime
/// keeps its listeners and fails connections closed until a host injects a (new) lease.
public enum UltraPolicyState: String, Equatable {
    case active
    case awaiting
    case lapsed
    case revoked
    case unknown = ""

    public static func fromWire(_ value: String?) -> UltraPolicyState {
        let normalized = (value ?? "").trimmingCharacters(in: .whitespacesAndNewlines).lowercased()
        if normalized.isEmpty { return .unknown }
        return UltraPolicyState(rawValue: normalized) ?? .unknown
    }

    /// Older runtimes only report `error_code` / `last_error`.
    public static func fromErrorCode(_ value: String?) -> UltraPolicyState {
        switch (value ?? "").trimmingCharacters(in: .whitespacesAndNewlines).lowercased() {
        case "awaiting_policy": return .awaiting
        case "policy_lapsed": return .lapsed
        case "policy_revoked": return .revoked
        default: return .unknown
        }
    }
}

/// The subset of the runtime status file a host needs (port of Android `UltraRuntimeStatus`).
public struct UltraRuntimeStatus: Equatable {
    public let policyState: UltraPolicyState
    public let errorCode: String
    public let policyGeneration: String
    public let updatedAt: Date?

    public init(policyState: UltraPolicyState, errorCode: String, policyGeneration: String, updatedAt: Date?) {
        self.policyState = policyState
        self.errorCode = errorCode
        self.policyGeneration = policyGeneration
        self.updatedAt = updatedAt
    }

    public var isIdleByDesign: Bool {
        return policyState == .awaiting || policyState == .lapsed || policyState == .revoked
    }

    /// A lease injection would fix it. REVOKED is excluded on purpose.
    public var needsLease: Bool {
        return policyState == .awaiting || policyState == .lapsed
    }

    public static func read(path: String) -> UltraRuntimeStatus? {
        if path.isEmpty { return nil }
        guard let data = FileManager.default.contents(atPath: path) else { return nil }
        return parse(data)
    }

    public static func parse(_ data: Data) -> UltraRuntimeStatus? {
        guard let root = JSONLenient.parseObject(data) else { return nil }
        let errorCode = JSONLenient.requiredString(root["error_code"]) ?? JSONLenient.requiredString(root["last_error"]) ?? ""
        let reported = UltraPolicyState.fromWire(JSONLenient.requiredString(root["policy_state"]))
        let state = reported != .unknown ? reported : UltraPolicyState.fromErrorCode(errorCode)
        return UltraRuntimeStatus(
            policyState: state,
            errorCode: errorCode,
            policyGeneration: JSONLenient.requiredString(root["policy_generation"]) ?? "",
            updatedAt: JSONLenient.requiredString(root["updated_at"]).flatMap { ISOInstant.parse($0) }
        )
    }
}

extension UltraRuntimePaths {
    /// Darwin limits a Unix socket path to 104 bytes (including the NUL), and the app group
    /// container alone is ~84, so iOS uses one-letter names: `<dir>/u<id>.sock` etc.
    /// Status files keep the `.status.json` suffix the usage ledger scans for.
    public static func compact(directory: String, forwardId: Int64) -> UltraRuntimePaths {
        let base = directory.hasSuffix("/") ? String(directory.dropLast()) : directory
        let prefix = base + "/u\(forwardId)"
        return UltraRuntimePaths(
            configPath: prefix + ".json",
            controlSocketPath: prefix + ".sock",
            statusPath: prefix + ".status.json",
            locationCachePath: prefix + ".loc.json",
            locationLockPath: prefix + ".loc.lock"
        )
    }

    /// Longest Unix socket path Darwin accepts (sun_path is 104 bytes including the NUL).
    public static let maxSocketPathBytes = 103
}

/// Lease renewal timing shared by every host (Android `TunnelOrchestrator.refreshUltraIfDue`).
public enum UltraLeaseSchedule {
    /// Renew this long before the panel's `renew_after`.
    public static let renewMarginSeconds: TimeInterval = 30
    /// How often a host re-checks a healthy lease (Android control loop tick).
    public static let checkIntervalSeconds: TimeInterval = 30

    public static func renewalTime(_ policy: UltraPolicy) -> Date {
        return policy.renewAfter.addingTimeInterval(-renewMarginSeconds)
    }

    public static func renewalDue(_ policy: UltraPolicy, now: Date = Date()) -> Bool {
        return now >= renewalTime(policy)
    }

    /// Seconds until the next check of a healthy lease: the next tick or the renewal point,
    /// whichever comes first, never less than one second.
    public static func nextCheckDelay(_ policy: UltraPolicy, now: Date = Date()) -> TimeInterval {
        let untilDue = renewalTime(policy).timeIntervalSince(now)
        return max(1, min(checkIntervalSeconds, untilDue))
    }

    /// True when the runtime reports it holds no policy in a status written after our last
    /// accepted lease (the status writer is throttled, so an older `awaiting` does not count).
    public static func runtimeLostPolicy(_ status: UltraRuntimeStatus?, leaseSentAt: Date) -> Bool {
        guard let status = status, status.needsLease, let updatedAt = status.updatedAt else { return false }
        let sentSeconds = leaseSentAt.timeIntervalSince1970.rounded(.down)
        return updatedAt.timeIntervalSince1970 > sentSeconds
    }
}
