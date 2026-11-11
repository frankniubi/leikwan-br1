package com.leikwan.bridge.routing

/**
 * Pure-JVM routing model types for the v2 safe-routing core.
 *
 * Enforcement of these rules lives entirely in sing-box `route.rules` (see
 * `SingboxConfigBuilder.buildRoute`). `RoutingPolicyEngine.decide()` is the canonical, unit-testable
 * statement of precedence (and powers diagnostics/explainability); it is explicitly NOT in the data
 * path. User rules resolve only to TUNNEL or DIRECT — never to arbitrary outbound graphs.
 */

/** Outcomes a user routing rule (or the global default) may resolve to. */
enum class UserTrafficOutcome { TUNNEL, DIRECT, REJECT }

/** The kinds of user-authored routing rule supported. */
enum class RoutingRuleKind {
    COUNTRY, WEBSITE_CATEGORY,
    // Clash-style rules:
    DOMAIN, DOMAIN_SUFFIX, DOMAIN_KEYWORD, DOMAIN_REGEX,
    IP_CIDR, IP_CIDR6,
    DST_PORT,
    GEOSITE, RULE_SET,
    /** A converted Surge/Clash rule list; matchValue = absolute path of its source rule-set file. */
    IMPORTED_LIST,
}

/** How a system-protected path is handled. Protected paths are always bypassed direct. */
enum class ProtectedPathHandling { BYPASS_DIRECT }

/** Why a routing decision resolved the way it did (precedence/diagnostics). */
enum class RoutingDecisionReason {
    SYSTEM_PROTECTED,
    RUNTIME_REACHABILITY,
    USER_RULE,
    DEFAULT,
    UNSUPPORTED_RULE_REJECTED,
}

/** A user-authored routing rule. Compiled into a single sing-box `route.rule`. */
data class RoutingRule(
    val id: String,
    val kind: RoutingRuleKind,
    val matchValue: String,
    val outcome: UserTrafficOutcome,
    val enabled: Boolean = true,
    /** When [outcome] is TUNNEL: null = use the global-default node; non-null = pin to this node id. */
    val targetNodeId: Long? = null,
)

/** A system-protected destination set that user rules can never override. */
data class SafePathEntry(
    val id: String,
    val domains: Set<String>,
    val ipCidrs: Set<String>,
    val handling: ProtectedPathHandling = ProtectedPathHandling.BYPASS_DIRECT,
)

/** A request the precedence engine evaluates (diagnostics / unit-testable precedence only). */
data class RoutingRequest(
    val destinationHost: String?,
    val destinationIp: String?,
    val countryCode: String?,
    val websiteCategory: String?,
    val isBootstrapTraffic: Boolean,
)

/** The result of a precedence evaluation. */
data class RoutingDecision(
    val outcome: UserTrafficOutcome,
    val reason: RoutingDecisionReason,
    val matchedRuleId: String?,
    val protectedPathId: String?,
)

/** A reference to a remote SagerNet `.srs` rule-set, emitted into `route.rule_set` (format "binary"). */
/** [localPath] set = a `local` source rule-set file (imported lists); otherwise remote binary [url]. */
data class RuleSetRef(val tag: String, val url: String, val localPath: String? = null)

/**
 * A user rule compiled into a single sing-box `route.rule`. [outbound] is either `"direct"`,
 * the sentinel `"<PROXY>"`, or a node sentinel `"<NODE:{id}>"`. When [rawMatch] is non-null,
 * emit it directly as the rule body with [outbound] or action added.
 */
data class CompiledRouteRule(
    val ruleSetTags: List<String>,
    /** "direct" | "<PROXY>" (default node) | "<NODE:{id}>" (pinned node) | "reject". */
    val outbound: String,
    val sourceRuleId: String?,
    /** Android package names; when set the rule matches the owning app instead of a rule-set. */
    val packageNames: List<String> = emptyList(),
    /** Raw match JSON object for Clash-rule compilation; when non-null, emit directly with outbound/action added. */
    val rawMatch: com.google.gson.JsonObject? = null,
)
