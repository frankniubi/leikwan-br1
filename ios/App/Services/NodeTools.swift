import Foundation
import LeikwanIOSCore
import Network

/// Measures TCP connect time to host:port from the app process.
enum TCPPing {
    static func measure(host: String, port: Int, timeout: TimeInterval = 3) async -> Int? {
        guard !host.isEmpty, let nwPort = NWEndpoint.Port(rawValue: UInt16(clamping: port)), port > 0 else {
            return nil
        }
        let connection = NWConnection(host: NWEndpoint.Host(host), port: nwPort, using: .tcp)
        let started = Date()
        return await withCheckedContinuation { continuation in
            let gate = OnceGate()
            connection.stateUpdateHandler = { state in
                switch state {
                case .ready:
                    if gate.claim() {
                        continuation.resume(returning: Int(Date().timeIntervalSince(started) * 1000))
                        connection.cancel()
                    }
                case .failed, .cancelled:
                    if gate.claim() {
                        continuation.resume(returning: nil)
                    }
                default:
                    break
                }
            }
            connection.start(queue: .global(qos: .utility))
            DispatchQueue.global().asyncAfter(deadline: .now() + timeout) {
                if gate.claim() {
                    continuation.resume(returning: nil)
                    connection.cancel()
                }
            }
        }
    }

    private final class OnceGate {
        private let lock = NSLock()
        private var claimed = false

        func claim() -> Bool {
            lock.lock()
            defer { lock.unlock() }
            if claimed { return false }
            claimed = true
            return true
        }
    }
}
