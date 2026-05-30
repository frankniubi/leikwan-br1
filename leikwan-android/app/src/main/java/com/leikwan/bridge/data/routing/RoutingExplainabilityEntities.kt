package com.leikwan.bridge.data.routing

import androidx.room.ColumnInfo
import androidx.room.Entity
import androidx.room.PrimaryKey

/**
 * A persisted routing explainability snapshot. Only one row is kept ([slot] == `"latest"`).
 * Column names are snake_case to match the hand-written `MIGRATION_1_2` table definition.
 */
@Entity(tableName = "routing_explainability")
data class RoutingExplainabilityEntity(
    @PrimaryKey @ColumnInfo(name = "slot") val slot: String,
    @ColumnInfo(name = "revision") val revision: Long,
    @ColumnInfo(name = "compiled_at_ms") val compiledAtMs: Long,
    @ColumnInfo(name = "rule_pack_country_version") val rulePackCountryVersion: String,
    @ColumnInfo(name = "rule_pack_category_version") val rulePackCategoryVersion: String,
    @ColumnInfo(name = "active_user_rules_json") val activeUserRulesJson: String,
    @ColumnInfo(name = "protected_paths_json") val protectedPathsJson: String,
    @ColumnInfo(name = "warnings_json") val warningsJson: String,
    @ColumnInfo(name = "payload_json") val payloadJson: String,
)
