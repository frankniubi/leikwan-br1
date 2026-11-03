import Foundation
import XCTest
@testable import LeikwanIOSCore

final class UltraUsageLedgerTests: XCTestCase {
    private var directory: URL!
    private var statusDirectory: URL!
    private var stateFile: URL!
    private let generationA = String(repeating: "a", count: 64)
    private let generationB = String(repeating: "b", count: 64)

    override func setUpWithError() throws {
        directory = FileManager.default.temporaryDirectory.appendingPathComponent("ledger-\(UUID().uuidString)", isDirectory: true)
        statusDirectory = directory.appendingPathComponent("r", isDirectory: true)
        stateFile = directory.appendingPathComponent("ultra-usage-state.json")
        try FileManager.default.createDirectory(at: statusDirectory, withIntermediateDirectories: true)
    }

    override func tearDownWithError() throws {
        try? FileManager.default.removeItem(at: directory)
    }

    private func ledger() -> UltraUsageLedger {
        return UltraUsageLedger(statusDirectory: statusDirectory, stateFile: stateFile)
    }

    private func statusURL(_ name: String) -> URL {
        return statusDirectory.appendingPathComponent(name)
    }

    private func writeStatus(_ name: String, forward: Int64, generation: String, bytesIn: Int64, bytesOut: Int64) throws {
        let body: [String: Any] = [
            "forward_id": forward,
            "policy_generation": generation,
            "bytes_in": bytesIn,
            "bytes_out": bytesOut,
            "policy_state": "active",
        ]
        try JSONSerialization.data(withJSONObject: body).write(to: statusURL(name))
    }

    func testFirstCollectReportsCountersAsDelta() throws {
        try writeStatus("u12.status.json", forward: 12, generation: generationA, bytesIn: 100, bytesOut: 40)
        let rows = ledger().collect()
        XCTAssertEqual(rows, [UltraUsageRow(forwardId: 12, policyGeneration: generationA, sequence: 1, bytesIn: 100, bytesOut: 40)])
    }

    func testPendingBatchIsResentUntilAcknowledged() throws {
        try writeStatus("u12.status.json", forward: 12, generation: generationA, bytesIn: 100, bytesOut: 40)
        let first = ledger().collect()
        // The runtime moves more bytes, but the unacknowledged batch is resent unchanged.
        try writeStatus("u12.status.json", forward: 12, generation: generationA, bytesIn: 150, bytesOut: 60)
        XCTAssertEqual(ledger().collect(), first)
        ledger().acknowledge(first, acknowledged: false)
        XCTAssertEqual(ledger().collect(), first)

        ledger().acknowledge(first, acknowledged: true)
        let second = ledger().collect()
        XCTAssertEqual(second, [UltraUsageRow(forwardId: 12, policyGeneration: generationA, sequence: 2, bytesIn: 50, bytesOut: 20)])
    }

    func testNoDeltaProducesNoRow() throws {
        try writeStatus("u12.status.json", forward: 12, generation: generationA, bytesIn: 10, bytesOut: 0)
        let first = ledger().collect()
        ledger().acknowledge(first, acknowledged: true)
        XCTAssertEqual(ledger().collect(), [])
    }

    func testCounterResetCountsFromZero() throws {
        try writeStatus("u12.status.json", forward: 12, generation: generationA, bytesIn: 1000, bytesOut: 1000)
        ledger().acknowledge(ledger().collect(), acknowledged: true)
        // Runtime restarted: counters went backwards.
        try writeStatus("u12.status.json", forward: 12, generation: generationA, bytesIn: 30, bytesOut: 5)
        let rows = ledger().collect()
        XCTAssertEqual(rows.first?.bytesIn, 30)
        XCTAssertEqual(rows.first?.bytesOut, 5)
        XCTAssertEqual(rows.first?.sequence, 2)
    }

