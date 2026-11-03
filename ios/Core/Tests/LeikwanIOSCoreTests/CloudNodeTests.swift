import XCTest
@testable import LeikwanIOSCore

final class CloudNodeTests: XCTestCase {
    private let credentials = PanelCredentials(clientId: 12345, agentToken: "agent-token", installId: "install-abc",
                                               deviceName: "iPhone", appVersion: "1.0.0")

    private func ss(_ id: Int64, server: String = "ss.example.com", port: Int = 8388, password: String = "pw",
                    name: String = "SS", hostedId: Int64? = nil) -> ProxyNode {
        return ProxyNode(id: id, name: name, server: server, port: port,
                         settings: .shadowsocks(ShadowsocksSettings(method: "aes-128-gcm", password: password)),
                         hostedId: hostedId)
    }

    private func listingJSON(nodes: [String], pairings: String = "[]", revision: String = "rev1", extra: String = "") -> String {
        return #"{"ok":true,"revision":""# + revision + #"","nodes":["# + nodes.joined(separator: ",") + #"],"pairings":"# + pairings + extra + "}"
    }

    private func nodeJSON(id: Int64, uri: String, name: String = "", proto: String = "shadowsocks") -> String {
        return #"{"id":\#(id),"name":"\#(name)","protocol":"\#(proto)","server":"x","port":1,"uri":"\#(uri)","version":1,"source":"device","updated_at":"2026-10-12 00:00:00"}"#
    }

    // MARK: Share links / identity

    func testShareLinkRoundTripsThroughParser() throws {
        var node = ss(1, name: "Tokyo 1")
        node.settings = .shadowsocks(ShadowsocksSettings(method: "2022-blake3-aes-128-gcm", password: "a+b/c==",
                                                         plugin: "simple-obfs", pluginOpts: "obfs=http", udpOverTcp: true))
        let uri = try XCTUnwrap(ProxyShareLink.format(node))
        XCTAssertTrue(uri.hasPrefix("ss://"))
        XCTAssertTrue(uri.contains("plugin=obfs-local%3Bobfs%3Dhttp"))
        XCTAssertTrue(uri.contains("uot=1"))
        XCTAssertTrue(uri.hasSuffix("#Tokyo%201"))
        let parsed = try XCTUnwrap(ProxyUriParser.parse(uri))
        XCTAssertEqual(parsed.name, "Tokyo 1")
        XCTAssertEqual(parsed.server, "ss.example.com")
        XCTAssertEqual(ProxyNodeIdentity.key(parsed), ProxyNodeIdentity.key(node))

        let socks = ProxyNode(id: 2, name: "S", server: "2001:db8::1", port: 1080,
                              settings: .socks(SocksSettings(username: "u@x", password: "p:w")))
        let socksUri = try XCTUnwrap(ProxyShareLink.format(socks))
        XCTAssertEqual(socksUri, "socks5://u%40x:p%3Aw@[2001:db8::1]:1080#S")
        let back = try XCTUnwrap(ProxyUriParser.parse(socksUri))
        XCTAssertEqual(back.server, "2001:db8::1")
        guard case .socks(let settings) = back.settings else { return XCTFail("socks expected") }
        XCTAssertEqual(settings.username, "u@x")
        XCTAssertEqual(settings.password, "p:w")
    }

    func testCloudUploadableAndIdentity() {
        XCTAssertTrue(ProxyShareLink.isCloudUploadable(ss(1)))
        var legacy = ss(2)
        legacy.settings = .shadowsocks(ShadowsocksSettings(method: "rc4-md5", password: "pw"))
        XCTAssertFalse(ProxyShareLink.isCloudUploadable(legacy))
        let halfAuth = ProxyNode(id: 3, name: "", server: "h.example", port: 1, settings: .socks(SocksSettings(username: nil, password: "p")))
        XCTAssertFalse(ProxyShareLink.isCloudUploadable(halfAuth))
        // Name and case of the host do not change the identity; the password does.
        XCTAssertEqual(ProxyNodeIdentity.key(ss(1, server: "SS.Example.com", name: "a")), ProxyNodeIdentity.key(ss(9, name: "b")))
        XCTAssertNotEqual(ProxyNodeIdentity.key(ss(1)), ProxyNodeIdentity.key(ss(1, password: "other")))
        let incoming = [ss(10), ss(11, password: "new")]
        XCTAssertEqual(ProxyNodeIdentity.withoutHostedDuplicates(incoming, existing: [ss(1, hostedId: 5)]).map { $0.id }, [11])
    }

