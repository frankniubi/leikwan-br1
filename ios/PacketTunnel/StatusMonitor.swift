import Foundation
import Libbox

/// Subscribes to the tunnel's own libbox command server so the app can poll
/// traffic and connection counts with a provider message instead of linking
/// libbox itself.
final class StatusMonitor {
    private var client: LibboxCommandClient?
    private let lock = NSLock()
    private var latest = LibboxStatusSnapshot()

    func start() {
        let options = LibboxCommandClientOptions()
        options.addCommand(LibboxCommandStatus)
        options.addCommand(LibboxCommandLog)
        options.statusInterval = Int64(NSEC_PER_SEC)
        guard let client = LibboxNewCommandClient(Handler(self), options) else {
            TunnelLog.shared.write("status monitor unavailable", level: 2)
            return
        }
        do {
            try client.connect()
            self.client = client
        } catch {
            TunnelLog.shared.write("status monitor connect failed: \(error.localizedDescription)", level: 2)
        }
    }

    func stop() {
        try? client?.disconnect()
        client = nil
    }

    func snapshot() -> LibboxStatusSnapshot {
        lock.lock()
        defer { lock.unlock() }
        return latest
    }

    fileprivate func update(_ message: LibboxStatusMessage) {
        lock.lock()
        latest = LibboxStatusSnapshot(
            uplink: message.uplink,
            downlink: message.downlink,
            uplinkTotal: message.uplinkTotal,
            downlinkTotal: message.downlinkTotal,
            connectionsIn: message.connectionsIn,
            connectionsOut: message.connectionsOut,
            memory: message.memory
        )
        lock.unlock()
    }

    private final class Handler: NSObject, LibboxCommandClientHandlerProtocol {
        private weak var monitor: StatusMonitor?

        init(_ monitor: StatusMonitor) {
            self.monitor = monitor
        }

        func connected() {}

        func disconnected(_ message: String?) {
            if let message, !message.isEmpty {
                TunnelLog.shared.write("status monitor disconnected: \(message)", level: 4)
            }
        }

        func setDefaultLogLevel(_: Int32) {}

        func clearLogs() {}

        func writeLogs(_ messageList: (any LibboxLogIteratorProtocol)?) {
            guard let messageList else { return }
            while messageList.hasNext() {
                guard let entry = messageList.next() else { break }
                // libbox levels: 0 panic .. 6 trace; map onto the shared scale.
                let level = max(1, min(5, Int(entry.level) - 1))
                TunnelLog.shared.write("[sing-box] \(entry.message)", level: level)
            }
        }

        func writeStatus(_ message: LibboxStatusMessage?) {
            guard let message else { return }
            monitor?.update(message)
        }

        func writeGroups(_: LibboxOutboundGroupIteratorProtocol?) {}

        func initializeClashMode(_: LibboxStringIteratorProtocol?, currentMode _: String?) {}

        func updateClashMode(_: String?) {}

        func write(_: LibboxConnectionEvents?) {}
    }
}

struct LibboxStatusSnapshot {
    var uplink: Int64 = 0
    var downlink: Int64 = 0
    var uplinkTotal: Int64 = 0
    var downlinkTotal: Int64 = 0
    var connectionsIn: Int32 = 0
    var connectionsOut: Int32 = 0
    var memory: Int64 = 0
}