    func testSequencesSurviveNewLedgerInstances() throws {
        try writeStatus("u12.status.json", forward: 12, generation: generationA, bytesIn: 1, bytesOut: 1)
        ledger().acknowledge(ledger().collect(), acknowledged: true)
        try writeStatus("u12.status.json", forward: 12, generation: generationA, bytesIn: 2, bytesOut: 1)
        ledger().acknowledge(ledger().collect(), acknowledged: true)
        try writeStatus("u12.status.json", forward: 12, generation: generationA, bytesIn: 3, bytesOut: 1)
        XCTAssertEqual(ledger().collect().first?.sequence, 3)
    }

    func testCaptureFinalCarriesBytesAfterStatusFileIsGone() throws {
        try writeStatus("u12.status.json", forward: 12, generation: generationA, bytesIn: 100, bytesOut: 10)
        ledger().acknowledge(ledger().collect(), acknowledged: true)
        try writeStatus("u12.status.json", forward: 12, generation: generationA, bytesIn: 180, bytesOut: 25)
        ledger().captureFinal(statusFile: statusURL("u12.status.json"))
        try FileManager.default.removeItem(at: statusURL("u12.status.json"))

        let rows = ledger().collect()
        XCTAssertEqual(rows, [UltraUsageRow(forwardId: 12, policyGeneration: generationA, sequence: 2, bytesIn: 80, bytesOut: 15)])
        ledger().acknowledge(rows, acknowledged: true)
        XCTAssertEqual(ledger().collect(), [])
    }

    func testCarryAndLiveRuntimeOfSameGenerationMergeIntoOneRow() throws {
        try writeStatus("u12.status.json", forward: 12, generation: generationA, bytesIn: 50, bytesOut: 0)
        ledger().captureFinal(statusFile: statusURL("u12.status.json"))
        // A new runtime instance for the same forward and generation starts from zero.
        try writeStatus("u12.status.json", forward: 12, generation: generationA, bytesIn: 7, bytesOut: 3)
        let rows = ledger().collect()
        XCTAssertEqual(rows, [UltraUsageRow(forwardId: 12, policyGeneration: generationA, sequence: 1, bytesIn: 57, bytesOut: 3)])
    }

    func testRowsAreSortedAndGroupedPerForwardAndGeneration() throws {
        try writeStatus("u20.status.json", forward: 20, generation: generationA, bytesIn: 1, bytesOut: 0)
        try writeStatus("u12.status.json", forward: 12, generation: generationB, bytesIn: 2, bytesOut: 0)
        try writeStatus("u13.status.json", forward: 12, generation: generationA, bytesIn: 3, bytesOut: 0)
        let rows = ledger().collect()
        XCTAssertEqual(rows.map { $0.forwardId }, [12, 12, 20])
        XCTAssertEqual(rows.map { $0.policyGeneration }, [generationA, generationB, generationA])
        // Sequences are per forward.
        XCTAssertEqual(rows.map { $0.sequence }, [1, 2, 1])
    }

    func testUnreadableStatusFilesAreIgnored() throws {
        try Data("{not json".utf8).write(to: statusURL("u1.status.json"))
        try writeStatus("u2.status.json", forward: 2, generation: "short", bytesIn: 5, bytesOut: 5)
        try writeStatus("u3.status.json", forward: 0, generation: generationA, bytesIn: 5, bytesOut: 5)
        try writeStatus("u4.json", forward: 4, generation: generationA, bytesIn: 5, bytesOut: 5)
        XCTAssertEqual(ledger().collect(), [])
    }

    func testRowsAreCappedAtMaxRows() throws {
        for forward in 1...40 {
            try writeStatus("u\(forward).status.json", forward: Int64(forward), generation: generationA, bytesIn: 1, bytesOut: 1)
        }
        let first = ledger().collect()
        XCTAssertEqual(first.count, UltraUsageLedger.maxRows)
        ledger().acknowledge(first, acknowledged: true)
        let second = ledger().collect()
        XCTAssertEqual(second.count, 40 - UltraUsageLedger.maxRows)
    }

    func testCorruptStateFileStartsFresh() throws {
        try Data("garbage".utf8).write(to: stateFile)
        try writeStatus("u12.status.json", forward: 12, generation: generationA, bytesIn: 9, bytesOut: 9)
        XCTAssertEqual(ledger().collect().first?.sequence, 1)
    }
}
