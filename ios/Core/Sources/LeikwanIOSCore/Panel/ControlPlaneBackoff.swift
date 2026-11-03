import Foundation

/// Exponential backoff with full jitter (port of `session/ControlPlaneBackoff.kt`).
/// The n-th consecutive failure waits uniformly in `[0, min(cap, base * 2^n)]`: 5, 10, 20, 40, then 60 s ceilings.
public struct ControlPlaneBackoff {
    public let baseMs: Int64
    public let capMs: Int64
    /// Returns a uniformly random value in `[0, bound]` (inclusive).
    private let randomUpTo: (Int64) -> Int64
    public private(set) var consecutiveFailures: Int = 0

    public init(baseMs: Int64 = 5_000, capMs: Int64 = 60_000, randomUpTo: @escaping (Int64) -> Int64 = ControlPlaneBackoff.systemRandom) {
        precondition(baseMs > 0, "backoff_base_invalid")
        precondition(capMs >= baseMs, "backoff_cap_invalid")
        self.baseMs = baseMs
        self.capMs = capMs
        self.randomUpTo = randomUpTo
    }

    public static func systemRandom(_ bound: Int64) -> Int64 {
        if bound <= 0 { return 0 }
        return Int64.random(in: 0...bound)
    }

    /// Ceiling for the given zero-based attempt, without jitter.
    public func ceilingMs(attempt: Int) -> Int64 {
        var ceiling = baseMs
        var remaining = max(attempt, 0)
        while remaining > 0 {
            if ceiling >= capMs { return capMs }
            ceiling = ceiling > capMs / 2 ? capMs : ceiling * 2
            remaining -= 1
        }
        return min(ceiling, capMs)
    }

    /// Records one failure and returns how long to wait before the next attempt.
    public mutating func nextDelayMs() -> Int64 {
        let attempt = consecutiveFailures
        if consecutiveFailures < Int.max { consecutiveFailures += 1 }
        let ceiling = ceilingMs(attempt: attempt)
        let value = randomUpTo(ceiling)
        return min(max(value, 0), ceiling)
    }

    public mutating func reset() {
        consecutiveFailures = 0
    }
}

public enum ControlPlaneState: String, Equatable {
    case ready
    case reconnecting
    case stopped
}

/// Result of one control poll (heartbeat and/or configs pull), as seen by the NE.
public enum ControlPollOutcome: Equatable {
    case success
    case transientFailure
    case deviceLimitReached(limit: Int?, active: Int?)
    case terminal(code: String, command: DeviceCommand?)

    /// Classifies a thrown error the way `ClientControlLoop` does.
    public static func from(error: Error) -> ControlPollOutcome {
        if let panel = error as? PanelError {
            switch panel {
            case .deviceLimitReached(let limit, let active): return .deviceLimitReached(limit: limit, active: active)
            case .terminalControl(let code, let command): return .terminal(code: code, command: command)
            default: return .transientFailure
            }
        }
        return .transientFailure
    }
}

public enum DeviceLimitChange: Equatable {
    case reached(limit: Int?, active: Int?)
    case cleared
}

public struct ControlPlaneDecision: Equatable {
    public let state: ControlPlaneState
    /// Delay before the next poll; nil when the loop must stop (terminal control).
    public let delayMs: Int64?
    /// True for the healthy interval: a "poll soon" wake may cut it short. Retry delays are never shortened.
    public let wakeable: Bool
    /// Non-nil when the device-limit banner must change.
    public let deviceLimitChange: DeviceLimitChange?
    /// Terminal control to act on (stop data plane, sign out).
    public let terminalCode: String?
}

/// Pure port of the `ClientControlLoop` scheduling policy: healthy poll 30 s, transient failures use
/// `ControlPlaneBackoff`, device-limit refusals retry every 60 s, terminal controls stop.
public struct ControlPlanePolicy {
    public let healthyIntervalMs: Int64
    public let deviceLimitRetryMs: Int64
    public private(set) var backoff: ControlPlaneBackoff
    public private(set) var state: ControlPlaneState = .stopped
    public private(set) var deviceLimited: Bool = false

    public init(
        healthyIntervalMs: Int64 = 30_000,
        deviceLimitRetryMs: Int64 = 60_000,
        backoff: ControlPlaneBackoff = ControlPlaneBackoff()
    ) {
        self.healthyIntervalMs = healthyIntervalMs
        self.deviceLimitRetryMs = deviceLimitRetryMs
        self.backoff = backoff
    }

    /// Call when the loop starts: resets backoff and enters `reconnecting` (poll immediately).
    public mutating func start() {
        backoff.reset()
        deviceLimited = false
        state = .reconnecting
    }

    public mutating func stop() {
        state = .stopped
    }

    public mutating func record(_ outcome: ControlPollOutcome) -> ControlPlaneDecision {
        switch outcome {
        case .success:
            backoff.reset()
            var change: DeviceLimitChange?
            if deviceLimited {
                deviceLimited = false
                change = .cleared
            }
            state = .ready
            return ControlPlaneDecision(state: .ready, delayMs: healthyIntervalMs, wakeable: true, deviceLimitChange: change, terminalCode: nil)
        case .terminal(let code, _):
            state = .stopped
            return ControlPlaneDecision(state: .stopped, delayMs: nil, wakeable: false, deviceLimitChange: nil, terminalCode: code)
        case .deviceLimitReached(let limit, let active):
            deviceLimited = true
            backoff.reset()
            state = .reconnecting
            return ControlPlaneDecision(
                state: .reconnecting,
                delayMs: deviceLimitRetryMs,
                wakeable: false,
                deviceLimitChange: .reached(limit: limit, active: active),
                terminalCode: nil
            )
        case .transientFailure:
            state = .reconnecting
            let delay = backoff.nextDelayMs()
            return ControlPlaneDecision(state: .reconnecting, delayMs: delay, wakeable: false, deviceLimitChange: nil, terminalCode: nil)
        }
    }

    /// `requestPollSoon` is honoured only while `ready`.
    public var acceptsPollSoon: Bool { return state == .ready }
}
