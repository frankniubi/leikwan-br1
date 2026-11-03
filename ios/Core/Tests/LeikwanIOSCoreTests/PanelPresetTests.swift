import XCTest
@testable import LeikwanIOSCore

final class PanelPresetTests: XCTestCase {
    func testBuiltInAddresses() {
        XCTAssertEqual(PanelPreset.cloudflare.endpoint.urlString, "https://v3.leikwanhost.com")
        XCTAssertEqual(PanelPreset.direct.endpoint.urlString, "https://api-leikwanbridge.nbwly.com")
    }

    func testEachPresetFallsBackToTheOther() {
        XCTAssertEqual(PanelPreset.cloudflare.backup, PanelPreset.direct.endpoint)
        XCTAssertEqual(PanelPreset.direct.backup, PanelPreset.cloudflare.endpoint)
        XCTAssertEqual(PanelPreset.backup(for: PanelPreset.cloudflare.endpoint), PanelPreset.direct.endpoint)
        XCTAssertEqual(PanelPreset.backup(for: PanelPreset.direct.endpoint), PanelPreset.cloudflare.endpoint)
    }

    func testTypedAddressMatchesItsPresetAfterNormalizing() throws {
        let typed = try PanelEndpoint.parse("HTTPS://V3.leikwanhost.com/").get()
        XCTAssertEqual(PanelPreset.matching(typed), .cloudflare)
        XCTAssertEqual(PanelPreset.backup(for: typed), PanelPreset.direct.endpoint)
    }

    func testCustomServerHasNoBackup() throws {
        let custom = try PanelEndpoint.parse("https://panel.example.com").get()
        XCTAssertNil(PanelPreset.matching(custom))
        XCTAssertNil(PanelPreset.backup(for: custom))
    }
}
