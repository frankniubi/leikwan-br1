package com.leikwan.bridge.routing

/**
 * The single authoritative routing contract for one compile/rebuild cycle.
 *
 * Produced by [RoutingPolicyEngine.compile] and consumed by SingboxConfigBuilder.buildRoute (the
 * single enforcement point) and by the explainability store for diagnostics.
 */
data class RoutingPolicySnapshot(
    val protectedPaths: List<SafePathEntry>,
    val userCountryRules: List<RoutingRule>,
    val userCategoryRules: List<RoutingRule>,
    /** User-settable global default. TUNNEL -> route.final=<proxy>; DIRECT -> "direct". */
    val globalDefaultOutcome: UserTrafficOutcome,
    /** When [globalDefaultOutcome] is TUNNEL: null = default proxy; non-null = pin route.final to this node. */
    val globalDefaultNodeId: Long? = null,
    val activeRulePackVersions: List<String>,
    val compileTimestampMs: Long,
    val warnings: List<String>,
    val precedenceVersion: Int,
    /** Per-app rules (`package_name`); they outrank country and category rules. */
    val userAppRules: List<AppRoutingRule> = emptyList(),
)
