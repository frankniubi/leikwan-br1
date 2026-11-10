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

    /// Parses `TYPE,value[,OUTCOME]` (Shadowrocket/Clash line syntax; OUTCOME defaults to proxy if omitted).
    /// `IP-CIDR6` is accepted as `IP-CIDR`; trailing options such as `no-resolve` are ignored.
    /// Type matching is case-insensitive; outcome is lowercased.
    public static func parseLine(_ line: String, id: String) -> CustomRoutingRule? {
        let parts = line.split(separator: ",", omittingEmptySubsequences: false)
            .map { $0.trimmingCharacters(in: .whitespaces) }
        guard parts.count >= 2 else { return nil }
        var typeText = parts[0].uppercased()
        if typeText == "IP-CIDR6" { typeText = "IP-CIDR" }
        guard let type = CustomRuleType(rawValue: typeText) else { return nil }

        let outcome: CustomRuleOutcome
        if parts.count >= 3 {
            guard let parsed = CustomRuleOutcome(rawValue: parts[2].lowercased()) else { return nil }
            outcome = parsed
        } else {
            outcome = .proxy
        }

        let rule = CustomRoutingRule(id: id, type: type, value: parts[1], outcome: outcome)
        return rule.normalizedValue() == nil ? nil : rule
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
            let name = raw.lowercased()
            guard name.allSatisfy({ $0.isLowercase || $0.isNumber || $0 == "-" || $0 == "_" }) else { return nil }
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
        case .domainRegex:
            // Validate regex compiles.
            do {
                _ = try NSRegularExpression(pattern: raw, options: [])
                return raw
            } catch {
                return nil
            }
        }
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
