import XCTest
@testable import LeikwanIOSCore

/// Scripted transport: each call pops the next result.
final class MockPanelTransport: PanelHTTPTransport {
    enum Step {
        case respond(Int, String)
        case fail(Error)
    }

    var steps: [Step]
    private(set) var requests: [URLRequest] = []

    init(_ steps: [Step]) {
        self.steps = steps
    }

    func send(_ request: URLRequest) async throws -> (Data, HTTPURLResponse) {
        requests.append(request)
        guard !steps.isEmpty else { throw URLError(.unknown) }
        let step = steps.removeFirst()
        switch step {
        case .fail(let error):
            throw error
        case .respond(let status, let body):
            let response = HTTPURLResponse(url: request.url!, statusCode: status, httpVersion: "HTTP/1.1", headerFields: nil)!
            return (Data(body.utf8), response)
        }
    }
}

final class PanelTests: XCTestCase {
    private let credentials = PanelCredentials(
        clientId: 12345,
        agentToken: " agent-token ",
        installId: " Install-ABC ",
        deviceName: "Frank's\r\niPhone",
        appVersion: "1.0.0"
    )

    /// Golden: SHA-256("ios|12345|install-abc") = acbf215cac438980... (Python hashlib; install id is
    /// trimmed + lowercased like Windows BuildWindowsDeviceId normalises the machine name).
    func testDeviceIdDerivation() {
        XCTAssertEqual(IOSDeviceIdentity.deviceId(clientId: 12345, installId: "install-abc"), "ios-client-12345-acbf215cac438980")
        XCTAssertEqual(IOSDeviceIdentity.deviceId(clientId: 12345, installId: " INSTALL-abc "), "ios-client-12345-acbf215cac438980")
        // SHA-256("ios|12345|unknown-install") prefix = 1d9901b662586b5f
        XCTAssertEqual(IOSDeviceIdentity.deviceId(clientId: 12345, installId: "  "), "ios-client-12345-1d9901b662586b5f")
        XCTAssertEqual(credentials.deviceId, "ios-client-12345-acbf215cac438980")
    }

    func testHeaders() {
        let headers = credentials.headers()
        XCTAssertEqual(headers["Authorization"], "Bearer agent-token")
        XCTAssertEqual(headers["X-Device-Platform"], "ios")
        XCTAssertEqual(headers["X-Client-Version"], "1.0.0")
        XCTAssertEqual(headers["User-Agent"], "LeiKwan-iOS/1.0.0")
        XCTAssertEqual(headers["X-Device-Id"], "ios-client-12345-acbf215cac438980")
        XCTAssertEqual(headers["X-Device-Name"], "Frank's  iPhone")
        XCTAssertEqual(IOSDeviceIdentity.sanitizeDeviceName(String(repeating: "x", count: 300))?.count, 128)
        XCTAssertNil(IOSDeviceIdentity.sanitizeDeviceName(" \r\n "))
    }

    func testPanelEndpoints() {
        XCTAssertEqual(PanelEndpoint.defaultEndpoint.urlString, "https://v3.leikwanhost.com")
        XCTAssertEqual(PanelEndpoint.aliyunCDN.urlString, "https://api-leikwanbridge.nbwly.com")
        XCTAssertEqual(PanelEndpoint.fromStorage("ALIYUN_CDN"), .aliyunCDN)
        XCTAssertEqual(PanelEndpoint.fromStorage("bogus"), .cloudflare)
        XCTAssertEqual(PanelEndpoint.cloudflare.alternate, .aliyunCDN)
        XCTAssertEqual(PanelEndpoint.allProtectedHosts, ["v3.leikwanhost.com", "api-leikwanbridge.nbwly.com", "v3-mirror.leikwanhost.com"])
    }

