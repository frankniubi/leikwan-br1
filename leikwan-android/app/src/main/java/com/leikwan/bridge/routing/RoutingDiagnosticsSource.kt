package com.leikwan.bridge.routing

import android.content.Context
import com.leikwan.bridge.data.AppDatabase
import com.leikwan.bridge.data.Prefs
import com.leikwan.bridge.data.routing.RoutingExplainabilitySnapshot
import com.leikwan.bridge.data.routing.RoutingExplainabilityStore
import com.leikwan.bridge.data.routing.UserRoutingRuleStore
import kotlinx.coroutines.runBlocking

/**
 * Read-only source of the routing-diagnostics summary surfaced on the Settings and Tunnels screens.
 *
 * Prefers the persisted [RoutingExplainabilityStore.latest] snapshot (recorded by the orchestrator
 * after each compile). When none exists yet (first launch / before the first VPN start) it
 * synthesises a deterministic default-from-registry summary from [Prefs] and the active user rules,
 * so diagnostics are never null. This is strictly diagnostics — it never mutates state.
 */
class RoutingDiagnosticsSource internal constructor(
    private val prefs: Prefs,
    private val explainabilityStore: RoutingExplainabilityStore,
    private val userRuleStore: UserRoutingRuleStore,
) {
    fun latest(): com.leikwan.bridge.ui.shared.RoutingDiagnosticsUiModel {
        val snapshot = runCatching { runBlocking { explainabilityStore.latest() } }.getOrNull()
        return if (snapshot != null) {
            fromSnapshot(snapshot)
        } else {
            defaultSummary()
        }
    }

    private fun fromSnapshot(
        snapshot: RoutingExplainabilitySnapshot,
    ): com.leikwan.bridge.ui.shared.RoutingDiagnosticsUiModel {
        val rulePacks = listOf(snapshot.rulePackCountryVersion, snapshot.rulePackCategoryVersion)
            .filter { it.isNotBlank() }
        return com.leikwan.bridge.ui.shared.RoutingDiagnosticsUiModel(
            defaultActionLabel = prefs.routingDefaultOutcome.name,
            decisionReasonLabel = if (snapshot.activeUserRules.isEmpty()) "DEFAULT" else "USER_RULE",
            protectedTrafficLabel = if (snapshot.protectedPaths.isEmpty()) {
                PROTECTED_LABEL
            } else {
                snapshot.protectedPaths.joinToString(", ")
            },
            activeRulePackLabel = if (rulePacks.isEmpty()) "none" else rulePacks.joinToString(", "),
            warnings = snapshot.warnings.ifEmpty { listOf(PROTECTED_WARNING) },
        )
    }

    private fun defaultSummary(): com.leikwan.bridge.ui.shared.RoutingDiagnosticsUiModel =
        com.leikwan.bridge.ui.shared.RoutingDiagnosticsUiModel(
            defaultActionLabel = prefs.routingDefaultOutcome.name,
            decisionReasonLabel = "DEFAULT",
            protectedTrafficLabel = PROTECTED_LABEL,
            activeRulePackLabel = "none",
            warnings = listOf(PROTECTED_WARNING),
        )

    companion object {
        private const val PROTECTED_LABEL = "Panel, bootstrap, and rule-pack endpoints"
        private const val PROTECTED_WARNING = "system-protected traffic is enforced"

        fun from(context: Context): RoutingDiagnosticsSource {
            val appContext = context.applicationContext
            val prefs = Prefs.get(appContext)
            return RoutingDiagnosticsSource(
                prefs = prefs,
                explainabilityStore = RoutingExplainabilityStore(AppDatabase.get(appContext), prefs),
                userRuleStore = UserRoutingRuleStore.from(appContext),
            )
        }
    }
}
