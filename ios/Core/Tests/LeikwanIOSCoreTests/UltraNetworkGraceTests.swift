import XCTest
@testable import LeikwanIOSCore

final class UltraNetworkGraceTests: XCTestCase {
    func testAbsoluteDeadlineAndFrame() throws {
        let expiry = Date(timeIntervalSince1970: 1_800_000_000)
        XCTAssertNil(UltraNetworkGrace.deadline(issuedExpiry: expiry, now: expiry.addingTimeInterval(-61)))
        XCTAssertEqual(expiry.addingTimeInterval(600), UltraNetworkGrace.deadline(issuedExpiry: expiry, now: expiry.addingTimeInterval(599)))
        XCTAssertNil(UltraNetworkGrace.deadline(issuedExpiry: expiry, now: expiry.addingTimeInterval(600)))
        let frame = try UltraNetworkGrace.command(generation: String(repeating: "a", count: 64), until: expiry.addingTimeInterval(600))
        let payload = try XCTUnwrap(JSONLenient.parseObject(String(decoding: frame.dropFirst(4), as: UTF8.self)))
        XCTAssertEqual(payload["op"] as? String, "extend_grace")
    }
}
