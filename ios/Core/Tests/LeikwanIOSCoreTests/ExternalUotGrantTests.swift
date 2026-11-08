import XCTest
@testable import LeikwanIOSCore

final class ExternalUotGrantTests: XCTestCase {
    private func wire(_ target: String = "sidecar.test:7443", token: String = "fixture-token") -> [String: Any] {
        ["enabled": true, "sidecar_target": target, "token": token, "delivery_mode": "reliable_datagram_v1"]
    }
    func testBootstrapAndIdentity() throws {
        let first = try ExternalUotGrant.from(wire())
        XCTAssertEqual(first.bootstrapJSON()["sidecar_target"] as? String, "sidecar.test:7443")
        XCTAssertEqual(first.bootstrapJSON()["delivery_mode"] as? String, "reliable_datagram_v1")
        XCTAssertEqual(first.fingerprint, try ExternalUotGrant.from(wire()).fingerprint)
        XCTAssertNotEqual(first.fingerprint, try ExternalUotGrant.from(wire("standby.test:7443")).fingerprint)
        XCTAssertNotEqual(first.fingerprint, try ExternalUotGrant.from(wire(token: "rotated")).fingerprint)
        XCTAssertFalse(first.description.contains("fixture-token"))
    }
    func testMalformedFailsClosed() {
        XCTAssertThrowsError(try ExternalUotGrant.from(wire("bad:0")))
        XCTAssertThrowsError(try ExternalUotGrant.from(wire(token: "")))
        var unsupported = wire()
        unsupported["delivery_mode"] = "unknown"
        XCTAssertThrowsError(try ExternalUotGrant.from(unsupported))
    }
}
