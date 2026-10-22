import Foundation

/// Injectable HTTP transport. Must NOT throw for HTTP status codes; only for missing answers.
public protocol PanelHTTPTransport {
    func send(_ request: URLRequest) async throws -> (Data, HTTPURLResponse)
}

/// URLSession-backed transport: no cache, no cookies, no system proxy, 10 s request / 20 s resource timeouts
/// (Android: connect 10 s, read 10 s, call 20 s).
public final class URLSessionPanelTransport: PanelHTTPTransport {
    public let session: URLSession

    public init(session: URLSession? = nil) {
        if let session = session {
            self.session = session
        } else {
            let configuration = URLSessionConfiguration.ephemeral
            configuration.requestCachePolicy = .reloadIgnoringLocalCacheData
            configuration.urlCache = nil
            configuration.httpCookieStorage = nil
            configuration.httpShouldSetCookies = false
            configuration.timeoutIntervalForRequest = 10
            configuration.timeoutIntervalForResource = 20
            configuration.waitsForConnectivity = false
            // Panel traffic never follows another app's HTTP proxy (Android Proxy.NO_PROXY).
            configuration.connectionProxyDictionary = [:]
            self.session = URLSession(configuration: configuration)
        }
    }

    public func send(_ request: URLRequest) async throws -> (Data, HTTPURLResponse) {
        let (data, response) = try await session.data(for: request)
        guard let http = response as? HTTPURLResponse else {
            throw URLError(.badServerResponse)
        }
        return (data, http)
    }
}

/// Panel control-plane client for iOS (Client ID + Agent Token login).
/// Thread-safe; after a transport failure the active endpoint flips to the profile's backup
/// address (when it has one and failover is on).
public final class PanelClient {
    public static let configsPullPath = "api/client/configs/pull"
    public static let heartbeatPath = "api/client/heartbeat"
    public static let ultraLeasePath = "api/client/ultra/lease"

    public let credentials: PanelCredentials
    public let allowFailover: Bool
    /// Primary first, then the backup (never a duplicate of the primary).
    public let endpoints: [PanelEndpoint]
    private let transport: PanelHTTPTransport
    private let lock = NSLock()
    private var activeIndex = 0

    public init(
        endpoint: PanelEndpoint,
        fallback: PanelEndpoint? = nil,
        credentials: PanelCredentials,
        transport: PanelHTTPTransport = URLSessionPanelTransport(),
        allowFailover: Bool = true
    ) {
        var endpoints = [endpoint]
        if let fallback = fallback, fallback != endpoint { endpoints.append(fallback) }
        self.endpoints = endpoints
        self.credentials = credentials
        self.transport = transport
        self.allowFailover = allowFailover
    }

    /// Endpoint that answered last (or the primary before any request).
    public var activeEndpoint: PanelEndpoint {
        lock.lock()
        defer { lock.unlock() }
        return endpoints[activeIndex]
    }

    private func currentIndex() -> Int {
        lock.lock()
        defer { lock.unlock() }
        return activeIndex
    }

    private func activate(_ index: Int) {
        lock.lock()
        activeIndex = index
        lock.unlock()
    }

    // MARK: Requests

    public func makeRequest(
        endpoint: PanelEndpoint,
        path: String,
        method: String,
        jsonBody: [String: Any]? = nil,
        extraHeaders: [String: String] = [:]
    ) throws -> URLRequest {
        let base = endpoint.urlString.hasSuffix("/") ? endpoint.urlString : endpoint.urlString + "/"
        guard let url = URL(string: base + path) else {
            throw PanelError.invalidResponse("panel_url_invalid")
        }
        var request = URLRequest(url: url, cachePolicy: .reloadIgnoringLocalCacheData, timeoutInterval: 20)
        request.httpMethod = method
        request.httpShouldHandleCookies = false
        for (name, value) in credentials.headers() {
            request.setValue(value, forHTTPHeaderField: name)
        }
        request.setValue("application/json", forHTTPHeaderField: "Accept")
        for (name, value) in extraHeaders {
            request.setValue(value, forHTTPHeaderField: name)
        }
        if let body = jsonBody {
            request.httpBody = try CanonicalJSON.data(body)
            request.setValue("application/json; charset=UTF-8", forHTTPHeaderField: "Content-Type")
        }
        return request
    }

    /// Sends with transport failover. Returns body + status for 2xx; throws `PanelError` otherwise.
    func perform(path: String, method: String, jsonBody: [String: Any]?, extraHeaders: [String: String]) async throws -> (Data, HTTPURLResponse) {
        let primaryIndex = currentIndex()
        do {
            return try await performOnce(endpoint: endpoints[primaryIndex], path: path, method: method, jsonBody: jsonBody, extraHeaders: extraHeaders)
        } catch let error as PanelError {
            guard error.isTransport, allowFailover, endpoints.count > 1 else { throw error }
            let alternateIndex = (primaryIndex + 1) % endpoints.count
            let result = try await performOnce(endpoint: endpoints[alternateIndex], path: path, method: method, jsonBody: jsonBody, extraHeaders: extraHeaders)
            activate(alternateIndex)
            return result
        }
    }

