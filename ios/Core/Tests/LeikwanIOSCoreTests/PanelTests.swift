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
        XCTAssertEqual(headers["User-Agent"], "Bridge-iOS/1.0.0")
        XCTAssertEqual(headers["X-Device-Id"], "ios-client-12345-acbf215cac438980")
        XCTAssertEqual(headers["X-Device-Name"], "Frank's  iPhone")
        XCTAssertEqual(IOSDeviceIdentity.sanitizeDeviceName(String(repeating: "x", count: 300))?.count, 128)
        XCTAssertNil(IOSDeviceIdentity.sanitizeDeviceName(" \r\n "))
    }

    func testPanelEndpointParsing() {
        func parsed(_ input: String) -> String? {
            return try? PanelEndpoint.parse(input).get().urlString
        }
        XCTAssertEqual(parsed("  Panel.Example.COM  "), "https://panel.example.com")
        XCTAssertEqual(parsed("https://panel.example.com/"), "https://panel.example.com")
        XCTAssertEqual(parsed("HTTPS://panel.example.com:8443/base//?x=1#frag"), "https://panel.example.com:8443/base")
        XCTAssertEqual(parsed("203.0.113.7:9443"), "https://203.0.113.7:9443")
        XCTAssertEqual(PanelEndpoint.parse(""), .failure(.empty))
        XCTAssertEqual(PanelEndpoint.parse("http://panel.example.com"), .failure(.insecure))
        XCTAssertEqual(PanelEndpoint.parse("ftp://panel.example.com"), .failure(.invalid))
        XCTAssertEqual(PanelEndpoint.parse("https://user:pw@panel.example.com"), .failure(.hasCredentials))
        XCTAssertEqual(PanelEndpoint.parse("pan el.example.com"), .failure(.invalid))
        XCTAssertEqual(PanelEndpoint.parse("https:///path"), .failure(.invalid))

        XCTAssertEqual(Fixtures.panel.host, "panel.example.com")
        XCTAssertEqual(try PanelEndpoint.parse("[2001:db8::1]:443").get().host, "2001:db8::1")
        // Only normalized addresses come back from storage; old endpoint keys do not.
        XCTAssertEqual(PanelEndpoint(storage: "https://panel.example.com"), Fixtures.panel)
        XCTAssertNil(PanelEndpoint(storage: "https://panel.example.com/"))
        XCTAssertNil(PanelEndpoint(storage: "cloudflare"))
        XCTAssertNil(PanelEndpoint(storage: nil))

        let encoded = try! JSONEncoder().encode([Fixtures.panel])
        XCTAssertEqual(String(decoding: encoded, as: UTF8.self), #"["https:\/\/panel.example.com"]"#)
        XCTAssertEqual(try JSONDecoder().decode([PanelEndpoint].self, from: encoded), [Fixtures.panel])
        XCTAssertThrowsError(try JSONDecoder().decode([PanelEndpoint].self, from: Data(#"["aliyun_cdn"]"#.utf8)))
    }

    func testProfiles() throws {
        let home = PanelProfile(name: "", server: Fixtures.panel, backupServer: Fixtures.panel, clientId: 7)
        XCTAssertNil(home.backupServer, "a backup equal to the server is dropped")
        XCTAssertEqual(home.displayName, "panel.example.com · 7")
        XCTAssertEqual(home.tokenAccount, "agent_token." + home.id.uuidString.lowercased())
        XCTAssertEqual(home.accountKey, "https://panel.example.com#7")

        var list = PanelProfileList()
        let stored = list.upsert(home)
        XCTAssertEqual(stored.id, home.id)
        // The same server + Client ID updates the saved entry (keeps its id and name).
        var renamed = home
        renamed.name = "Home"
        list.upsert(renamed)
        let again = list.upsert(PanelProfile(server: Fixtures.panel, backupServer: Fixtures.backupPanel, clientId: 7))
        XCTAssertEqual(again.id, home.id)
        XCTAssertEqual(again.name, "Home")
        XCTAssertEqual(list.profiles.count, 1)
        XCTAssertEqual(list.profiles[0].panelHosts, ["panel.example.com", "backup.example.net"])

        let work = list.upsert(PanelProfile(name: "Office", server: Fixtures.backupPanel, clientId: 7))
        XCTAssertEqual(list.profiles.count, 2)
        list.activeId = work.id
        XCTAssertEqual(list.active?.name, "Office")

        let decoded = try JSONDecoder().decode(PanelProfileList.self, from: JSONEncoder().encode(list))
        XCTAssertEqual(decoded, list)

        list.remove(id: work.id)
        XCTAssertNil(list.activeId)
        XCTAssertEqual(list.profiles.map { $0.id }, [home.id])
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
        let client = PanelClient(endpoint: Fixtures.panel, credentials: credentials, transport: transport)
        let response = try await client.pullConfigs()
        let catalog = response.catalog(selectedForwardId: nil)
        XCTAssertEqual(catalog.supported.map { $0.forwardId }, [4])
        XCTAssertEqual(catalog.unsupportedHiddenCount, 1)
        let request = transport.requests[0]
        XCTAssertEqual(request.url?.absoluteString, "https://panel.example.com/api/client/configs/pull")
        XCTAssertEqual(request.value(forHTTPHeaderField: "User-Agent"), "Bridge-iOS/1.0.0")
        XCTAssertEqual(request.httpMethod, "GET")
        XCTAssertEqual(request.value(forHTTPHeaderField: "X-Leikwan-Ultra-Offline-Validity"), "1800")
        XCTAssertEqual(request.value(forHTTPHeaderField: "Authorization"), "Bearer agent-token")
        XCTAssertEqual(request.value(forHTTPHeaderField: "X-Device-Platform"), "ios")
    }

    func testTransportFailureFailsOverButHttpErrorDoesNot() async throws {
        let transport = MockPanelTransport([.fail(URLError(.cannotConnectToHost)), .respond(200, #"{"ok":true}"#)])
        let client = PanelClient(endpoint: Fixtures.panel, fallback: Fixtures.backupPanel, credentials: credentials, transport: transport)
        let response = try await client.heartbeat(HeartbeatRequest(osRelease: "17.5"))
        XCTAssertTrue(response.ok)
        XCTAssertEqual(transport.requests.map { $0.url?.host ?? "" }, ["panel.example.com", "backup.example.net"])
        XCTAssertEqual(client.activeEndpoint, Fixtures.backupPanel)

        // Without a backup address a transport failure is not retried.
        let single = MockPanelTransport([.fail(URLError(.cannotConnectToHost)), .respond(200, #"{"ok":true}"#)])
        do {
            _ = try await PanelClient(endpoint: Fixtures.panel, credentials: credentials, transport: single).heartbeat(HeartbeatRequest(osRelease: "17.5"))
            XCTFail("expected error")
        } catch let error as PanelError {
            XCTAssertTrue(error.isTransport)
        }
        XCTAssertEqual(single.requests.count, 1)

        let httpTransport = MockPanelTransport([.respond(500, #"{"error":"boom"}"#)])
        let httpClient = PanelClient(endpoint: Fixtures.panel, credentials: credentials, transport: httpTransport)
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
            _ = try await PanelClient(endpoint: Fixtures.panel, credentials: credentials, transport: limited).pullConfigs()
            XCTFail("expected error")
        } catch {
            XCTAssertEqual(ControlPollOutcome.from(error: error), .deviceLimitReached(limit: 1, active: nil))
        }
        let kicked = MockPanelTransport([.respond(403, #"{"error":"device_banned","command":{"version":2,"action":"ban"}}"#)])
        do {
            _ = try await PanelClient(endpoint: Fixtures.panel, credentials: credentials, transport: kicked).pullConfigs()
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
        let client = PanelClient(endpoint: Fixtures.panel, credentials: credentials, transport: transport)
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
