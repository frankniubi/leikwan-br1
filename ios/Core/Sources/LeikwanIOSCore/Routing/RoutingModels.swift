import Foundation

/// The only two outcomes a country/category/app rule (or the global default) may resolve to.
public enum UserTrafficOutcome: String, Codable, Equatable {
    case tunnel = "TUNNEL"
    case direct = "DIRECT"
}

/// iOS global routing mode (Shadowrocket-like).
public enum RoutingGlobalMode: String, Codable, Equatable, CaseIterable {
    /// Rule-based: protected > runtime > custom rules > app presets > countries > categories > bypass > final.
    case rule
    /// Everything (except protected/runtime paths) goes through the proxy.
    case global
    /// Everything goes direct (TUN stays up; user rules are ignored).
    case direct
}

public enum RoutingRuleKind: String, Codable, Equatable {
    case country = "COUNTRY"
    case websiteCategory = "WEBSITE_CATEGORY"
    /// iOS addition: app-centric preset pack (see `AppPresetCatalog`).
    case appPreset = "APP_PRESET"
}

public enum RoutingDecisionReason: String, Equatable {
    case systemProtected = "SYSTEM_PROTECTED"
    case runtimeReachability = "RUNTIME_REACHABILITY"
    case userRule = "USER_RULE"
    case defaultOutcome = "DEFAULT"
    case unsupportedRuleRejected = "UNSUPPORTED_RULE_REJECTED"
}

/// A user-authored country / category / app-preset rule (port of `RoutingRule`).
public struct RoutingRule: Codable, Equatable, Identifiable {
    public var id: String
    public var kind: RoutingRuleKind
    public var matchValue: String
    public var outcome: UserTrafficOutcome
    public var enabled: Bool
    /// TUNNEL only: nil = default proxy; non-nil = pin to this node id.
    public var targetNodeId: Int64?

    public init(id: String, kind: RoutingRuleKind, matchValue: String, outcome: UserTrafficOutcome, enabled: Bool = true, targetNodeId: Int64? = nil) {
        self.id = id
        self.kind = kind
        self.matchValue = matchValue
        self.outcome = outcome
        self.enabled = enabled
        self.targetNodeId = targetNodeId
    }
}

/// A system-protected destination set that user rules can never override.
public struct SafePathEntry: Equatable {
    public var id: String
    public var domains: [String]
    public var ipCidrs: [String]

    public init(id: String, domains: [String], ipCidrs: [String]) {
        self.id = id
        self.domains = domains
        self.ipCidrs = ipCidrs
    }
}

/// Port of `routing/SafePathRegistry.kt`. On iOS the hosts of the signed-in profile's server
/// addresses are protected (the client may fail over to the backup), plus loopback/RFC-1918.
public struct SafePathRegistry: Equatable {
    public let entries: [SafePathEntry]

    public init(entries: [SafePathEntry]) {
        self.entries = entries
    }

    /// `panelHosts`: the profile's server hosts (`PanelProfile.panelHosts`); IP literals become
    /// /32 or /128 entries, empty when signed out.
    public static func iosBaseline(panelHosts: [String] = []) -> SafePathRegistry {
        var hosts: [String] = []
        var cidrs: [String] = []
        for host in panelHosts {
            let normalized = host.trimmingCharacters(in: .whitespacesAndNewlines).lowercased()
            if normalized.isEmpty { continue }
            if let ip = IPLiteral.normalize(normalized) {
                let cidr = ip.contains(":") ? "\(ip)/128" : "\(ip)/32"
                if !cidrs.contains(cidr) { cidrs.append(cidr) }
            } else if !hosts.contains(normalized) {
                hosts.append(normalized)
            }
        }
        return SafePathRegistry(entries: [
            SafePathEntry(id: "panel-api", domains: hosts, ipCidrs: cidrs),
            SafePathEntry(id: "loopback-control", domains: [], ipCidrs: ["127.0.0.0/8", "10.0.0.0/8", "172.16.0.0/12", "192.168.0.0/16"]),
        ])
    }

    public func containsDomain(_ domain: String) -> Bool {
        return entries.contains { entry in entry.domains.contains { $0.lowercased() == domain.lowercased() } }
    }

    /// Every protected domain, in order (used for DNS bootstrap rules).
    public var allDomains: [String] {
        var result: [String] = []
        for entry in entries {
            for domain in entry.domains where !result.contains(domain) { result.append(domain) }
        }
        return result
    }
}

/// A `.srs` rule-set (format "binary") shipped inside the app; see `BundledRuleSets`.
public struct RuleSetRef: Equatable, Hashable {
    public let tag: String

    public init(tag: String) {
        self.tag = tag
    }
}

/// A rule compiled into one sing-box `route.rule`. `outbound` is "direct", `"<PROXY>"` or `"<NODE:id>"`.
public struct CompiledRouteRule: Equatable {
    public let ruleSetTags: [String]
    public let outbound: String
    public let sourceRuleId: String?

    public init(ruleSetTags: [String], outbound: String, sourceRuleId: String?) {
        self.ruleSetTags = ruleSetTags
        self.outbound = outbound
        self.sourceRuleId = sourceRuleId
    }
}

/// Authoritative routing state for one config build (port of `RoutingPolicySnapshot` + iOS additions).
public struct RoutingPolicySnapshot: Equatable {
    public var protectedPaths: [SafePathEntry]
    public var globalMode: RoutingGlobalMode
    public var customRules: [CustomRoutingRule]
    public var userAppPresetRules: [RoutingRule]
    public var userCountryRules: [RoutingRule]
    public var userCategoryRules: [RoutingRule]
    public var globalDefaultOutcome: UserTrafficOutcome
    public var globalDefaultNodeId: Int64?
    public var compileTimestampMs: Int64
    public var warnings: [String]

    public init(
        protectedPaths: [SafePathEntry],
        globalMode: RoutingGlobalMode = .rule,
        customRules: [CustomRoutingRule] = [],
        userAppPresetRules: [RoutingRule] = [],
        userCountryRules: [RoutingRule] = [],
        userCategoryRules: [RoutingRule] = [],
        globalDefaultOutcome: UserTrafficOutcome = .tunnel,
        globalDefaultNodeId: Int64? = nil,
        compileTimestampMs: Int64 = 0,
        warnings: [String] = []
    ) {
        self.protectedPaths = protectedPaths
        self.globalMode = globalMode
        self.customRules = customRules
        self.userAppPresetRules = userAppPresetRules
        self.userCountryRules = userCountryRules
        self.userCategoryRules = userCategoryRules
        self.globalDefaultOutcome = globalDefaultOutcome
        self.globalDefaultNodeId = globalDefaultNodeId
        self.compileTimestampMs = compileTimestampMs
        self.warnings = warnings
    }
}

/// Diagnostics-only precedence request/decision (not in the data path).
public struct RoutingRequest: Equatable {
    public var destinationHost: String?
    public var destinationIp: String?
    public var countryCode: String?
    public var websiteCategory: String?
    public var appPreset: String?

    public init(destinationHost: String? = nil, destinationIp: String? = nil, countryCode: String? = nil, websiteCategory: String? = nil, appPreset: String? = nil) {
        self.destinationHost = destinationHost
        self.destinationIp = destinationIp
        self.countryCode = countryCode
        self.websiteCategory = websiteCategory
        self.appPreset = appPreset
    }
}

public struct RoutingDecision: Equatable {
    public let outcome: UserTrafficOutcome
    public let reason: RoutingDecisionReason
    public let matchedRuleId: String?
    public let protectedPathId: String?
}
