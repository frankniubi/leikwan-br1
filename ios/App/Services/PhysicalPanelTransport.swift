import Foundation
import LeikwanIOSCore
import Libbox
import Network
import Security

/// HTTPS and its hostname resolution use Network.framework's selected physical interface.
/// System TLS trust/hostname checking stays enabled. No URLSession/default-route fallback.
final class PhysicalPanelTransport: PanelHTTPTransport {
    private let interface: NWInterface
    private let egress: LocalPortEgress
    private let queue = DispatchQueue(label: "bridge.local-ports.https")
    private let lock = NSLock()
    private var operations: [UUID: PhysicalHTTPRequest] = [:]
    private var invalidated = false

    init(interface: NWInterface, egress: LocalPortEgress) { self.interface = interface; self.egress = egress }

    func invalidate() {
        lock.lock()
        invalidated = true
        let pending = Array(operations.values)
        lock.unlock()
        for operation in pending { operation.cancel() }
    }

    func send(_ request: URLRequest) async throws -> (Data, HTTPURLResponse) {
        lock.lock(); let unavailable = invalidated; lock.unlock()
        guard !unavailable else { throw URLError(.notConnectedToInternet) }
        try Task.checkCancellation()
        guard let hostname = request.url?.host else { throw URLError(.badURL) }
        let config = try CanonicalJSON.string([
            "direct_egress_required": true, "egress_interface": egress.interfaceName,
            "egress_interface_index": egress.interfaceIndex, "egress_dns_servers": egress.dnsServers
        ])
        let addresses: [String] = try await Task.detached(priority: .userInitiated) {
            var error: NSError?
            let json = (LkmobileResolvePhysicalJSON(config, hostname, &error) as String?) ?? ""
            if let error { throw error }
            guard let data = json.data(using: .utf8), let addresses = try? JSONDecoder().decode([String].self, from: data), !addresses.isEmpty else {
                throw URLError(.cannotFindHost)
            }
            return addresses
        }.value
        try Task.checkCancellation()
        let id = UUID()
        // Resolve before connecting, but keep TLS verification against the ORIGINAL hostname.
        let operation = PhysicalHTTPRequest(request: request, address: addresses[0], interface: interface, queue: queue)
        lock.lock()
        guard !invalidated else { lock.unlock(); throw URLError(.notConnectedToInternet) }
        operations[id] = operation
        lock.unlock()
        defer { lock.lock(); operations.removeValue(forKey: id); lock.unlock() }
        return try await withTaskCancellationHandler {
            try await operation.run()
        } onCancel: { operation.cancel() }
    }
}

private final class PhysicalHTTPRequest {
    private let request: URLRequest
    private let address: String
    private let interface: NWInterface
    private let queue: DispatchQueue
    private var connection: NWConnection?
    private var continuation: CheckedContinuation<(Data, HTTPURLResponse), Error>?
    private var bytes = Data()
    private var finished = false
    private var cancelled = false

    init(request: URLRequest, address: String, interface: NWInterface, queue: DispatchQueue) {
        self.request = request; self.address = address; self.interface = interface; self.queue = queue
    }

    func cancel() {
        queue.async { self.cancelled = true; self.finish(.failure(CancellationError())) }
    }

    func run() async throws -> (Data, HTTPURLResponse) {
        try await withCheckedThrowingContinuation { continuation in
            queue.async {
                self.continuation = continuation
                if self.cancelled { self.finish(.failure(CancellationError())); return }
                do { try self.start() } catch { self.finish(.failure(error)) }
            }
        }
    }