    // MARK: API client

    func testListAndWritesUseHostedNodeEndpoints() async throws {
        let uri = try XCTUnwrap(ProxyShareLink.format(ss(1)))
        let body = listingJSON(nodes: [nodeJSON(id: 7, uri: uri, name: "SS")], pairings: #"[{"forward_id":12,"node_id":7,"source":"explicit"}]"#,
                               extra: #","node_id":7,"created":true"#)
        let transport = MockPanelTransport([.respond(200, body), .respond(200, body), .respond(200, body), .respond(200, body)])
        let client = PanelClient(endpoint: Fixtures.panel, credentials: credentials, transport: transport)

        let listing = try await client.listHostedNodes()
        XCTAssertEqual(listing.revision, "rev1")
        XCTAssertEqual(listing.nodes.map { $0.id }, [7])
        XCTAssertEqual(listing.nodes.first?.nodeProtocol, "shadowsocks")
        XCTAssertEqual(listing.pairings, [HostedNodePairingDTO(forwardId: 12, nodeId: 7, source: "explicit")])

        let created = try await client.createHostedNode(uri: uri, name: "SS", pairForwardId: 12)
        XCTAssertEqual(created.nodeId, 7)
        XCTAssertEqual(created.created, true)
        _ = try await client.pairHostedNode(forwardId: 12, nodeId: nil)
        _ = try await client.deleteHostedNode(id: 7)

        let requests = transport.requests
        XCTAssertEqual(requests[0].httpMethod, "GET")
        XCTAssertEqual(requests[0].url?.absoluteString, "https://panel.example.com/api/client/hosted-nodes")
        XCTAssertEqual(requests[0].value(forHTTPHeaderField: "X-Device-Platform"), "ios")
        XCTAssertEqual(requests[1].httpMethod, "POST")
        let sent = Fixtures.parseJSON(String(decoding: requests[1].httpBody ?? Data(), as: UTF8.self))
        XCTAssertEqual(sent["uri"] as? String, uri)
        XCTAssertEqual(sent["pair_forward_id"] as? Int, 12)
        XCTAssertEqual(requests[2].httpMethod, "PUT")
        XCTAssertEqual(requests[2].url?.path, "/api/client/hosted-node-pairings/12")
        XCTAssertEqual(String(decoding: requests[2].httpBody ?? Data(), as: UTF8.self), "{}", "no node_id clears the pairing")
        XCTAssertEqual(requests[3].httpMethod, "DELETE")
        XCTAssertEqual(requests[3].url?.path, "/api/client/hosted-nodes/7")
    }

    func testErrorsBecomeHostedNodeCodes() async {
        let cases: [(Int, String, String, String?)] = [
            (422, #"{"ok":false,"error":"hosted_node_invalid","field":"method"}"#, "hosted_node_invalid", nil),
            (409, #"{"ok":false,"error":"hosted_node_limit_reached","limit":32}"#, "hosted_node_limit_reached", nil),
            (404, "<html>not found</html>", "hosted_nodes_unsupported", nil),
            // The panel refuses platforms it does not trust for hosted nodes: never a session kill here.
            (403, #"{"ok":false,"error":"device_untrusted"}"#, "device_untrusted", nil),
            (429, #"{"error":"client_online_limit_reached"}"#, PanelError.deviceLimitCode, nil),
            (200, #"{"ok":false,"error":"hosted_nodes_unavailable"}"#, "hosted_nodes_unavailable", nil),
        ]
        for (status, body, code, _) in cases {
            let client = PanelClient(endpoint: Fixtures.panel, credentials: credentials,
                                     transport: MockPanelTransport([.respond(status, body)]))
            do {
                _ = try await client.listHostedNodes()
                XCTFail("expected error for \(status)")
            } catch let error as HostedNodeError {
                XCTAssertEqual(error.code, code)
                XCTAssertEqual(HostedNodeError.code(for: error), code)
            } catch {
                XCTFail("unexpected \(error)")
            }
        }
        XCTAssertEqual(HostedNodeError.userMessage("hosted_node_duplicate"), "The same node is already in the cloud")
    }

    // MARK: Merge

    func testApplyInsertsUpdatesAdoptsAndDeletes() throws {
        let uriA = try XCTUnwrap(ProxyShareLink.format(ss(0, server: "a.example", name: "A-cloud")))
        let uriB = try XCTUnwrap(ProxyShareLink.format(ss(0, server: "b.example", name: "B")))
        let uriNew = try XCTUnwrap(ProxyShareLink.format(ss(0, server: "new.example", name: "New")))
        let vless = "vless://11111111-2222-3333-4444-555555555555@v.example:443?encryption=none&type=tcp&security=tls#V"

        var hostedA = ss(1, server: "a.example", password: "old", name: "A", hostedId: 100)
        hostedA.enabled = false
        let duplicateA = ss(2, server: "a.example", name: "A copy", hostedId: 100)
        let localB = ss(3, server: "b.example", name: "B local")
        let staleHosted = ss(4, server: "gone.example", hostedId: 400)
        let localOnly = ss(5, server: "mine.example")

        let listing = HostedNodesListing(
            revision: "r2",
            nodes: [
                HostedNodeDTO(id: 100, name: "A-cloud", nodeProtocol: "shadowsocks", server: "a.example", port: 8388, uri: uriA),
                HostedNodeDTO(id: 200, name: "", nodeProtocol: "shadowsocks", server: "b.example", port: 8388, uri: uriB),
                HostedNodeDTO(id: 300, name: "New", nodeProtocol: "shadowsocks", server: "new.example", port: 8388, uri: uriNew),
                HostedNodeDTO(id: 900, name: "V", nodeProtocol: "vless", server: "v.example", port: 443, uri: vless),
            ],
            pairings: [
                HostedNodePairingDTO(forwardId: 12, nodeId: 200, source: "explicit"),
                HostedNodePairingDTO(forwardId: 13, nodeId: 900, source: "target_match"),
                HostedNodePairingDTO(forwardId: 14, nodeId: 555, source: "explicit"),
            ]
        )
        let previous = CloudNodeSyncState(clientId: 12345, revision: "r1",
                                          pairings: [CloudPairing(forwardId: 13, hostedNodeId: 900, localNodeId: nil, source: "target_match")])
        let result = CloudNodeMerge.apply(listing, to: [hostedA, duplicateA, localB, staleHosted, localOnly],
                                          previous: previous, clientId: 12345, now: Fixtures.now)

        XCTAssertEqual(result.removedLocalIds, [2, 4])
        let byId = Dictionary(uniqueKeysWithValues: result.nodes.map { ($0.id, $0) })
        XCTAssertEqual(Set(byId.keys), [1, 3, 5, 6])
        // Existing hosted row: panel copy wins, device-local `enabled` stays.
        XCTAssertEqual(byId[1]?.name, "A-cloud")
        XCTAssertEqual(byId[1]?.enabled, false)
        XCTAssertEqual(byId[1].map { ProxyNodeIdentity.key($0) }, ProxyNodeIdentity.key(ss(0, server: "a.example")))
        // Identical local node adopted (keeps its id).
        XCTAssertEqual(byId[3]?.hostedId, 200)
        XCTAssertEqual(byId[5]?.hostedId, nil, "local-only nodes are untouched")
        XCTAssertEqual(byId[6]?.hostedId, 300)
        XCTAssertEqual(byId[6]?.name, "New")

        XCTAssertEqual(result.state.revision, "r2")
        XCTAssertEqual(result.state.syncedAt, Fixtures.now)
        XCTAssertEqual(result.state.unsupportedNodes.map { $0.id }, [900], "VLESS kept, read-only")
        XCTAssertEqual(result.state.pairings, [
            CloudPairing(forwardId: 12, hostedNodeId: 200, localNodeId: 3, source: "explicit"),
            CloudPairing(forwardId: 13, hostedNodeId: 900, localNodeId: nil, source: "target_match"),
        ])
        XCTAssertEqual(result.changedPairings.map { $0.forwardId }, [12], "unchanged pairings are not re-applied")
    }

    func testRevisionDecisionLinkAndBatches() {
        let state = CloudNodeSyncState(clientId: 1, revision: "r")
        XCTAssertEqual(CloudNodeMerge.decide(revision: nil, clientId: 1, state: state, nodes: []), .upToDate)
        XCTAssertEqual(CloudNodeMerge.decide(revision: "r", clientId: 1, state: state, nodes: []), .upToDate)
        XCTAssertEqual(CloudNodeMerge.decide(revision: "", clientId: 1, state: state, nodes: [ss(1)]), .recordEmpty)
        XCTAssertEqual(CloudNodeMerge.decide(revision: "", clientId: 1, state: state, nodes: [ss(1, hostedId: 3)]), .fetch)
        XCTAssertEqual(CloudNodeMerge.decide(revision: "r", clientId: 2, state: state, nodes: []), .fetch)

        let linked = CloudNodeMerge.linkUploaded(localId: 1, hostedId: 50, in: [ss(1), ss(2)])
        XCTAssertEqual(linked.first?.hostedId, 50)
        let deduped = CloudNodeMerge.linkUploaded(localId: 2, hostedId: 50, in: linked)
        XCTAssertEqual(deduped.map { $0.id }, [1], "an upload that maps to an already hosted row drops the copy")

        var many: [ProxyNode] = (1...40).map { ss(Int64($0), server: "h\($0).example") }
        many.append(ss(41, hostedId: 9))
        var bad = ss(42)
        bad.settings = .shadowsocks(ShadowsocksSettings(method: "rc4-md5", password: "x"))
        many.append(bad)
        let plan = CloudNodeMerge.bulkUploadBatches(many)
        XCTAssertEqual(plan.batches.map { $0.count }, [32, 8])
        XCTAssertEqual(plan.unsupported, 1)
        XCTAssertEqual(plan.batches[0][0].ref, "1")
    }

    func testPullRevisionField() {
        func revision(_ body: String) -> String? {
            return PullConfigsResponse.parse(Data(body.utf8))?.hostedNodesRevision
        }
        XCTAssertNil(revision(#"{"ok":true,"client":{"id":1}}"#), "older panel: no field")
        XCTAssertEqual(revision(#"{"ok":true,"client":{"id":1,"hosted_nodes_revision":""}}"#), "")
        XCTAssertEqual(revision(#"{"ok":true,"client":{"id":1,"hosted_nodes_revision":"abc"}}"#), "abc")
    }

    func testStateAndPreferencesDecodeOldFiles() throws {
        let state = try JSONDecoder().decode(CloudNodeSyncState.self, from: Data(#"{"clientId":3}"#.utf8))
        XCTAssertEqual(state, CloudNodeSyncState(clientId: 3))
        let prefs = try JSONDecoder().decode(BindingPreferences.self, from: Data(#"{"enabled":["f:12","bogus","n:3"],"defaultBinding":"n:3"}"#.utf8))
        XCTAssertEqual(prefs.enabled, [.forward(12), .direct(nodeId: 3)])
        XCTAssertEqual(prefs.defaultBinding, .direct(nodeId: 3))
        let encoded = try JSONEncoder().encode(prefs)
        XCTAssertEqual(try JSONDecoder().decode(BindingPreferences.self, from: encoded), prefs)
        // Node files written before cloud sync decode with hostedId nil.
        let old = try JSONDecoder().decode(ProxyNode.self, from: try JSONEncoder().encode(ss(1)))
        XCTAssertNil(old.hostedId)
        let rule = try JSONDecoder().decode(CustomRoutingRule.self, from: Data(#"{"id":"c","type":"DOMAIN","value":"a.com","outcome":"proxy","enabled":true}"#.utf8))
        XCTAssertNil(rule.targetNodeId)
    }
}
