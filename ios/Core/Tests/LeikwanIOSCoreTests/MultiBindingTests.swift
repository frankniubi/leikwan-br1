import XCTest
@testable import LeikwanIOSCore

final class MultiBindingTests: XCTestCase {
    private let ssNode = ProxyNode(id: 1, name: "SS", server: "ss.example.com", port: 8388,
                                   settings: .shadowsocks(ShadowsocksSettings(method: "aes-128-gcm", password: "pw")))
    private let socksNode = ProxyNode(id: 2, name: "SOCKS", server: "5.6.7.8", port: 1080,
                                      settings: .socks(SocksSettings(username: "u", password: "p")))
    private let thirdNode = ProxyNode(id: 3, name: "Direct", server: "9.9.9.9", port: 443,
                                      settings: .socks(SocksSettings()))

    private let environment = MultiBindingEnvironment(runtimeDirectory: "/rt", panelId: "https://v3.leikwanhost.com",
                                                      clientId: 5, hasPanelSession: true, now: Fixtures.now)

    private func supported(_ contract: ForwardContract) -> IOSSupportedForward {
        guard case .success(let forward) = IOSForwardFilter.classify(contract) else { fatalError("fixture not supported") }
        return forward
    }

    private var bondForward: IOSSupportedForward {
        return supported(ForwardContractParser.parse(Fixtures.bondForwardJSON())!)
    }

    /// Ultra forward 13 with the same listen port as the bond forward (31000) to force a collision.
    private var ultraForward: IOSSupportedForward {
        let raw: [String: Any] = [
            "forward_id": 13,
            "service_name": "Ultra HK",
            "backend_mode": "ultra",
            "mode": "ultra",
            "transport": "tcp",
            "listen_port": 31000,
            "service_status": "active",
            "target": Fixtures.target,
            "ultra_access": Fixtures.ultraAccess(forwardId: 13),
        ]
        return supported(ForwardContractParser.parse(raw)!)
    }

    private func outbounds(_ plan: MultiBindingPlan) -> [[String: Any]] {
        return Fixtures.parseJSON(plan.singbox.configJSON)["outbounds"] as? [[String: Any]] ?? []
    }

    private func route(_ plan: MultiBindingPlan) -> [String: Any] {
        return Fixtures.parseJSON(plan.singbox.configJSON)["route"] as? [String: Any] ?? [:]
    }

    func testLegacySelectionMatchesSingleForwardPlan() throws {
        let resolved = BindingResolver.resolve(preferences: BindingPreferences(), forwards: [bondForward], nodes: [ssNode, socksNode],
                                               cloud: .empty, legacyForwardId: 12, legacyNodeId: 1)
        XCTAssertEqual(resolved.active.map { $0.id }, [.forward(12)])
        XCTAssertEqual(resolved.active.first?.nodeSource, .selection)
        let builder = SingboxConfigBuilder(settings: SingboxSettings(cacheFilePath: "/c.db"))
        let snapshot = builder.routingEngine.compile()
        let plan = try MultiBindingPlanner.plan(resolved: resolved, environment: environment, builder: builder, routing: snapshot)

        // Same sing-box config and runtimes as the pre-multi-binding single plan.
        let strategy = BondStrategyFile.make(directory: "/rt", panelId: "https://v3.leikwanhost.com", clientId: 5, forwardId: 12)
        let legacyPlan = try BondPlanBuilder.buildPlan(forward: bondForward.contract, strategyFile: strategy)
        let legacy = try builder.build(proxyNodes: [ssNode], runtime: .bond(legacyPlan), routing: snapshot, connectionMode: .services)
        XCTAssertEqual(plan.singbox.configJSON, legacy.configJSON)
        XCTAssertEqual(plan.runtimes.map { $0.arguments }, legacyPlan.invocations.map { $0.arguments })
        XCTAssertEqual(plan.runtimes.map { $0.name }, ["fwd-12-ingress-0", "fwd-12-ingress-1", "fwd-12-bond-2"])
        XCTAssertEqual(plan.runtimes.last?.files, [strategy])
        XCTAssertEqual(plan.bindings.first?.isDefault, true)
        XCTAssertEqual(plan.bindings.first?.localPort, 31000)
    }

