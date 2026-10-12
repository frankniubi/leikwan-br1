import Darwin
import Foundation
import LeikwanIOSCore

/// One framed request/reply on the Ultra runtime's control socket, matching Android
/// `AndroidUltraControlSocket`: the caller supplies the 4-byte big-endian length-prefixed frame,
/// the reply is one frame of at most 64 KiB and only its payload is returned.
enum UnixControlSocket {
    static let maxPayload = 64 * 1024

    enum Failure: Error {
        /// connect() failed: the runtime is not listening (yet). Nothing was written.
        case notReady(Int32)
        case io(String)
    }

    static func exchange(path: String, frame: Data, timeoutMs: Int) throws -> Data {
        let fd = Darwin.socket(AF_UNIX, SOCK_STREAM, 0)
        if fd < 0 { throw Failure.notReady(errno) }
        defer { _ = Darwin.close(fd) }

        var noSigPipe: Int32 = 1
        _ = setsockopt(fd, SOL_SOCKET, SO_NOSIGPIPE, &noSigPipe, socklen_t(MemoryLayout<Int32>.size))

        var address = sockaddr_un()
        address.sun_family = sa_family_t(AF_UNIX)
        let pathBytes = Array(path.utf8)
        let capacity = MemoryLayout.size(ofValue: address.sun_path)
        if pathBytes.isEmpty || pathBytes.count >= capacity {
            throw Failure.io("ultra_control_path_invalid")
        }
        withUnsafeMutableBytes(of: &address.sun_path) { raw in
            for index in 0..<pathBytes.count {
                raw[index] = pathBytes[index]
            }
            raw[pathBytes.count] = 0
        }
        let addressLength = socklen_t(MemoryLayout<sockaddr_un>.size)
        address.sun_len = UInt8(truncatingIfNeeded: MemoryLayout<sockaddr_un>.size)
        let connected = withUnsafePointer(to: &address) { pointer -> Int32 in
            pointer.withMemoryRebound(to: sockaddr.self, capacity: 1) { socketAddress in
                Darwin.connect(fd, socketAddress, addressLength)
            }
        }
        if connected != 0 { throw Failure.notReady(errno) }

        let clamped = min(max(timeoutMs, 1_000), 70_000)
        var timeout = timeval(tv_sec: clamped / 1_000, tv_usec: Int32((clamped % 1_000) * 1_000))
        _ = setsockopt(fd, SOL_SOCKET, SO_RCVTIMEO, &timeout, socklen_t(MemoryLayout<timeval>.size))
        _ = setsockopt(fd, SOL_SOCKET, SO_SNDTIMEO, &timeout, socklen_t(MemoryLayout<timeval>.size))

        try writeAll(fd, frame)
        var header = try readExactly(fd, count: 4)
        defer { header.resetBytes(in: 0..<header.count) }
        let length = (Int(header[0]) << 24) | (Int(header[1]) << 16) | (Int(header[2]) << 8) | Int(header[3])
        if length < 1 || length > maxPayload {
            throw Failure.io("ultra_control_reply_invalid")
        }
        return try readExactly(fd, count: length)
    }

    private static func writeAll(_ fd: Int32, _ data: Data) throws {
        let bytes = [UInt8](data)
        var offset = 0
        while offset < bytes.count {
            let written = bytes.withUnsafeBufferPointer { buffer -> Int in
                Darwin.write(fd, buffer.baseAddress! + offset, bytes.count - offset)
            }
            if written < 0 {
                if errno == EINTR { continue }
                throw Failure.io("ultra_control_write_failed")
            }
            if written == 0 { throw Failure.io("ultra_control_write_failed") }
            offset += written
        }
    }

    private static func readExactly(_ fd: Int32, count: Int) throws -> Data {
        var buffer = [UInt8](repeating: 0, count: count)
        var offset = 0
        while offset < count {
            let received = buffer.withUnsafeMutableBufferPointer { pointer -> Int in
                Darwin.read(fd, pointer.baseAddress! + offset, count - offset)
            }
            if received < 0 {
                if errno == EINTR { continue }
                throw Failure.io("ultra_control_reply_incomplete")
            }
            if received == 0 { throw Failure.io("ultra_control_reply_incomplete") }
            offset += received
        }
        let data = Data(buffer)
        for index in buffer.indices { buffer[index] = 0 }
        return data
    }
}