    private func start() throws {
        guard let url = request.url, url.scheme == "https", let host = url.host,
              let rawPort = UInt16(exactly: url.port ?? 443),
              let port = NWEndpoint.Port(rawValue: rawPort) else { throw URLError(.unsupportedURL) }
        let tls = NWProtocolTLS.Options()
        sec_protocol_options_set_tls_server_name(tls.securityProtocolOptions, host)
        sec_protocol_options_add_tls_application_protocol(tls.securityProtocolOptions, "http/1.1")
        // The endpoint is a scoped-DNS literal, so explicitly retain hostname policy as
        // well as SNI. SecTrust uses the system anchors; no accept-all/custom CA bypass.
        sec_protocol_options_set_verify_block(tls.securityProtocolOptions, { _, trust, complete in
            let systemTrust = sec_trust_copy_ref(trust).takeRetainedValue()
            SecTrustSetPolicies(systemTrust, SecPolicyCreateSSL(true, host as CFString))
            // Do not let trust evaluation fetch AIA/OCSP over an unscoped system route.
            // Incomplete chains fail normally; never accept untrusted certificates.
            SecTrustSetNetworkFetchAllowed(systemTrust, false)
            complete(SecTrustEvaluateWithError(systemTrust, nil))
        }, queue)
        let parameters = NWParameters(tls: tls, tcp: NWProtocolTCP.Options())
        parameters.requiredInterface = interface
        parameters.prohibitedInterfaceTypes = [.other, .loopback]
        let connection = NWConnection(host: NWEndpoint.Host(address), port: port, using: parameters)
        self.connection = connection
        connection.stateUpdateHandler = { [weak self] state in
            guard let self else { return }
            switch state {
            case .ready:
                do {
                    let data = try self.wireRequest(url: url, host: host)
                    connection.send(content: data, completion: .contentProcessed { error in
                        if let error { self.finish(.failure(error)) } else { self.receive(url: url) }
                    })
                } catch { self.finish(.failure(error)) }
            case let .failed(error): self.finish(.failure(error))
            case let .waiting(error): self.finish(.failure(error))
            case .cancelled: self.finish(.failure(URLError(.cancelled)))
            default: break
            }
        }
        connection.start(queue: queue)
        queue.asyncAfter(deadline: .now() + 20) { [weak self] in self?.finish(.failure(URLError(.timedOut))) }
    }

    private func wireRequest(url: URL, host: String) throws -> Data {
        guard let parts = URLComponents(url: url, resolvingAgainstBaseURL: false) else { throw URLError(.badURL) }
        let target = (parts.percentEncodedPath.isEmpty ? "/" : parts.percentEncodedPath) + (parts.percentEncodedQuery.map { "?" + $0 } ?? "")
        let hostHeader = (host.contains(":") ? "[\(host)]" : host) + (url.port.map { ":\($0)" } ?? "")
        var text = "\(request.httpMethod ?? "GET") \(target) HTTP/1.1\r\nHost: \(hostHeader)\r\nConnection: close\r\nAccept-Encoding: identity\r\n"
        for (key, value) in request.allHTTPHeaderFields ?? [:] {
            if ["host", "connection", "content-length", "accept-encoding"].contains(key.lowercased()) { continue }
            guard !key.contains("\r"), !key.contains("\n"), !value.contains("\r"), !value.contains("\n") else { throw URLError(.badURL) }
            text += "\(key): \(value)\r\n"
        }
        let body = request.httpBody ?? Data()
        text += "Content-Length: \(body.count)\r\n\r\n"
        var data = Data(text.utf8); data.append(body)
        return data
    }

    private func receive(url: URL) {
        connection?.receive(minimumIncompleteLength: 1, maximumLength: 64 * 1024) { [weak self] data, _, complete, error in
            guard let self, !self.finished else { return }
            if let data { self.bytes.append(data) }
            if self.bytes.count > PortHTTPResponse.maximumBytes { self.finish(.failure(URLError(.dataLengthExceedsMaximum))); return }
            if let error { self.finish(.failure(error)); return }
            if complete || PortHTTPResponse.isComplete(self.bytes) {
                do { self.finish(.success(try PortHTTPResponse.decode(self.bytes, url: url))) }
                catch { self.finish(.failure(error)) }
            } else { self.receive(url: url) }
        }
    }

    private func finish(_ result: Result<(Data, HTTPURLResponse), Error>) {
        // Cancellation can arrive before run() installs its continuation.
        guard !finished, let continuation else { return }
        finished = true; self.continuation = nil
        connection?.stateUpdateHandler = nil
        connection?.cancel(); connection = nil
        continuation.resume(with: result)
    }
}
