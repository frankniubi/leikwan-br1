import XCTest
@testable import LeikwanIOSCore

final class SurgeModuleTests: XCTestCase {
    /// Shape of a real-world module: one direct rule, cron + event + generic scripts sharing
    /// one source, a panel, and a module argument substituted into the script arguments.
    private let allowlistModule = """
    #!name=Firewall allowlist
    #!desc=Adds this device's exit address to the allowlist.
    #!category=demo
    #!author=someone
    #!arguments=tokens:""
    #!arguments-desc=tokens: API tokens, comma separated

    [Rule]
    # The API must see the real exit address
    IP-CIDR,203.0.113.228/32,DIRECT,no-resolve

    [Script]
    fw-cron = type=cron,cronexp="*/10 * * * *",script-path=https://example.com/scripts/allowlist.js,timeout=60,argument="tokens={{{tokens}}}"
    fw-event = type=event,event-name=network-changed,script-path=https://example.com/scripts/allowlist.js,timeout=60,argument="tokens={{{tokens}}}"
    fw-panel = type=generic,script-path=https://example.com/scripts/allowlist.js,timeout=60,argument="tokens={{{tokens}}}"

    [Panel]
    fw = script-name=fw-panel,title=Firewall allowlist,content=Tap to refresh,update-interval=300
    """

    func testParsesMetadataRulesScriptsAndPanel() {
        let module = SurgeModule.parse(allowlistModule, argumentValues: ["tokens": "tok_a,tok_b@0"])
        XCTAssertEqual(module.name, "Firewall allowlist")
        XCTAssertEqual(module.author, "someone")
        XCTAssertEqual(module.category, "demo")
        XCTAssertEqual(module.arguments, [SurgeModule.Argument(name: "tokens", defaultValue: "")])
        XCTAssertEqual(module.argumentsDescription, "tokens: API tokens, comma separated")
        XCTAssertEqual(module.unsupported, [:])

        XCTAssertEqual(module.rules, [
            SurgeModule.Rule(match: .inline(type: .ipCidr, value: "203.0.113.228/32"), outcome: .direct),
        ])

        XCTAssertEqual(module.scripts.map { $0.name }, ["fw-cron", "fw-event", "fw-panel"])
        XCTAssertEqual(module.scripts.map { $0.kind }, [.cron, .event, .generic])
        XCTAssertEqual(module.scripts[0].cronExpression, "*/10 * * * *")
        XCTAssertEqual(module.scripts[1].eventName, "network-changed")
        for script in module.scripts {
            XCTAssertEqual(script.scriptPath, "https://example.com/scripts/allowlist.js")
            XCTAssertEqual(script.timeoutSeconds, 60)
            // The quoted argument keeps the comma that separates the two tokens.
            XCTAssertEqual(script.argument, "tokens=tok_a,tok_b@0")
        }
        XCTAssertEqual(module.scriptURLs, ["https://example.com/scripts/allowlist.js"])

        XCTAssertEqual(module.panels, [
            SurgeModule.Panel(name: "fw", scriptName: "fw-panel", title: "Firewall allowlist", content: "Tap to refresh", updateInterval: 300),
        ])
    }

    func testArgumentDefaultsApplyWhenNoValueIsGiven() {
        let text = """
        #!name=Args
        #!arguments=host:example.org,mode:"a,b"
        [Rule]
        DOMAIN-SUFFIX,{{{host}}},DIRECT
        [Script]
        s = type=generic,script-path=https://example.com/s.js,argument="mode={{{mode}}}"
        """
        let module = SurgeModule.parse(text)
        XCTAssertEqual(module.arguments.map { $0.name }, ["host", "mode"])
        XCTAssertEqual(module.arguments.map { $0.defaultValue }, ["example.org", "a,b"])
        XCTAssertEqual(module.rules.first?.match, .inline(type: .domainSuffix, value: "example.org"))
        XCTAssertEqual(module.scripts.first?.argument, "mode=a,b")
        XCTAssertEqual(module.scripts.first?.timeoutSeconds, SurgeModule.defaultScriptTimeout)

        let overridden = SurgeModule.parse(text, argumentValues: ["host": "example.net"])
        XCTAssertEqual(overridden.rules.first?.match, .inline(type: .domainSuffix, value: "example.net"))
    }

