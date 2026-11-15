import Foundation

/// Shadowrocket-style rule types supported on iOS, plus Clash extensions (GEOSITE, RULE-SET, DST-PORT, DOMAIN-REGEX).
public enum CustomRuleType: String, Codable, Equatable, CaseIterable {
    case domain = "DOMAIN"
    case domainSuffix = "DOMAIN-SUFFIX"
    case domainKeyword = "DOMAIN-KEYWORD"
    case ipCidr = "IP-CIDR"
    case geoip = "GEOIP"
    case geosite = "GEOSITE"
    case ruleSet = "RULE-SET"
    case dstPort = "DST-PORT"
    case domainRegex = "DOMAIN-REGEX"
    /// An imported Surge/Clash list (value = its `ImportedRuleListStore` id). Created by the
    /// list importer only, never typed or pasted as a line.
    case userList = "USER-LIST"
    /// Surge types whose match is built from the line itself by `SurgeListConverter`
    /// (value = the rule's second column; for AND/OR/NOT the parenthesised operands).
    case domainWildcard = "DOMAIN-WILDCARD"
    case srcIp = "SRC-IP"
    case srcPort = "SRC-PORT"
    case protocolType = "PROTOCOL"
    case subnet = "SUBNET"
    case logicalAnd = "AND"
    case logicalOr = "OR"
    case logicalNot = "NOT"

    /// Types a user can add by hand (the add-rule picker).
    public static var manualTypes: [CustomRuleType] { allCases.filter { $0 != .userList } }

    /// Surge spellings that mean one of the types above.
    static let aliases: [String: String] = [
        "IP-CIDR6": "IP-CIDR", "IP6-CIDR": "IP-CIDR", "DEST-PORT": "DST-PORT", "SRC-IP-CIDR": "SRC-IP",
    ]
}

public enum CustomRuleOutcome: String, Codable, Equatable, CaseIterable {
    case proxy
    case direct
    case reject
}

/// One user custom rule, e.g. `DOMAIN-SUFFIX,example.com,PROXY`.
public struct CustomRoutingRule: Codable, Equatable, Identifiable {
    public var id: String
    public var type: CustomRuleType
    public var value: String
    public var outcome: CustomRuleOutcome
    public var enabled: Bool
    /// `.proxy` only: run this rule's traffic through the binding of this node (its sing-box
    /// outbound `proxy-<id>`); nil = the default binding. Optional, so rules saved before
    /// multi-binding still decode.
    public var targetNodeId: Int64?

    public init(id: String, type: CustomRuleType, value: String, outcome: CustomRuleOutcome, enabled: Bool = true, targetNodeId: Int64? = nil) {
        self.id = id
        self.type = type
        self.value = value
        self.outcome = outcome
        self.enabled = enabled
        self.targetNodeId = targetNodeId
    }

    /// Parses `TYPE,value[,POLICY]` (Shadowrocket/Clash line syntax; POLICY defaults to proxy if omitted).
    /// `IP-CIDR6` is accepted as `IP-CIDR`; trailing options such as `no-resolve` are ignored.
    /// Type matching is case-insensitive. POLICY follows Clash semantics (see `clashPolicyOutcome(_:)`).
    public static func parseLine(_ line: String, id: String) -> CustomRoutingRule? {
        // Top-level commas only: AND/OR/NOT carry their operands in parentheses.
        let parts = SurgeListConverter.splitTopLevel(line).map { $0.trimmingCharacters(in: .whitespaces) }
        guard parts.count >= 2 else { return nil }
        var typeText = parts[0].uppercased()
        typeText = CustomRuleType.aliases[typeText] ?? typeText
        guard let type = CustomRuleType(rawValue: typeText), type != .userList else { return nil }

        let outcome: CustomRuleOutcome = parts.count >= 3 ? CustomRoutingRule.clashPolicyOutcome(parts[2]) : .proxy

        // Surge writes port ranges as 8000-9000, sing-box as 8000:9000.
        let value = type == .dstPort ? parts[1].replacingOccurrences(of: "-", with: ":") : parts[1]
        let rule = CustomRoutingRule(id: id, type: type, value: value, outcome: outcome)
        return rule.normalizedValue() == nil ? nil : rule
    }

