import Foundation
import XCTest
@testable import LeikwanIOSCore

/// Builds the sing-box configs the routing E2E workflow (.github/workflows/routing-e2e.yml) runs
/// through a real sing-box core: tools/routing-e2e/rules.txt goes through the production
/// `CustomRoutingRule.parseLines`, `RoutingPolicyEngine` and `SingboxConfigBuilder`, exactly as the
/// app does, and the JSON is written to $ROUTING_E2E_OUT (ios-s1.json = global default TUNNEL,
/// ios-s2.json = global default DIRECT). tools/routing-e2e/harness.py then proves at runtime that
/// real connections land on proxy / direct / reject as the rules say.
final class RoutingE2EConfigDumpTests: XCTestCase {
    private static let proxyTag = "proxy-50"
    private static let markerHost = "127.0.0.1"
    private static let markerPort = 1081
    /// Placeholder the harness swaps for the real ios/RuleSets directory.
    private static let ruleSetDirectory = "/__RULESETS__"

    /// rules.txt, in file order, as sing-box route rules.
    private func expectedClashRules() -> [[String: Any]] {
        let v6 = CustomRoutingRule.normalizeAnyCidr("2001:db8:1::/48") ?? "invalid"
        return [
            ["domain_suffix": ["example-proxy.test"], "outbound": Self.proxyTag],
            ["domain": ["direct.test"], "outbound": "direct"],
            ["domain_keyword": ["blockme"], "action": "reject"],
            ["domain_regex": ["^rx[0-9]+\\.test$"], "outbound": "direct"],
            ["ip_cidr": ["198.51.100.0/24"], "outbound": Self.proxyTag],
            ["ip_cidr": ["203.0.113.0/24"], "action": "reject"],
            ["ip_cidr": ["192.0.2.128/25"], "outbound": "direct"],
            ["ip_cidr": [v6], "outbound": Self.proxyTag],
            ["port": [8443], "outbound": "direct"],
            ["rule_set": ["geosite-openai"], "outbound": "direct"],
            ["rule_set": ["geosite-telegram"], "action": "reject"],
        ]
    }

    func testDumpRoutingE2EConfigs() throws {
        let root = try Self.repoRoot()
        let rulesURL = root.appendingPathComponent("tools/routing-e2e/rules.txt")
        let rulesText = try String(contentsOf: rulesURL, encoding: .utf8)
        let parsed = CustomRoutingRule.parseLines(rulesText)
        XCTAssertEqual(parsed.errors, [], "rules.txt must parse without errors")
        XCTAssertEqual(parsed.rules.count, expectedClashRules().count)

        let outDir: URL
        if let env = ProcessInfo.processInfo.environment["ROUTING_E2E_OUT"], !env.isEmpty {
            outDir = URL(fileURLWithPath: env, isDirectory: true)
        } else {
            outDir = FileManager.default.temporaryDirectory.appendingPathComponent("routing-e2e-\(UUID().uuidString)", isDirectory: true)
        }
        try FileManager.default.createDirectory(at: outDir, withIntermediateDirectories: true)

        let settings = SingboxSettings(logLevel: "debug", ruleSetDirectory: Self.ruleSetDirectory)
        let builder = SingboxConfigBuilder(settings: settings, safePathRegistry: Fixtures.safePaths)
        let node = ProxyNode(
            id: 50, name: "socks", server: Self.markerHost, port: Self.markerPort,
            settings: .socks(SocksSettings(username: "user", password: "pass"))
        )

        let scenarios: [(name: String, globalDefault: UserTrafficOutcome, expectedFinal: String)] = [
            ("s1", .tunnel, Self.proxyTag),
            ("s2", .direct, "direct"),
        ]
        for scenario in scenarios {
            let snapshot = builder.routingEngine.compile(
                globalMode: .rule,
                globalDefault: scenario.globalDefault,
                customRules: parsed.rules
            )
            XCTAssertEqual(snapshot.warnings, [], "no rule may be dropped (\(scenario.name))")
            let compiled = try builder.build(proxyNodes: [node], runtime: nil, routing: snapshot, connectionMode: .direct)
            XCTAssertEqual(compiled.finalProxyTag, Self.proxyTag)
            XCTAssertEqual(compiled.warnings, [], "builder warnings (\(scenario.name))")
            assertConfigShape(Fixtures.parseJSON(compiled.configJSON), expectedFinal: scenario.expectedFinal, scenario: scenario.name)

            let target = outDir.appendingPathComponent("ios-\(scenario.name).json")
            try compiled.configJSON.write(to: target, atomically: true, encoding: .utf8)
            print("routing-e2e: wrote \(target.path)")
        }
    }

    private func assertConfigShape(_ config: [String: Any], expectedFinal: String, scenario: String) {
        XCTAssertEqual((config["log"] as? [String: Any])?["level"] as? String, "debug", scenario)
        XCTAssertNil(config["experimental"], "no cache_file in the dump (\(scenario))")

        let outbounds = (config["outbounds"] as? [[String: Any]]) ?? []
        let proxy = outbounds.first { ($0["tag"] as? String) == Self.proxyTag }
        XCTAssertEqual(proxy?["type"] as? String, "socks", scenario)
        XCTAssertEqual(proxy?["server"] as? String, Self.markerHost, scenario)
        XCTAssertEqual(proxy?["server_port"] as? Int, Self.markerPort, scenario)

        let route = (config["route"] as? [String: Any]) ?? [:]
        XCTAssertEqual(route["final"] as? String, expectedFinal, scenario)

        // The clash rules appear contiguously and in fixture order.
        let rules = (route["rules"] as? [[String: Any]]) ?? []
        let expected = expectedClashRules()
        guard let first = rules.firstIndex(where: { Self.same($0, expected[0]) }) else {
            XCTFail("first clash rule missing from route.rules (\(scenario)): \(rules)")
            return
        }
        for (offset, rule) in expected.enumerated() {
            let index = first + offset
            XCTAssertTrue(index < rules.count && Self.same(rules[index], rule),
                          "route.rules[\(index)] should be \(rule), was \(index < rules.count ? String(describing: rules[index]) : "missing") (\(scenario))")
        }

        // The rule-sets are the bundled local copies under the placeholder directory.
        let ruleSets = (route["rule_set"] as? [[String: Any]]) ?? []
        for tag in ["geosite-openai", "geosite-telegram"] {
            let entry = ruleSets.first { ($0["tag"] as? String) == tag }
            XCTAssertEqual(entry?["type"] as? String, "local", "\(tag) (\(scenario))")
            XCTAssertEqual(entry?["format"] as? String, "binary", "\(tag) (\(scenario))")
            XCTAssertEqual(entry?["path"] as? String, "\(Self.ruleSetDirectory)/\(tag).srs", "\(tag) (\(scenario))")
        }
    }

    /// Order-insensitive comparison through the module's sorted-key JSON encoder.
    private static func same(_ actual: [String: Any], _ expected: [String: Any]) -> Bool {
        guard let lhs = try? CanonicalJSON.string(actual), let rhs = try? CanonicalJSON.string(expected) else { return false }
        return lhs == rhs
    }

    private static func repoRoot() throws -> URL {
        var dir = URL(fileURLWithPath: #filePath).deletingLastPathComponent()
        while dir.path != "/" && !dir.path.isEmpty {
            if FileManager.default.fileExists(atPath: dir.appendingPathComponent("tools/routing-e2e/rules.txt").path) {
                return dir
            }
            dir.deleteLastPathComponent()
        }
        throw SingboxConfigError("tools/routing-e2e/rules.txt not found above \(#filePath)")
    }
}