/// Delivers lease batches to one runtime with Android `UltraLeaseClient.sendPayload` semantics:
/// connect-stage failures (runtime not listening yet) are retried on their own 5 s readiness
/// budget (25 ms doubling to 400 ms) without using an attempt; any other failure uses an attempt
/// and is retried after 50 ms. Blocking: call it off the cooperative thread pool.
enum UltraControlDelivery {
    static let singleTimeoutMs = 2_000
    /// Above the runtime's selection window (30 s probe + association budget + grace).
    static let batchTimeoutMs = 70_000
    static let singleAttempts = 20
    static let batchAttempts = 3
    static let readinessBudgetMs: Double = 5_000

    /// Wipes the leases (best effort) whether the runtime accepts them or not.
    static func deliver(
        socketPath: String,
        policy: UltraPolicy,
        leases: [UltraLease],
        slowSelection: Bool
    ) -> Bool {
        var owned = leases
        defer {
            for index in owned.indices { owned[index].wipe() }
        }
        guard !socketPath.isEmpty, !owned.isEmpty, owned.count <= UltraConstants.maxLeaseCandidates else { return false }
        for lease in owned {
            if lease.forwardId != policy.forwardId || lease.policyGeneration != policy.policyGeneration
                || lease.transport != policy.transport || lease.user.isEmpty || lease.password.isEmpty {
                return false
            }
        }
        // try? flattens the optional result (Swift 5): nil on throw or an out-of-bounds payload.
        guard let built = try? UltraRuntimeConfigBuilder.controlFrame(leases: owned) else { return false }
        var frame = built
        defer { frame.resetBytes(in: 0..<frame.count) }
        // A multi-candidate batch, or any delivery to a runtime that probes before it answers.
        let slow = owned.count > 1 || slowSelection
        return send(
            socketPath: socketPath,
            frame: frame,
            forwardId: policy.forwardId,
            policyGeneration: policy.policyGeneration,
            timeoutMs: slow ? batchTimeoutMs : singleTimeoutMs,
            attempts: slow ? batchAttempts : singleAttempts
        )
    }

    /// Shared by ordinary and native deliveries (NativeOutboundLeaseManager).
    static func send(
        socketPath: String,
        frame: Data,
        forwardId: Int64,
        policyGeneration: String,
        timeoutMs: Int,
        attempts: Int
    ) -> Bool {
        let started = Date()
        var readyBackoffMs: Double = 25
        var failures = 0
        var waitedForReady = false
        while true {
            var reply: Data?
            do {
                reply = try UnixControlSocket.exchange(path: socketPath, frame: frame, timeoutMs: timeoutMs)
            } catch UnixControlSocket.Failure.notReady(_) {
                let remaining = readinessBudgetMs - Date().timeIntervalSince(started) * 1_000
                if remaining <= 0 { return false }
                waitedForReady = true
                Thread.sleep(forTimeInterval: min(readyBackoffMs, remaining) / 1_000)
                readyBackoffMs = min(readyBackoffMs * 2, 400)
                continue
            } catch {
                reply = nil
            }
            if var answer = reply {
                if waitedForReady {
                    let waited = Int(Date().timeIntervalSince(started) * 1_000)
                    TunnelLog.shared.write("ultra control socket ready after \(waited)ms")
                }
                let accepted = !answer.isEmpty && answer.count <= UnixControlSocket.maxPayload
                    && UltraRuntimeConfigBuilder.controlReplyAccepted(answer, forwardId: forwardId, policyGeneration: policyGeneration)
                if !accepted, let object = JSONLenient.parseObject(answer), let error = JSONLenient.string(object["error"]) {
                    TunnelLog.shared.write("ultra runtime refused lease for forward \(forwardId): \(error)", level: 2)
                }
                answer.resetBytes(in: 0..<answer.count)
                return accepted
            }
            failures += 1
            if failures >= max(1, min(attempts, singleAttempts)) { return false }
            Thread.sleep(forTimeInterval: 0.05)
        }
    }
}