    func testCountsWhatItCannotRun() {
        let text = """
        #!name=Mixed
        [General]
        skip-proxy = %APPEND% 10.0.0.0/8
        [Rule]
        PROCESS-NAME,Telegram,PROXY
        URL-REGEX,^https?://ads\\.example\\.com,REJECT
        DOMAIN,ok.example.com,REJECT-DROP
        RULE-SET,https://example.com/list.list,DIRECT
        DOMAIN-SET,https://example.com/domains.txt,PROXY
        RULE-SET,LAN,DIRECT
        [URL Rewrite]
        ^https?://a\\.example\\.com https://b.example.com 302
        [Script]
        rewrite = type=http-response,pattern=^https://api\\.example\\.com,script-path=https://example.com/r.js,requires-body=1
        plain = type=cron,cronexp="not a cron",script-path=https://example.com/c.js
        insecure = type=generic,script-path=http://example.com/plain.js
        other-event = type=event,event-name=notification,script-path=https://example.com/e.js
        http-request ^https://old\\.example\\.com script-path=https://example.com/old.js
        [MITM]
        hostname = %APPEND% api.example.com
        """
        let module = SurgeModule.parse(text)
        XCTAssertEqual(module.rules, [
            SurgeModule.Rule(match: .inline(type: .domain, value: "ok.example.com"), outcome: .reject),
            SurgeModule.Rule(match: .remoteList(url: "https://example.com/list.list", domainSet: false), outcome: .direct),
            SurgeModule.Rule(match: .remoteList(url: "https://example.com/domains.txt", domainSet: true), outcome: .proxy),
        ])
        XCTAssertTrue(module.scripts.isEmpty)
        XCTAssertEqual(module.unsupported, [
            "General": 1,
            "Rule PROCESS-NAME": 1,
            "Rule URL-REGEX": 1,
            "Rule RULE-SET": 1,
            "URL Rewrite": 1,
            "Script http-response": 1,
            "Script cron": 1,
            "Script generic": 1,
            "Script event notification": 1,
            "Script": 1,
            "MITM": 1,
        ])
        XCTAssertEqual(module.remoteLists.map { $0.url }, ["https://example.com/list.list", "https://example.com/domains.txt"])
    }

