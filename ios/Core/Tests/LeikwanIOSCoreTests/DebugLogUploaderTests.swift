import XCTest
@testable import LeikwanIOSCore

final class DebugLogUploaderTests: XCTestCase {
    func testRedactScrubsSecretsAndIPLiteralsWhileKeepingTimestamps() {
        let raw = """
        12:34:56 Authorization: Bearer secret_bearer_token
        agent_token=tok_xyz password="my pw" ?token=qtok&ok=1
        {"agent_token":"json-secret","access_token":"access-secret","refresh-token":"refresh-secret"}
        relay 198.51.100.42:443 v6 [2001:db8::cafe]:8443
        """
        let redacted = DebugLogUploader.redact(raw)
        for leak in ["secret_bearer_token", "tok_xyz", "my pw", "qtok", "json-secret", "access-secret", "refresh-secret", "198.51.100.42", "2001:db8::cafe"] {
            XCTAssertFalse(redacted.contains(leak), "leaked \(leak) in \(redacted)")
        }
        XCTAssertTrue(redacted.contains("12:34:56"))
        XCTAssertTrue(redacted.contains("ok=1"))
    }

    func testStartAndUploadBundleOverMockTransport() async throws {
        let uuid = "123e4567-e89b-42d3-a456-426614174000"
        let transport = MockPanelTransport([
            .respond(200, #"{"ok":true,"reference_code":"123e4567","debug_collection":{"uuid":"\#(uuid)","status":"active","supports_upload_id":true}}"#),
            .respond(200, #"{"ok":true,"accepted":true}"#),
            .respond(200, #"{"ok":true,"accepted":true}"#),
        ])
        let endpoint = try PanelEndpoint.parse("https://panel.example.test").get()
        let client = PanelClient(
            endpoint: endpoint,
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
        XCTAssertTrue(started.collection.supportsUploadID)
        let bundle = try PendingDebugLogBundle(scope: "account", collection: started.collection, referenceCode: started.referenceCode, rawText: body)
        let uploaded = try await client.uploadDebugLogBundle(bundle)
        XCTAssertEqual(uploaded.chunks, 2)
        XCTAssertEqual(transport.requests.count, 3)
        XCTAssertEqual(transport.requests[0].url?.path, "/api/client/debug/start")
        XCTAssertEqual(transport.requests[1].url?.path, "/api/client/debug/log")
        let firstChunkJSON = try XCTUnwrap(
            JSONSerialization.jsonObject(with: XCTUnwrap(transport.requests[1].httpBody)) as? [String: Any]
        )
        let decodedFirst = try XCTUnwrap(Data(base64Encoded: XCTUnwrap(firstChunkJSON["chunk_base64"] as? String)))
        XCTAssertFalse(String(decoding: decodedFirst, as: UTF8.self).contains("topsecret"))
        XCTAssertEqual(firstChunkJSON["upload_id"] as? String, bundle.uploadID)
        XCTAssertTrue(DebugLogUploader.isValidCollectionUUID(bundle.uploadID))
    }

    func testPullResponseExtractsActiveDebugCollection() throws {
        let data = Data(#"{"ok":true,"forwards":[],"client":{"id":68,"debug_collection":{"uuid":"aabbccdd-e89b-42d3-a456-426614174000","status":"active","supports_upload_id":true}}}"#.utf8)
        let resp = try XCTUnwrap(PullConfigsResponse.parse(data))
        XCTAssertEqual(resp.debugCollection?.isActive, true)
        XCTAssertEqual(resp.debugCollection?.supportsUploadID, true)
        XCTAssertEqual(resp.debugCollection?.uuid, "aabbccdd-e89b-42d3-a456-426614174000")
        let old = try XCTUnwrap(PullConfigsResponse.parse(Data(#"{"ok":true,"client":{"debug_collection":{"uuid":"aabbccdd-e89b-42d3-a456-426614174000","status":"active"}}}"#.utf8)))
        XCTAssertEqual(old.debugCollection?.supportsUploadID, false)
    }

    func testCapabilityCodableDefaultsAndSnakeCase() throws {
        let legacy = Data(#"{"uuid":"123e4567-e89b-42d3-a456-426614174000","status":"active"}"#.utf8)
        XCTAssertFalse(try JSONDecoder().decode(DebugCollectionMeta.self, from: legacy).supportsUploadID)
        let data = try JSONEncoder().encode(collection)
        let object = try XCTUnwrap(JSONSerialization.jsonObject(with: data) as? [String: Any])
        XCTAssertEqual(object["supports_upload_id"] as? Bool, true)
        XCTAssertNotNil(object["expires_at"])
        XCTAssertNil(object["supportsUploadID"])
        XCTAssertEqual(try JSONDecoder().decode(DebugCollectionMeta.self, from: data), collection)
    }

    func testOnlyDocumented404And409CodesAreTerminal() {
        for (status, code) in [(404, "debug_collection_not_found"), (409, "debug_chunk_replay")] {
            let scalar = Data("{\"error\":\"\(code)\"}".utf8)
            let nested = Data("{\"error\":{\"code\":\"\(code)\",\"message\":\"detail\"}}".utf8)
            XCTAssertEqual(DebugLogUploadFailure.classify(DebugLogHTTPError(status: status, body: scalar)), .terminal)
            XCTAssertEqual(DebugLogUploadFailure.classify(DebugLogHTTPError(status: status, body: nested)), .terminal)
            for body in [Data("<html>proxy</html>".utf8), Data(#"{"error":"unknown"}"#.utf8)] {
                XCTAssertEqual(DebugLogUploadFailure.classify(DebugLogHTTPError(status: status, body: body)), .retry)
            }
        }
    }

    @MainActor
    func testInactiveOrReplacedCollectionStopsOldPendingUpload() async throws {
        for inactive in [true, false] {
            let store = DebugUploadMemoryStore()
            do {
                _ = try await store.coordinator().run(scope: "a", collection: collection,
                    start: { (self.collection, "ref") }, collect: { "old log" },
                    send: { _ in throw URLError(.timedOut) }, completed: { _ in XCTFail() })
            } catch {}
            let next = DebugCollectionMeta(uuid: inactive ? collection.uuid : UUID().uuidString,
                status: inactive ? "closed" : "active", supportsUploadID: true)
            var sent: [String] = []
            _ = try await store.coordinator().run(scope: "a", collection: next,
                start: { (next, "ref") }, collect: { "new log" },
                send: { sent.append($0.collection.uuid) }, completed: { _ in })
            XCTAssertFalse(sent.contains(collection.uuid))
            XCTAssertEqual(sent.count, inactive ? 0 : 1)
            XCTAssertEqual(try store.state().records.first?.reason, inactive ? "expired" : "superseded")
            XCTAssertNil(try store.state().pending)
        }
    }

    private let collection = DebugCollectionMeta(
        uuid: "123e4567-e89b-42d3-a456-426614174000", status: "active", supportsUploadID: true
    )

    private func client(_ transport: PanelHTTPTransport) throws -> PanelClient {
        PanelClient(
            endpoint: try PanelEndpoint.parse("https://panel.example.test").get(),
            credentials: PanelCredentials(clientId: 1, agentToken: "tok", installId: "inst", deviceName: "iPhone", appVersion: "1"),
            transport: transport
        )
    }

    func testOldStartMetadataDoesNotEnableUpload() async throws {
        let transport = MockPanelTransport([
            .respond(200, #"{"ok":true,"debug_collection":{"uuid":"123e4567-e89b-42d3-a456-426614174000","status":"active"}}"#)
        ])
        let api = try client(transport)
        let started = try await api.startDebugCollection()
        XCTAssertFalse(started.collection.supportsUploadID)
        XCTAssertThrowsError(try PendingDebugLogBundle(scope: "a", collection: started.collection, referenceCode: "ref", rawText: "log"))
        XCTAssertEqual(transport.requests.count, 1)
    }

    func testSnapshotIsBoundedRedactedAndStableAfterPersistence() throws {
        let pending = try PendingDebugLogBundle(
            scope: "a", collection: collection, referenceCode: "ref",
            rawText: "password=secret\n" + String(repeating: "a", count: 5 * 1024 * 1024)
        )
        XCTAssertEqual(pending.bytes.count, DebugLogUploader.maxBundleBytes)
        XCTAssertFalse(String(decoding: pending.bytes, as: UTF8.self).contains("secret"))
        let restored = try JSONDecoder().decode(PendingDebugLogBundle.self, from: JSONEncoder().encode(pending))
        XCTAssertEqual(pending, restored)
        XCTAssertEqual(DebugLogUploader.buildChunks(fromSanitizedBundle: pending.bytes), DebugLogUploader.buildChunks(fromSanitizedBundle: restored.bytes))
    }

    @MainActor
    func testPartialFailureRestartsWithIdenticalUploadIDAndRequestBytes() async throws {
        let store = DebugUploadMemoryStore()
        let transport = MockPanelTransport([
            .respond(200, #"{"ok":true}"#), .fail(URLError(.networkConnectionLost)),
            .respond(200, #"{"ok":true}"#), .respond(200, #"{"ok":true}"#)
        ])
        let api = try client(transport)
        var captures = 0
        var completions = 0
        let first = store.coordinator()
        do {
            _ = try await first.run(scope: "a", collection: collection,
                start: { XCTFail("passive must not start"); return (self.collection, "ref") },
                collect: { captures += 1; return String(repeating: "x", count: DebugLogUploader.chunkBytes + 5) },
                send: { _ = try await api.uploadDebugLogBundle($0) }, completed: { _ in completions += 1 })
            XCTFail("expected network failure")
        } catch { XCTAssertEqual(DebugLogUploadFailure.classify(error), .retry) }
        let pending = try XCTUnwrap(store.state().pending)
        XCTAssertEqual(completions, 0)
        // A new coordinator emulates process restart. A manual retry bypasses retry delay.
        let second = store.coordinator()
        let result = try await second.run(scope: "a",
            start: { XCTFail("must reuse the collection"); return (self.collection, "ref") },
            collect: { XCTFail("must reuse frozen bytes"); return "changed logs" },
            send: { XCTAssertEqual($0, pending); _ = try await api.uploadDebugLogBundle($0) },
            completed: { _ in completions += 1 })
        XCTAssertEqual(result?.bytes, DebugLogUploader.chunkBytes + 5)
        XCTAssertEqual(captures, 1)
        XCTAssertEqual(completions, 1)
        XCTAssertEqual(transport.requests[0].httpBody, transport.requests[2].httpBody)
        XCTAssertEqual(transport.requests[1].httpBody, transport.requests[3].httpBody)
        XCTAssertNil(try store.state().pending)
        XCTAssertEqual(try store.state().records.last?.reason, "completed")
    }

    func testHTTPFailurePolicy() {
        for status in [410, 413, 422] {
            XCTAssertEqual(DebugLogUploadFailure.classify(PanelError.http(status: status, message: "server")), .terminal)
        }
        for status in [404, 408, 409, 429, 500, 502, 503, 599] {
            XCTAssertEqual(DebugLogUploadFailure.classify(PanelError.http(status: status, message: "server")), .retry)
        }
        for status in [401, 403] {
            XCTAssertEqual(DebugLogUploadFailure.classify(PanelError.http(status: status, message: "server")), .paused)
        }
        XCTAssertEqual(DebugLogUploadFailure.classify(CancellationError()), .cancelled)
        XCTAssertEqual(DebugLogUploadFailure.classify(PanelError.transport(URLError(.cancelled))), .cancelled)
    }

    @MainActor
    func testTerminalFailuresPersistAndAreNotCompletedOrRetried() async throws {
        for status in [404, 409, 410, 413, 422] {
            let store = DebugUploadMemoryStore()
            var completed = false
            do {
                _ = try await store.coordinator().run(scope: "a", collection: collection,
                    start: { (self.collection, "ref") }, collect: { "log" },
                    send: { _ in
                        let code = status == 404 ? "debug_collection_not_found" : "debug_chunk_replay"
                        throw DebugLogHTTPError(status: status, body: Data("{\"error\":\"\(code)\",\"message\":\"password=do-not-store\"}".utf8))
                    },
                    completed: { _ in completed = true })
                XCTFail("expected terminal error")
            } catch {}
            XCTAssertFalse(completed)
            XCTAssertNil(try store.state().pending)
            XCTAssertEqual(try store.state().records.last?.reason, "http_\(status)")
            XCTAssertFalse(String(decoding: store.data!, as: UTF8.self).contains("do-not-store"))
            let result = try await store.coordinator().run(scope: "a", collection: collection,
                start: { (self.collection, "ref") }, collect: { XCTFail("must not collect"); return "" },
                send: { _ in XCTFail("must not retry terminal collection") }, completed: { _ in XCTFail("must not mark complete") })
            XCTAssertNil(result)
        }
    }

    @MainActor
    func testAuthenticationPauseKeepsSnapshotAndResumesAfterAuthenticatedPull() async throws {
        for status in [401, 403] {
            let store = DebugUploadMemoryStore()
            do {
                _ = try await store.coordinator().run(scope: "a", collection: collection,
                    start: { (self.collection, "ref") }, collect: { "first log" },
                    send: { _ in throw PanelError.http(status: status, message: "denied") }, completed: { _ in XCTFail() })
                XCTFail("expected auth failure")
            } catch {}
            let snapshot = try XCTUnwrap(store.state().pending)
            XCTAssertEqual(try store.state().lastFailure, .paused)
            _ = try await store.coordinator().run(scope: "a", collection: collection,
                start: { (self.collection, "ref") }, collect: { XCTFail(); return "new log" },
                send: { XCTAssertEqual($0, snapshot) }, completed: { _ in })
            XCTAssertNil(try store.state().pending)
        }
    }

    @MainActor
    func testCancellationAndPersistenceFailureNeverComplete() async throws {
        let store = DebugUploadMemoryStore()
        do {
            _ = try await store.coordinator().run(scope: "a", collection: collection,
                start: { (self.collection, "ref") }, collect: { "log" },
                send: { _ in throw CancellationError() }, completed: { _ in XCTFail() })
            XCTFail("expected cancellation")
        } catch is CancellationError {} catch { XCTFail("\(error)") }
        XCTAssertNotNil(try store.state().pending)
        XCTAssertTrue(try store.state().records.isEmpty)

        let failing = DebugLogUploadCoordinator(load: { DebugLogUploadState() }, save: { _ in throw URLError(.cannotWriteToFile) })
        do {
            _ = try await failing.run(scope: "a", collection: collection,
                start: { (self.collection, "ref") }, collect: { "log" },
                send: { _ in XCTFail("must persist before sending") }, completed: { _ in XCTFail() })
            XCTFail("expected storage failure")
        } catch {}
    }

    @MainActor
    func testConcurrentManualAndPassiveCallsCannotInterleave() async throws {
        let store = DebugUploadMemoryStore()
        let coordinator = store.coordinator()
        _ = try await coordinator.run(scope: "a", collection: collection,
            start: { (self.collection, "ref") },
            collect: {
                // Reenter while the first caller is suspended collecting logs.
                let passive = try await coordinator.run(scope: "a", collection: self.collection,
                    start: { (self.collection, "ref") }, collect: { XCTFail(); return "" },
                    send: { _ in XCTFail() }, completed: { _ in XCTFail() })
                XCTAssertNil(passive)
                do {
                    _ = try await coordinator.run(scope: "a", start: { XCTFail(); return (self.collection, "ref") },
                        collect: { XCTFail(); return "" }, send: { _ in XCTFail() }, completed: { _ in XCTFail() })
                    XCTFail("expected busy")
                } catch DebugLogUploadError.busy {} catch { XCTFail("\(error)") }
                return "log"
            }, send: { _ in }, completed: { _ in })
    }

    func testHTTPUploadKeepsStatusAndDoesNotFailOverControlEndpoint() async throws {
        for status in [401, 403, 404, 408, 409, 410, 413, 422, 429, 503] {
            let transport = MockPanelTransport([.respond(status, #"{"error":"device_untrusted"}"#)])
            let primary = try PanelEndpoint.parse("https://panel.example.test").get()
            let api = PanelClient(endpoint: primary,
                fallback: try PanelEndpoint.parse("https://backup.example.test").get(),
                credentials: PanelCredentials(clientId: 1, agentToken: "tok", installId: "i", deviceName: "phone", appVersion: "1"),
                transport: transport)
            let bundle = try PendingDebugLogBundle(scope: "a", collection: collection, referenceCode: "ref", rawText: "log")
            do {
                _ = try await api.uploadDebugLogBundle(bundle)
                XCTFail("expected HTTP error")
            } catch let error as DebugLogHTTPError {
                XCTAssertEqual(error.status, status)
            } catch { XCTFail("upload must preserve HTTP status, got \(error)") }
            XCTAssertEqual(transport.requests.count, 1)
            XCTAssertEqual(api.activeEndpoint, primary)
        }
    }

    func testAccountContextIsCheckedBeforeEveryChunk() async throws {
        let transport = MockPanelTransport([.respond(200, #"{"ok":true}"#)])
        let api = try client(transport)
        let bundle = try PendingDebugLogBundle(scope: "a", collection: collection, referenceCode: "ref",
            rawText: String(repeating: "x", count: DebugLogUploader.chunkBytes + 1))
        var validations = 0
        do {
            _ = try await api.uploadDebugLogBundle(bundle, validateContext: {
                validations += 1
                if validations == 2 { throw CancellationError() }
            })
            XCTFail("changed login context must stop subsequent chunks")
        } catch is CancellationError {} catch { XCTFail("\(error)") }
        XCTAssertEqual(transport.requests.count, 1)
        XCTAssertEqual(validations, 2)
    }

    @MainActor
    func testRetryThrottleAndAccountScopePreservePending() async throws {
        let store = DebugUploadMemoryStore()
        do {
            _ = try await store.coordinator().run(scope: "a", collection: collection,
                start: { (self.collection, "ref") }, collect: { "log" },
                send: { _ in throw PanelError.http(status: 429, message: "retry") }, completed: { _ in XCTFail() })
        } catch {}
        let pending = try XCTUnwrap(store.state().pending)
        for scope in ["a", "b"] {
            let result = try await store.coordinator().run(scope: scope, collection: collection,
                start: { XCTFail(); return (self.collection, "ref") }, collect: { XCTFail(); return "" },
                send: { _ in XCTFail("throttled or wrong account") }, completed: { _ in XCTFail() })
            XCTAssertNil(result)
            XCTAssertEqual(try store.state().pending, pending)
        }
        do {
            _ = try await store.coordinator().run(scope: "b", start: { XCTFail(); return (self.collection, "ref") },
                collect: { XCTFail(); return "" }, send: { _ in XCTFail() }, completed: { _ in XCTFail() })
            XCTFail("wrong account must not replace the snapshot")
        } catch DebugLogUploadError.pendingForAnotherAccount {} catch { XCTFail("\(error)") }
    }

    @MainActor
    func testExpiredOtherAccountPendingAllowsFreshManualAndPassiveUpload() async throws {
        for manual in [true, false] {
            let store = DebugUploadMemoryStore()
            let expired = DebugCollectionMeta(uuid: collection.uuid, status: "active",
                expiresAt: "2000-01-01T00:00:00Z", supportsUploadID: true)
            let old = try PendingDebugLogBundle(scope: "a", collection: expired,
                referenceCode: "old-ref", rawText: "account a evidence")
            var state = DebugLogUploadState()
            state.pending = old
            state.lastFailure = .retry
            state.nextAttemptAt = Date().addingTimeInterval(3600)
            store.data = try JSONEncoder().encode(state)
            let fresh = DebugCollectionMeta(uuid: UUID().uuidString, status: "active", supportsUploadID: true)
            var starts = 0
            var captures = 0
            var sends = 0
            var completions = 0

            let result = try await store.coordinator().run(scope: "b", collection: manual ? nil : fresh,
                start: {
                    starts += 1
                    XCTAssertNil(try store.state().pending, "retirement must be durable before starting B")
                    return (fresh, "new-ref")
                },
                collect: {
                    captures += 1
                    let retired = try store.state()
                    XCTAssertNil(retired.pending)
                    XCTAssertEqual(retired.records.last?.scope, "a")
                    XCTAssertEqual(retired.records.last?.collectionUUID, old.collection.uuid)
                    XCTAssertEqual(retired.records.last?.reason, "expired")
                    return "fresh account b log"
                },
                send: { bundle in
                    sends += 1
                    XCTAssertEqual(bundle.scope, "b")
                    XCTAssertEqual(bundle.collection.uuid, fresh.uuid)
                    XCTAssertNotEqual(bundle.uploadID, old.uploadID)
                    XCTAssertEqual(bundle.bytes, Data("fresh account b log".utf8))
                    XCTAssertNotEqual(bundle.bytes, old.bytes)
                    XCTAssertEqual(try store.state().pending, bundle)
                }, completed: { _ in completions += 1 })

            XCTAssertNotNil(result)
            XCTAssertEqual(starts, manual ? 1 : 0)
            XCTAssertEqual(captures, 1)
            XCTAssertEqual(sends, 1)
            XCTAssertEqual(completions, 1)
            XCTAssertNil(try store.state().pending)
            XCTAssertEqual(try store.state().records.map(\.reason), ["expired", "completed"])
            XCTAssertEqual(try store.state().records.map(\.scope), ["a", "b"])
        }
    }

    @MainActor
    func testExpiredSameAccountManualRequestStartsFreshWithoutExtraClick() async throws {
        let store = DebugUploadMemoryStore()
        let expired = DebugCollectionMeta(uuid: collection.uuid, status: "active",
            expiresAt: "2000-01-01T00:00:00Z", supportsUploadID: true)
        let old = try PendingDebugLogBundle(scope: "a", collection: expired,
            referenceCode: "old-ref", rawText: "old evidence")
        var state = DebugLogUploadState()
        state.pending = old
        store.data = try JSONEncoder().encode(state)
        let fresh = DebugCollectionMeta(uuid: UUID().uuidString, status: "active", supportsUploadID: true)
        var starts = 0
        var sends = 0

        let result = try await store.coordinator().run(scope: "a",
            start: {
                starts += 1
                XCTAssertNil(try store.state().pending)
                return (fresh, "fresh-ref")
            }, collect: { "fresh evidence" },
            send: { bundle in
                sends += 1
                XCTAssertEqual(bundle.scope, "a")
                XCTAssertEqual(bundle.collection.uuid, fresh.uuid)
                XCTAssertNotEqual(bundle.uploadID, old.uploadID)
                XCTAssertEqual(bundle.bytes, Data("fresh evidence".utf8))
            }, completed: { _ in })

        XCTAssertEqual(result?.referenceCode, "fresh-ref")
        XCTAssertEqual(starts, 1)
        XCTAssertEqual(sends, 1)
        XCTAssertNil(try store.state().pending)
        XCTAssertEqual(try store.state().records.map(\.reason), ["expired", "completed"])
    }

    @MainActor
    func testFailedCompletionWriteKeepsSameBundleForReplay() async throws {
        let store = DebugUploadMemoryStore()
        var writes = 0
        let coordinator = DebugLogUploadCoordinator(load: { try store.state() }, save: { state in
            writes += 1
            if writes == 2 { throw URLError(.cannotWriteToFile) }
            store.data = try JSONEncoder().encode(state)
        })
        var accepted: PendingDebugLogBundle?
        do {
            _ = try await coordinator.run(scope: "a", collection: collection,
                start: { (self.collection, "ref") }, collect: { "log" },
                send: { accepted = $0 }, completed: { _ in XCTFail("completion was not durable") })
            XCTFail("expected storage failure")
        } catch {}
        XCTAssertEqual(try store.state().pending, accepted)
        _ = try await store.coordinator().run(scope: "a", start: { XCTFail(); return (self.collection, "ref") },
            collect: { XCTFail(); return "new log" }, send: { XCTAssertEqual($0, accepted) }, completed: { _ in })
        XCTAssertNil(try store.state().pending)
    }

    @MainActor
    func testCancellationDuringCompletionWriteRestoresPending() async throws {
        let store = DebugUploadMemoryStore()
        let writingCompletion = expectation(description: "writing completion")
        var resumeWrite: CheckedContinuation<Void, Never>?
        let coordinator = DebugLogUploadCoordinator(load: { try store.state() }, save: { state in
            store.data = try JSONEncoder().encode(state)
            if state.pending == nil {
                await withCheckedContinuation { continuation in
                    resumeWrite = continuation
                    writingCompletion.fulfill()
                }
            }
        })
        let task = Task { @MainActor in
            try await coordinator.run(scope: "a", collection: collection,
                start: { (self.collection, "ref") }, collect: { "log" },
                send: { _ in }, completed: { _ in XCTFail("cancelled work must not complete") })
        }
        await fulfillment(of: [writingCompletion], timeout: 2)
        task.cancel()
        resumeWrite?.resume()
        do {
            _ = try await task.value
            XCTFail("expected cancellation")
        } catch is CancellationError {} catch { XCTFail("\(error)") }
        XCTAssertNotNil(try store.state().pending)
        XCTAssertTrue(try store.state().records.isEmpty)
    }

    @MainActor
    func testCorruptStateFailsClosedWithoutReplacingPendingIdentity() async throws {
        let store = DebugUploadMemoryStore()
        store.data = Data("invalid json".utf8)
        do {
            _ = try await store.coordinator().run(scope: "a", start: { XCTFail(); return (self.collection, "ref") },
                collect: { XCTFail(); return "new snapshot" }, send: { _ in XCTFail() }, completed: { _ in XCTFail() })
            XCTFail("expected decode failure")
        } catch {}
        XCTAssertEqual(store.data, Data("invalid json".utf8))
    }

    @MainActor
    func testTerminalAndCompletionHistoryIsBounded() async throws {
        let store = DebugUploadMemoryStore()
        for _ in 0..<40 {
            let next = DebugCollectionMeta(uuid: UUID().uuidString, status: "active", supportsUploadID: true)
            _ = try await store.coordinator().run(scope: "a", collection: next,
                start: { (next, "ref") }, collect: { "log" }, send: { _ in }, completed: { _ in })
        }
        XCTAssertEqual(try store.state().records.count, 32)
        XCTAssertNil(try store.state().pending)
    }

    @MainActor
    func testOldPanelManualErrorAndPassiveSkipBeforeCapture() async throws {
        let store = DebugUploadMemoryStore()
        let old = DebugCollectionMeta(uuid: collection.uuid, status: "active")
        let passive = try await store.coordinator().run(scope: "a", collection: old,
            start: { (old, "ref") }, collect: { XCTFail(); return "" },
            send: { _ in XCTFail() }, completed: { _ in XCTFail() })
        XCTAssertNil(passive)
        do {
            _ = try await store.coordinator().run(scope: "a", start: { (old, "ref") },
                collect: { XCTFail(); return "" }, send: { _ in XCTFail() }, completed: { _ in XCTFail() })
            XCTFail("expected unsupported")
        } catch DebugLogUploadError.unsupportedPanel {} catch { XCTFail("\(error)") }
        XCTAssertNil(store.data)
    }

    @MainActor
    func testCompletedMarkerAndFreshManualSnapshotUseDifferentUploadIDs() async throws {
        let store = DebugUploadMemoryStore()
        var ids: [String] = []
        for _ in 0..<2 {
            _ = try await store.coordinator().run(scope: "a", start: { (self.collection, "ref") },
                collect: { "log" }, send: { ids.append($0.uploadID) }, completed: { _ in })
        }
        XCTAssertNotEqual(ids[0], ids[1])
        let passive = try await store.coordinator().run(scope: "a", collection: collection,
            start: { (self.collection, "ref") }, collect: { XCTFail(); return "" },
            send: { _ in XCTFail() }, completed: { _ in XCTFail() })
        XCTAssertNil(passive)
        let legacy = try await DebugUploadMemoryStore().coordinator().run(
            scope: "a", collection: collection, lastCompletedCollectionUUID: collection.uuid,
            start: { (self.collection, "ref") }, collect: { XCTFail(); return "" },
            send: { _ in XCTFail() }, completed: { _ in XCTFail() })
        XCTAssertNil(legacy)
    }
}

@MainActor
private final class DebugUploadMemoryStore {
    var data: Data?

    func state() throws -> DebugLogUploadState {
        guard let data else { return DebugLogUploadState() }
        return try JSONDecoder().decode(DebugLogUploadState.self, from: data)
    }

    func coordinator() -> DebugLogUploadCoordinator {
        DebugLogUploadCoordinator(load: { try self.state() }, save: { self.data = try JSONEncoder().encode($0) })
    }
}
