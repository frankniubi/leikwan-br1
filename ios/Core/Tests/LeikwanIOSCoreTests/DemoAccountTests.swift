import XCTest
@testable import LeikwanIOSCore

final class DemoAccountTests: XCTestCase {
    func testOnlyTheDemoClientIdAndTokenMatch() {
        XCTAssertTrue(DemoAccount.matches(clientId: DemoAccount.clientId, token: DemoAccount.agentToken))
        XCTAssertTrue(DemoAccount.matches(clientId: DemoAccount.clientId, token: "  \(DemoAccount.agentToken)\n"))
        XCTAssertFalse(DemoAccount.matches(clientId: DemoAccount.clientId, token: "wrong"))
        XCTAssertFalse(DemoAccount.matches(clientId: DemoAccount.clientId + 1, token: DemoAccount.agentToken))
        XCTAssertFalse(DemoAccount.matches(clientId: DemoAccount.clientId, token: ""))
    }

    func testCatalogShowsTheTwoDemoForwardsAndHidesNothing() {
        let catalog = DemoAccount.catalog(selectedForwardId: nil)
        XCTAssertEqual(catalog.supported.map(\.serviceName), ["New York Office", "Oregon Branch"])
        XCTAssertTrue(catalog.hidden.isEmpty)
        XCTAssertEqual(catalog.bindableForwards.map(\.forwardId), [1001, 1002])
    }
}