    func testSurgeRuleTypesBecomeRouteRules() throws {
        let lines = """
        DOMAIN-WILDCARD,*.cdn.example.com,PROXY
        SRC-IP,192.168.1.0/24,DIRECT
        SRC-PORT,5000-5100,DIRECT
        DEST-PORT,8000-9000,REJECT
        PROTOCOL,QUIC,REJECT
        SUBNET,TYPE:CELLULAR,DIRECT
        SUBNET,SSID:Home Wi-Fi,DIRECT
        AND,((DOMAIN-SUFFIX,example.com),(DST-PORT,443),(PROTOCOL,TCP)),PROXY
        OR,((DOMAIN,a.example.com),(IP-CIDR,198.51.100.0/24)),DIRECT
        NOT,((DOMAIN-SUFFIX,example.org)),REJECT
        IP-CIDR6,2001:db8::/32,DIRECT,no-resolve
        """
        let parsed = CustomRoutingRule.parseLines(lines)
        XCTAssertEqual(parsed.errors, [])
        XCTAssertEqual(parsed.rules.map { $0.type }, [
            .domainWildcard, .srcIp, .srcPort, .dstPort, .protocolType, .subnet, .subnet,
            .logicalAnd, .logicalOr, .logicalNot, .ipCidr,
        ])
        XCTAssertEqual(parsed.rules.map { $0.outcome }, [
            .proxy, .direct, .direct, .reject, .reject, .direct, .direct, .proxy, .direct, .reject, .direct,
        ])
        func match(_ index: Int) throws -> NSDictionary {
            try XCTUnwrap(parsed.rules[index].singboxMatch()) as NSDictionary
        }
        XCTAssertEqual(try match(0), ["domain_regex": ["^.*\\.cdn\\.example\\.com$"]])
        XCTAssertEqual(try match(1), ["source_ip_cidr": ["192.168.1.0/24"]])
        XCTAssertEqual(try match(2), ["source_port_range": ["5000:5100"]])
        XCTAssertEqual(try match(3), ["port_range": "8000:9000"])
        XCTAssertEqual(try match(4), ["protocol": ["quic"]])
        XCTAssertEqual(try match(5), ["network_type": ["cellular"]])
        XCTAssertEqual(try match(6), ["wifi_ssid": ["Home Wi-Fi"]])
        XCTAssertEqual(try match(7), [
            "type": "logical",
            "mode": "and",
            "rules": [["domain_suffix": ["example.com"]], ["port": [443]], ["network": ["tcp"]]],
        ])
        XCTAssertEqual(try match(8), [
            "type": "logical",
            "mode": "or",
            "rules": [["domain": ["a.example.com"]], ["ip_cidr": ["198.51.100.0/24"]]],
        ])
        XCTAssertEqual(try match(9), ["domain_suffix": ["example.org"], "invert": true])
        // IPLiteral writes IPv6 uncompressed.
        XCTAssertEqual(try match(10), ["ip_cidr": ["2001:db8:0:0:0:0:0:0/32"]])
    }

    func testRuleTypesWithoutARouteMatchAreRejected() {
        XCTAssertNil(CustomRoutingRule.parseLine("PROTOCOL,DOH,REJECT", id: "x"))
        XCTAssertNil(CustomRoutingRule.parseLine("SUBNET,ROUTER:192.168.1.1,DIRECT", id: "x"))
        XCTAssertNil(CustomRoutingRule.parseLine("AND,((PROCESS-NAME,app),(DST-PORT,443)),DIRECT", id: "x"))
        XCTAssertNil(CustomRoutingRule.parseLine("FINAL,PROXY", id: "x"))
        // Imported lists keep the narrower, Android-parity operand set.
        XCTAssertNil(SurgeListConverter.convertRuleLine("AND,((DOMAIN,a.example.com),(PROTOCOL,TCP))"))
        XCTAssertNotNil(SurgeListConverter.convertRuleLine("AND,((DOMAIN,a.example.com),(PROTOCOL,TCP))", extended: true))
    }

    func testDomainSetConversion() throws {
        let conversion = try SurgeListConverter.parseDomainSet("""
        # comment
        example.com
        .example.org
        not a domain
        """)
        XCTAssertEqual(conversion.convertedCount, 2)
        XCTAssertEqual(conversion.skipped, ["INVALID": 1])
        XCTAssertEqual(conversion.rules as NSArray, [["domain": ["example.com"], "domain_suffix": ["example.org"]]])
    }

    // MARK: Store

