import XCTest
@testable import LeikwanIOSCore

/// Same fixtures as Android `SurgeListConverterTest.kt`.
final class SurgeListConverterTests: XCTestCase {
    func testBlackmatrixStyleListMergesIntoCompactHeadlessRules() throws {
        let text = """
        # NAME: ChinaMax
        DOMAIN,www.example.cn
        DOMAIN-SUFFIX,.Baidu.com
        DOMAIN-KEYWORD,taobao
        DOMAIN-REGEX,^ad\\d+\\.example\\.com$
        IP-CIDR,1.0.1.0/24,no-resolve
        IP-CIDR6,2400:da00::/32,no-resolve
        DST-PORT,8000-9000
        DST-PORT,443
        """
        let result = try SurgeListConverter.parse(text)

        XCTAssertEqual(result.convertedCount, 8)
        XCTAssertEqual(result.skippedCount, 0)
        XCTAssertEqual(result.rules[0]["domain"] as? [String], ["www.example.cn"])
        XCTAssertEqual(result.rules[0]["domain_suffix"] as? [String], ["baidu.com"])
        XCTAssertEqual(result.rules[0]["domain_keyword"] as? [String], ["taobao"])
        XCTAssertEqual(result.rules[0]["domain_regex"] as? [String], ["^ad\\d+\\.example\\.com$"])
        XCTAssertEqual(result.rules[1]["ip_cidr"] as? [String], ["1.0.1.0/24", "2400:da00::/32"])
        XCTAssertEqual(result.rules[2]["port"] as? [Int], [443])
        XCTAssertEqual(result.rules[3]["port_range"] as? [String], ["8000:9000"])
    }

    func testLogicalRulesBecomeLogicalAndNotInverts() throws {
        let text = """
        AND,((DOMAIN-SUFFIX,example.com),(DST-PORT,443)),DIRECT
        OR,((DOMAIN,a.com),(IP-CIDR,8.8.8.0/24)),PROXY
        NOT,((DOMAIN-KEYWORD,ads)),REJECT
        AND,((OR,((DOMAIN,x.com),(DOMAIN,y.com))),(NOT,((DST-PORT,80)))),DIRECT
        """
        let result = try SurgeListConverter.parse(text)

        XCTAssertEqual(result.convertedCount, 4)
        let and = result.rules[0]
        XCTAssertEqual(and["type"] as? String, "logical")
        XCTAssertEqual(and["mode"] as? String, "and")
        let andRules = try XCTUnwrap(and["rules"] as? [[String: Any]])
        XCTAssertEqual(andRules[0]["domain_suffix"] as? [String], ["example.com"])
        XCTAssertEqual(andRules[1]["port"] as? [Int], [443])
        XCTAssertEqual(result.rules[1]["mode"] as? String, "or")
        XCTAssertEqual(result.rules[2]["domain_keyword"] as? [String], ["ads"])
        XCTAssertEqual(result.rules[2]["invert"] as? Bool, true)
        let nested = try XCTUnwrap(result.rules[3]["rules"] as? [[String: Any]])
        XCTAssertEqual(nested[0]["mode"] as? String, "or")
        XCTAssertEqual(nested[1]["invert"] as? Bool, true)
        XCTAssertEqual(result.policies, ["DIRECT": 2, "PROXY": 1, "REJECT": 1])
    }

    func testUnsupportedTypesAreSkippedAndCounted() throws {
        let text = """
        PROCESS-NAME,WeChat,DIRECT
        URL-REGEX,^https?://ad\\.,REJECT
        IP-ASN,13335
        GEOIP,CN
        USER-AGENT,MicroMessenger*
        AND,((PROCESS-NAME,curl),(DOMAIN,a.com)),DIRECT
        DOMAIN-REGEX,(?=lookahead)
        IP-CIDR,999.1.1.1/24
        DOMAIN,bad domain.com
        DOMAIN,ok.com
        FINAL,PROXY
        """
        let result = try SurgeListConverter.parse(text)

        XCTAssertEqual(result.convertedCount, 1)
        for type in ["PROCESS-NAME", "URL-REGEX", "IP-ASN", "GEOIP", "USER-AGENT", "LOGICAL-UNSUPPORTED"] {
            XCTAssertEqual(result.skipped[type], 1, type)
        }
        XCTAssertEqual(result.skipped["INVALID"], 3)
        XCTAssertEqual(result.finalPolicy, "PROXY")
        XCTAssertFalse(result.samples.isEmpty)
    }

    func testClashPayloadYamlAndComments() throws {
        let text = "\u{FEFF}payload:\r\n  - DOMAIN-SUFFIX,google.com\r\n  - 'IP-CIDR,8.8.8.8/32,no-resolve'\r\n  // comment\r\n  ; another\r\n  - \"DOMAIN,mail.google.com\" # trailing\r\n"
        let result = try SurgeListConverter.parse(text)

        XCTAssertEqual(result.convertedCount, 3)
        XCTAssertEqual(result.skippedCount, 0)
        XCTAssertEqual(result.rules[0]["domain"] as? [String], ["mail.google.com"])
    }

