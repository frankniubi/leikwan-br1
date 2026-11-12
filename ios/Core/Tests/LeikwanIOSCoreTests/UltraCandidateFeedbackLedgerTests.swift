import Foundation
import XCTest
@testable import LeikwanIOSCore

/// Runtime candidate feedback reaches the heartbeat once, fresh and in the panel's shape
/// (mirrors Android `UltraCandidateFeedbackLedgerTest`).
final class UltraCandidateFeedbackLedgerTests: XCTestCase {
    private var directory: URL!
    private var statusDirectory: URL!
    private var stateFile: URL!
    private let now = ISOInstant.parse("2030-01-01T00:10:00Z")!
    private let candidate = String(repeating: "a", count: 64)
    private let lease = String(repeating: "b", count: 64)

    override func setUpWithError() throws {
        directory = FileManager.default.temporaryDirectory.appendingPathComponent("feedback-\(UUID().uuidString)", isDirectory: true)
        statusDirectory = directory.appendingPathComponent("r", isDirectory: true)
        stateFile = directory.appendingPathComponent("ultra-feedback-state.json")
        try FileManager.default.createDirectory(at: statusDirectory, withIntermediateDirectories: true)
    }

    override func tearDownWithError() throws {
        try? FileManager.default.removeItem(at: directory)
    }

    private func ledger() -> UltraCandidateFeedbackLedger {
        return UltraCandidateFeedbackLedger(statusDirectory: statusDirectory, stateFile: stateFile)
    }

    private func entry(
        _ evidence: String,
        reason: String = "relay_connect_timeout",
        observedAt: String = "2030-01-01T00:09:00Z",
        protocolName: String = "tcp",
        outcome: String = "failure",
        candidateHash: String? = nil,
        leaseHash: String? = nil
    ) -> [String: Any] {
        return [
            "candidate_key_hash": candidateHash ?? candidate,
            "lease_hash": leaseHash ?? lease,
            "protocol": protocolName,
            "outcome": outcome,
            "reason": reason,
            "evidence_id": evidence,
            "observed_at": observedAt,
            "endpoint": "203.0.113.9:443",
        ]
    }

    private func writeStatus(_ name: String, forward: Int64, _ entries: [[String: Any]]) throws {
        let body: [String: Any] = [
            "forward_id": forward,
            "state": "error",
            "bytes_in": 0,
            "candidate_feedback": entries,
        ]
        try JSONSerialization.data(withJSONObject: body).write(to: statusDirectory.appendingPathComponent(name))
    }

    func testFreshValidEntriesAreSentInThePanelShapeAndInvalidOrStaleOnesAreDropped() throws {
        try writeStatus("u170.status.json", forward: 170, [
            entry("ev-1"),
            entry("ev-2", reason: "Credential_Rejected", protocolName: "UDP", candidateHash: candidate.uppercased()),
            entry("ev-stale", observedAt: "2030-01-01T00:04:59Z"),
            entry("ev-future", observedAt: "2030-01-01T00:11:01Z"),
            entry("ev-offset", observedAt: "2030-01-01T08:09:00+08:00"),
            entry("ev-stderr", reason: "process_stderr"),
            entry("ev-proto", protocolName: "quic"),
            entry("ev-outcome", outcome: "maybe"),
            entry("ev-hash", leaseHash: "short"),
            entry("bad evidence!"),
            entry("", reason: "udp_arm_failed", observedAt: ""),
        ])
        let feedback = ledger().collect(now: now)
        XCTAssertEqual(feedback.map { $0.evidenceId }, ["ev-1", "ev-2", ""])
        let second = feedback[1]
        XCTAssertEqual(second.forwardId, 170)
        XCTAssertEqual(second.reason, "credential_rejected")
        XCTAssertEqual(second.protocolName, "udp")
        XCTAssertEqual(second.candidateKeyHash, candidate)
        XCTAssertEqual(second.leaseHash, lease)

        XCTAssertEqual(Set(feedback[0].jsonObject().keys), [
            "forward_id", "candidate_key_hash", "lease_hash", "protocol", "outcome", "reason", "evidence_id", "observed_at",
        ])
        let wire = try CanonicalJSON.string(feedback.map { $0.jsonObject() })
        XCTAssertFalse(wire.contains("203.0.113.9"))
    }

