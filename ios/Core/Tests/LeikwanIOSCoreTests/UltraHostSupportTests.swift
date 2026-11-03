import Foundation
import XCTest
@testable import LeikwanIOSCore

final class UltraHostSupportTests: XCTestCase {
    private func policy() -> UltraPolicy {
        // renew_after = now + 100 s, expires_at = now + 600 s.
        return UltraPolicy.from(Fixtures.ultraForward(), now: Fixtures.now)!
    }

    func testRenewalScheduleUsesThirtySecondMargin() {
        let p = policy()
        XCTAssertEqual(UltraLeaseSchedule.renewalTime(p), Fixtures.now.addingTimeInterval(70))
        XCTAssertFalse(UltraLeaseSchedule.renewalDue(p, now: Fixtures.now.addingTimeInterval(69)))
        XCTAssertTrue(UltraLeaseSchedule.renewalDue(p, now: Fixtures.now.addingTimeInterval(70)))
        XCTAssertEqual(UltraLeaseSchedule.nextCheckDelay(p, now: Fixtures.now), 30)
        XCTAssertEqual(UltraLeaseSchedule.nextCheckDelay(p, now: Fixtures.now.addingTimeInterval(60)), 10)
        XCTAssertEqual(UltraLeaseSchedule.nextCheckDelay(p, now: Fixtures.now.addingTimeInterval(200)), 1)
    }

    func testStatusParsingAndLostPolicy() {
        let json = "{\"policy_state\":\"lapsed\",\"policy_generation\":\"abc\",\"updated_at\":\"2027-01-15T08:00:05Z\"}"
        let status = UltraRuntimeStatus.parse(Data(json.utf8))
        XCTAssertEqual(status?.policyState, .lapsed)
        XCTAssertEqual(status?.needsLease, true)
        XCTAssertTrue(UltraLeaseSchedule.runtimeLostPolicy(status, leaseSentAt: Fixtures.now))
        XCTAssertFalse(UltraLeaseSchedule.runtimeLostPolicy(status, leaseSentAt: Fixtures.now.addingTimeInterval(5.4)))

        let legacy = UltraRuntimeStatus.parse(Data("{\"error_code\":\"awaiting_policy\"}".utf8))
        XCTAssertEqual(legacy?.policyState, .awaiting)
        // No updated_at: never counts as lost.
        XCTAssertFalse(UltraLeaseSchedule.runtimeLostPolicy(legacy, leaseSentAt: Fixtures.now))

        let revoked = UltraRuntimeStatus.parse(Data("{\"policy_state\":\"revoked\",\"updated_at\":\"2027-01-15T09:00:00Z\"}".utf8))
        XCTAssertEqual(revoked?.needsLease, false)
        XCTAssertFalse(UltraLeaseSchedule.runtimeLostPolicy(revoked, leaseSentAt: Fixtures.now))
        XCTAssertNil(UltraRuntimeStatus.parse(Data("[]".utf8)))
    }

    func testCompactPathsFitDarwinSocketLimit() {
        // A realistic app group container path on device.
        let directory = "/private/var/mobile/Containers/Shared/AppGroup/0123ABCD-4567-89EF-0123-456789ABCDEF/r/"
        let paths = UltraRuntimePaths.compact(directory: directory, forwardId: 123456)
        XCTAssertEqual(paths.controlSocketPath, "/private/var/mobile/Containers/Shared/AppGroup/0123ABCD-4567-89EF-0123-456789ABCDEF/r/u123456.sock")
        XCTAssertLessThanOrEqual(paths.controlSocketPath.utf8.count, UltraRuntimePaths.maxSocketPathBytes)
        XCTAssertTrue(paths.statusPath.hasSuffix(UltraUsageLedger.statusSuffix))
        XCTAssertEqual(Set(paths.allPaths).count, 5)
    }
}