    /// Clash policy -> outcome, matching Android's ClashRuleParser: `DIRECT` and `REJECT`
    /// (case-insensitive; Surge's `REJECT-DROP`, `REJECT-TINYGIF` and `REJECT-NO-DROP` count as
    /// `REJECT`) are themselves; any other policy name (`PROXY`, a proxy-group name such
    /// as `Proxies` or `🚀 节点选择`, or a stray option like `no-resolve`) goes through the proxy,
    /// because a pasted Clash profile names its groups freely and every one of them is a proxy.
    static func clashPolicyOutcome(_ policy: String) -> CustomRuleOutcome {
        switch policy.trimmingCharacters(in: .whitespaces).uppercased() {
        case "DIRECT": return .direct
        case "REJECT", "REJECT-DROP", "REJECT-TINYGIF", "REJECT-NO-DROP": return .reject
        default: return .proxy
        }
    }

    /// Validated, normalised match value; nil when invalid.
    public func normalizedValue() -> String? {
        let raw = value.trimmingCharacters(in: .whitespacesAndNewlines)
        if raw.isEmpty { return nil }
        switch type {
        case .domain, .domainSuffix:
            var host = raw.lowercased()
            if type == .domainSuffix && host.hasPrefix(".") { host.removeFirst() }
            if host.hasSuffix(".") { host.removeLast() }
            return ProtectedConnectivitySet.isHostname(host) ? host : nil
        case .domainKeyword:
            let keyword = raw.lowercased()
            if keyword.contains(" ") || keyword.contains(",") { return nil }
            return keyword
        case .ipCidr:
            return CustomRoutingRule.normalizeAnyCidr(raw)
        case .geoip:
            // Only countries with a bundled geoip set (a missing set would stop sing-box).
            let code = raw.lowercased()
            return BundledRuleSets.hasGeoip(code) ? code : nil
        case .geosite, .ruleSet:
            // Validate geosite/rule-set name: lowercase alphanumeric, dash, underscore.
            // Accept both the bare name (`telegram`) and the full tag (`geosite-telegram`), as
            // Android does; the stored value is the bare name (ruleSetRef re-adds the prefix).
            var name = raw.lowercased()
            if name.hasPrefix("geosite-") { name.removeFirst("geosite-".count) }
            guard !name.isEmpty, name.allSatisfy({ $0.isLowercase || $0.isNumber || $0 == "-" || $0 == "_" }) else { return nil }
            // Check if geosite-<name> is bundled.
            return BundledRuleSets.contains("geosite-\(name)") ? name : nil
        case .dstPort:
            // Single port (1-65535) or range "a:b" with a < b.
            if let port = UInt16(raw) { return raw }
            let range = raw.split(separator: ":", maxSplits: 1, omittingEmptySubsequences: false)
                .map { $0.trimmingCharacters(in: .whitespaces) }
            guard range.count == 2,
                  let a = UInt16(range[0]), let b = UInt16(range[1]), a < b
            else { return nil }
            return raw
        case .userList:
            return ImportedRuleListStore.isValidID(raw) ? raw : nil
        case .domainWildcard, .srcIp, .srcPort, .protocolType, .subnet, .logicalAnd, .logicalOr, .logicalNot:
            return SurgeListConverter.convertRuleLine("\(type.rawValue),\(raw)", extended: true) == nil ? nil : raw
        case .domainRegex:
            // Validate regex compiles, then reject syntax sing-box's RE2 engine can't run even
            // though Foundation's ICU-backed NSRegularExpression accepts it (lookaround,
            // backreferences, atomic groups) — see CustomRoutingRule.hasUnsupportedRE2Syntax.
            do {
                _ = try NSRegularExpression(pattern: raw, options: [])
                return CustomRoutingRule.hasUnsupportedRE2Syntax(raw) ? nil : raw
            } catch {
                return nil
            }
        }
    }

