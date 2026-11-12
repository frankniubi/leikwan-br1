import Foundation
import XCTest
@testable import LeikwanIOSCore

/// The heartbeat's `ultra_status` is shaped, bounded and redacted like the OpenWrt router
/// (mirrors Android `UltraStatusCollectorTest`).
final class UltraStatusCollectorTests: XCTestCase {
    private var directory: URL!
    private let now = ISOInstant.parse("2030-01-01T00:10:00Z")!
    private let hashA = String(repeating: "a", count: 64)
    private let hashB = String(repeating: "b", count: 64)
    private let selectionRef = "sel_ABCDEFGHIJKLMNOP"

    override func setUpWithError() throws {
        directory = FileManager.default.temporaryDirectory.appendingPathComponent("status-\(UUID().uuidString)", isDirectory: true)
        try FileManager.default.createDirectory(at: directory, withIntermediateDirectories: true)
    }

    override func tearDownWithError() throws {
        try? FileManager.default.removeItem(at: directory)
    }

    private func write(_ name: String, _ json: String) throws {
        try Data(json.utf8).write(to: directory.appendingPathComponent(name))
    }

    private func collect() -> [UltraStatusReport] {
        return UltraStatusCollector.collect(statusDirectory: directory, now: now)
    }

    private func wire(_ reports: [UltraStatusReport]) throws -> String {
        return try CanonicalJSON.string(reports.map { $0.jsonObject() })
    }

    private func document(_ json: String) -> UltraStatusCollector.Document {
        return UltraStatusCollector.Document(root: JSONLenient.parseObject(json)!, modifiedAt: nil)
    }

    func testMissingDirectoryOrNoDocumentsIsAnEmptyList() {
        XCTAssertEqual(UltraStatusCollector.collect(statusDirectory: directory.appendingPathComponent("missing"), now: now), [])
        XCTAssertEqual(collect(), [])
    }

