import XCTest
@testable import LeikwanIOSCore

final class SingboxConfigTests: XCTestCase {
    private let ssNode = ProxyNode(
        id: 1, name: "SS", server: "ss.example.com", port: 8388,
        settings: .shadowsocks(ShadowsocksSettings(method: "aes-128-gcm", password: "pw"))
    )
    private let socksNode = ProxyNode(
        id: 2, name: "SOCKS", server: "5.6.7.8", port: 1080,
        settings: .socks(SocksSettings(username: "u", password: "p"))
    )

    private func bondRuntime() throws -> SingboxRuntimeContext {
        let plan = try BondPlanBuilder.buildPlan(forward: ForwardContractParser.parse(Fixtures.bondForwardJSON())!)
        return SingboxRuntimeContext.bond(plan)
    }

    private func build(
        nodes: [ProxyNode],
        settings: SingboxSettings = SingboxSettings(cacheFilePath: "/cache/cache.db"),
        routing: RoutingPolicySnapshot? = nil,
        mode: ProxyConnectionMode = .services,
        runtimeOnly: Bool = false
    ) throws -> (config: [String: Any], compiled: CompiledSingboxConfig) {
        var runtime: SingboxRuntimeContext?
        if mode == .services {
            runtime = try bondRuntime()
        }
        let compiled = try SingboxConfigBuilder(settings: settings).build(
            proxyNodes: nodes, runtime: runtime, routing: routing, connectionMode: mode, runtimeOnlyProxy: runtimeOnly
        )
        return (Fixtures.parseJSON(compiled.configJSON), compiled)
    }

    private func rules(_ config: [String: Any]) -> [[String: Any]] {
        return ((config["route"] as? [String: Any])?["rules"] as? [[String: Any]]) ?? []
    }

    func testServicesModeStructure() throws {
        let (config, compiled) = try build(nodes: [ssNode])
        XCTAssertEqual(compiled.finalProxyTag, "proxy-1")

        let inbound = (config["inbounds"] as! [[String: Any]])[0]
        XCTAssertEqual(inbound["type"] as? String, "tun")
        XCTAssertEqual(inbound["address"] as? [String], ["172.19.0.1/30"])
        XCTAssertEqual(inbound["auto_route"] as? Bool, true)
        XCTAssertEqual(inbound["strict_route"] as? Bool, true)
        XCTAssertEqual(inbound["stack"] as? String, "mixed")
        XCTAssertEqual(inbound["mtu"] as? Int, 9000)

        let outbounds = config["outbounds"] as! [[String: Any]]
        let ss = outbounds.first { ($0["type"] as? String) == "shadowsocks" }!
        XCTAssertEqual(ss["server"] as? String, "127.0.0.1")
        XCTAssertEqual(ss["server_port"] as? Int, 31000, "bond --listen port")
        XCTAssertEqual(ss["tag"] as? String, "proxy-1")
        XCTAssertEqual(ss["method"] as? String, "aes-128-gcm")
        XCTAssertTrue(outbounds.contains { ($0["tag"] as? String) == "direct" })
        XCTAssertFalse(outbounds.contains { ($0["type"] as? String) == "selector" })

        XCTAssertEqual((config["log"] as? [String: Any])?["level"] as? String, "info")
        let cache = (config["experimental"] as? [String: Any])?["cache_file"] as? [String: Any]
        XCTAssertEqual(cache?["enabled"] as? Bool, true)
        XCTAssertEqual(cache?["path"] as? String, "/cache/cache.db")

        let route = config["route"] as! [String: Any]
        XCTAssertEqual(route["final"] as? String, "proxy-1")
        XCTAssertNil(route["rule_set"])

        let r = rules(config)
        XCTAssertEqual(r[0]["action"] as? String, "sniff")
        XCTAssertEqual(r[1]["action"] as? String, "hijack-dns")
        XCTAssertEqual(r[2]["domain"] as? [String], ["v3.leikwanhost.com", "api-leikwanbridge.nbwly.com"])
        XCTAssertEqual(r[2]["outbound"] as? String, "direct")
        XCTAssertEqual(r[3]["domain"] as? [String], ["v3-mirror.leikwanhost.com"])
        XCTAssertEqual(r[4]["ip_cidr"] as? [String], ["127.0.0.0/8", "10.0.0.0/8", "172.16.0.0/12", "192.168.0.0/16"])
        // Runtime reachability: endpoint IP + hosts + cdn host + the node's own server.
        XCTAssertEqual(r[5]["ip_cidr"] as? [String], ["1.2.3.4/32"])
        XCTAssertEqual(r[6]["domain"] as? [String], ["edge.example.com", "cdn.example.com", "ss.example.com"])

        let dns = config["dns"] as! [String: Any]
        let servers = dns["servers"] as! [[String: Any]]
        XCTAssertEqual(servers[0]["type"] as? String, "tcp")
        XCTAssertEqual(servers[0]["detour"] as? String, "proxy-1")
        XCTAssertEqual(dns["strategy"] as? String, "ipv4_only")
        XCTAssertTrue(compiled.configJSON.hasPrefix("{\"dns\":"), "sorted keys")
    }

