import XCTest
@testable import LeikwanIOSCore

final class UltraRetryPolicyTests: XCTestCase {
    func testFirstFailureOnPlannedPolicyRetriesImmediately() {
        // The failed attempt used the plan-time grant (no fresh pull yet); the next
        // attempt pulls, so it should run at once instead of after the backoff.
        let decision = UltraRetryPolicy.delayMs(pullFirstAtFailure: false, backoffDelayMs: 5_000)
        XCTAssertEqual(decision, .immediate)
    }

    func testFailureAfterFreshPullWaitsBackoff() {
        // The failed attempt already pulled a fresh grant; repeating that pull
        // immediately would return the same result, so pace normally.
        let decision = UltraRetryPolicy.delayMs(pullFirstAtFailure: true, backoffDelayMs: 5_000)
        XCTAssertEqual(decision, .backoff(5_000))
    }

    func testBackoffDelayNeverBelowOneSecond() {
        let decision = UltraRetryPolicy.delayMs(pullFirstAtFailure: true, backoffDelayMs: 0)
        XCTAssertEqual(decision, .backoff(1_000))
    }

    func testConsecutiveFailuresKeepBackingOff() {
        // Second and later failures always follow the (already doubling) backoff.
        let second = UltraRetryPolicy.delayMs(pullFirstAtFailure: true, backoffDelayMs: 10_000)
        let third = UltraRetryPolicy.delayMs(pullFirstAtFailure: true, backoffDelayMs: 20_000)
        XCTAssertEqual(second, .backoff(10_000))
        XCTAssertEqual(third, .backoff(20_000))
    }
}