    func testWildcardAndSourceIP() throws {
        let result = try SurgeListConverter.parse("DOMAIN-WILDCARD,*.example.?n\nSRC-IP-CIDR,192.168.1.0/24")

        let regex = try XCTUnwrap((result.rules[0]["domain_regex"] as? [String])?.first)
        XCTAssertEqual(regex, "^.*\\.example\\..n$")
        XCTAssertNotNil("a.example.cn".range(of: regex, options: .regularExpression))
        XCTAssertEqual(result.rules[1]["source_ip_cidr"] as? [String], ["192.168.1.0/24"])
    }

    func testBareCidrGetsFullPrefixAndDuplicatesCollapse() throws {
        let result = try SurgeListConverter.parse("IP-CIDR,1.1.1.1\nIP-CIDR,1.1.1.1/32\nIP-CIDR6,::1")
        XCTAssertEqual(result.rules[0]["ip_cidr"] as? [String], ["1.1.1.1/32", "::1/128"])
    }

    func testRuleCapAndSizeLimit() throws {
        let text = (1...(SurgeListConverter.maxRules + 5)).map { "DOMAIN,d\($0).example.com" }.joined(separator: "\n")
        let result = try SurgeListConverter.parse(text)
        XCTAssertEqual(result.convertedCount, SurgeListConverter.maxRules)
        XCTAssertEqual(result.skipped["LIMIT"], 5)

        XCTAssertThrowsError(try SurgeListConverter.parse(String(repeating: "D", count: SurgeListConverter.maxBytes + 1)))
    }

    func testJSONIsVersionTwoSourceRuleSet() throws {
        let data = try SurgeListConverter.json(try SurgeListConverter.parse("DOMAIN,a.com"))
        let object = try XCTUnwrap(JSONSerialization.jsonObject(with: data) as? [String: Any])
        XCTAssertEqual(object["version"] as? Int, 2)
        let rules = try XCTUnwrap(object["rules"] as? [[String: Any]])
        XCTAssertEqual(rules[0]["domain"] as? [String], ["a.com"])
    }

    // MARK: Store + USER-LIST pipeline

    func testStoreRoundTripRefreshAndDelete() throws {
        let dir = FileManager.default.temporaryDirectory.appendingPathComponent("lists-\(UUID().uuidString)")
        let store = ImportedRuleListStore(directory: dir)
        let first = try store.save(name: "", sourceURL: "https://example.invalid/Foo.list", conversion: try SurgeListConverter.parse("DOMAIN,a.com"))
        XCTAssertEqual(first.name, "Foo.list")
        XCTAssertTrue(ImportedRuleListStore.isValidID(first.id))
        XCTAssertNotNil(store.path(for: first.id))

        let refreshed = try store.save(name: first.name, sourceURL: first.sourceURL, conversion: try SurgeListConverter.parse("DOMAIN,a.com\nDOMAIN,b.com"), existingID: first.id)
        XCTAssertEqual(refreshed.id, first.id)
        XCTAssertEqual(store.get(first.id)?.ruleCount, 2)
        XCTAssertEqual(store.all().count, 1)

        store.delete(first.id)
        XCTAssertTrue(store.all().isEmpty)
        XCTAssertNil(store.path(for: first.id))
        XCTAssertNil(store.path(for: "../escape"))
    }

    func testUserListRuleIsALocalSourceRuleSet() throws {
        let id = "l0123456789ab"
        let rule = CustomRoutingRule(id: "list-\(id)", type: .userList, value: id, outcome: .direct)
        XCTAssertEqual(rule.normalizedValue(), id)
        XCTAssertNil(CustomRoutingRule(id: "x", type: .userList, value: "../etc", outcome: .direct).normalizedValue())
        XCTAssertNil(CustomRoutingRule.parseLine("USER-LIST,\(id),DIRECT", id: "p"), "never typed or pasted")
        XCTAssertFalse(CustomRuleType.manualTypes.contains(.userList))

        let snapshot = RoutingPolicyEngine().compile(customRules: [rule])
        XCTAssertTrue(snapshot.warnings.isEmpty, "\(snapshot.warnings)")
        let builder = SingboxConfigBuilder(settings: SingboxSettings(
            cacheFilePath: "/c.db",
            ruleSetDirectory: "/group/rule-sets",
            userRuleSetDirectory: "/group/user-rule-sets/"
        ))
        let sets = builder.buildRuleSets(snapshot: snapshot, bypassChina: false)
        let entry = try XCTUnwrap(sets.first { ($0["tag"] as? String) == "user-list-\(id)" })
        XCTAssertEqual(entry["type"] as? String, "local")
        XCTAssertEqual(entry["format"] as? String, "source")
        XCTAssertEqual(entry["path"] as? String, "/group/user-rule-sets/\(id).json")

        // Custom rules reach route.rules through singboxMatch (covered for every type by SingboxConfigTests).
        XCTAssertEqual(rule.singboxMatch()?["rule_set"] as? [String], ["user-list-\(id)"])
    }
}