    func testSelectorAndDirectMode() throws {
        let (config, compiled) = try build(nodes: [ssNode, socksNode], mode: .direct)
        XCTAssertEqual(compiled.finalProxyTag, "proxy")
        let outbounds = config["outbounds"] as! [[String: Any]]
        let selector = outbounds.first { ($0["type"] as? String) == "selector" }!
        XCTAssertEqual(selector["outbounds"] as? [String], ["proxy-1", "proxy-2"])
        XCTAssertEqual(selector["default"] as? String, "proxy-1")
        let socks = outbounds.first { ($0["type"] as? String) == "socks" }!
        XCTAssertEqual(socks["server"] as? String, "5.6.7.8")
        XCTAssertEqual(socks["server_port"] as? Int, 1080)
        XCTAssertEqual(socks["username"] as? String, "u")
        XCTAssertEqual(socks["version"] as? String, "5")
    }

    func testRuleSetsCustomRulesOrderingAndDownloadDetour() throws {
        let engine = RoutingPolicyEngine()
        let snapshot = engine.compile(
            customRules: [
                CustomRoutingRule(id: "c1", type: .domainSuffix, value: "corp.example", outcome: .direct),
                CustomRoutingRule(id: "c2", type: .domainKeyword, value: "ads", outcome: .reject),
                CustomRoutingRule(id: "c3", type: .geoip, value: "JP", outcome: .proxy),
            ],
            appPresetRules: [RoutingRule(id: "a1", kind: .appPreset, matchValue: "telegram", outcome: .tunnel)],
            countryRules: [RoutingRule(id: "k1", kind: .country, matchValue: "CN", outcome: .direct)],
            categoryRules: [RoutingRule(id: "g1", kind: .websiteCategory, matchValue: "china_direct", outcome: .direct)]
        )
        let settings = SingboxSettings(bypassChina: true, customBypassRules: "cafe.de\n8.8.8.8\n", cacheFilePath: "/c.db")
        let (config, _) = try build(nodes: [ssNode], settings: settings, routing: snapshot)
        let r = rules(config)

        let firstCustom = r.firstIndex { ($0["domain_suffix"] as? [String]) == ["corp.example"] }!
        let lastProtected = r.lastIndex { ($0["outbound"] as? String) == "direct" && ($0["ip_cidr"] as? [String]) == ["1.2.3.4/32"] }!
        XCTAssertLessThan(lastProtected, firstCustom, "custom rules come after protected/runtime rules")
        XCTAssertEqual(r[firstCustom]["outbound"] as? String, "direct")
        XCTAssertEqual(r[firstCustom + 1]["domain_keyword"] as? [String], ["ads"])
        XCTAssertEqual(r[firstCustom + 1]["action"] as? String, "reject")
        XCTAssertEqual(r[firstCustom + 2]["rule_set"] as? [String], ["geoip-jp"])
        XCTAssertEqual(r[firstCustom + 2]["outbound"] as? String, "proxy-1")
        XCTAssertEqual(r[firstCustom + 3]["rule_set"] as? [String], ["geosite-telegram", "geoip-telegram"], "app preset before country")
        XCTAssertEqual(r[firstCustom + 4]["rule_set"] as? [String], ["geoip-cn"])
        XCTAssertEqual(r[firstCustom + 5]["rule_set"] as? [String], ["geosite-cn", "geosite-geolocation-cn"])
        XCTAssertEqual(r[firstCustom + 6]["rule_set"] as? [String], ["geoip-cn", "geosite-cn"], "bypass china toggle")
        // Hex-only domain stays a domain (Android would have emitted cafe.de/32).
        XCTAssertEqual(r[firstCustom + 7]["ip_cidr"] as? [String], ["8.8.8.8/32"])
        XCTAssertEqual(r[firstCustom + 8]["domain"] as? [String], ["cafe.de"])

        let ruleSets = (config["route"] as! [String: Any])["rule_set"] as! [[String: Any]]
        XCTAssertEqual(ruleSets.map { $0["tag"] as? String ?? "" },
                       ["geoip-cn", "geosite-cn", "geoip-jp", "geosite-telegram", "geoip-telegram", "geosite-geolocation-cn"])
        for set in ruleSets {
            XCTAssertEqual(set["download_detour"] as? String, "proxy-1")
            XCTAssertEqual(set["format"] as? String, "binary")
            XCTAssertEqual(set["type"] as? String, "remote")
        }
        let dnsRules = (config["dns"] as! [String: Any])["rules"] as! [[String: Any]]
        XCTAssertEqual(dnsRules.last?["rule_set"] as? [String], ["geosite-cn"])
        XCTAssertTrue((dnsRules[0]["domain"] as? [String] ?? []).contains("cafe.de"))
    }

