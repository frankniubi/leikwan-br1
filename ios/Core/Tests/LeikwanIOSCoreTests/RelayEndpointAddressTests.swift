import XCTest
@testable import LeikwanIOSCore

final class RelayEndpointAddressTests: XCTestCase {
    func testParsesIPv4AndBracketedIPv6() {
        XCTAssertEqual(RelayEndpointAddress.parse("203.0.113.7:443"), RelayEndpointAddress(host: "203.0.113.7", port: 443))
        XCTAssertEqual(RelayEndpointAddress.parse("[2001:db8::1]:8443"), RelayEndpointAddress(host: "2001:db8::1", port: 8443))
    }

    func testRejectsMalformed() {
        for text in ["", "203.0.113.7", "203.0.113.7:0", "203.0.113.7:70000", "2001:db8::1:443", "[2001:db8::1]443", ":443"] {
            XCTAssertNil(RelayEndpointAddress.parse(text), text)
        }
    }

    func testPreferredIsTheLowestForwardWithAValidEndpoint() {
        let endpoints: [Int64: String] = [9: "198.51.100.9:443", 3: "garbage", 5: "198.51.100.5:443"]
        XCTAssertEqual(RelayEndpointAddress.preferred(endpoints)?.host, "198.51.100.5")
        XCTAssertNil(RelayEndpointAddress.preferred([:]))
    }
}
