import XCTest
@testable import LeikwanIOSCore

final class DebugLogUploaderTests: XCTestCase {
    func testRedactScrubsSecretsAndIPLiteralsWhileKeepingTimestamps() {
        let raw = """
        12:34:56 Authorization: Bearer secret_bearer_token
        agent_token=tok_xyz password="my pw" ?token=qtok&ok=1
        relay 198.51.100.42:443 v6 [2001:db8::cafe]:8443
        """
        let redacted = DebugLogUploader.redact(raw)
        for leak in ["secret_bearer_token", "tok_xyz", "my pw", "qtok", "198.51.100.42", "2001:db8::cafe"] {
            XCTAssertFalse(redacted.contains(leak), "leaked \(leak) in \(redacted)")
        }
        XCTAssertTrue(redacted.contains("12:34:56"))
        XCTAssertTrue(redacted.contains("ok=1"))
    }

    func testStartAndUploadBundleOverMockTransport() async throws {
        let uuid = "123e4567-e89b-42d3-a456-426614174000"
        let transport = MockPanelTransport([
            .respond(200, #"{"ok":true,"reference_code":"123e4567","debug_collection":{"uuid":"\#(uuid)","status":"active"}}"#),
            .respond(200, #"{"ok":true,"accepted":true}"#),
            .respond(200, #"{"ok":true,"accepted":true}"#),
        ])
        let client = PanelClient(
            endpoint: PanelEndpoint(label: "main", urlString: "https://panel.example.test"),
            credentials: PanelCredentials(
                clientId: 1,
                agentToken: "tok",
                installId: "inst",
                deviceName: "iPhone",
                appVersion: "1.0.0",
                appBuild: "1"
            ),
            transport: transport
        )
        let started = try await client.startDebugCollection()
        XCTAssertEqual(started.referenceCode, "123e4567")
        XCTAssertEqual(started.collection.uuid, uuid)

        let body = "token=topsecret\n" + String(repeating: "a", count: DebugLogUploader.chunkBytes + 32)
        let uploaded = try await client.uploadDebugLogBundle(uuid: uuid, rawText: body)
        XCTAssertEqual(uploaded.chunks, 2)
        XCTAssertEqual(transport.requests.count, 3)
        XCTAssertEqual(transport.requests[0].url?.path, "/api/client/debug/start")
        XCTAssertEqual(transport.requests[1].url?.path, "/api/client/debug/log")
        let firstChunkJSON = try XCTUnwrap(
            JSONSerialization.jsonObject(with: XCTUnwrap(transport.requests[1].httpBody)) as? [String: Any]
        )
        let decodedFirst = try XCTUnwrap(Data(base64Encoded: XCTUnwrap(firstChunkJSON["chunk_base64"] as? String)))
        XCTAssertFalse(String(decoding: decodedFirst, as: UTF8.self).contains("topsecret"))
    }

    func testPullResponseExtractsActiveDebugCollection() throws {
        let data = Data(#"{"ok":true,"forwards":[],"client":{"id":68,"debug_collection":{"uuid":"aabbccdd-e89b-42d3-a456-426614174000","status":"active"}}}"#.utf8)
        let resp = try XCTUnwrap(PullConfigsResponse.parse(data))
        XCTAssertEqual(resp.debugCollection?.isActive, true)
        XCTAssertEqual(resp.debugCollection?.uuid, "aabbccdd-e89b-42d3-a456-426614174000")
    }
}
