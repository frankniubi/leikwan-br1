package com.leikwan.bridge.data.routing

import androidx.room.withTransaction
import com.leikwan.bridge.data.AppDatabase
import com.leikwan.bridge.data.Prefs

/**
 * Manages the `active`/`previous` rule-pack slots. Activating a new snapshot demotes the prior
 * active snapshot to `previous` (enabling rollback) and mirrors the active versions into [Prefs].
 * A 24h freshness window is used to flag a re-read snapshot as stale.
 */
class RulePackManager(
    private val db: AppDatabase,
    private val prefs: Prefs,
    private val clock: () -> Long = System::currentTimeMillis,
) {
    suspend fun activate(snapshot: RulePackSnapshot): RulePackSnapshot = db.withTransaction {
        val dao = db.rulePackDao()
        val priorActive = dao.active()
        dao.deleteBySlot("previous")
        if (priorActive != null) {
            dao.upsert(priorActive.copy(slot = "previous", rollbackAvailable = true))
        }
        val rollbackAvailable = priorActive != null
        dao.upsert(snapshot.copy(rollbackAvailable = rollbackAvailable).toEntity("active"))
        prefs.activeRulePackCountryVersion = snapshot.countryPackVersion
        prefs.activeRulePackCategoryVersion = snapshot.categoryPackVersion
        prefs.activeRulePackSourceLabel = snapshot.sourceLabel
        prefs.activeRulePackFetchedAtMs = snapshot.fetchedAtMs
        prefs.activeRulePackStale = snapshot.stale
        snapshot.copy(rollbackAvailable = rollbackAvailable)
    }

    suspend fun current(): RulePackSnapshot? = db.rulePackDao().active()?.toSnapshot(clock)

    suspend fun previous(): RulePackSnapshot? = db.rulePackDao().previous()?.toSnapshot(clock)

    /** The active country + category pack versions (in that order), or empty if none active. */
    suspend fun activeVersions(): List<String> {
        val active = db.rulePackDao().active() ?: return emptyList()
        return listOf(active.countryPackVersion, active.categoryPackVersion)
    }

    private companion object {
        const val FRESHNESS_WINDOW_MS = 24L * 60L * 60L * 1000L
    }

    private fun RulePackSnapshot.toEntity(slot: String): RulePackSnapshotEntity =
        RulePackSnapshotEntity(
            slot = slot,
            countryPackVersion = countryPackVersion,
            categoryPackVersion = categoryPackVersion,
            sourceLabel = sourceLabel,
            fetchedAtMs = fetchedAtMs,
            validatedAtMs = validatedAtMs,
            integrityState = integrityState.name,
            stale = stale,
            rollbackAvailable = rollbackAvailable,
            payloadJson = payloadJson,
        )

    private fun RulePackSnapshotEntity.toSnapshot(clock: () -> Long): RulePackSnapshot {
        val stale = stale || (clock() - fetchedAtMs) > FRESHNESS_WINDOW_MS
        return RulePackSnapshot(
            countryPackVersion = countryPackVersion,
            categoryPackVersion = categoryPackVersion,
            sourceLabel = sourceLabel,
            fetchedAtMs = fetchedAtMs,
            validatedAtMs = validatedAtMs,
            integrityState = runCatching { RulePackSnapshot.IntegrityState.valueOf(integrityState) }
                .getOrDefault(RulePackSnapshot.IntegrityState.VERIFIED),
            stale = stale,
            rollbackAvailable = rollbackAvailable,
            payloadJson = payloadJson,
        )
    }
}