    private func performOnce(endpoint: PanelEndpoint, path: String, method: String, jsonBody: [String: Any]?, extraHeaders: [String: String]) async throws -> (Data, HTTPURLResponse) {
        let request = try makeRequest(endpoint: endpoint, path: path, method: method, jsonBody: jsonBody, extraHeaders: extraHeaders)
        let result: (Data, HTTPURLResponse)
        do {
            result = try await transport.send(request)
        } catch is CancellationError {
            throw CancellationError()
        } catch let urlError as URLError where urlError.code == .cancelled {
            throw urlError
        } catch let panelError as PanelError {
            throw panelError
        } catch {
            throw PanelError.transport(error)
        }
        let data = result.0
        let response = result.1
        if (200...299).contains(response.statusCode) {
            return (data, response)
        }
        let reason = HTTPURLResponse.localizedString(forStatusCode: response.statusCode)
        throw PanelErrorParser.error(status: response.statusCode, reason: reason, body: data)
    }

    // MARK: Endpoints

    /// `GET api/client/configs/pull` with the Ultra offline-validity header.
    public func pullConfigs() async throws -> PullConfigsResponse {
        let (data, _) = try await perform(
            path: PanelClient.configsPullPath,
            method: "GET",
            jsonBody: nil,
            extraHeaders: [UltraConstants.offlineValidityHeaderName: String(UltraConstants.offlineValiditySeconds)]
        )
        guard let response = PullConfigsResponse.parse(data) else {
            throw PanelError.invalidResponse("Pull configs failed")
        }
        if !response.ok { throw PanelError.invalidResponse("Pull configs failed") }
        return response
    }

    /// Android `pullCatalogOnce`: best-effort heartbeat (only terminal/limit refusals propagate), then pull.
    public func pullCatalog(selectedForwardId: Int64?, heartbeat: HeartbeatRequest?) async throws -> (response: PullConfigsResponse, catalog: IOSForwardCatalog) {
        if let heartbeat = heartbeat {
            do {
                _ = try await self.heartbeat(heartbeat)
            } catch let error as PanelError {
                switch error {
                case .terminalControl, .deviceLimitReached: throw error
                default: break
                }
            } catch is CancellationError {
                throw CancellationError()
            } catch {
                // Capability advertisement is best-effort.
            }
        }
        let response = try await pullConfigs()
        return (response, response.catalog(selectedForwardId: selectedForwardId))
    }

    /// `POST api/client/heartbeat`. Throws on every failure; the caller decides via `ControlPlanePolicy`.
    public func heartbeat(_ request: HeartbeatRequest) async throws -> HeartbeatResponse {
        let (data, _) = try await perform(path: PanelClient.heartbeatPath, method: "POST", jsonBody: request.jsonObject(), extraHeaders: [:])
        guard let response = HeartbeatResponse.parse(data) else {
            throw PanelError.invalidResponse("heartbeat_response_invalid")
        }
        return response
    }

    /// `POST api/client/ultra/lease` for an ordinary Ultra forward; returns validated leases.
    public func resolveUltraLeases(
        policy: UltraPolicy,
        maxCandidates: Int = UltraConstants.maxLeaseCandidates,
        sealer: UltraLeaseSealer = PlaintextLeaseOpener(),
        nonceHex: String = UltraLeaseRequest.randomNonceHex(),
        now: Date = Date()
    ) async throws -> [UltraLease] {
        let request = try UltraLeaseRequest.ordinary(
            policy: policy,
            deviceId: credentials.deviceId,
            maxCandidates: maxCandidates,
            nonceHex: nonceHex
        )
        var headers: [String: String] = [
            UltraConstants.offlineValidityHeaderName: String(UltraConstants.offlineValiditySeconds),
        ]
        if let leaseKey = sealer.leaseKeyHeaderValue() {
            headers[UltraConstants.leaseKeyHeaderName] = leaseKey
        }
        let (data, response) = try await perform(path: PanelClient.ultraLeasePath, method: "POST", jsonBody: request.jsonObject(), extraHeaders: headers)
        guard let body = UltraLeaseResponse.parse(data) else {
            throw PanelError.http(status: response.statusCode, message: PanelErrorParser.messageFromHttp(status: response.statusCode, reason: nil, body: data))
        }
        if !body.ok {
            let message = body.error ?? PanelErrorParser.messageFromHttp(status: response.statusCode, reason: nil, body: data)
            throw UltraLeaseError(message)
        }
        return try body.validatedLeases(policy: policy, maxCandidates: maxCandidates, sealer: sealer, now: now)
    }
}