    func testDeviceLimitParsing() {
        let flat = Data(#"{"ok":false,"error":"client_online_limit_reached","online_device_limit":3,"active_online_devices":"3"}"#.utf8)
        guard case .deviceLimitReached(let limit, let active)? = PanelErrorParser.deviceLimit(status: 429, body: flat) else {
            return XCTFail("expected device limit")
        }
        XCTAssertEqual(limit, 3)
        XCTAssertEqual(active, 3)

        let nested = Data(#"{"error":{"code":"client_online_limit_reached"},"details":{"online_device_limit":2,"active_online_devices":5}}"#.utf8)
        guard case .deviceLimitReached(let l2, let a2)? = PanelErrorParser.deviceLimit(status: 429, body: nested) else {
            return XCTFail("expected device limit")
        }
        XCTAssertEqual(l2, 2)
        XCTAssertEqual(a2, 5)

        XCTAssertNil(PanelErrorParser.deviceLimit(status: 429, body: Data(#"{"error":"rate_limited"}"#.utf8)))
        XCTAssertNil(PanelErrorParser.deviceLimit(status: 403, body: flat))
    }

    func testTerminalControlParsing() {
        let body = Data(#"{"ok":false,"error":"device_kicked","command":{"version":"7","action":"kick","reason":"tenant"}}"#.utf8)
        guard case .terminalControl(let code, let command)? = PanelErrorParser.terminalControl(status: 403, body: body) else {
            return XCTFail("expected terminal control")
        }
        XCTAssertEqual(code, "device_kicked")
        XCTAssertEqual(command, DeviceCommand(version: 7, action: "kick", reason: "tenant"))
        XCTAssertNotNil(PanelErrorParser.terminalControl(status: 409, body: Data(#"{"error":"device_untrusted"}"#.utf8)))
        XCTAssertNil(PanelErrorParser.terminalControl(status: 403, body: Data(#"{"error":"forbidden"}"#.utf8)))
        XCTAssertNil(PanelErrorParser.terminalControl(status: 500, body: body))
    }

    func testGenericMessages() {
        XCTAssertEqual(PanelErrorParser.messageFromHttp(status: 500, reason: "Server Error", body: nil), "HTTP 500 Server Error")
        XCTAssertEqual(PanelErrorParser.messageFromHttp(status: 400, reason: nil, body: Data(#"{"error":"bad_input"}"#.utf8)), "bad_input")
        XCTAssertEqual(PanelErrorParser.messageFromHttp(status: 400, reason: nil,
                       body: Data(#"{"error":{"code":"x","details":{"reason":"why"},"message":"m"}}"#.utf8)), "x: why")
        XCTAssertEqual(PanelErrorParser.messageFromHttp(status: 400, reason: nil, body: Data(#"{"error":{"code":"x","message":"x"}}"#.utf8)), "x")
        XCTAssertEqual(PanelErrorParser.messageFromHttp(status: 400, reason: nil, body: Data(#"{"message":"plain"}"#.utf8)), "plain")
        XCTAssertEqual(PanelErrorParser.messageFromHttp(status: 502, reason: nil, body: Data("<html>".utf8)), "HTTP 502")
    }

    func testPullSendsHeadersAndFiltersForwards() async throws {
        let body = #"{"ok":true,"client":{"id":12345},"forwards":[{"forward_id":4,"backend_mode":"ultra"},{"forward_id":5,"backend_mode":"current_mode","client_mode":"single"}]}"#
        let transport = MockPanelTransport([.respond(200, body)])
        let client = PanelClient(endpoint: .cloudflare, credentials: credentials, transport: transport)
        let response = try await client.pullConfigs()
        let catalog = response.catalog(selectedForwardId: nil)
        XCTAssertEqual(catalog.supported.map { $0.forwardId }, [4])
        XCTAssertEqual(catalog.unsupportedHiddenCount, 1)
        let request = transport.requests[0]
        XCTAssertEqual(request.url?.absoluteString, "https://v3.leikwanhost.com/api/client/configs/pull")
        XCTAssertEqual(request.httpMethod, "GET")
        XCTAssertEqual(request.value(forHTTPHeaderField: "X-Leikwan-Ultra-Offline-Validity"), "1800")
        XCTAssertEqual(request.value(forHTTPHeaderField: "Authorization"), "Bearer agent-token")
        XCTAssertEqual(request.value(forHTTPHeaderField: "X-Device-Platform"), "ios")
    }

    func testTransportFailureFailsOverButHttpErrorDoesNot() async throws {
        let transport = MockPanelTransport([.fail(URLError(.cannotConnectToHost)), .respond(200, #"{"ok":true}"#)])
        let client = PanelClient(endpoint: .cloudflare, credentials: credentials, transport: transport)
        let response = try await client.heartbeat(HeartbeatRequest(osRelease: "17.5"))
        XCTAssertTrue(response.ok)
        XCTAssertEqual(transport.requests.map { $0.url?.host ?? "" }, ["v3.leikwanhost.com", "api-leikwanbridge.nbwly.com"])
        XCTAssertEqual(client.activeEndpoint, .aliyunCDN)

        let httpTransport = MockPanelTransport([.respond(500, #"{"error":"boom"}"#)])
        let httpClient = PanelClient(endpoint: .cloudflare, credentials: credentials, transport: httpTransport)
        do {
            _ = try await httpClient.heartbeat(HeartbeatRequest(osRelease: "17.5"))
            XCTFail("expected error")
        } catch let error as PanelError {
            guard case .http(let status, let message) = error else { return XCTFail("wrong error \(error)") }
            XCTAssertEqual(status, 500)
            XCTAssertEqual(message, "boom")
        }
        XCTAssertEqual(httpTransport.requests.count, 1)
    }

    func testDeviceLimitAndKickSurfaceAsTypedErrors() async {
        let limited = MockPanelTransport([.respond(429, #"{"error":"client_online_limit_reached","online_device_limit":1}"#)])
        do {
            _ = try await PanelClient(endpoint: .cloudflare, credentials: credentials, transport: limited).pullConfigs()
            XCTFail("expected error")
        } catch {
            XCTAssertEqual(ControlPollOutcome.from(error: error), .deviceLimitReached(limit: 1, active: nil))
        }
        let kicked = MockPanelTransport([.respond(403, #"{"error":"device_banned","command":{"version":2,"action":"ban"}}"#)])
        do {
            _ = try await PanelClient(endpoint: .cloudflare, credentials: credentials, transport: kicked).pullConfigs()
            XCTFail("expected error")
        } catch {
            XCTAssertEqual(ControlPollOutcome.from(error: error), .terminal(code: "device_banned", command: DeviceCommand(version: 2, action: "ban", reason: "")))
        }
    }

    func testHeartbeatBody() throws {
        let usage = UltraUsageRow(forwardId: 4, policyGeneration: "g", sequence: 2, bytesIn: 10, bytesOut: 20)
        let body = Fixtures.parseJSON(try CanonicalJSON.string(HeartbeatRequest(osRelease: "17.5", ultraUsage: [usage]).jsonObject()))
        XCTAssertEqual(body["client_platform"] as? String, "ios")
        XCTAssertEqual(body["os_release"] as? String, "17.5")
        XCTAssertEqual(body["supports_route_direction_rules"] as? Bool, false)
        XCTAssertEqual(body["supports_exact_destination_rules"] as? Bool, false)
        XCTAssertEqual((body["ultra_usage"] as? [[String: Any]])?.first?["bytes_out"] as? Int, 20)
        let empty = HeartbeatRequest(osRelease: "17.5", ultraUsage: []).jsonObject()
        XCTAssertNil(empty["ultra_usage"])
    }

    func testUltraLeaseRequestThroughClient() async throws {
        let leaseBody = try CanonicalJSON.string(["ok": true, "leases": [Fixtures.leaseJSON()]] as [String: Any])
        let transport = MockPanelTransport([.respond(200, leaseBody)])
        let client = PanelClient(endpoint: .cloudflare, credentials: credentials, transport: transport)
        let policy = UltraPolicy.from(Fixtures.ultraForward(), now: Fixtures.now)!
        let leases = try await client.resolveUltraLeases(policy: policy, maxCandidates: 4, nonceHex: "ab", now: Fixtures.now)
        XCTAssertEqual(leases.count, 1)
        let request = transport.requests[0]
        XCTAssertEqual(request.url?.path, "/api/client/ultra/lease")
        XCTAssertEqual(request.value(forHTTPHeaderField: "X-Leikwan-Ultra-Offline-Validity"), "1800")
        XCTAssertNil(request.value(forHTTPHeaderField: "X-Leikwan-Lease-Key"))
        let sent = Fixtures.parseJSON(String(decoding: request.httpBody ?? Data(), as: UTF8.self))
        XCTAssertEqual(sent["device_id"] as? String, "ios-client-12345-acbf215cac438980")
    }
}
