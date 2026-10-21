import XCTest
@testable import LeikwanIOSCore

final class NativeSingboxConfigTests: XCTestCase {
    private func build(
        _ scope: NativeRouteScope,
        _ policy: NativeRoutePolicy,
        bypassChina: Bool = false,
        settings: SingboxSettings = SingboxSettings(cacheFilePath: "/cache/cache.db")
    ) throws -> (config: [String: Any], compiled: CompiledSingboxConfig) {
        let compiled = try SingboxConfigBuilder(settings: settings, safePathRegistry: Fixtures.safePaths).buildNative(
            NativeSingboxOptions(localProxyPort: 50123, routeScope: scope, routePolicy: policy, bypassChina: bypassChina)
        )
        return (Fixtures.parseJSON(compiled.configJSON), compiled)
    }

    private func rules(_ config: [String: Any]) -> [[String: Any]] {
        return ((config["route"] as? [String: Any])?["rules"] as? [[String: Any]]) ?? []
    }

    private func dnsServers(_ config: [String: Any]) -> [String: [String: Any]] {
        var result: [String: [String: Any]] = [:]
        for server in ((config["dns"] as? [String: Any])?["servers"] as? [[String: Any]]) ?? [] {
            if let tag = server["tag"] as? String { result[tag] = server }
        }
        return result
    }

    private func hasResolve(_ config: [String: Any]) -> Bool {
        return rules(config).contains { ($0["action"] as? String) == "resolve" }
    }

    private func lowPortDirect(_ config: [String: Any]) -> Bool {
        return rules(config).contains { ($0["port_range"] as? String) == "1:999" && ($0["outbound"] as? String) == "direct" }
    }

    private func splitRule(_ config: [String: Any]) -> [String: Any]? {
        return rules(config).first { ($0["rule_set"] as? [String]) == ["geoip-cn", "geosite-cn"] && $0["port_range"] != nil }
    }

    func testGlobalRestrictedStructure() throws {
        let (config, compiled) = try build(.global, .restricted)
        XCTAssertEqual(compiled.finalProxyTag, "proxy-native")

        let outbounds = config["outbounds"] as! [[String: Any]]
        XCTAssertEqual(outbounds.count, 2)
        XCTAssertEqual(outbounds[0]["type"] as? String, "socks")
        XCTAssertEqual(outbounds[0]["tag"] as? String, "proxy-native")
        XCTAssertEqual(outbounds[0]["server"] as? String, "127.0.0.1")
        XCTAssertEqual(outbounds[0]["server_port"] as? Int, 50123)
        XCTAssertEqual(outbounds[0]["version"] as? String, "5")
        XCTAssertEqual((outbounds[0]["udp_over_tcp"] as? [String: Any])?["enabled"] as? Bool, false)
        XCTAssertNil(outbounds[0]["username"], "no credentials in the sing-box config")
        XCTAssertEqual(outbounds[1]["tag"] as? String, "direct")
        XCTAssertFalse(outbounds.contains { ($0["type"] as? String) == "selector" })

        let inbound = (config["inbounds"] as! [[String: Any]])[0]
        XCTAssertEqual(inbound["type"] as? String, "tun")
        XCTAssertNil(inbound["route_exclude_address"], "iOS: tunnel-process sockets bypass the tunnel")

        let dns = config["dns"] as! [String: Any]
        XCTAssertEqual(dns["final"] as? String, "remote")
        XCTAssertEqual(dns["strategy"] as? String, "ipv4_only")
        let servers = dnsServers(config)
        XCTAssertEqual(servers["remote"]?["type"] as? String, "https")
        XCTAssertEqual(servers["remote"]?["server"] as? String, "223.5.5.5")
        XCTAssertEqual((servers["remote"]?["tls"] as? [String: Any])?["server_name"] as? String, "dns.alidns.com")
        XCTAssertNil(servers["remote"]?["detour"], "restricted relay refuses 443")
        XCTAssertEqual(servers["local"]?["type"] as? String, "udp")
        XCTAssertEqual(servers["local"]?["server"] as? String, "223.5.5.5")
        XCTAssertNil(servers["china"])

        let r = rules(config)
        XCTAssertEqual(r[0]["action"] as? String, "sniff")
        XCTAssertEqual(r[1]["action"] as? String, "hijack-dns")
        XCTAssertEqual(r[2]["network"] as? String, "tcp")
        XCTAssertEqual(r[2]["ip_cidr"] as? [String], ["172.19.0.2/32"])
        XCTAssertEqual(r[2]["action"] as? String, "reject")
        XCTAssertEqual(r[2]["method"] as? String, "drop")
        XCTAssertEqual(r[3]["network"] as? String, "udp")
        XCTAssertEqual(r[4]["domain"] as? [String], ["panel.example.com", "backup.example.net"])
        XCTAssertTrue(lowPortDirect(config))
        XCTAssertFalse(hasResolve(config), "restricted global hands hostnames to the relay")
        XCTAssertNil(splitRule(config))

        let route = config["route"] as! [String: Any]
        XCTAssertEqual(route["final"] as? String, "proxy-native")
        XCTAssertEqual(route["default_domain_resolver"] as? String, "local")
        XCTAssertNil(route["rule_set"])
    }

