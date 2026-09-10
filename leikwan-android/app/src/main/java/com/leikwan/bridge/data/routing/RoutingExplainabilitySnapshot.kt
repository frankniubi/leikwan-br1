package com.leikwan.bridge.data.routing

/**
 * The most recently compiled routing decision summary, persisted (single `latest` slot) for the
 * diagnostics UI. Records what was in effect at the last config compile so the user can understand
 * why traffic was tunneled or sent direct.
 */
data class RoutingExplainabilitySnapshot(
    val revision: Long,
    val compiledAtMs: Long,
    val rulePackCountryVersion: String,
    val rulePackCategoryVersion: String,
    val activeUserRules: List<String>,
    val protectedPaths: List<String>,
    val warnings: List<String>,
    val payloadJson: String,
)
