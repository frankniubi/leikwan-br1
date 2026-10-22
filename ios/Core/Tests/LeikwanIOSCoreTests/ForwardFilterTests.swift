import XCTest
@testable import LeikwanIOSCore

final class ForwardFilterTests: XCTestCase {
    private func contract(
        backend: String,
        clientMode: String = "",
        rawMode: String = "",
        bondingIngress: String? = nil,
        topIngress: String? = nil,
        helperOnly: Bool = false
    ) -> ForwardContract {
        var raw: [String: Any] = [
            "forward_id": 1,
            "service_name": "f",
            "backend_mode": backend,
            "client_mode": clientMode,
            "mode": rawMode,
            "route_rule_helper_only": helperOnly,
        ] as [String: Any]
        if let top = topIngress { raw["bond_ingress_mode"] = top }
        if let ingress = bondingIngress { raw["bonding"] = ["ingress_mode": ingress] as [String: Any] }
        else if topIngress != nil { raw["bonding"] = [String: Any]() }
        return ForwardContractParser.parse(raw)!
    }

    private func reason(_ c: ForwardContract) -> IOSForwardFilterReason? {
        switch IOSForwardFilter.classify(c) {
        case .success: return nil
        case .failure(let rejection): return rejection.reason
        }
    }

    private func kind(_ c: ForwardContract) -> IOSForwardKind? {
        switch IOSForwardFilter.classify(c) {
        case .success(let forward): return forward.kind
        case .failure: return nil
        }
    }

    func testSupportedModes() {
        XCTAssertEqual(kind(contract(backend: "ultra")), .ultra)
        XCTAssertEqual(kind(contract(backend: "ULTRA", clientMode: "whatever")), .ultra)
        XCTAssertEqual(kind(contract(backend: "current_mode", clientMode: "bond", bondingIngress: "plain_tcp_mux")), .bondPlainTcpMux)
        XCTAssertEqual(kind(contract(backend: "current_mode", clientMode: "bond", rawMode: "bond", bondingIngress: "plain_tcp_mux")), .bondPlainTcpMux)
        // Top-level bond_ingress_mode is the fallback for bonding.ingress_mode.
        XCTAssertEqual(kind(contract(backend: "current_mode", clientMode: "bond", topIngress: "plain_tcp_mux")), .bondPlainTcpMux)
    }

    func testFilteredMatrix() {
        XCTAssertEqual(reason(contract(backend: "current_mode", clientMode: "single")), .currentModeSingle)
        XCTAssertEqual(reason(contract(backend: "current_mode", clientMode: "bond", bondingIngress: "wss_path_mux")), .bondWssPathMux(explicit: true))
        XCTAssertEqual(reason(contract(backend: "current_mode", clientMode: "bond")), .bondWssPathMux(explicit: false))
        XCTAssertEqual(reason(contract(backend: "current_mode", clientMode: "bond", bondingIngress: "quic_mux")), .bondIngressUnsupported("quic_mux"))
        XCTAssertEqual(reason(contract(backend: "current_mode", clientMode: "race")), .unsupportedClientMode("race"))
        XCTAssertEqual(reason(contract(backend: "current_mode", clientMode: "mptcp")), .unsupportedClientMode("mptcp"))
        XCTAssertEqual(reason(contract(backend: "current_mode", clientMode: "")), .unsupportedClientMode(""))
        XCTAssertEqual(reason(contract(backend: "current_mode", clientMode: "bond", rawMode: "single", bondingIngress: "plain_tcp_mux")),
                       .clientModeMismatch(rawMode: "single", clientMode: "bond"))
        XCTAssertEqual(reason(contract(backend: "leigod_mode", clientMode: "bond")), .backendModeUnsupported("leigod_mode"))
        XCTAssertEqual(reason(contract(backend: "")), .backendModeUnsupported(""))
        XCTAssertEqual(reason(contract(backend: "mystery")), .backendModeUnsupported("mystery"))
        XCTAssertEqual(reason(contract(backend: "ultra", helperOnly: true)), .routeRuleHelperOnly)
    }

    func testCatalogCountsHiddenForwardsAndKeepsSelection() {
        let raw: [Any] = [
            Fixtures.bondForwardJSON(),
            ["forward_id": 2, "backend_mode": "current_mode", "client_mode": "single"] as [String: Any],
            ["forward_id": 3, "backend_mode": "current_mode", "client_mode": "bond", "bonding": ["ingress_mode": "wss_path_mux"]] as [String: Any],
            ["forward_id": 4, "backend_mode": "ultra"] as [String: Any],
            ["forward_id": 5, "backend_mode": "ultra", "route_rule_helper_only": true] as [String: Any],
            ["service_name": "no id"] as [String: Any],
            "not an object",
        ]
        let catalog = IOSForwardFilter.buildCatalog(rawForwards: raw, selectedForwardId: 2)
        XCTAssertEqual(catalog.supported.map { $0.forwardId }, [12, 4])
        XCTAssertEqual(catalog.hidden.count, 5)
        XCTAssertEqual(catalog.unsupportedHiddenCount, 2)
        XCTAssertNil(catalog.selectedForwardId, "selection of a hidden forward is dropped")
        XCTAssertEqual(IOSForwardFilter.buildCatalog(rawForwards: raw, selectedForwardId: 4).selectedForwardId, 4)
        let bond = catalog.supportedForward(id: 12)!
        XCTAssertEqual(bond.contract.mode, .bond)
        XCTAssertEqual(bond.bypassHosts, ["1.2.3.4", "edge.example.com", "cdn.example.com"])
        XCTAssertEqual(bond.protectedConnectivity.ipCidrs, ["1.2.3.4/32"])
        XCTAssertEqual(bond.protectedConnectivity.hosts, ["edge.example.com", "cdn.example.com"])
        XCTAssertEqual(bond.methodDisplay, "Host")
    }

