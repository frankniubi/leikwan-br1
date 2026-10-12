import Foundation

/// Compiler + precedence authority (port of `routing/RoutingPolicyEngine.kt` with iOS additions).
///
/// Precedence (identical in `decide` and in the sing-box route):
/// protected paths > runtime reachability > custom rules > app presets > countries > categories
/// > bypass-China > custom bypass > final. In `.global` / `.direct` mode user rules are ignored.
public struct RoutingPolicyEngine {
    public static let proxyOutboundSentinel = "<PROXY>"
    public static let geoipBase = "https://raw.githubusercontent.com/SagerNet/sing-geoip/rule-set/"

    public static func nodeSentinel(_ nodeId: Int64) -> String { return "<NODE:\(nodeId)>" }

    public static func geoipTag(_ countryCode: String) -> String {
        return "geoip-" + countryCode.trimmingCharacters(in: .whitespacesAndNewlines).lowercased()
    }

    public static func geoipUrl(_ tag: String) -> String { return geoipBase + tag + ".srs" }

    public let safePathRegistry: SafePathRegistry
    public let categoryCatalog: RoutingCategoryCatalog

    public init(safePathRegistry: SafePathRegistry = .iosBaseline(), categoryCatalog: RoutingCategoryCatalog = .standard) {
        self.safePathRegistry = safePathRegistry
        self.categoryCatalog = categoryCatalog
    }

    /// Drops disabled/unsupported rules with a warning; outcomes never expand beyond TUNNEL/DIRECT
    /// (custom rules additionally allow REJECT).
    public func compile(
        globalMode: RoutingGlobalMode = .rule,
        globalDefault: UserTrafficOutcome = .tunnel,
        globalDefaultNodeId: Int64? = nil,
        customRules: [CustomRoutingRule] = [],
        appPresetRules: [RoutingRule] = [],
        countryRules: [RoutingRule] = [],
        categoryRules: [RoutingRule] = [],
        compiledAtMs: Int64 = 0
    ) -> RoutingPolicySnapshot {
        var warnings: [String] = []

        let keptCustom = customRules.filter { rule in
            if !rule.enabled { return false }
            if rule.normalizedValue() == nil {
                warnings.append("Dropped custom rule '\(rule.id)': invalid \(rule.type.rawValue) value '\(rule.value)'.")
                return false
            }
            return true
        }
        let keptApps = appPresetRules.filter { rule in
            if !rule.enabled { return false }
            if rule.kind != .appPreset {
                warnings.append("Dropped rule '\(rule.id)': kind \(rule.kind.rawValue) is not an app preset rule.")
                return false
            }
            if !AppPresetCatalog.supports(rule.matchValue) {
                warnings.append("Dropped app rule '\(rule.id)': unknown app preset '\(rule.matchValue)'.")
                return false
            }
            return true
        }
        let keptCountry = countryRules.filter { rule in
            if !rule.enabled { return false }
            if rule.kind != .country {
                warnings.append("Dropped rule '\(rule.id)': kind \(rule.kind.rawValue) is not a country rule.")
                return false
            }
            if !RoutingPolicyEngine.isSupportedCountry(rule.matchValue) {
                warnings.append("Dropped country rule '\(rule.id)': unsupported country code '\(rule.matchValue)'.")
                return false
            }
            return true
        }
        let keptCategory = categoryRules.filter { rule in
            if !rule.enabled { return false }
            if rule.kind != .websiteCategory {
                warnings.append("Dropped rule '\(rule.id)': kind \(rule.kind.rawValue) is not a website-category rule.")
                return false
            }
            if !categoryCatalog.supports(rule.matchValue) {
                warnings.append("Dropped category rule '\(rule.id)': unknown category '\(rule.matchValue)'.")
                return false
            }
            return true
        }

        return RoutingPolicySnapshot(
            protectedPaths: safePathRegistry.entries,
            globalMode: globalMode,
            customRules: keptCustom,
            userAppPresetRules: keptApps,
            userCountryRules: keptCountry,
            userCategoryRules: keptCategory,
            globalDefaultOutcome: globalDefault,
            globalDefaultNodeId: globalDefaultNodeId,
            compileTimestampMs: compiledAtMs,
            warnings: warnings
        )
    }

