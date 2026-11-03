import Darwin
import Foundation

/// Where an Ultra runtime's first connection lands, and how long a host may spend waiting for it.
///
/// The runtime binds its TCP/UDP listeners only after it holds a policy
/// (`runRuntimeLoop`: `WaitValid` -> `serveTCP`), and a freshly delivered candidate batch is
/// probed before the control socket answers. A host that starts its data plane as soon as the
/// runtime reports "started" therefore routes into a closed port for as long as the first lease
/// takes (up to the probe window). Android gates this with `TunnelOrchestrator`: resolve and
/// deliver the first lease, then `awaitLocalPort` (15 s), and only then start sing-box. iOS uses
/// the same gate with a budget that fits the Network Extension start window.
public enum UltraLocalListener {
    /// True when something accepts a TCP connection on 127.0.0.1:<port>. The probe connection is
    /// closed immediately; the runtime treats it as one short stream, like Android's
    /// `awaitLocalPort` probe.
    public static func canConnect(port: Int, timeoutMilliseconds: Int = 250) -> Bool {
        guard port > 0, port <= 65535 else { return false }
        let descriptor = Darwin.socket(AF_INET, SOCK_STREAM, 0)
        if descriptor < 0 { return false }
        defer { _ = Darwin.close(descriptor) }

        let flags = fcntl(descriptor, F_GETFL, 0)
        if flags < 0 || fcntl(descriptor, F_SETFL, flags | O_NONBLOCK) < 0 { return false }

        var address = sockaddr_in()
        address.sin_len = UInt8(MemoryLayout<sockaddr_in>.size)
        address.sin_family = sa_family_t(AF_INET)
        address.sin_port = in_port_t(UInt16(port).bigEndian)
        address.sin_addr.s_addr = inet_addr("127.0.0.1")

        let connected = withUnsafePointer(to: &address) { pointer -> Int32 in
            pointer.withMemoryRebound(to: sockaddr.self, capacity: 1) { socketAddress in
                Darwin.connect(descriptor, socketAddress, socklen_t(MemoryLayout<sockaddr_in>.size))
            }
        }
        if connected == 0 { return true }
        if errno != EINPROGRESS { return false }

        var entry = pollfd(fd: descriptor, events: Int16(POLLOUT), revents: 0)
        var ready: Int32 = 0
        while true {
            ready = Darwin.poll(&entry, 1, Int32(timeoutMilliseconds))
            if ready >= 0 || errno != EINTR { break }
        }
        if ready <= 0 { return false }
        var socketError: Int32 = 0
        var length = socklen_t(MemoryLayout<Int32>.size)
        guard getsockopt(descriptor, SOL_SOCKET, SO_ERROR, &socketError, &length) == 0 else { return false }
        return socketError == 0
    }
}

/// Outcome of a bounded readiness wait (reported in the tunnel log).
public enum UltraReadinessOutcome: Equatable {
    case ready(milliseconds: Int)
    case timedOut
    case aborted
}

/// Polls a listener until it accepts connections, the budget runs out, or the runtime that owns it
/// stops (a dead runtime will never bind, so waiting the whole budget would only delay the tunnel).
public enum UltraRuntimeReadiness {
    public static let pollIntervalSeconds: TimeInterval = 0.2

    public static func waitForListener(
        budgetSeconds: TimeInterval,
        probe: () -> Bool,
        shouldAbort: () -> Bool = { false },
        sleep: (TimeInterval) -> Void = { Thread.sleep(forTimeInterval: $0) },
        now: () -> Date = Date.init
    ) -> UltraReadinessOutcome {
        let started = now()
        while true {
            if probe() {
                return .ready(milliseconds: Int(now().timeIntervalSince(started) * 1_000))
            }
            if shouldAbort() { return .aborted }
            // Never sleep past the budget: the caller is a packet-tunnel start path.
            if now().timeIntervalSince(started) >= budgetSeconds { return .timedOut }
            sleep(pollIntervalSeconds)
        }
    }

    /// The same loop for async callers: suspends between polls instead of blocking a thread.
    public static func waitForListenerAsync(
        budgetSeconds: TimeInterval,
        probe: () -> Bool,
        shouldAbort: () -> Bool = { false }
    ) async -> UltraReadinessOutcome {
        let started = Date()
        while true {
            if probe() {
                return .ready(milliseconds: Int(Date().timeIntervalSince(started) * 1_000))
            }
            if shouldAbort() { return .aborted }
            if Date().timeIntervalSince(started) >= budgetSeconds { return .timedOut }
            try? await Task.sleep(nanoseconds: UInt64(pollIntervalSeconds * 1_000_000_000))
        }
    }
}
