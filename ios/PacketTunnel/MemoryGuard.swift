import Darwin
import Foundation
import LeikwanIOSCore

/// Keeps the extension under the ~50 MB jetsam line (see `MemoryGuardPolicy`): samples
/// `phys_footprint` every few seconds, listens for the OS memory-pressure signal, and asks
/// sing-box to close all connections when the policy says shed.
final class MemoryGuard {
    private let queue = DispatchQueue(label: "leikwan.memory-guard", qos: .utility)
    private var timer: DispatchSourceTimer?
    private var pressure: DispatchSourceMemoryPressure?
    private var policy = MemoryGuardPolicy()
    private let shed: () -> Void

    /// `shed` runs on the guard's queue.
    init(shed: @escaping () -> Void) {
        self.shed = shed
    }

    func start() {
        queue.async { [self] in
            guard timer == nil else { return }
            let pressure = DispatchSource.makeMemoryPressureSource(eventMask: [.warning, .critical], queue: queue)
            pressure.setEventHandler { [weak self] in
                guard let self else { return }
                let critical = pressure.data.contains(.critical)
                self.apply(self.policy.pressureEvent(critical: critical, now: Date()), reason: critical ? "memory pressure critical" : "memory pressure warning")
            }
            pressure.resume()
            self.pressure = pressure

            let timer = DispatchSource.makeTimerSource(queue: queue)
            timer.schedule(deadline: .now() + 5, repeating: 5, leeway: .seconds(1))
            timer.setEventHandler { [weak self] in
                guard let self, let footprint = Self.physFootprint() else { return }
                self.apply(self.policy.evaluate(footprint: footprint, now: Date()), reason: "footprint \(footprint / (1024 * 1024)) MB")
            }
            timer.resume()
            self.timer = timer
        }
    }

    func stop() {
        queue.async { [self] in
            timer?.cancel()
            timer = nil
            pressure?.cancel()
            pressure = nil
        }
    }

    private func apply(_ action: MemoryGuardAction, reason: String) {
        switch action {
        case .none:
            return
        case .warn:
            TunnelLog.shared.write("memory high (\(reason))", level: 2)
        case .shed:
            TunnelLog.shared.write("memory shed: closing connections (\(reason))", level: 2)
            shed()
        }
    }

    /// The number jetsam judges the extension by (what Xcode's memory gauge shows).
    static func physFootprint() -> UInt64? {
        var info = task_vm_info_data_t()
        var count = mach_msg_type_number_t(MemoryLayout<task_vm_info_data_t>.size / MemoryLayout<natural_t>.size)
        let result = withUnsafeMutablePointer(to: &info) { pointer in
            pointer.withMemoryRebound(to: integer_t.self, capacity: Int(count)) {
                task_info(mach_task_self_, task_flavor_t(TASK_VM_INFO), $0, &count)
            }
        }
        return result == KERN_SUCCESS ? info.phys_footprint : nil
    }
}