    func testCnOnlyRestricted() throws {
        let (config, _) = try build(.cnOnly, .restricted)
        let servers = dnsServers(config)
        XCTAssertEqual(servers["remote"]?["server"] as? String, "1.1.1.1")
        XCTAssertNil(servers["remote"]?["detour"])
        XCTAssertEqual(servers["china"]?["server"] as? String, "223.5.5.5")
        XCTAssertNil(servers["china"]?["detour"])
        let dnsRules = ((config["dns"] as? [String: Any])?["rules"] as? [[String: Any]]) ?? []
        XCTAssertTrue(dnsRules.contains { ($0["rule_set"] as? [String]) == ["geosite-cn"] && ($0["server"] as? String) == "china" })

        XCTAssertTrue(lowPortDirect(config))
        XCTAssertTrue(hasResolve(config))
        let split = splitRule(config)!
        XCTAssertEqual(split["port_range"] as? String, "1000:65535")
        XCTAssertEqual(split["outbound"] as? String, "proxy-native")
        XCTAssertEqual(split["network"] as? [String], ["tcp", "udp"])

        let route = config["route"] as! [String: Any]
        XCTAssertEqual(route["final"] as? String, "direct")
        XCTAssertEqual(route["default_domain_resolver"] as? String, "remote")
        let ruleSets = route["rule_set"] as! [[String: Any]]
        XCTAssertEqual(ruleSets.map { $0["tag"] as? String }, ["geoip-cn", "geosite-cn"])
        XCTAssertEqual(ruleSets[0]["type"] as? String, "local")
        XCTAssertEqual(ruleSets[0]["path"] as? String, "geoip-cn.srs")
        XCTAssertNil(ruleSets[0]["download_detour"])
    }

    func testIntlOnlyUnrestricted() throws {
        let (config, _) = try build(.intlOnly, .unrestricted)
        let servers = dnsServers(config)
        XCTAssertEqual(servers["remote"]?["server"] as? String, "1.1.1.1")
        XCTAssertEqual(servers["remote"]?["detour"] as? String, "proxy-native")
        XCTAssertFalse(lowPortDirect(config))
        XCTAssertTrue(hasResolve(config))
        let split = splitRule(config)!
        XCTAssertEqual(split["port_range"] as? String, "1:65535")
        XCTAssertEqual(split["outbound"] as? String, "direct")
        let route = config["route"] as! [String: Any]
        XCTAssertEqual(route["final"] as? String, "proxy-native")
        XCTAssertEqual((route["rule_set"] as! [[String: Any]])[0]["type"] as? String, "local")
    }

