package com.leikwan.bridge.ui.shared

/**
 * Read-only, presentation-ready summary of the most recent routing-policy compile, rendered by the
 * Settings and Tunnels diagnostics cards. Produced by
 * [com.leikwan.bridge.routing.RoutingDiagnosticsSource].
 */
data class RoutingDiagnosticsUiModel(
    val defaultActionLabel: String,
    val decisionReasonLabel: String,
    val protectedTrafficLabel: String,
    val activeRulePackLabel: String,
    val warnings: List<String> = emptyList(),
)
