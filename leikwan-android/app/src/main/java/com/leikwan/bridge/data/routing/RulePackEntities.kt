package com.leikwan.bridge.data.routing

import androidx.room.ColumnInfo
import androidx.room.Entity
import androidx.room.PrimaryKey

/**
 * A persisted rule-pack snapshot. [slot] is the primary key and is one of `"active"`/`"previous"`.
 * Column names are snake_case to match the hand-written `MIGRATION_1_2` table definition.
 */
@Entity(tableName = "rule_pack_snapshots")
data class RulePackSnapshotEntity(
    @PrimaryKey @ColumnInfo(name = "slot") val slot: String,
    @ColumnInfo(name = "country_pack_version") val countryPackVersion: String,
    @ColumnInfo(name = "category_pack_version") val categoryPackVersion: String,
    @ColumnInfo(name = "source_label") val sourceLabel: String,
    @ColumnInfo(name = "fetched_at_ms") val fetchedAtMs: Long,
    @ColumnInfo(name = "validated_at_ms") val validatedAtMs: Long,
    @ColumnInfo(name = "integrity_state") val integrityState: String,
    @ColumnInfo(name = "stale") val stale: Boolean,
    @ColumnInfo(name = "rollback_available") val rollbackAvailable: Boolean,
    @ColumnInfo(name = "payload_json") val payloadJson: String,
)
