package com.leikwan.bridge.routing

/**
 * Derived artifact returned by [com.leikwan.bridge.singbox.SingboxConfigBuilder.build].
 *
 * It is derived from the authoritative [RoutingPolicySnapshot] + runtime intent and must NOT become a
 * second source of truth for routing decisions.
 */
data class CompiledConfigResult(
    val configJson: String,
    val finalProxyTag: String,
    val warnings: List<String> = emptyList(),
)
