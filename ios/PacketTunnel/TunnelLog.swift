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
    /// Batches file appends: coalesced lines are written once per flush window instead of one
    /// open/seek/write/close per line (a busy second used to hit the file dozens of times).
    private static let flushInterval: TimeInterval = 2.0
    private static let maxFileBytes = 256 * 1024
    private var pending: [String] = []
    private var flushScheduled = false
    private var fileHandle: FileHandle?

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
            self.pending.append(line)
            self.scheduleFlush()
        }
    }

    private func scheduleFlush() {
        guard !flushScheduled else { return }
        flushScheduled = true
        queue.asyncAfter(deadline: .now() + Self.flushInterval) { [weak self] in
            self?.flush()
        }
    }

    private func flush() {
        flushScheduled = false
        guard !pending.isEmpty else { return }
        let data = Data(pending.joined(separator: "\n").appending("\n").utf8)
        pending.removeAll()
        Self.append(data, handle: &fileHandle)
    }

    /// Appends to the shared log file so the app can show what happened even when the
    /// tunnel process died during start (the in-memory ring dies with the process).
    private static func append(_ data: Data, handle: inout FileHandle?) {
        let url = AppGroup.tunnelLogURL
        if handle == nil {
            if let size = try? FileManager.default.attributesOfItem(atPath: url.path)[.size] as? Int, size > maxFileBytes {
                try? FileManager.default.removeItem(at: url)
            }
            if let existing = try? FileHandle(forWritingTo: url) {
                _ = try? existing.seekToEnd()
                handle = existing
            } else {
                try? data.write(to: url, options: [.atomic])
                handle = try? FileHandle(forWritingTo: url)
                _ = try? handle?.seekToEnd()
                return
            }
        }
        guard handle != nil else { return }
        _ = try? handle?.write(contentsOf: data)
        // Rotate only when past the ceiling (checked per batch, not per line).
        if let size = try? handle?.seekToEnd(), size > maxFileBytes {
            try? handle?.close()
            handle = nil
            try? FileManager.default.removeItem(at: url)
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
