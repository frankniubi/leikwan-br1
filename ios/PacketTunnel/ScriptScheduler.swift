import Foundation
import LeikwanIOSCore

/// Runs the plan's module scripts while the tunnel is up: `cron` scripts on their schedule,
/// `network-changed` scripts when the default network changes, and any script on request
/// (a panel tap in the app). Scripts run one at a time, and not at all while the extension
/// is close to its memory limit.
final class ScriptScheduler {
    /// A JavaScript context costs a few MB; above this footprint a run is refused rather
    /// than risked (jetsam kills the extension near 50 MB, `MemoryGuard` sheds at 44).
    static let memoryCeiling: UInt64 = 38 * 1024 * 1024
    /// How long a changed network is given to settle before event scripts use it.
    static let networkSettleSeconds: UInt64 = 3

    private let executor = ScriptExecutor(
        log: { TunnelLog.shared.write($0) },
        admission: {
            guard let footprint = MemoryGuard.physFootprint(), footprint > ScriptScheduler.memoryCeiling else { return nil }
            return "memory_low"
        }
    )
    private let lock = NSLock()
    private var scripts: [ScriptLaunch] = []
    private var cronLoop: Task<Void, Never>?
    private var eventPending = false
    private let wakeup = LoopWakeup()

    /// Replaces the scripts; the cron loop restarts only when they changed.
    func update(_ next: [ScriptLaunch]) {
        guard let previousLoop = replace(next) else { return }
        previousLoop.loop?.cancel()
        let cron = next.filter { $0.kind == "cron" }
        guard !cron.isEmpty else { return }
        TunnelLog.shared.write("scripts: \(next.count) loaded, \(cron.count) on a schedule")
        let loop = Task.detached { [weak self] in
            guard let self else { return }
            await self.runCron(cron)
        }
        setLoop(loop)
    }

    func stop() {
        update([])
    }

    /// The device woke: a schedule that came due during sleep runs now.
    func deviceWoke() {
        wakeup.fire()
    }

    func networkChanged() {
        guard claimEvent() else { return }
        Task.detached { [weak self] in
            try? await Task.sleep(nanoseconds: ScriptScheduler.networkSettleSeconds * NSEC_PER_SEC)
            guard let self else { return }
            for script in self.releaseEvent() {
                _ = await self.executor.run(script, trigger: "event", eventName: SurgeModule.networkChangedEvent)
            }
        }
    }

    /// Runs one script now; nil when the plan has no script with that key.
    func run(key: String, trigger: String) async -> ScriptOutcome? {
        guard let script = snapshot().first(where: { $0.key == key }) else { return nil }
        return await executor.run(script, trigger: trigger)
    }

    // MARK: Cron

    private func runCron(_ cron: [ScriptLaunch]) async {
        var due: [String: Date] = [:]
        var reference = Date()
        while !Task.isCancelled {
            for script in cron where due[script.key] == nil {
                due[script.key] = script.cronExpression.flatMap { CronExpression($0) }?.nextDate(after: reference)
            }
            guard let earliest = due.values.min() else { return }
            let wait = earliest.timeIntervalSinceNow
            if wait > 0 {
                await wakeup.sleep(seconds: wait)
            }
            if Task.isCancelled { return }
            let now = Date()
            for script in cron {
                guard let date = due[script.key], date <= now else { continue }
                // A schedule missed several times (device asleep) runs once.
                due[script.key] = nil
                _ = await executor.run(script, trigger: "cron")
                if Task.isCancelled { return }
            }
            reference = Date()
        }
    }

    // MARK: State (lock held only inside these)

    private struct PreviousLoop {
        var loop: Task<Void, Never>?
    }

    /// nil when `next` equals the current scripts.
    private func replace(_ next: [ScriptLaunch]) -> PreviousLoop? {
        lock.lock()
        defer { lock.unlock() }
        guard scripts != next else { return nil }
        scripts = next
        let previous = PreviousLoop(loop: cronLoop)
        cronLoop = nil
        return previous
    }

    private func setLoop(_ loop: Task<Void, Never>) {
        lock.lock()
        cronLoop = loop
        lock.unlock()
    }

    private func snapshot() -> [ScriptLaunch] {
        lock.lock()
        defer { lock.unlock() }
        return scripts
    }

    /// True when event scripts exist and no run is already waiting for the network to settle.
    private func claimEvent() -> Bool {
        lock.lock()
        defer { lock.unlock() }
        guard !eventPending, scripts.contains(where: { $0.kind == "event" }) else { return false }
        eventPending = true
        return true
    }

    private func releaseEvent() -> [ScriptLaunch] {
        lock.lock()
        defer { lock.unlock() }
        eventPending = false
        return scripts.filter { $0.kind == "event" && $0.eventName == SurgeModule.networkChangedEvent }
    }
}
