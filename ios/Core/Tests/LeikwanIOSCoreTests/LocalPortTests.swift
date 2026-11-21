import XCTest
@testable import LeikwanIOSCore

final class LocalPortTests: XCTestCase {
    func testLocalPortConfigKeepsRealForwardPortAndStrictScopeAcrossRotation() throws {
        let forward = Fixtures.ultraForward()
        let policy = try XCTUnwrap(UltraPolicy.from(forward, now: Fixtures.now))
        let paths = UltraRuntimePaths.compact(directory: "/group/p", forwardId: forward.forwardId)
        let plan = try UltraRuntimeConfigBuilder.buildPlan(forward: forward, policy: policy, paths: paths)
        let scope = try LocalPortEgress(interfaceName: "en0", interfaceIndex: 4, dnsServers: ["223.5.5.5:53"])
        var config = Fixtures.parseJSON(try scope.applying(to: plan.configJSON))
        XCTAssertEqual(config["listen_host"] as? String, "127.0.0.1")
        XCTAssertEqual(config["listen_port"] as? Int, forward.listenPort)
        XCTAssertEqual(config["target"] as? String, forward.target)
        XCTAssertEqual(config["direct_egress_required"] as? Bool, true)
        XCTAssertEqual(config["egress_interface_index"] as? Int, 4)
        XCTAssertEqual(config["egress_dns_servers"] as? [String], ["223.5.5.5:53"])
        config["external_uot"] = ["sidecar_target": "changed.test:8443", "token": "fixture"]
        let rotated = Fixtures.parseJSON(try scope.applying(to: CanonicalJSON.string(config)))
        XCTAssertEqual(rotated["egress_interface"] as? String, "en0")
        XCTAssertEqual(rotated["direct_egress_required"] as? Bool, true)
        XCTAssertEqual(rotated["egress_dns_servers"] as? [String], ["223.5.5.5:53"])
        XCTAssertEqual(paths.controlSocketPath, "/group/p/u12.sock")
    }

    func testBackgroundAndStopInvalidateAsyncStartsWithoutAutoResume() {
        var lifecycle = LocalPortRunGate()
        let first = lifecycle.generation
        XCTAssertTrue(lifecycle.permits(first))
        lifecycle.setForeground(false)
        XCTAssertFalse(lifecycle.permits(first))
        let background = lifecycle.generation
        XCTAssertFalse(lifecycle.permits(background))
        lifecycle.setForeground(true)
        XCTAssertFalse(lifecycle.permits(first), "foreground must not resurrect an old callback")
        lifecycle.invalidate() // explicit foreground restart
        let restarted = lifecycle.generation
        XCTAssertTrue(lifecycle.permits(restarted))
        lifecycle.invalidate() // repeated stop is harmless and rejects stale work
        lifecycle.invalidate()
        XCTAssertFalse(lifecycle.permits(restarted))
    }

    func testLeaseKeychainGroupKeepsExtensionPinAndLegacyFallback() {
        XCTAssertEqual(LeaseKeychainScope.explicitGroup("TEAM.org.example.bridge.tunnel"), "TEAM.org.example.bridge.tunnel")
        XCTAssertNil(LeaseKeychainScope.explicitGroup(nil))
        XCTAssertNil(LeaseKeychainScope.explicitGroup(""))
        XCTAssertNil(LeaseKeychainScope.explicitGroup("$(AppIdentifierPrefix)org.example.bridge.tunnel"))
    }

    func testNoInterfaceOrLANOrNativeSocksFallback() throws {
        XCTAssertThrowsError(try LocalPortEgress(interfaceName: "", interfaceIndex: 0, dnsServers: []))
        XCTAssertThrowsError(try LocalPortEgress(interfaceName: "utun5", interfaceIndex: 5, dnsServers: ["1.1.1.1:53"]))
        XCTAssertThrowsError(try LocalPortEgress(interfaceName: "lo0", interfaceIndex: 1, dnsServers: ["1.1.1.1:53"]))
        let scope = try LocalPortEgress(interfaceName: "pdp_ip0", interfaceIndex: 8, dnsServers: ["1.1.1.1:53"])
        XCTAssertThrowsError(try scope.applying(to: "{\"listen_host\":\"0.0.0.0\"}"))
        XCTAssertThrowsError(try scope.applying(to: "{\"listen_host\":\"127.0.0.1\",\"native_socks5\":true}"))
    }
}