    func testTwoForwardsRunTogetherWithDistinctPortsAndTargets() throws {
        var preferences = BindingPreferences(enabled: [.forward(12), .forward(13)], defaultBinding: .forward(13))
        preferences.assign(nodeId: 1, forwardId: 12)
        preferences.assign(nodeId: 2, forwardId: 13)
        let resolved = BindingResolver.resolve(preferences: preferences, forwards: [bondForward, ultraForward],
                                               nodes: [ssNode, socksNode], cloud: .empty)
        XCTAssertEqual(resolved.active.map { $0.id }, [.forward(13), .forward(12)], "default first")

        let builder = SingboxConfigBuilder()
        let snapshot = builder.routingEngine.compile(
            customRules: [CustomRoutingRule(id: "c1", type: .domainSuffix, value: "t.me", outcome: .proxy, targetNodeId: 1),
                          CustomRoutingRule(id: "c2", type: .domainSuffix, value: "x.com", outcome: .proxy, targetNodeId: 99)],
            appPresetRules: [RoutingRule(id: "app-youtube", kind: .appPreset, matchValue: "youtube", outcome: .tunnel, targetNodeId: 1),
                             RoutingRule(id: "app-netflix", kind: .appPreset, matchValue: "netflix", outcome: .tunnel)]
        )
        let plan = try MultiBindingPlanner.plan(resolved: resolved, environment: environment, builder: builder, routing: snapshot)

        // Ultra (default, planned first) keeps 31000; the bond listener moves to 31001.
        XCTAssertEqual(plan.bindings.map { $0.localPort }, [31000, 31001])
        XCTAssertEqual(plan.ultra.map { $0.forwardId }, [13])
        XCTAssertEqual(plan.ultra.first?.listenPort, 31000)
        XCTAssertEqual(plan.ultra.first?.controlSocketPath, "/rt/u13.sock")
        XCTAssertTrue(plan.runtimes.contains { $0.name == "fwd-13-ultra" })
        let bond = try XCTUnwrap(plan.runtimes.first { $0.role == .bond })
        XCTAssertTrue(bond.arguments.contains("127.0.0.1:31001"))
        let ultraConfig = Fixtures.parseJSON(try XCTUnwrap(plan.runtimes.first { $0.role == .ultra }?.configJSON))
        XCTAssertEqual(ultraConfig["listen_port"] as? Int, 31000)

        let out = outbounds(plan)
        let byTag = Dictionary(uniqueKeysWithValues: out.compactMap { item -> (String, [String: Any])? in
            guard let tag = item["tag"] as? String else { return nil }
            return (tag, item)
        })
        XCTAssertEqual(byTag["proxy-2"]?["server_port"] as? Int, 31000)
        XCTAssertEqual(byTag["proxy-1"]?["server_port"] as? Int, 31001)
        XCTAssertEqual(byTag["proxy"]?["default"] as? String, "proxy-2", "selector defaults to the default binding")
        XCTAssertEqual(route(plan)["final"] as? String, "proxy-2")

        let rules = route(plan)["rules"] as? [[String: Any]] ?? []
        XCTAssertEqual(rules.first { ($0["domain_suffix"] as? [String]) == ["t.me"] }?["outbound"] as? String, "proxy-1")
        XCTAssertEqual(rules.first { ($0["domain_suffix"] as? [String]) == ["x.com"] }?["outbound"] as? String, "proxy")
        XCTAssertEqual(rules.first { ($0["rule_set"] as? [String]) == ["geosite-youtube"] }?["outbound"] as? String, "proxy-1")
        XCTAssertEqual(rules.first { ($0["rule_set"] as? [String])?.first == "geosite-netflix" }?["outbound"] as? String, "proxy")
        XCTAssertTrue(plan.singbox.warnings.contains { $0.contains("target node 99") })
        // Both runtimes' endpoints stay direct.
        XCTAssertTrue(rules.contains { ($0["ip_cidr"] as? [String] ?? []).contains("1.2.3.4/32") && ($0["outbound"] as? String) == "direct" })
    }

