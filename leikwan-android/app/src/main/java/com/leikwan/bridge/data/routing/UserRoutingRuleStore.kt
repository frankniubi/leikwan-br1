package com.leikwan.bridge.data.routing

import android.content.Context
import com.leikwan.bridge.data.AppDatabase
import com.leikwan.bridge.routing.RoutingRule
import com.leikwan.bridge.routing.RoutingRuleKind
import com.leikwan.bridge.routing.UserTrafficOutcome

/**
 * CRUD over the per-client `user_routing_rules` table, mapping between the persisted
 * [UserRoutingRuleEntity] and the routing-domain [RoutingRule]. Cleared on client switch.
 */
class UserRoutingRuleStore(private val db: AppDatabase) {

    suspend fun upsert(rule: RoutingRule) = db.userRoutingRuleDao().upsert(rule.toEntity())

    suspend fun delete(id: String) = db.userRoutingRuleDao().deleteById(id)

    suspend fun setEnabled(id: String, enabled: Boolean) =
        db.userRoutingRuleDao().setEnabled(id, enabled)

    suspend fun setTargetNodeId(id: String, targetNodeId: Long?) =
        db.userRoutingRuleDao().setTargetNodeId(id, targetNodeId)

    suspend fun clear() = db.userRoutingRuleDao().clear()

    suspend fun all(): List<RoutingRule> = db.userRoutingRuleDao().all().map { it.toRule() }

    suspend fun countryRules(): List<RoutingRule> =
        db.userRoutingRuleDao().byKind(RoutingRuleKind.COUNTRY.name).map { it.toRule() }

    suspend fun categoryRules(): List<RoutingRule> =
        db.userRoutingRuleDao().byKind(RoutingRuleKind.WEBSITE_CATEGORY.name).map { it.toRule() }

    suspend fun clashRules(): List<RoutingRule> {
        val clashKinds = listOf(
            RoutingRuleKind.DOMAIN, RoutingRuleKind.DOMAIN_SUFFIX, RoutingRuleKind.DOMAIN_KEYWORD,
            RoutingRuleKind.DOMAIN_REGEX, RoutingRuleKind.IP_CIDR, RoutingRuleKind.IP_CIDR6,
            RoutingRuleKind.DST_PORT, RoutingRuleKind.GEOSITE, RoutingRuleKind.RULE_SET,
        )
        return db.userRoutingRuleDao().byKinds(clashKinds.map { it.name }).map { it.toRule() }
    }

    suspend fun upsertAll(rules: List<RoutingRule>) {
        rules.forEach { rule -> upsert(rule) }
    }

    private fun RoutingRule.toEntity(): UserRoutingRuleEntity =
        UserRoutingRuleEntity(
            id = id,
            kind = kind.name,
            matchValue = matchValue,
            outcome = outcome.name,
            enabled = enabled,
            targetNodeId = targetNodeId,
            createdAt = System.currentTimeMillis(),
        )

    private fun UserRoutingRuleEntity.toRule(): RoutingRule =
        RoutingRule(
            id = id,
            kind = RoutingRuleKind.valueOf(kind),
            matchValue = matchValue,
            outcome = UserTrafficOutcome.valueOf(outcome),
            enabled = enabled,
            targetNodeId = targetNodeId,
        )

    companion object {
        fun from(context: Context): UserRoutingRuleStore =
            UserRoutingRuleStore(AppDatabase.get(context))
    }
}