    func testLenientParsingOfStringIntsAndBools() {
        let raw = Fixtures.json("""
        {"forward_id":"77","listen_port":" 4000 ","speed_limit_mbps":12.7,"aggregation_enabled":"yes",
         "sidecar_required":1,"route_rule_helper_only":"off","backend_mode":"current_mode","client_mode":"bond",
         "bond_ingress_mode":"plain_tcp_mux","service_name":"  Spaced  ","sni":null,
         "endpoints":[{"id":"9","host":"h.example","port":"443","supports_quic_leg":"true","dynamic_priority":"5"},
                      {"host":"","port":1},{"host":"x","port":0},{"host":"y","port":"abc"}],
         "bonding":{"chunk_size":"1024","quic_enabled":"0","adaptive_join_timeout":false,"ws_priority":3}}
        """)
        let c = ForwardContractParser.parse(raw)!
        XCTAssertEqual(c.forwardId, 77)
        XCTAssertEqual(c.listenPort, 4000)
        XCTAssertEqual(c.speedLimitMbps, 12)
        XCTAssertTrue(c.aggregationEnabled)
        XCTAssertTrue(c.sidecarRequired)
        XCTAssertFalse(c.routeRuleHelperOnly)
        XCTAssertEqual(c.serviceName, "Spaced")
        XCTAssertEqual(c.sni, "")
        XCTAssertEqual(c.endpoints.count, 1)
        XCTAssertEqual(c.endpoints[0].endpointId, 9)
        XCTAssertEqual(c.endpoints[0].port, 443)
        XCTAssertTrue(c.endpoints[0].supportsQuicLeg)
        XCTAssertEqual(c.endpoints[0].dynamicPriority, 5)
        XCTAssertEqual(c.bonding?.chunkSize, 1024)
        XCTAssertEqual(c.bonding?.quicEnabled, false)
        XCTAssertEqual(c.bonding?.adaptiveJoinTimeout, false)
        XCTAssertEqual(c.bonding?.wsPriority, 3)
        XCTAssertEqual(c.bonding?.ingressMode, "plain_tcp_mux", "falls back to top-level bond_ingress_mode")
        XCTAssertEqual(c.bondIngressMode, "plain_tcp_mux")
    }

    func testMissingForwardIdIsUnparseable() {
        XCTAssertNil(ForwardContractParser.parse(["service_name": "x"] as [String: Any]))
        XCTAssertNil(ForwardContractParser.parse(["forward_id": true] as [String: Any]))
        XCTAssertNil(ForwardContractParser.parse([1, 2] as [Any]))
    }

    func testForwardModeNormalize() {
        XCTAssertEqual(ForwardMode.normalize("Aggregated"), .bond)
        XCTAssertEqual(ForwardMode.normalize("failover"), .race)
        XCTAssertEqual(ForwardMode.normalize("multipath-tcp"), .mptcp)
        XCTAssertEqual(ForwardMode.normalize(nil), .unknown)
    }

    func testNativeOutboundRulesAreSupportedButNotBindable() {
        let raw: [Any] = [
            ["forward_id": 1, "backend_mode": "ultra", "native_outbound": true] as [String: Any],
            ["forward_id": 2, "backend_mode": "ultra", "native_outbound": "0"] as [String: Any],
            ["forward_id": 3, "backend_mode": "ultra"] as [String: Any],
            // The flag only matters on Ultra forwards (Windows IsNativeOutboundRule).
            ["forward_id": 4, "backend_mode": "current_mode", "client_mode": "bond",
             "bond_ingress_mode": "plain_tcp_mux", "bonding": [String: Any](), "native_outbound": true] as [String: Any],
        ]
        let catalog = IOSForwardFilter.buildCatalog(rawForwards: raw, selectedForwardId: nil)
        XCTAssertEqual(catalog.supported.map { $0.forwardId }, [1, 2, 3, 4])
        XCTAssertEqual(catalog.supported.filter { $0.isNativeOutboundRule }.map { $0.forwardId }, [1])
        XCTAssertEqual(catalog.bindableForwards.map { $0.forwardId }, [2, 3, 4])
        XCTAssertNil(catalog.bindableForward(id: 1))
        XCTAssertNotNil(catalog.supportedForward(id: 1))
        XCTAssertNotNil(catalog.bindableForward(id: 4))
    }
}
