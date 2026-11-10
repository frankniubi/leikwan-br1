import XCTest
@testable import LeikwanIOSCore

final class RoutingTests: XCTestCase {
    func testCategoryCatalogFixesGeolocationPrefix() {
        let tags = RoutingCategoryCatalog.standard.ruleSets(for: "china_direct").map { $0.tag }
        XCTAssertEqual(tags, ["geosite-cn", "geosite-geolocation-cn"])
        XCTAssertEqual(RoutingCategoryCatalog.standard.ruleSets(for: "VIDEO").count, 6, "alias")
        XCTAssertTrue(RoutingCategoryCatalog.standard.ruleSets(for: "nope").isEmpty)
        for preset in RoutingCategoryCatalog.seedPresets {
            for tag in preset.tags {
                XCTAssertTrue(tag.hasPrefix("geosite-"), "\(preset.key): \(tag)")
            }
        }
    }

    func testAppPresetTagMapping() {
        XCTAssertEqual(AppPresetCatalog.preset(for: "telegram")?.tags, ["geosite-telegram", "geoip-telegram"])
        XCTAssertEqual(AppPresetCatalog.preset(for: "anthropic")?.tags, ["geosite-anthropic"])
        XCTAssertEqual(AppPresetCatalog.preset(for: "openai")?.tags, ["geosite-openai"])
        XCTAssertEqual(AppPresetCatalog.preset(for: "tencent")?.tags, ["geosite-tencent"])
        XCTAssertEqual(AppPresetCatalog.preset(for: "tencent")?.defaultOutcome, .direct)
        XCTAssertEqual(AppPresetCatalog.preset(for: "bilibili_iqiyi")?.defaultOutcome, .direct)
        XCTAssertEqual(AppPresetCatalog.preset(for: "google")?.tags, ["geosite-google", "geosite-google-gemini", "geoip-google"])
        XCTAssertEqual(Set(AppPresetCatalog.presets.map { $0.key }).count, AppPresetCatalog.presets.count, "unique keys")
        for preset in AppPresetCatalog.presets {
            XCTAssertFalse(preset.ruleSets.isEmpty, preset.key)
            for ref in preset.ruleSets {
                XCTAssertTrue(BundledRuleSets.contains(ref.tag), ref.tag)
            }
        }
    }

    func testCustomRuleParsingAndValidation() {
        // Existing tests
        XCTAssertEqual(CustomRoutingRule.parseLine("DOMAIN-SUFFIX, .Example.COM ,PROXY", id: "1")?.normalizedValue(), "example.com")
        XCTAssertEqual(CustomRoutingRule.parseLine("IP-CIDR6,2001:db8::/32,DIRECT,no-resolve", id: "2")?.normalizedValue(), "2001:db8:0:0:0:0:0:0/32")
        XCTAssertEqual(CustomRoutingRule.parseLine("IP-CIDR,10.0.0.1,REJECT", id: "3")?.normalizedValue(), "10.0.0.1/32")
        XCTAssertEqual(CustomRoutingRule.parseLine("GEOIP,JP,DIRECT", id: "4")?.ruleSetRef?.tag, "geoip-jp")
        XCTAssertNil(CustomRoutingRule.parseLine("GEOIP,JPN,DIRECT", id: "5"))
        XCTAssertNil(CustomRoutingRule.parseLine("GEOIP,BV,DIRECT", id: "5b"), "no bundled geoip set")
        XCTAssertNil(CustomRoutingRule.parseLine("GEOIP,ZZ,DIRECT", id: "5c"), "not a country")
        XCTAssertNil(CustomRoutingRule.parseLine("USER-AGENT,foo,DIRECT", id: "6"))
        XCTAssertNil(CustomRoutingRule.parseLine("DOMAIN,foo,MAYBE", id: "7"))
        XCTAssertNil(CustomRoutingRule.parseLine("IP-CIDR,1.2.3.4/33,DIRECT", id: "8"))

        // New types: GEOSITE/RULE-SET
        XCTAssertEqual(CustomRoutingRule.parseLine("GEOSITE,openai,PROXY", id: "g1")?.ruleSetRef?.tag, "geosite-openai")
        XCTAssertEqual(CustomRoutingRule.parseLine("RULE-SET,telegram,DIRECT", id: "g2")?.ruleSetRef?.tag, "geosite-telegram")
        XCTAssertNil(CustomRoutingRule.parseLine("GEOSITE,unknown_tag_xyz,PROXY", id: "g3"), "unbundled geosite")
        XCTAssertNil(CustomRoutingRule.parseLine("RULE-SET,not.a.tag,DIRECT", id: "g4"), "invalid name chars")

        // DST-PORT: single port and range
        XCTAssertEqual(CustomRoutingRule.parseLine("DST-PORT,443,PROXY", id: "p1")?.normalizedValue(), "443")
        XCTAssertEqual(CustomRoutingRule.parseLine("DST-PORT,8000:8999,DIRECT", id: "p2")?.normalizedValue(), "8000:8999")
        XCTAssertNil(CustomRoutingRule.parseLine("DST-PORT,65536,PROXY", id: "p3"), "port out of range")
        XCTAssertNil(CustomRoutingRule.parseLine("DST-PORT,9000:8000,PROXY", id: "p4"), "range reversed")
        XCTAssertNil(CustomRoutingRule.parseLine("DST-PORT,8000:8000,PROXY", id: "p5"), "range not strictly increasing")

        // DOMAIN-REGEX
        XCTAssertEqual(CustomRoutingRule.parseLine("DOMAIN-REGEX,^ad\\d+\\.example\\.com$,REJECT", id: "r1")?.normalizedValue(), "^ad\\d+\\.example\\.com$")
        XCTAssertNil(CustomRoutingRule.parseLine("DOMAIN-REGEX,[invalid(regex,PROXY", id: "r2"), "invalid regex")

        // 2-field format defaults to PROXY
        XCTAssertEqual(CustomRoutingRule.parseLine("DOMAIN,example.com", id: "2f1")?.outcome, .proxy)
        XCTAssertEqual(CustomRoutingRule.parseLine("GEOIP,CN", id: "2f2")?.outcome, .proxy)

        // Case insensitive type matching
        XCTAssertNotNil(CustomRoutingRule.parseLine("domain-suffix,example.com,direct", id: "ci1"))
        XCTAssertNotNil(CustomRoutingRule.parseLine("geosite,openai,proxy", id: "ci2"))
    }

