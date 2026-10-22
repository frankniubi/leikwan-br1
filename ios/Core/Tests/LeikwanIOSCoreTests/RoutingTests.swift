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