    func testStoreResolvesRulesScriptsAndPanelsOfEnabledModules() throws {
        let directory = FileManager.default.temporaryDirectory.appendingPathComponent("modules-\(UUID().uuidString)")
        defer { try? FileManager.default.removeItem(at: directory) }
        let store = SurgeModuleStore(directory: directory)
        XCTAssertEqual(store.load(), ModuleLibrary())

        let id = SurgeModuleStore.newID()
        XCTAssertTrue(SurgeModuleStore.isValidID(id))
        XCTAssertFalse(SurgeModuleStore.isValidID("../etc"))
        try store.writeModuleText(allowlistModule, id: id)
        let file = try store.writeScript(Data("$done({})".utf8), moduleID: id)
        // Whole seconds: the index stores dates as seconds since 1970.
        let installed = Date(timeIntervalSince1970: 1_800_000_000)
        var record = InstalledModule(id: id, sourceURL: "https://example.com/m.sgmodule", name: "Firewall allowlist", installedAt: installed, updatedAt: installed)
        record.argumentValues = ["tokens": "tok_a"]
        record.scriptFiles = ["https://example.com/scripts/allowlist.js": file]
        var library = ModuleLibrary(modules: [record])
        try store.save(library)
        XCTAssertEqual(store.load(), library)

        let rules = store.customRules(library)
        XCTAssertEqual(rules.count, 1)
        XCTAssertEqual(rules[0].id, "module-\(id)-0")
        XCTAssertEqual(rules[0].type, .ipCidr)
        XCTAssertEqual(rules[0].outcome, .direct)

        let scripts = store.resolvedScripts(library)
        XCTAssertEqual(scripts.map { $0.key }, ["\(id)/fw-cron", "\(id)/fw-event", "\(id)/fw-panel"])
        XCTAssertEqual(scripts[0].argument, "tokens=tok_a")
        XCTAssertTrue(FileManager.default.fileExists(atPath: scripts[0].sourcePath))

        let panels = store.resolvedPanels(library)
        XCTAssertEqual(panels.map { $0.scriptKey }, ["\(id)/fw-panel"])
        XCTAssertEqual(panels[0].updateInterval, 300)

        // Scripts off: rules stay, scripts and the panel's script go.
        library.scriptsEnabled = false
        XCTAssertEqual(store.customRules(library).count, 1)
        XCTAssertTrue(store.resolvedScripts(library).isEmpty)
        XCTAssertEqual(store.resolvedPanels(library).map { $0.scriptKey }, [nil])

        // Module off: nothing of it applies.
        library.scriptsEnabled = true
        library.modules[0].enabled = false
        XCTAssertTrue(store.customRules(library).isEmpty)
        XCTAssertTrue(store.resolvedScripts(library).isEmpty)
        XCTAssertTrue(store.resolvedPanels(library).isEmpty)

        // A script whose file is missing is not offered to the tunnel.
        library.modules[0].enabled = true
        library.modules[0].scriptFiles = [:]
        XCTAssertTrue(store.resolvedScripts(library).isEmpty)
    }

    func testModuleRulesReachTheSingboxRouteBeforeUserRules() throws {
        let directory = FileManager.default.temporaryDirectory.appendingPathComponent("modules-\(UUID().uuidString)")
        defer { try? FileManager.default.removeItem(at: directory) }
        let store = SurgeModuleStore(directory: directory)
        let id = SurgeModuleStore.newID()
        try store.writeModuleText(allowlistModule, id: id)
        let library = ModuleLibrary(modules: [InstalledModule(id: id, sourceURL: nil, name: "m")])
        let user = CustomRoutingRule(id: "u1", type: .ipCidr, value: "203.0.113.0/24", outcome: .proxy)

        let builder = SingboxConfigBuilder()
        let snapshot = builder.routingEngine.compile(customRules: store.customRules(library) + [user])
        let compiled = try builder.build(proxyNodes: [], runtime: nil, routing: snapshot, connectionMode: .direct)
        let root = try XCTUnwrap(JSONLenient.parseObject(compiled.configJSON))
        let rules = try XCTUnwrap((root["route"] as? [String: Any])?["rules"] as? [[String: Any]])
        let moduleIndex = try XCTUnwrap(rules.firstIndex { ($0["ip_cidr"] as? [String]) == ["203.0.113.228/32"] })
        let userIndex = try XCTUnwrap(rules.firstIndex { ($0["ip_cidr"] as? [String]) == ["203.0.113.0/24"] })
        XCTAssertEqual(rules[moduleIndex]["outbound"] as? String, "direct")
        XCTAssertLessThan(moduleIndex, userIndex)
    }
}
