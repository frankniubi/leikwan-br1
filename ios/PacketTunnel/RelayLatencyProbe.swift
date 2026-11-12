import Foundation
import LeikwanIOSCore
import Network

/// In-tunnel latency for Ultra connections, where the sing-box `probe` group is left out
/// (its "proxy" is the local runtime bridge, so a URL test would only time loopback).
/// Times a TCP handshake to the relay entry in use from the extension itself, whose own
/// sockets go out the physical interface. A refused connection (a UDP-only relay port)
/// still answers after one round trip, so it counts as a sample too; a timeout does not.
final class RelayLatencyProbe {
    private let queue = DispatchQueue(label: "leikwan.relay-latency", qos: .utility)
    private var timer: DispatchSourceTimer?
    private let endpoints: () -> [Int64: String]
    private let lock = NSLock()
    private var latest: Int64 = 0
    private var inFlight: NWConnection?

    /// `endpoints` returns forward id → "ip:port" of the relay entry each Ultra forward uses.
    init(endpoints: @escaping () -> [Int64: String]) {
        self.endpoints = endpoints
    }

    /// Last measured round trip in ms; 0 = none yet (or no Ultra relay in use).
    var latencyMs: Int64 {
        lock.lock()
        defer { lock.unlock() }
        return latest
    }

    func start() {
        queue.async { [self] in
            guard timer == nil else { return }
            let timer = DispatchSource.makeTimerSource(queue: queue)
            timer.schedule(deadline: .now() + 3, repeating: 30, leeway: .seconds(2))
            timer.setEventHandler { [weak self] in self?.measure() }
            timer.resume()
            self.timer = timer
        }
    }

    func stop() {
        queue.async { [self] in
            timer?.cancel()
            timer = nil
            inFlight?.cancel()
            inFlight = nil
        }
    }

    private func measure() {
        guard inFlight == nil else { return }
        guard let address = RelayEndpointAddress.preferred(endpoints()),
              let port = NWEndpoint.Port(rawValue: address.port)
        else {
            record(0)
            return
        }
        let parameters = NWParameters.tcp
        if let tcp = parameters.defaultProtocolStack.transportProtocol as? NWProtocolTCP.Options {
            tcp.connectionTimeout = 3
            tcp.noDelay = true
        }
        let connection = NWConnection(host: NWEndpoint.Host(address.host), port: port, using: parameters)
        let started = DispatchTime.now()
        var finished = false
        func finish(_ sample: Int64?) {
            guard !finished else { return }
            finished = true
            if let sample { record(max(1, sample)) }
            connection.cancel()
            inFlight = nil
        }
        connection.stateUpdateHandler = { state in
            let elapsed = Int64((DispatchTime.now().uptimeNanoseconds - started.uptimeNanoseconds) / 1_000_000)
            switch state {
            case .ready:
                finish(elapsed)
            case .failed(let error), .waiting(let error):
                if case .posix(let code) = error, code == .ECONNREFUSED {
                    finish(elapsed)
                } else {
                    finish(nil)
                }
            default:
                break
            }
        }
        inFlight = connection
        connection.start(queue: queue)
        queue.asyncAfter(deadline: .now() + 4) { finish(nil) }
    }

    private func record(_ value: Int64) {
        lock.lock()
        latest = value
        lock.unlock()
    }
}