    func testFullDocumentKeepsOnlyTheFieldsThePanelNormalizes() throws {
        try write("u170.status.json", """
        {"forward_id":170,"state":"Degraded","updated_at":"2030-01-01T00:09:30.123456789Z",
         "error_code":"relay_handshake_timeout","policy_generation":"\(String(repeating: "c", count: 64))",
         "detected_ingress_zone":"CN-GD","udp_transport":"external_uot","location_source":"probe",
         "latest_tcp":{"selection_ref":"\(selectionRef)","selection_tier":"primary","candidate_key_hash":"\(hashA.uppercased())",
                       "lease_hash":"\(hashB)","rtt_ms":42,"p95_rtt_ms":80,"loss_percent":0,"probe_sent":10,
                       "probe_received":9,"selected_at":"2030-01-01T00:05:00Z","endpoint":"203.0.113.9:443"},
         "active_udp":{"selection_ref":"short","rtt_ms":1},
         "udp_runtime_session":{"selection_ref":"\(selectionRef)","rtt_ms":1},
         "ha_failures":3,"udp_queue_drops":-5,"udp_setup_failures":2,"bytes_in":4096,"bytes_out":1024,
         "candidate_feedback":[{"candidate_key_hash":"\(hashA)","outcome":"failure"}],
         "runtime_attestation":{"sha256":"\(hashA)"},
         "credentials":{"password":"secret"},
         "ha_events":[{"protocol":"TCP","reason":"Relay_Handshake_Timeout","generation":"g1","at":"2030-01-01T00:09:00Z",
                       "candidate_key_hash":"\(hashA)","lease_hash":"\(hashB)","upstream_bytes":-1,"remote_read_bytes":77,
                       "remote_read_reason":"eof","remote_stopped_first":true,"local_path_avoided":false,
                       "endpoint":"203.0.113.9:443"}]}
        """)

        let reports = collect()
        XCTAssertEqual(reports.count, 1)
        let entry = reports[0].jsonObject()
        XCTAssertEqual(Set(entry.keys), [
            "forward_id", "state", "udp_transport", "detected_ingress_zone", "latest_tcp", "ha_failures",
            "udp_queue_drops", "udp_setup_failures", "bytes_in", "bytes_out", "ha_events", "updated_at", "error_code",
        ])
        let report = reports[0]
        XCTAssertEqual(report.forwardId, 170)
        XCTAssertEqual(report.state, "degraded")
        XCTAssertEqual(report.udpTransport, "external_uot")
        XCTAssertEqual(report.detectedIngressZone, "cn-gd")
        XCTAssertEqual(report.udpQueueDrops, 0)
        XCTAssertEqual(report.haFailures, 3)
        XCTAssertEqual(report.bytesIn, 4096)
        XCTAssertEqual(report.errorCode, "relay_handshake_timeout")
        XCTAssertEqual(report.updatedAt, "2030-01-01T00:09:30.123456789Z")
        XCTAssertNil(report.activeUDP)

        let tcp = try XCTUnwrap(report.latestTCP)
        XCTAssertEqual(Set(tcp.jsonObject().keys), [
            "selection_ref", "rtt_ms", "selected_at", "candidate_key_hash", "p95_rtt_ms", "probe_sent", "probe_received",
        ])
        XCTAssertEqual(tcp.selectionRef, selectionRef)
        XCTAssertEqual(tcp.candidateKeyHash, hashA)
        XCTAssertEqual(tcp.rttMs, 42)

        let event = try XCTUnwrap(report.haEvents.first)
        XCTAssertEqual(event.protocolName, "tcp")
        XCTAssertEqual(event.reason, "relay_handshake_timeout")
        XCTAssertEqual(event.leaseHash, hashB)
        XCTAssertEqual(event.upstreamBytes, 0)
        XCTAssertEqual(event.remoteReadBytes, 77)
        XCTAssertEqual(event.remoteReadReason, "eof")
        XCTAssertEqual(event.remoteStoppedFirst, true)
        XCTAssertEqual(event.localPathAvoided, false)

        let encoded = try wire(reports)
        for leaked in ["secret", "203.0.113.9", "runtime_attestation", "candidate_feedback", "selection_tier", "policy_generation"] {
            XCTAssertFalse(encoded.contains(leaked), "leaked \(leaked)")
        }
        // Booleans stay JSON booleans (the panel only keeps is_bool values).
        XCTAssertTrue(encoded.contains(#""remote_stopped_first":true"#))
        XCTAssertTrue(encoded.contains(#""local_path_avoided":false"#))
    }

    func testNewestDocumentPerForwardWinsAndStoppedOrStaleRuntimesAreLeftOut() throws {
        try write("u1.status.json", #"{"forward_id":1,"state":"error","updated_at":"2030-01-01T00:08:00Z"}"#)
        try write("n1.status.json", #"{"forward_id":1,"state":"ready","updated_at":"2030-01-01T00:09:00Z"}"#)
        try write("u2.status.json", #"{"forward_id":2,"state":"stopped","updated_at":"2030-01-01T00:09:00Z"}"#)
        try write("u3.status.json", #"{"forward_id":3,"error_code":"runtime_stopped","updated_at":"2030-01-01T00:09:00Z"}"#)
        try write("u4.status.json", #"{"forward_id":4,"state":"ready","updated_at":"2030-01-01T00:04:59Z"}"#)
        try write("u5.status.json", #"{"forward_id":5,"state":"weird","updated_at":"2030-01-01T00:09:30Z"}"#)
        try write("u6.status.json", #"{"forward_id":0,"state":"ready"}"#)
        try write("u7.status.json", "{not json")
        try write("u8.status.json", "")
        try write("notes.json", #"{"forward_id":9,"state":"ready","updated_at":"2030-01-01T00:09:00Z"}"#)

        let reports = collect()
        // Newest first: forward 5 (00:09:30) before forward 1 (00:09:00).
        XCTAssertEqual(reports.map { $0.forwardId }, [5, 1])
        XCTAssertEqual(reports[0].state, "error")
        XCTAssertEqual(reports[1].state, "ready")
        XCTAssertEqual(reports[1].udpTransport, "")
        XCTAssertEqual(reports[1].detectedIngressZone, "unknown")
        XCTAssertNil(reports[1].jsonObject()["udp_transport"])
    }

    func testEntriesEventsAndBytesAreBounded() throws {
        let events = (1...20).map { #"{"protocol":"udp","reason":"r\#($0)","at":"t\#($0)"}"# }.joined(separator: ",")
        for id in 1...40 {
            try write("u\(id).status.json", #"{"forward_id":\#(id),"state":"ready","updated_at":"2030-01-01T00:09:00Z","ha_events":[\#(events)]}"#)
        }
        let reports = collect()
        XCTAssertEqual(reports.count, UltraStatusCollector.maxEntries)
        XCTAssertEqual(reports.map { $0.forwardId }, Array(1...32).map { Int64($0) })
        XCTAssertEqual(reports[0].haEvents.map { $0.reason }, (13...20).map { "r\($0)" })

        let x = String(repeating: "x", count: 200)
        let g = String(repeating: "g", count: 200)
        let t = String(repeating: "t", count: 200)
        let longEvent = #"{"protocol":"tcp","reason":"\#(x)","generation":"\#(g)","at":"\#(t)","candidate_key_hash":"\#(hashA)","lease_hash":"\#(hashB)"}"#
        let longEvents = Array(repeating: longEvent, count: 8).joined(separator: ",")
        let code = String(repeating: "e", count: 500)
        let documents = (1...32).map { id in
            document(#"{"forward_id":\#(id),"state":"ready","updated_at":"2030-01-01T00:09:00Z","error_code":"\#(code)","ha_events":[\#(longEvents)]}"#)
        }
        let bounded = UltraStatusCollector.build(documents: documents, now: now)
        // The budget runs out: early entries keep their events, later ones give them up, and
        // what still does not fit is left out rather than overflowing the panel's limit.
        XCTAssertTrue((2..<32).contains(bounded.count))
        XCTAssertEqual(bounded.map { $0.forwardId }, Array(1...bounded.count).map { Int64($0) })
        XCTAssertLessThanOrEqual(try CanonicalJSON.data(bounded.map { $0.jsonObject() }).count, UltraStatusCollector.maxBytes + 1)
        XCTAssertFalse(bounded.first!.haEvents.isEmpty)
        XCTAssertTrue(bounded.last!.haEvents.isEmpty)
        XCTAssertEqual(bounded.first!.errorCode.count, 64)
        XCTAssertEqual(bounded.first!.haEvents.first!.reason.count, 64)
    }

    func testStringsAreTrimmedToPrintableASCIIAndInvalidHashesAndEventsAreDropped() throws {
        try write("u9.status.json", """
        {"forward_id":9,"updated_at":" 2030-01-01T00:09:00Z ","error_code":"bad\\u0000code\\nnext 中文",
         "detected_ingress_zone":"hk-1",
         "latest_tcp":{"selection_ref":"\(selectionRef)","rtt_ms":5,"candidate_key_hash":"xyz"},
         "ha_events":[{"protocol":"quic","reason":"x"},{"protocol":"tcp","reason":"  "},
                      {"protocol":"udp","reason":"ok","candidate_key_hash":"\(String(repeating: "a", count: 63))","remote_read_reason":"Bad Reason",
                       "remote_stopped_first":"true","upstream_bytes":"12"}]}
        """)
        let report = try XCTUnwrap(collect().first)
        XCTAssertEqual(report.state, "ready")
        XCTAssertEqual(report.detectedIngressZone, "hk-1")
        XCTAssertEqual(report.errorCode, "badcodenext")
        XCTAssertEqual(report.updatedAt, "2030-01-01T00:09:00Z")
        XCTAssertEqual(report.latestTCP?.candidateKeyHash, "")
        XCTAssertNil(report.latestTCP?.jsonObject()["candidate_key_hash"])
        XCTAssertEqual(report.haEvents.count, 1)
        let event = report.haEvents[0]
        XCTAssertEqual(event.protocolName, "udp")
        XCTAssertEqual(event.candidateKeyHash, "")
        XCTAssertEqual(event.remoteReadReason, "")
        XCTAssertNil(event.remoteStoppedFirst)
        XCTAssertNil(event.upstreamBytes)
        XCTAssertEqual(event.generation, "")
        XCTAssertEqual(Set(event.jsonObject().keys), ["protocol", "reason", "generation", "at"])
    }

    func testHeartbeatAlwaysCarriesTheStatusList() throws {
        let report = UltraStatusReport(forwardId: 7, state: "error", errorCode: "relay_handshake_timeout")
        let body = Fixtures.parseJSON(try CanonicalJSON.string(
            HeartbeatRequest(osRelease: "17.5", ultraStatus: [report]).jsonObject()
        ))
        let status = try XCTUnwrap(body["ultra_status"] as? [[String: Any]])
        XCTAssertEqual(status.count, 1)
        XCTAssertEqual(status[0]["forward_id"] as? Int, 7)
        XCTAssertEqual(status[0]["error_code"] as? String, "relay_handshake_timeout")
        XCTAssertEqual((status[0]["ha_events"] as? [Any])?.count, 0)
        XCTAssertNil(body["ultra_usage"])

        // An empty list (no runtime) is still sent; nil (no access to the runtime directory) is not.
        let empty = HeartbeatRequest(osRelease: "17.5", ultraStatus: []).jsonObject()
        XCTAssertEqual((empty["ultra_status"] as? [Any])?.count, 0)
        XCTAssertNil(HeartbeatRequest(osRelease: "17.5").jsonObject()["ultra_status"])
    }
}