    func testGlobalAndDirectModes() throws {
        let engine = RoutingPolicyEngine()
        let country = [RoutingRule(id: "k1", kind: .country, matchValue: "JP", outcome: .direct)]
        let global = try build(nodes: [ssNode], settings: SingboxSettings(bypassChina: true), routing: engine.compile(globalMode: .global, countryRules: country))
        XCTAssertEqual((global.config["route"] as! [String: Any])["final"] as? String, "proxy-1")
        XCTAssertFalse(rules(global.config).contains { $0["rule_set"] != nil }, "no user/china rules in global mode")
        XCTAssertNil((global.config["route"] as! [String: Any])["rule_set"])

        let direct = try build(nodes: [ssNode], routing: engine.compile(globalMode: .direct, countryRules: country))
        XCTAssertEqual((direct.config["route"] as! [String: Any])["final"] as? String, "direct")

        let ruleDirect = try build(nodes: [ssNode], routing: engine.compile(globalDefault: .direct))
        XCTAssertEqual((ruleDirect.config["route"] as! [String: Any])["final"] as? String, "direct")
    }

    func testIpv6TunAndUltraBridge() throws {
        let settings = SingboxSettings(logLevel: "debug", tunStack: "gvisor", tunMTU: 1500, enableIPv6: true)
        let (config, _) = try build(nodes: [], settings: settings, runtimeOnly: true)
        let inbound = (config["inbounds"] as! [[String: Any]])[0]
        XCTAssertEqual(inbound["address"] as? [String], ["172.19.0.1/30", "fdfe:dcba:9876::1/126"])
        XCTAssertEqual(inbound["stack"] as? String, "gvisor")
        XCTAssertEqual(inbound["mtu"] as? Int, 1500)
        XCTAssertNil(config["experimental"])
        let bridge = rules(config).last!
        XCTAssertEqual(bridge["action"] as? String, "route")
        XCTAssertEqual(bridge["override_address"] as? String, "127.0.0.1")
        XCTAssertEqual(bridge["override_port"] as? Int, 31000)
        XCTAssertEqual(bridge["outbound"] as? String, "proxy")
        let outbounds = config["outbounds"] as! [[String: Any]]
        XCTAssertEqual(outbounds.first?["type"] as? String, "direct")
        XCTAssertEqual(outbounds.first?["tag"] as? String, "proxy")
        let dnsServers = (config["dns"] as! [String: Any])["servers"] as! [[String: Any]]
        XCTAssertEqual(dnsServers[0]["type"] as? String, "udp")
        XCTAssertNil(dnsServers[0]["detour"])
    }

    func testServicesModeWithoutRuntimeThrows() {
        XCTAssertThrowsError(try SingboxConfigBuilder().build(proxyNodes: [ssNode], runtime: nil, connectionMode: .services))
    }

    func testNodesBoundToOtherForwardsAreDropped() throws {
        var other = socksNode
        other.forwardId = 99
        var disabled = ssNode
        disabled.id = 3
        disabled.enabled = false
        let (config, compiled) = try build(nodes: [ssNode, other, disabled])
        XCTAssertEqual(compiled.finalProxyTag, "proxy-1")
        let tags = (config["outbounds"] as! [[String: Any]]).compactMap { $0["tag"] as? String }
        XCTAssertEqual(tags, ["proxy-1", "direct"])
    }
}