    func testGlobalUnrestrictedBypassChina() throws {
        let (config, _) = try build(.global, .unrestricted, bypassChina: true)
        XCTAssertEqual(dnsServers(config)["remote"]?["detour"] as? String, "proxy-native")
        XCTAssertTrue(hasResolve(config), "unrestricted global resolves before the SOCKS handoff")
        XCTAssertNil(splitRule(config))
        XCTAssertTrue(rules(config).contains { ($0["rule_set"] as? [String]) == ["geoip-cn", "geosite-cn"] && ($0["outbound"] as? String) == "direct" })
        let dnsRules = ((config["dns"] as? [String: Any])?["rules"] as? [[String: Any]]) ?? []
        XCTAssertTrue(dnsRules.contains { ($0["rule_set"] as? [String]) == ["geosite-cn"] && ($0["server"] as? String) == "local" })
        let route = config["route"] as! [String: Any]
        XCTAssertEqual(route["default_domain_resolver"] as? String, "remote")
        XCTAssertNotNil(route["rule_set"])
    }

    func testBypassChinaIgnoredOutsideGlobal() throws {
        let (config, _) = try build(.intlOnly, .restricted, bypassChina: true)
        XCTAssertFalse(rules(config).contains { ($0["rule_set"] as? [String]) == ["geoip-cn", "geosite-cn"] && $0["port_range"] == nil })
    }

    func testUserRulesTargetTheNativeExit() throws {
        let builder = SingboxConfigBuilder(settings: SingboxSettings(customBypassRules: "10.9.8.7\nexample.org", enableIPv6: true))
        let snapshot = builder.routingEngine.compile(countryRules: [
            RoutingRule(id: "country-JP", kind: .country, matchValue: "JP", outcome: .tunnel),
        ])
        let compiled = try builder.buildNative(NativeSingboxOptions(localProxyPort: 50123, routeScope: .global, routePolicy: .restricted), routing: snapshot)
        let config = Fixtures.parseJSON(compiled.configJSON)
        let r = rules(config)
        XCTAssertEqual(r[2]["ip_cidr"] as? [String], ["172.19.0.2/32", "fdfe:dcba:9876::2/128"])
        XCTAssertTrue(r.contains { ($0["rule_set"] as? [String])?.first?.hasPrefix("geoip-") == true && ($0["outbound"] as? String) == "proxy-native" })
        XCTAssertTrue(r.contains { ($0["ip_cidr"] as? [String]) == ["10.9.8.7/32"] && ($0["outbound"] as? String) == "direct" })
        XCTAssertTrue(r.contains { ($0["domain"] as? [String]) == ["example.org"] && ($0["outbound"] as? String) == "direct" })
        XCTAssertThrowsError(try builder.buildNative(NativeSingboxOptions(localProxyPort: 0, routeScope: .global, routePolicy: .restricted)))
    }

    // MARK: Planner