    /// sing-box compiles DOMAIN-REGEX with Go's RE2 engine (no backtracking): lookaround
    /// assertions, backreferences, and atomic groups are rejected even when they compile fine
    /// under ICU/NSRegularExpression. Mirrors ClashRuleParser.hasUnsupportedRe2Syntax on Android.
    static func hasUnsupportedRE2Syntax(_ pattern: String) -> Bool {
        let forbiddenLiterals = ["(?=", "(?!", "(?<=", "(?<!", "(?>"]
        if forbiddenLiterals.contains(where: { pattern.contains($0) }) { return true }
        guard let backreference = try? NSRegularExpression(pattern: #"\\[1-9]"#) else { return false }
        let range = NSRange(pattern.startIndex..<pattern.endIndex, in: pattern)
        return backreference.firstMatch(in: pattern, options: [], range: range) != nil
    }

    /// Accepts `a.b.c.d`, `a.b.c.d/n`, v6 and v6/n with any valid prefix (0...32 / 0...128).
    public static func normalizeAnyCidr(_ value: String) -> String? {
        let parts = value.split(separator: "/", maxSplits: 1, omittingEmptySubsequences: false).map(String.init)
        guard let literal = IPLiteral.normalize(parts[0]) else { return nil }
        let isV6 = literal.contains(":")
        let maxPrefix = isV6 ? 128 : 32
        var prefix = maxPrefix
        if parts.count == 2 {
            guard let parsed = Int(parts[1].trimmingCharacters(in: .whitespaces)), parsed >= 0, parsed <= maxPrefix else { return nil }
            prefix = parsed
        }
        return "\(literal)/\(prefix)"
    }

    /// sing-box rule-set tag needed by this rule (GEOIP, GEOSITE, or RULE-SET).
    public var ruleSetRef: RuleSetRef? {
        guard let normalized = normalizedValue() else { return nil }
        switch type {
        case .geoip: return RuleSetRef(tag: "geoip-\(normalized)")
        case .geosite, .ruleSet: return RuleSetRef(tag: "geosite-\(normalized)")
        case .userList: return RuleSetRef(tag: ImportedRuleListStore.tag(for: normalized))
        default: return nil
        }
    }

    /// The sing-box route rule (without outbound/action) or nil when invalid.
    public func singboxMatch() -> [String: Any]? {
        guard let normalized = normalizedValue() else { return nil }
        switch type {
        case .domain: return ["domain": [normalized]]
        case .domainSuffix: return ["domain_suffix": [normalized]]
        case .domainKeyword: return ["domain_keyword": [normalized]]
        case .ipCidr: return ["ip_cidr": [normalized]]
        case .geoip: return ["rule_set": ["geoip-\(normalized)"]]
        case .geosite, .ruleSet: return ["rule_set": ["geosite-\(normalized)"]]
        case .dstPort:
            if normalized.contains(":") {
                return ["port_range": normalized]
            } else {
                return ["port": [Int(normalized) ?? 0]]
            }
        case .domainRegex: return ["domain_regex": [normalized]]
        case .userList: return ["rule_set": [ImportedRuleListStore.tag(for: normalized)]]
        case .domainWildcard, .srcIp, .srcPort, .protocolType, .subnet, .logicalAnd, .logicalOr, .logicalNot:
            return SurgeListConverter.convertRuleLine("\(type.rawValue),\(normalized)", extended: true)
        }
    }

    /// Parses multiple lines (e.g., from paste); returns valid rules and error messages per invalid line.
    /// Error messages use English format "Line N: <message>" for Core layer (no localization).
    /// Lines are processed in order; valid rules retain their line order. Empty lines and comments (starting with #) are ignored.
    public static func parseLines(_ text: String) -> (rules: [CustomRoutingRule], errors: [String]) {
        var rules: [CustomRoutingRule] = []
        var errors: [String] = []
        let lines = text.split(separator: "\n", omittingEmptySubsequences: false)
            .map { String($0).trimmingCharacters(in: .whitespaces) }

        var lineNum = 0
        for line in lines {
            lineNum += 1
            if line.isEmpty || line.hasPrefix("#") { continue }
            let id = UUID().uuidString
            if let rule = parseLine(line, id: id) {
                rules.append(rule)
            } else {
                errors.append("Line \(lineNum): Invalid rule syntax or value.")
            }
        }

        return (rules, errors)
    }
}