    func testParseLinesCollectsErrors() {
        let text = """
        DOMAIN,example.com,PROXY
        GEOSITE,openai,DIRECT
        INVALID,syntax
        # This is a comment and ignored

        DST-PORT,invalid_port,PROXY
        DOMAIN-REGEX,^test\\.com$,REJECT
        """
        let result = CustomRoutingRule.parseLines(text)
        XCTAssertEqual(result.rules.count, 3, "valid rules: domain, geosite, domain-regex")
        XCTAssertEqual(result.errors.count, 2, "two errors: invalid type on line 3, invalid port on line 6")
        XCTAssert(result.errors[0].contains("Line 3"), "first error mentions line 3")
        XCTAssert(result.errors[1].contains("Line 6"), "second error mentions line 6")
        // Verify rules are in order
        XCTAssertEqual(result.rules[0].value, "example.com")
        XCTAssertEqual(result.rules[1].value, "openai")
        XCTAssertEqual(result.rules[2].value, "^test\\.com$")
    }

    func testEngineCompilesInPrecedenceOrderAndDropsInvalidRules() {
        let engine = RoutingPolicyEngine(safePathRegistry: Fixtures.safePaths)
        let snapshot = engine.compile(
            customRules: [CustomRoutingRule(id: "c1", type: .domain, value: "bad host!", outcome: .proxy)],
            appPresetRules: [
                RoutingRule(id: "a1", kind: .appPreset, matchValue: "telegram", outcome: .tunnel),
                RoutingRule(id: "a2", kind: .appPreset, matchValue: "unknown-app", outcome: .tunnel),
            ],
            countryRules: [
                RoutingRule(id: "k1", kind: .country, matchValue: "JP", outcome: .direct),
                RoutingRule(id: "k2", kind: .country, matchValue: "JPN", outcome: .direct),
                RoutingRule(id: "k3", kind: .country, matchValue: "SJ", outcome: .direct),
            ],
            categoryRules: [RoutingRule(id: "g1", kind: .websiteCategory, matchValue: "ai", outcome: .tunnel, targetNodeId: 9)]
        )
        XCTAssertEqual(snapshot.warnings.count, 4)
        let compiled = engine.compileRouteRules(snapshot)
        XCTAssertEqual(compiled.map { $0.sourceRuleId ?? "" }, ["a1", "k1", "g1"])
        XCTAssertEqual(compiled[1].ruleSetTags, ["geoip-jp"])
        XCTAssertEqual(compiled[1].outbound, "direct")
        XCTAssertEqual(compiled[2].outbound, "<NODE:9>")
        XCTAssertEqual(engine.requiredRuleSets(snapshot).map { $0.tag },
                       ["geosite-telegram", "geoip-telegram", "geoip-jp", "geosite-openai", "geosite-category-ai-!cn"])

        let global = engine.compile(globalMode: .global, countryRules: [RoutingRule(id: "k1", kind: .country, matchValue: "JP", outcome: .direct)])
        XCTAssertTrue(engine.compileRouteRules(global).isEmpty)
        XCTAssertEqual(engine.decide(global, RoutingRequest(countryCode: "JP")).outcome, .tunnel)
        XCTAssertEqual(engine.decide(snapshot, RoutingRequest(destinationHost: "Backup.Example.NET")).reason, .systemProtected)
        XCTAssertEqual(engine.decide(snapshot, RoutingRequest(countryCode: "jp")).outcome, .direct)
    }

    func testCountryCatalog() {
        XCTAssertEqual(CountryCatalog.all.count, 249)
        XCTAssertEqual(CountryCatalog.name(for: "jp", zh: true), "日本")
        XCTAssertEqual(CountryCatalog.name(for: "zz", zh: false), "ZZ")
        XCTAssertEqual(CountryCatalog.search("japan").first?.code, "JP")
        XCTAssertEqual(CountryCatalog.search("cote").first?.code, "CI")
        XCTAssertEqual(CountryCatalog.search("中国").first?.code, "CN")
        XCTAssertTrue(CountryCatalog.search(" ").isEmpty)
    }
}