    func testEffectiveSelection() {
        let availability = NativeFixtures.availability()
        var selection = NativeOutboundPlanner.effectiveSelection(
            availability: availability, eligibleForwardIds: [12],
            preferredForwardId: 99, preferredScope: "intl_only", preferredPolicy: "unrestricted", bypassChina: true
        )!
        XCTAssertEqual(selection.forwardId, 12, "unknown preference falls back to the panel default")
        XCTAssertEqual(selection.routeScope, .global, "scope the carrier lacks falls back to its default")
        XCTAssertEqual(selection.routePolicy, .unrestricted)
        XCTAssertTrue(selection.bypassChina)

        selection = NativeOutboundPlanner.effectiveSelection(
            availability: availability, eligibleForwardIds: nil,
            preferredForwardId: 12, preferredScope: "mainland_only", preferredPolicy: "restricted", bypassChina: true
        )!
        XCTAssertEqual(selection.routeScope, .cnOnly)
        XCTAssertFalse(selection.bypassChina, "bypass only applies to global")

        let strict = NativeOutboundAvailability.from(NativeFixtures.outboundJSON(carrier: NativeFixtures.carrierJSON(unrestricted: false)))
        XCTAssertEqual(NativeOutboundPlanner.effectiveSelection(
            availability: strict, eligibleForwardIds: nil,
            preferredForwardId: nil, preferredScope: nil, preferredPolicy: "unrestricted", bypassChina: false
        )?.routePolicy, .restricted)

        XCTAssertNil(NativeOutboundPlanner.effectiveSelection(
            availability: availability, eligibleForwardIds: [13],
            preferredForwardId: nil, preferredScope: nil, preferredPolicy: nil, bypassChina: false
        ), "carrier forward not runnable on iOS")
        XCTAssertNil(NativeOutboundPlanner.effectiveSelection(
            availability: nil, eligibleForwardIds: nil,
            preferredForwardId: nil, preferredScope: nil, preferredPolicy: nil, bypassChina: false
        ))
    }

    func testPlanBuildsRuntimeAndSingbox() throws {
        let paths = UltraRuntimePaths.compact(directory: "/g/r", forwardId: 12)
        let selection = NativeOutboundSelection(forwardId: 12, routeScope: .cnOnly, routePolicy: .restricted, bypassChina: false)
        let plan = try NativeOutboundPlanner.plan(
            availability: NativeFixtures.availability(),
            forward: NativeFixtures.forward(),
            selection: selection,
            paths: paths,
            socksPort: 50123,
            builder: SingboxConfigBuilder(),
            routing: nil,
            now: Fixtures.now
        )
        XCTAssertEqual(plan.policy.policyGeneration, Fixtures.generation)
        XCTAssertEqual(plan.carrier.forwardId, 12)
        XCTAssertEqual(Fixtures.parseJSON(plan.runtime.configJSON)["listen_port"] as? Int, 50123)
        XCTAssertEqual(plan.singbox.finalProxyTag, "proxy-native")
    }

    func testPlanRefusals() {
        let paths = UltraRuntimePaths.compact(directory: "/g/r", forwardId: 12)
        func code(_ forward: ForwardContract, _ selection: NativeOutboundSelection, paths: UltraRuntimePaths = UltraRuntimePaths.compact(directory: "/g/r", forwardId: 12)) -> String? {
            do {
                _ = try NativeOutboundPlanner.plan(
                    availability: NativeFixtures.availability(), forward: forward, selection: selection,
                    paths: paths, socksPort: 50123, builder: SingboxConfigBuilder(), routing: nil, now: Fixtures.now
                )
                return nil
            } catch let error as UltraLeaseError {
                return error.message
            } catch {
                return "other"
            }
        }
        let ok = NativeOutboundSelection(forwardId: 12, routeScope: .global, routePolicy: .restricted, bypassChina: false)
        XCTAssertNil(code(NativeFixtures.forward(), ok, paths: paths))
        XCTAssertEqual(code(NativeFixtures.forward(), NativeOutboundSelection(forwardId: 12, routeScope: .intlOnly, routePolicy: .restricted, bypassChina: false)), "native_route_scope_invalid")
        XCTAssertEqual(code(NativeFixtures.forward(), NativeOutboundSelection(forwardId: 13, routeScope: .global, routePolicy: .restricted, bypassChina: false)), "native_forward_unavailable")
        XCTAssertEqual(code(NativeFixtures.forward(direction: "SG -> CN"), ok), "native_forward_direction_mismatch")
        XCTAssertEqual(code(NativeFixtures.forward(transport: "tcp"), ok), "native_transport_invalid")
        let longPaths = UltraRuntimePaths.compact(directory: "/" + String(repeating: "x", count: 120), forwardId: 12)
        XCTAssertEqual(code(NativeFixtures.forward(), ok, paths: longPaths), "native_control_path_too_long")
    }
}