    func testDirectBindingConflictsAndLimit() throws {
        var preferences = BindingPreferences(enabled: [.forward(12), .direct(nodeId: 3), .direct(nodeId: 1), .forward(13)])
        preferences.assign(nodeId: 1, forwardId: 12)
        preferences.assign(nodeId: 2, forwardId: 13)
        let resolved = BindingResolver.resolve(preferences: preferences, forwards: [bondForward, ultraForward],
                                               nodes: [ssNode, socksNode, thirdNode], cloud: .empty, maxForwardBindings: 1)
        XCTAssertEqual(resolved.active.map { $0.id }, [.forward(12), .direct(nodeId: 3)])
        XCTAssertEqual(resolved.skipped, [
            SkippedBinding(id: .direct(nodeId: 1), reason: .nodeAlreadyUsed(.forward(12))),
            SkippedBinding(id: .forward(13), reason: .overLimit(1)),
        ])
        let builder = SingboxConfigBuilder()
        let plan = try MultiBindingPlanner.plan(resolved: resolved, environment: environment, builder: builder,
                                                routing: builder.routingEngine.compile())
        let direct = try XCTUnwrap(outbounds(plan).first { ($0["tag"] as? String) == "proxy-3" })
        XCTAssertEqual(direct["server"] as? String, "9.9.9.9", "a node without a forward is dialled directly")
        XCTAssertEqual(direct["server_port"] as? Int, 443)
        XCTAssertEqual(plan.bindings.map { $0.runtimeNames.isEmpty }, [false, true])
    }

    func testCloudPairingsAndFailures() throws {
        let cloud = CloudNodeSyncState(clientId: 5, revision: "r", pairings: [
            CloudPairing(forwardId: 12, hostedNodeId: 70, localNodeId: 2, source: "explicit"),
            CloudPairing(forwardId: 13, hostedNodeId: 90, localNodeId: nil, source: "target_match"),
        ])
        var preferences = BindingPreferences(enabled: [.forward(12), .forward(13), .forward(77)])
        XCTAssertEqual(BindingResolver.effectiveNode(forwardId: 12, preferences: preferences, cloud: cloud).source, .cloudExplicit)
        let resolved = BindingResolver.resolve(preferences: preferences, forwards: [bondForward, ultraForward],
                                               nodes: [ssNode, socksNode], cloud: cloud)
        XCTAssertEqual(resolved.active.first?.node?.id, 2)
        XCTAssertEqual(resolved.skipped.map { $0.reason }, [.cloudNodeUnsupported, .forwardUnavailable])

        // A local pick beats the panel pairing.
        preferences.assign(nodeId: 1, forwardId: 12)
        XCTAssertEqual(BindingResolver.effectiveNode(forwardId: 12, preferences: preferences, cloud: cloud).nodeId, 1)

        // An Ultra binding without a session is skipped at plan time; nothing left -> error.
        let ultraOnly = BindingResolver.resolve(preferences: BindingPreferences(enabled: [.forward(13)]),
                                                forwards: [ultraForward], nodes: [], cloud: .empty)
        XCTAssertEqual(ultraOnly.active.count, 1, "a lone Ultra forward may run without a node")
        var offline = environment
        offline.hasPanelSession = false
        XCTAssertThrowsError(try MultiBindingPlanner.plan(resolved: ultraOnly, environment: offline, builder: SingboxConfigBuilder(),
                                                          routing: RoutingPolicyEngine().compile()))
        let bridged = try MultiBindingPlanner.plan(resolved: ultraOnly, environment: environment, builder: SingboxConfigBuilder(),
                                                   routing: RoutingPolicyEngine().compile())
        let last = (route(bridged)["rules"] as? [[String: Any]])?.last
        XCTAssertEqual(last?["override_port"] as? Int, 31000, "lone node-less Ultra keeps the runtime bridge")
    }

    func testPortPlannerAndForgetNodes() throws {
        let ports = LoopbackPortPlanner(reserved: [40000])
        XCTAssertEqual(try ports.claim(preferred: 40000), 40001)
        XCTAssertEqual(try ports.claim(preferred: 0), 20000)
        XCTAssertEqual(try ports.claim(preferred: 65535), 65535)
        XCTAssertEqual(try ports.claim(preferred: 65535), 20001)

        var preferences = BindingPreferences(enabled: [.forward(12), .direct(nodeId: 3)], defaultBinding: .direct(nodeId: 3),
                                             appPresetTargets: ["telegram": 3, "youtube": 1], countryTargets: ["JP": 3])
        preferences.assign(nodeId: 3, forwardId: 12)
        preferences.forgetNodes([3])
        XCTAssertEqual(preferences.enabled, [.forward(12)])
        XCTAssertNil(preferences.defaultBinding)
        XCTAssertNil(preferences.assignedNode(forwardId: 12))
        XCTAssertEqual(preferences.appPresetTargets, ["youtube": 1])
        XCTAssertEqual(preferences.countryTargets, [:])
    }
}
