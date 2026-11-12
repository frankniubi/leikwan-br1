import XCTest
@testable import LeikwanIOSCore

final class MemoryGuardPolicyTests: XCTestCase {
    private let mb: UInt64 = 1024 * 1024
    private let t0 = Date(timeIntervalSince1970: 1_000)

    func testBelowWarnDoesNothing() {
        var policy = MemoryGuardPolicy()
        XCTAssertEqual(policy.evaluate(footprint: 20 * mb, now: t0), .none)
    }

    func testWarnOncePerCrossing() {
        var policy = MemoryGuardPolicy()
        XCTAssertEqual(policy.evaluate(footprint: 38 * mb, now: t0), .warn)
        XCTAssertEqual(policy.evaluate(footprint: 39 * mb, now: t0 + 5), .none, "still high: no repeat log")
        XCTAssertEqual(policy.evaluate(footprint: 20 * mb, now: t0 + 10), .none)
        XCTAssertEqual(policy.evaluate(footprint: 38 * mb, now: t0 + 15), .warn, "a new crossing logs again")
    }

    func testShedAboveThresholdIsRateLimited() {
        var policy = MemoryGuardPolicy()
        XCTAssertEqual(policy.evaluate(footprint: 45 * mb, now: t0), .shed)
        XCTAssertEqual(policy.evaluate(footprint: 47 * mb, now: t0 + 5), .none, "inside the cooldown")
        XCTAssertEqual(policy.evaluate(footprint: 47 * mb, now: t0 + MemoryGuardPolicy.shedCooldown + 1), .shed)
    }

    func testPressureEventsShareTheCooldown() {
        var policy = MemoryGuardPolicy()
        XCTAssertEqual(policy.pressureEvent(critical: false, now: t0), .shed)
        XCTAssertEqual(policy.pressureEvent(critical: false, now: t0 + 1), .warn)
        XCTAssertEqual(policy.pressureEvent(critical: true, now: t0 + 2), .none)
        XCTAssertEqual(policy.evaluate(footprint: 46 * mb, now: t0 + 3), .none)
        XCTAssertEqual(policy.pressureEvent(critical: true, now: t0 + MemoryGuardPolicy.shedCooldown + 1), .shed)
    }
}
