import Darwin
import Foundation
import XCTest
@testable import LeikwanIOSCore

final class UltraRuntimeReadinessTests: XCTestCase {
    func testWaitsUntilTheListenerAccepts() {
        var probes = 0
        var slept = 0
        let outcome = UltraRuntimeReadiness.waitForListener(
            budgetSeconds: 5,
            probe: {
                probes += 1
                return probes >= 3
            },
            sleep: { _ in slept += 1 },
            now: { Date(timeIntervalSince1970: 0) }
        )
        XCTAssertEqual(outcome, .ready(milliseconds: 0))
        XCTAssertEqual(probes, 3)
        XCTAssertEqual(slept, 2)
    }

    func testStopsAtTheBudget() {
        var clock = 0.0
        var probes = 0
        let outcome = UltraRuntimeReadiness.waitForListener(
            budgetSeconds: 1,
            probe: {
                probes += 1
                return false
            },
            sleep: { interval in clock += interval },
            now: { Date(timeIntervalSince1970: clock) }
        )
        XCTAssertEqual(outcome, .timedOut)
        // 1 s budget at 200 ms polls: the probe runs at 0, 0.2, 0.4, 0.6, 0.8 s and once more
        // when the loop notices the budget is spent at 1.0 s.
        XCTAssertEqual(probes, 6)
    }

    func testStopsWhenTheRuntimeDies() {
        var probes = 0
        let outcome = UltraRuntimeReadiness.waitForListener(
            budgetSeconds: 10,
            probe: {
                probes += 1
                return false
            },
            shouldAbort: { probes >= 2 },
            sleep: { _ in },
            now: { Date(timeIntervalSince1970: 0) }
        )
        XCTAssertEqual(outcome, .aborted)
        XCTAssertEqual(probes, 2)
    }

    func testCanConnectSeesAnOpenAndAClosedPort() {
        let listener = Darwin.socket(AF_INET, SOCK_STREAM, 0)
        XCTAssertGreaterThanOrEqual(listener, 0)
        defer { _ = Darwin.close(listener) }
        var address = sockaddr_in()
        address.sin_len = UInt8(MemoryLayout<sockaddr_in>.size)
        address.sin_family = sa_family_t(AF_INET)
        address.sin_port = 0
        address.sin_addr.s_addr = inet_addr("127.0.0.1")
        let bound = withUnsafePointer(to: &address) { pointer -> Int32 in
            pointer.withMemoryRebound(to: sockaddr.self, capacity: 1) {
                Darwin.bind(listener, $0, socklen_t(MemoryLayout<sockaddr_in>.size))
            }
        }
        XCTAssertEqual(bound, 0)
        XCTAssertEqual(Darwin.listen(listener, 4), 0)

        var actual = sockaddr_in()
        var length = socklen_t(MemoryLayout<sockaddr_in>.size)
        let named = withUnsafeMutablePointer(to: &actual) { pointer -> Int32 in
            pointer.withMemoryRebound(to: sockaddr.self, capacity: 1) {
                getsockname(listener, $0, &length)
            }
        }
        XCTAssertEqual(named, 0)
        let port = Int(UInt16(bigEndian: actual.sin_port))
        XCTAssertGreaterThan(port, 0)
        XCTAssertTrue(UltraLocalListener.canConnect(port: port))

        // A port nothing listens on: bind, read the port, close, then probe.
        let spare = Darwin.socket(AF_INET, SOCK_STREAM, 0)
        XCTAssertGreaterThanOrEqual(spare, 0)
        var spareAddress = address
        withUnsafePointer(to: &spareAddress) { pointer in
            pointer.withMemoryRebound(to: sockaddr.self, capacity: 1) {
                _ = Darwin.bind(spare, $0, socklen_t(MemoryLayout<sockaddr_in>.size))
            }
        }
        var spareActual = sockaddr_in()
        var spareLength = socklen_t(MemoryLayout<sockaddr_in>.size)
        withUnsafeMutablePointer(to: &spareActual) { pointer in
            pointer.withMemoryRebound(to: sockaddr.self, capacity: 1) {
                _ = getsockname(spare, $0, &spareLength)
            }
        }
        let sparePort = Int(UInt16(bigEndian: spareActual.sin_port))
        _ = Darwin.close(spare)
        XCTAssertFalse(UltraLocalListener.canConnect(port: sparePort, timeoutMilliseconds: 100))
    }
}