final class PortHTTPResponseTests: XCTestCase {
    private let url = URL(string: "https://panel.test/api/client/configs/pull")!
    func testFragmentedHeadersAndLengthBody() throws {
        let wire = Data("HTTP/1.1 200 OK\r\nContent-Length: 11\r\n\r\n{\"ok\":true}".utf8)
        for count in 0..<wire.count { XCTAssertFalse(PortHTTPResponse.isComplete(wire.prefix(count))) }
        XCTAssertTrue(PortHTTPResponse.isComplete(wire))
        let (body, response) = try PortHTTPResponse.decode(wire, url: url)
        XCTAssertEqual(response.statusCode, 200)
        XCTAssertEqual(String(decoding: body, as: UTF8.self), "{\"ok\":true}")
    }
    func testChunkedFragmentsAndTrailers() throws {
        let wire = Data("HTTP/1.1 200 OK\r\nTransfer-Encoding: chunked\r\n\r\n3;key=v\r\nabc\r\n2\r\nde\r\n0\r\nX-Trace: test\r\n\r\n".utf8)
        for count in 0..<wire.count { XCTAssertFalse(PortHTTPResponse.isComplete(wire.prefix(count))) }
        XCTAssertTrue(PortHTTPResponse.isComplete(wire))
        XCTAssertEqual(try PortHTTPResponse.decode(wire, url: url).0, Data("abcde".utf8))
    }
    func testCloseDelimitedAndBodylessStatuses() throws {
        let close = Data("HTTP/1.1 200 OK\r\nConnection: close\r\n\r\nbody".utf8)
        XCTAssertFalse(PortHTTPResponse.isComplete(close))
        XCTAssertEqual(try PortHTTPResponse.decode(close, url: url).0, Data("body".utf8))
        for status in [204, 304] {
            let wire = Data("HTTP/1.1 \(status) No body\r\nContent-Length: 12345\r\n\r\n".utf8)
            XCTAssertFalse(PortHTTPResponse.isComplete(wire.dropLast()))
            XCTAssertTrue(PortHTTPResponse.isComplete(wire))
            XCTAssertTrue(try PortHTTPResponse.decode(wire, url: url).0.isEmpty)
        }
    }
    func testDuplicateNonFramingHeadersDoNotRejectValidResponse() throws {
        let wire = Data("HTTP/1.1 200 OK\r\nContent-Length: 0\r\nSet-Cookie: one=1\r\nSet-Cookie: two=2\r\nVary: Origin\r\nVary: Accept-Encoding\r\n\r\n".utf8)
        XCTAssertNoThrow(try PortHTTPResponse.decode(wire, url: url))
    }
    func testMalformedAmbiguousTruncatedAndOversizeResponsesRejected() {
        for raw in [
            "HTTP/1.1 200 OK\r\nContent-Length: 3\r\n\r\nab",
            "HTTP/1.1 200 OK\r\nContent-Length: 0\r\nContent-Length: 1\r\n\r\n",
            "HTTP/1.1 200 OK\r\nContent-Length: 0\r\nTransfer-Encoding: chunked\r\n\r\n0\r\n\r\n",
            "HTTP/1.1 200 OK\r\nTransfer-Encoding: chunked\r\n\r\n99\r\nshort\r\n",
            "HTTP/1.1 200 OK\r\nTransfer-Encoding: chunked\r\n\r\n0\r\n",
            "HTTP/1.1 304 Not Modified\r\n\r\nbody",
            "not HTTP\r\n\r\n"
        ] { XCTAssertThrowsError(try PortHTTPResponse.decode(Data(raw.utf8), url: url)) }
        XCTAssertThrowsError(try PortHTTPResponse.decode(Data(repeating: 1, count: PortHTTPResponse.maximumBytes + 1), url: url))
    }
}
