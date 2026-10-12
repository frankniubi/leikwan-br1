import Foundation
import LeikwanRuntime
import Libbox

/// Hosts the forward runtimes in the tunnel process. Android runs each of
/// these as a child process; here ingress/bond run on Rust threads
/// (LeikwanRuntime) and ultra on the Go runtime shared with libbox.
final class RuntimeHost {
    private enum Handle {
        case native(Int64)
        case ultra(LkmobileUltraRuntime)
    }

    private struct Running {
        let launch: RuntimeLaunch
        let handle: Handle
    }

    private let lock = NSLock()
    private var running: [Running] = []
    private static var logSinkInstalled = false

    static func installLogSink() {
        guard !logSinkInstalled else { return }
        logSinkInstalled = true
        _ = lk_runtime_set_log({ level, message, _ in
            guard let message else { return }
            TunnelLog.shared.write(String(cString: message), level: Int(level))
        }, nil, 3)
    }

    /// Starts runtimes in order; on failure everything already started is
    /// stopped again and the error names the failing runtime.
    func start(_ launches: [RuntimeLaunch]) throws {
        for launch in launches {
            do {
                let handle = try Self.start(launch)
                lock.lock()
                running.append(Running(launch: launch, handle: handle))
                lock.unlock()
                TunnelLog.shared.write("runtime \(launch.name) started")
            } catch {
                stopAll()
                throw error
            }
        }
    }

    func stopAll() {
        lock.lock()
        let stopping = running.reversed()
        running.removeAll()
        lock.unlock()
        for item in stopping {
            switch item.handle {
            case let .native(handle):
                _ = lk_runtime_stop(handle)
            case let .ultra(runtime):
                runtime.stop()
            }
            TunnelLog.shared.write("runtime \(item.launch.name) stopped")
        }
    }

    /// Stops the runtime with this name (if running); used to restart one runtime in place.
    func stop(named name: String) {
        lock.lock()
        let matching = running.filter { $0.launch.name == name }
        running.removeAll { $0.launch.name == name }
        lock.unlock()
        for item in matching {
            switch item.handle {
            case let .native(handle):
                _ = lk_runtime_stop(handle)
            case let .ultra(runtime):
                runtime.stop()
            }
            TunnelLog.shared.write("runtime \(item.launch.name) stopped")
        }
    }

    func statuses() -> [RuntimeStatus] {
        lock.lock()
        let items = running
        lock.unlock()
        return items.map { item in
            switch item.handle {
            case let .native(handle):
                let state = lk_runtime_state(handle)
                let error = state == LK_STATE_FAILED ? Self.lastError(handle) : nil
                return RuntimeStatus(name: item.launch.name, kind: item.launch.kind, running: state == LK_STATE_RUNNING, error: error)
            case let .ultra(runtime):
                let error = runtime.lastError()
                return RuntimeStatus(
                    name: item.launch.name,
                    kind: item.launch.kind,
                    running: runtime.running(),
                    error: error.isEmpty ? nil : error
                )
            }
        }
    }

    private static func start(_ launch: RuntimeLaunch) throws -> Handle {
        for file in launch.files {
            let url = URL(fileURLWithPath: file.path)
            try FileManager.default.createDirectory(at: url.deletingLastPathComponent(), withIntermediateDirectories: true)
            try Data(file.contents.utf8).write(to: url, options: .atomic)
        }
        switch launch.kind {
        case .ingress, .bond:
            return .native(try startNative(kind: launch.kind.rawValue, arguments: launch.arguments, name: launch.name))
        case .ultra:
            guard let config = launch.configJSON else {
                throw RuntimeHostError("runtime \(launch.name): missing ultra configuration")
            }
            var error: NSError?
            let runtime = LkmobileStartUltra(config, &error)
            if let error {
                throw RuntimeHostError("runtime \(launch.name): \(error.localizedDescription)")
            }
            guard let runtime else {
                throw RuntimeHostError("runtime \(launch.name): ultra did not start")
            }
            return .ultra(runtime)
        }
    }

    private static func startNative(kind: String, arguments: [String], name: String) throws -> Int64 {
        let cArguments = arguments.map { strdup($0) }
        defer { cArguments.forEach { free($0) } }
        let pointers: [UnsafePointer<CChar>?] = cArguments.map { UnsafePointer($0) }
        var errorBuffer = [CChar](repeating: 0, count: 1024)
        let errorCapacity = errorBuffer.count
        let handle = pointers.withUnsafeBufferPointer { argv in
            errorBuffer.withUnsafeMutableBufferPointer { errorPointer in
                lk_runtime_start(kind, argv.baseAddress, arguments.count, errorPointer.baseAddress, errorCapacity)
            }
        }
        if handle <= 0 {
            let message = String(cString: errorBuffer)
            throw RuntimeHostError("runtime \(name): \(message.isEmpty ? "failed to start" : message)")
        }
        return handle
    }

    private static func lastError(_ handle: Int64) -> String {
        var buffer = [CChar](repeating: 0, count: 1024)
        let capacity = buffer.count
        _ = buffer.withUnsafeMutableBufferPointer { pointer in
            lk_runtime_last_error(handle, pointer.baseAddress, capacity)
        }
        return String(cString: buffer)
    }
}

struct RuntimeHostError: LocalizedError {
    let message: String

    init(_ message: String) {
        self.message = message
    }

    var errorDescription: String? { message }
}
