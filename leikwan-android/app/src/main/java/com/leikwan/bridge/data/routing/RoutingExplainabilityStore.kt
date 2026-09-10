package com.leikwan.bridge.data.routing

import androidx.room.withTransaction
import com.leikwan.bridge.data.AppDatabase
import com.leikwan.bridge.data.Prefs
import org.json.JSONArray

/**
 * Persists the single `latest` routing explainability snapshot and mirrors its revision +
 * compile timestamp into [Prefs] for cheap first-launch reads.
 */
class RoutingExplainabilityStore(
    private val db: AppDatabase,
    private val prefs: Prefs,
) {
    suspend fun record(snapshot: RoutingExplainabilitySnapshot) = db.withTransaction {
        db.routingExplainabilityDao().upsert(snapshot.toEntity())
        prefs.latestExplainabilityRevision = snapshot.revision
        prefs.latestExplainabilityCompiledAtMs = snapshot.compiledAtMs
    }

    suspend fun latest(): RoutingExplainabilitySnapshot? =
        db.routingExplainabilityDao().latest()?.toSnapshot()

    private fun RoutingExplainabilitySnapshot.toEntity(): RoutingExplainabilityEntity =
        RoutingExplainabilityEntity(
            slot = "latest",
            revision = revision,
            compiledAtMs = compiledAtMs,
            rulePackCountryVersion = rulePackCountryVersion,
            rulePackCategoryVersion = rulePackCategoryVersion,
            activeUserRulesJson = encodeList(activeUserRules),
            protectedPathsJson = encodeList(protectedPaths),
            warningsJson = encodeList(warnings),
            payloadJson = payloadJson,
        )

    private fun RoutingExplainabilityEntity.toSnapshot(): RoutingExplainabilitySnapshot =
        RoutingExplainabilitySnapshot(
            revision = revision,
            compiledAtMs = compiledAtMs,
            rulePackCountryVersion = rulePackCountryVersion,
            rulePackCategoryVersion = rulePackCategoryVersion,
            activeUserRules = decodeList(activeUserRulesJson),
            protectedPaths = decodeList(protectedPathsJson),
            warnings = decodeList(warningsJson),
            payloadJson = payloadJson,
        )

    private fun encodeList(values: List<String>): String {
        val arr = JSONArray()
        values.forEach { arr.put(it) }
        return arr.toString()
    }

    private fun decodeList(json: String): List<String> {
        val arr = runCatching { JSONArray(json) }.getOrNull() ?: return emptyList()
        return (0 until arr.length()).map { arr.getString(it) }
    }
}
