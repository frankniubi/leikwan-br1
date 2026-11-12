import Foundation

/// What the packet tunnel does about its memory footprint. iOS kills a Network Extension
/// near 50 MB of `phys_footprint` (jetsam), which tears the whole tunnel down; a speed test
/// or a page with many parallel downloads gets there through buffered-but-unread
/// connection data. Closing the open connections frees those buffers at the cost of the
/// in-flight transfers only — far cheaper than the tunnel dying and reconnecting.
///
/// Thresholds follow Surge's NE (logs at ~40 MB, critical at 48 MB), measured on
/// `phys_footprint`, not the Go heap libbox reports.
public enum MemoryGuardAction: Equatable {
    case none
    /// Footprint is high: log it (once per crossing).
    case warn
    /// Shed load: close all connections (rate-limited).
    case shed
}

public struct MemoryGuardPolicy {
    public static let warnBytes: UInt64 = 36 * 1024 * 1024
    public static let shedBytes: UInt64 = 44 * 1024 * 1024
    /// Minimum spacing between two sheds, so a footprint that stays high (fragmentation the
    /// OS has not reclaimed yet) does not cut every new connection.
    public static let shedCooldown: TimeInterval = 20

    private var lastShedAt: Date?
    private var warned = false

    public init() {}

    /// Decision for one footprint sample.
    public mutating func evaluate(footprint: UInt64, now: Date) -> MemoryGuardAction {
        if footprint >= Self.shedBytes {
            return shedIfAllowed(now: now)
        }
        if footprint >= Self.warnBytes {
            if warned { return .none }
            warned = true
            return .warn
        }
        warned = false
        return .none
    }

    /// Decision for an OS memory-pressure event (warning or critical): shed regardless of
    /// the sampled footprint, still rate-limited.
    public mutating func pressureEvent(critical: Bool, now: Date) -> MemoryGuardAction {
        if shedIfAllowed(now: now) == .shed { return .shed }
        // Inside the cooldown: a warning is still worth a log line, a repeated critical is not.
        return critical ? .none : .warn
    }

    private mutating func shedIfAllowed(now: Date) -> MemoryGuardAction {
        if let last = lastShedAt, now.timeIntervalSince(last) < Self.shedCooldown {
            return .none
        }
        lastShedAt = now
        return .shed
    }
}
