import Foundation

/// Shadowrocket-style rule types supported on iOS.
public enum CustomRuleType: String, Codable, Equatable, CaseIterable {
    case domain = "DOMAIN"
    case domainSuffix = "DOMAIN-SUFFIX"
    case domainKeyword = "DOMAIN-KEYWORD"
    case ipCidr = "IP-CIDR"
    case geoip = "GEOIP"
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

    /// Parses `TYPE,value,OUTCOME` (Shadowrocket line syntax; `IP-CIDR6` is accepted as `IP-CIDR`,
    /// trailing options such as `no-resolve` are ignored).
    public static func parseLine(_ line: String, id: String) -> CustomRoutingRule? {
        let parts = line.split(separator: ",", omittingEmptySubsequences: false)
            .map { $0.trimmingCharacters(in: .whitespaces) }
        guard parts.count >= 3 else { return nil }
        var typeText = parts[0].uppercased()
        if typeText == "IP-CIDR6" { typeText = "IP-CIDR" }
        guard let type = CustomRuleType(rawValue: typeText),
              let outcome = CustomRuleOutcome(rawValue: parts[2].lowercased())
        else { return nil }
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

    /// sing-box rule-set tag needed by this rule (GEOIP only).
    public var ruleSetRef: RuleSetRef? {
        guard type == .geoip, let code = normalizedValue() else { return nil }
        return RuleSetRef(tag: "geoip-\(code)")
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
        }
    }
}