    /// Country/category/app rules projected to rule_set route rules, in precedence order
    /// (app presets, countries, categories). Empty outside `.rule` mode.
    public func compileRouteRules(_ snapshot: RoutingPolicySnapshot) -> [CompiledRouteRule] {
        guard snapshot.globalMode == .rule else { return [] }
        var compiled: [CompiledRouteRule] = []
        for rule in snapshot.userAppPresetRules {
            guard let preset = AppPresetCatalog.preset(for: rule.matchValue), !preset.ruleSets.isEmpty else { continue }
            compiled.append(CompiledRouteRule(ruleSetTags: preset.tags, outbound: outbound(for: rule), sourceRuleId: rule.id))
        }
        for rule in snapshot.userCountryRules {
            compiled.append(CompiledRouteRule(
                ruleSetTags: [RoutingPolicyEngine.geoipTag(rule.matchValue)],
                outbound: outbound(for: rule),
                sourceRuleId: rule.id
            ))
        }
        for rule in snapshot.userCategoryRules {
            let tags = categoryCatalog.ruleSets(for: rule.matchValue).map { $0.tag }
            if !tags.isEmpty {
                compiled.append(CompiledRouteRule(ruleSetTags: tags, outbound: outbound(for: rule), sourceRuleId: rule.id))
            }
        }
        return compiled
    }

    /// Every remote rule-set the snapshot's user rules need, de-duplicated by tag (first seen wins).
    public func requiredRuleSets(_ snapshot: RoutingPolicySnapshot) -> [RuleSetRef] {
        guard snapshot.globalMode == .rule else { return [] }
        var refs: [RuleSetRef] = []
        var seen = Set<String>()
        func add(_ ref: RuleSetRef) {
            if seen.insert(ref.tag).inserted { refs.append(ref) }
        }
        for rule in snapshot.customRules {
            if let ref = rule.ruleSetRef { add(ref) }
        }
        for rule in snapshot.userAppPresetRules {
            AppPresetCatalog.preset(for: rule.matchValue)?.ruleSets.forEach(add)
        }
        for rule in snapshot.userCountryRules {
            let tag = RoutingPolicyEngine.geoipTag(rule.matchValue)
            add(RuleSetRef(tag: tag, url: RoutingPolicyEngine.geoipUrl(tag)))
        }
        for rule in snapshot.userCategoryRules {
            categoryCatalog.ruleSets(for: rule.matchValue).forEach(add)
        }
        return refs
    }

    /// Diagnostics-only precedence statement (not the data path).
    public func decide(_ snapshot: RoutingPolicySnapshot, _ request: RoutingRequest) -> RoutingDecision {
        let host = request.destinationHost?.trimmingCharacters(in: .whitespacesAndNewlines).lowercased()
        let ip = request.destinationIp?.trimmingCharacters(in: .whitespacesAndNewlines)
        if let match = snapshot.protectedPaths.first(where: { entry in
            (host != nil && entry.domains.contains { $0.lowercased() == host }) || (ip != nil && entry.ipCidrs.contains(ip ?? ""))
        }) {
            return RoutingDecision(outcome: .direct, reason: .systemProtected, matchedRuleId: nil, protectedPathId: match.id)
        }
        switch snapshot.globalMode {
        case .global:
            return RoutingDecision(outcome: .tunnel, reason: .defaultOutcome, matchedRuleId: nil, protectedPathId: nil)
        case .direct:
            return RoutingDecision(outcome: .direct, reason: .defaultOutcome, matchedRuleId: nil, protectedPathId: nil)
        case .rule:
            break
        }
        if let app = request.appPreset,
           let rule = snapshot.userAppPresetRules.first(where: { $0.matchValue.lowercased() == app.lowercased() }) {
            return RoutingDecision(outcome: rule.outcome, reason: .userRule, matchedRuleId: rule.id, protectedPathId: nil)
        }
        if let country = request.countryCode,
           let rule = snapshot.userCountryRules.first(where: { $0.matchValue.lowercased() == country.lowercased() }) {
            return RoutingDecision(outcome: rule.outcome, reason: .userRule, matchedRuleId: rule.id, protectedPathId: nil)
        }
        if let category = request.websiteCategory,
           let rule = snapshot.userCategoryRules.first(where: { $0.matchValue.lowercased() == category.lowercased() }) {
            return RoutingDecision(outcome: rule.outcome, reason: .userRule, matchedRuleId: rule.id, protectedPathId: nil)
        }
        return RoutingDecision(outcome: snapshot.globalDefaultOutcome, reason: .defaultOutcome, matchedRuleId: nil, protectedPathId: nil)
    }

    func outbound(for rule: RoutingRule) -> String {
        switch rule.outcome {
        case .direct: return "direct"
        case .tunnel:
            if let node = rule.targetNodeId { return RoutingPolicyEngine.nodeSentinel(node) }
            return RoutingPolicyEngine.proxyOutboundSentinel
        }
    }

    static func isSupportedCountry(_ value: String) -> Bool {
        let code = value.trimmingCharacters(in: .whitespacesAndNewlines)
        return code.count == 2 && code.allSatisfy { $0.isLetter }
    }
}
