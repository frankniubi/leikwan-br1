import Foundation
import LeikwanIOSCore

/// Keeps the device online on the panel while the tunnel runs, like Android's
/// ClientControlLoop: heartbeat every 30 s when healthy, jittered backoff on
/// transient failures, 60 s retries while the device limit is reached, and a
/// hard stop when the panel kicks, bans or distrusts the device.
final class TunnelControlLoop {
    private let client: PanelClient
    private let onTerminal: (String) -> Void
    private var policy = ControlPlanePolicy()
    private var task: Task<Void, Never>?
    private let lock = NSLock()
    private var currentAlert: String?
    /// Ultra usage rides the heartbeat; unacknowledged rows stay pending and are resent.
    private let usageLedger: UltraUsageLedger?
    private let wakeup = LoopWakeup()

    init(client: PanelClient, usageLedger: UltraUsageLedger? = nil, onTerminal: @escaping (String) -> Void) {
        self.usageLedger = usageLedger
        self.client = client
        self.onTerminal = onTerminal
    }

    /// Problem the app should show (device limit, revocation), if any.
    var alert: String? {
        lock.lock()
        defer { lock.unlock() }
        return currentAlert
    }

    func start() {
        guard task == nil else { return }
        policy.start()
        task = Task.detached { [weak self] in
            await self?.run()
        }
    }

    func stop() {
        task?.cancel()
        task = nil
        policy.stop()
    }

    /// Heartbeat now instead of at the end of the current wait (device wake, network change).
    func nudge() {
        wakeup.fire()
    }

    private func run() async {
        while !Task.isCancelled {
            let outcome: ControlPollOutcome
            do {
                let usage = usageLedger?.collect() ?? []
                let response = try await client.heartbeat(
                    HeartbeatRequest(osRelease: Self.osRelease, ultraUsage: usage.isEmpty ? nil : usage)
                )
                if !usage.isEmpty {
                    usageLedger?.acknowledge(usage, acknowledged: response.usageAcknowledged)
                }
                outcome = .success
            } catch {
                outcome = ControlPollOutcome.from(error: error)
                if case .transientFailure = outcome {
                    TunnelLog.shared.write("heartbeat failed: \(error)", level: 2)
                }
            }
            if Task.isCancelled { return }
            let decision = policy.record(outcome)
            if let change = decision.deviceLimitChange {
                switch change {
                case let .reached(limit, active):
                    let detail = limit.map { "\(active ?? $0)/\($0)" } ?? ""
                    setAlert("device_limit_reached \(detail)".trimmingCharacters(in: .whitespaces))
                case .cleared:
                    setAlert(nil)
                }
            }
            if let code = decision.terminalCode {
                setAlert(code)
                onTerminal(code)
                return
            }
            guard let delayMs = decision.delayMs else { return }
            await wakeup.sleep(seconds: Double(max(delayMs, 1_000)) / 1_000)
        }
    }

    private func setAlert(_ value: String?) {
        lock.lock()
        currentAlert = value
        lock.unlock()
    }

    static var osRelease: String {
        let version = ProcessInfo.processInfo.operatingSystemVersion
        return "iOS \(version.majorVersion).\(version.minorVersion).\(version.patchVersion)"
    }
}

/// A loop's wait that `fire()` can end early. Task.sleep alone cannot be shortened, and its
/// clock does not advance while the device sleeps, so after a wake a renewal that is already
/// overdue would otherwise wait out the rest of the interval.
final class LoopWakeup {
    private let lock = NSLock()
    private var sleepers: [UUID: CheckedContinuation<Void, Never>] = [:]
    private var cancelled = Set<UUID>()
    /// A fire() while nobody was waiting: the next wait returns at once.
    private var pending = false

    func sleep(seconds: Double) async {
        let id = UUID()
        await withTaskCancellationHandler {
            await withCheckedContinuation { (continuation: CheckedContinuation<Void, Never>) in
                lock.lock()
                if pending || cancelled.remove(id) != nil {
                    pending = false
                    lock.unlock()
                    continuation.resume()
                    return
                }
                sleepers[id] = continuation
                lock.unlock()
                // Wall clock: a deadline that passed while the device slept fires on wake.
                DispatchQueue.global().asyncAfter(wallDeadline: .now() + max(seconds, 0)) {
                    self.resume(id)
                }
            }
        } onCancel: {
            lock.lock()
            let continuation = sleepers.removeValue(forKey: id)
            if continuation == nil { cancelled.insert(id) }
            lock.unlock()
            continuation?.resume()
        }
        lock.lock()
        cancelled.remove(id)
        lock.unlock()
    }

    func fire() {
        lock.lock()
        let waiting = Array(sleepers.values)
        sleepers.removeAll()
        if waiting.isEmpty { pending = true }
        lock.unlock()
        for continuation in waiting { continuation.resume() }
    }

    private func resume(_ id: UUID) {
        lock.lock()
        let continuation = sleepers.removeValue(forKey: id)
        lock.unlock()
        continuation?.resume()
    }
}
