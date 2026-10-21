import Foundation
import os.log

/// In-memory ring of recent tunnel log lines (served to the app on request)
/// mirrored to os_log.
final class TunnelLog {
    static let shared = TunnelLog()

    private let logger = Logger(subsystem: "org.forfoxsakesantuary.foxbridgeinternal.tunnel", category: "tunnel")
    private let queue = DispatchQueue(label: "org.forfoxsakesantuary.foxbridgeinternal.tunnel.log")
    private var lines: [String] = []
    private let capacity = 600
    private let formatter: DateFormatter = {
        let formatter = DateFormatter()
        formatter.dateFormat = "HH:mm:ss"
        formatter.locale = Locale(identifier: "en_US_POSIX")
        return formatter
    }()

    func write(_ message: String, level: Int = 3) {
        let line = "\(formatter.string(from: Date())) \(Self.label(level)) \(message)"
        logger.log("\(line, privacy: .public)")
        queue.async {
            self.lines.append(line)
            if self.lines.count > self.capacity {
                self.lines.removeFirst(self.lines.count - self.capacity)
            }
        }
    }

    func snapshot() -> [String] {
        queue.sync { lines }
    }

    /// Levels follow the Rust log crate: 1 error, 2 warn, 3 info, 4 debug, 5 trace.
    private static func label(_ level: Int) -> String {
        switch level {
        case ...1: return "E"
        case 2: return "W"
        case 3: return "I"
        case 4: return "D"
        default: return "T"
        }
    }
}
