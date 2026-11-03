import XCTest
@testable import LeikwanIOSCore

final class BackoffTests: XCTestCase {
    func testCeilingSequence() {
        let backoff = ControlPlaneBackoff()
        XCTAssertEqual((0..<7).map { backoff.ceilingMs(attempt: $0) }, [5_000, 10_000, 20_000, 40_000, 60_000, 60_000, 60_000])
        XCTAssertEqual(backoff.ceilingMs(attempt: -3), 5_000)
    }

    func testFullJitterUsesInjectedRandom() {
        var bounds: [Int64] = []
        var backoff = ControlPlaneBackoff(randomUpTo: { bound in
            bounds.append(bound)
            return bound // worst case: always the ceiling
        })
        let delays = (0..<6).map { _ in backoff.nextDelayMs() }
        XCTAssertEqual(delays, [5_000, 10_000, 20_000, 40_000, 60_000, 60_000])
        XCTAssertEqual(bounds, delays)
        XCTAssertEqual(backoff.consecutiveFailures, 6)
        backoff.reset()
        XCTAssertEqual(backoff.nextDelayMs(), 5_000)

        var zero = ControlPlaneBackoff(randomUpTo: { _ in -10 })
        XCTAssertEqual(zero.nextDelayMs(), 0, "clamped into [0, ceiling]")
        var huge = ControlPlaneBackoff(randomUpTo: { _ in 1_000_000 })
        XCTAssertEqual(huge.nextDelayMs(), 5_000)
    }

    func testControlPlanePolicy() {
        var policy = ControlPlanePolicy(backoff: ControlPlaneBackoff(randomUpTo: { $0 }))
        policy.start()
        XCTAssertEqual(policy.state, .reconnecting)

        let ok = policy.record(.success)
        XCTAssertEqual(ok.state, .ready)
        XCTAssertEqual(ok.delayMs, 30_000)
        XCTAssertTrue(ok.wakeable)
        XCTAssertTrue(policy.acceptsPollSoon)

        XCTAssertEqual(policy.record(.transientFailure).delayMs, 5_000)
        let second = policy.record(.transientFailure)
        XCTAssertEqual(second.delayMs, 10_000)
        XCTAssertFalse(second.wakeable)
        XCTAssertFalse(policy.acceptsPollSoon)

        let limited = policy.record(.deviceLimitReached(limit: 2, active: 2))
        XCTAssertEqual(limited.delayMs, 60_000)
        XCTAssertEqual(limited.deviceLimitChange, .reached(limit: 2, active: 2))
        XCTAssertEqual(policy.record(.transientFailure).delayMs, 5_000, "device limit reset the backoff")

        let recovered = policy.record(.success)
        XCTAssertEqual(recovered.deviceLimitChange, .cleared)
        XCTAssertNil(policy.record(.success).deviceLimitChange)

        let terminal = policy.record(.terminal(code: "device_kicked", command: nil))
        XCTAssertEqual(terminal.state, .stopped)
        XCTAssertNil(terminal.delayMs)
        XCTAssertEqual(terminal.terminalCode, "device_kicked")
    }

    func testIPLiteralNormalisation() {
        XCTAssertEqual(IPLiteral.normalize("2001:DB8::1"), "2001:db8:0:0:0:0:0:1")
        XCTAssertEqual(IPLiteral.normalize("::ffff:1.2.3.4"), "1.2.3.4")
        XCTAssertEqual(IPLiteral.normalize("::"), "0:0:0:0:0:0:0:0")
        XCTAssertNil(IPLiteral.normalize("1.2.3"))
        XCTAssertNil(IPLiteral.normalize("256.1.1.1"))
        XCTAssertNil(IPLiteral.normalize("1:2:3:4:5:6:7:8:9"))
        XCTAssertEqual(ProtectedConnectivitySet.normalizeCidr("10.0.0.0/7"), nil, "too broad")
        XCTAssertEqual(ProtectedConnectivitySet.normalizeCidr("[::1]/128".replacingOccurrences(of: "[", with: "").replacingOccurrences(of: "]", with: "")), "0:0:0:0:0:0:0:1/128")
        XCTAssertEqual(FlexibleBase64.decodeString("dXNlcjpwYXNz"), "user:pass")
        XCTAssertNil(FlexibleBase64.decodeString("ss://abc"))
        XCTAssertEqual(ISOInstant.parse("2027-01-15T08:00:00.123456Z")?.timeIntervalSince1970 ?? 0, 1_800_000_000.123456, accuracy: 0.001)
        XCTAssertEqual(ISOInstant.parse("2027-01-15T16:00:00+08:00"), Fixtures.now)
    }
}