    func testAcknowledgedEvidenceIsNeverSentAgainAndUnacknowledgedEvidenceIsRetried() throws {
        try writeStatus("u1.status.json", forward: 1, [entry("ev-1"), entry("ev-2")])
        let first = ledger().collect(now: now)
        XCTAssertEqual(first.count, 2)
        // Not acknowledged (failed heartbeat): the same entries come again.
        XCTAssertEqual(ledger().collect(now: now), first)

        ledger().acknowledge(first)
        XCTAssertEqual(ledger().collect(now: now), [])

        // The runtime's rolling list moves on; only the new evidence is sent.
        try writeStatus("u1.status.json", forward: 1, [entry("ev-2"), entry("ev-3")])
        XCTAssertEqual(ledger().collect(now: now).map { $0.evidenceId }, ["ev-3"])

        // The same evidence id on another forward is a different observation.
        try writeStatus("u2.status.json", forward: 2, [entry("ev-1")])
        XCTAssertEqual(Set(ledger().collect(now: now).map { $0.dedupeKey }), ["1:ev-3", "2:ev-1"])
    }

    func testAtMost32EntriesPerHeartbeatAnd512AcknowledgedKeysAreKept() throws {
        try writeStatus("u1.status.json", forward: 1, (1...40).map { entry("ev-\($0)") })
        let first = ledger().collect(now: now)
        XCTAssertEqual(first.count, UltraCandidateFeedbackLedger.maxEntries)
        ledger().acknowledge(first)
        XCTAssertEqual(ledger().collect(now: now).map { $0.evidenceId }, (33...40).map { "ev-\($0)" })

        let many = (1...600).map {
            UltraCandidateFeedbackReport(forwardId: 9, candidateKeyHash: candidate, leaseHash: lease, protocolName: "tcp",
                                         outcome: "failure", reason: "relay_connect_timeout", evidenceId: "bulk-\($0)", observedAt: "")
        }
        ledger().acknowledge(many)
        let state = try XCTUnwrap(JSONLenient.parseObject(try Data(contentsOf: stateFile)))
        let keys = try XCTUnwrap(state["acknowledged"] as? [String])
        XCTAssertEqual(keys.count, UltraCandidateFeedbackLedger.maxAcknowledged)
        XCTAssertEqual(keys.last, "9:bulk-600")
        XCTAssertFalse(keys.contains { $0.hasPrefix("1:ev-") })
    }

    func testHeartbeatOmitsEmptyFeedbackAndCarriesEntries() throws {
        let item = UltraCandidateFeedbackReport(forwardId: 4, candidateKeyHash: candidate, leaseHash: lease, protocolName: "tcp",
                                                outcome: "failure", reason: "relay_connect_timeout", evidenceId: "ev-1",
                                                observedAt: "2030-01-01T00:09:00Z")
        let body = Fixtures.parseJSON(try CanonicalJSON.string(
            HeartbeatRequest(osRelease: "17.5", ultraCandidateFeedback: [item]).jsonObject()
        ))
        let feedback = try XCTUnwrap(body["ultra_candidate_feedback"] as? [[String: Any]])
        XCTAssertEqual(feedback.first?["evidence_id"] as? String, "ev-1")
        XCTAssertEqual(feedback.first?["forward_id"] as? Int, 4)
        XCTAssertNil(HeartbeatRequest(osRelease: "17.5", ultraCandidateFeedback: []).jsonObject()["ultra_candidate_feedback"])
    }
}
