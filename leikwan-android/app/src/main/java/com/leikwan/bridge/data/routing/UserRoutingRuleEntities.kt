package com.leikwan.bridge.data.routing

import androidx.room.ColumnInfo
import androidx.room.Entity
import androidx.room.PrimaryKey

/**
 * A persisted, user-authored routing rule. [id] is the primary key. [kind]/[outcome] are stored as
 * the enum [com.leikwan.bridge.routing.RoutingRuleKind] / [com.leikwan.bridge.routing.UserTrafficOutcome]
 * `name`s. Column names are snake_case to match the hand-written `MIGRATION_1_2` table definition.
 */
@Entity(tableName = "user_routing_rules")
data class UserRoutingRuleEntity(
    @PrimaryKey @ColumnInfo(name = "id") val id: String,
    @ColumnInfo(name = "kind") val kind: String,
    @ColumnInfo(name = "match_value") val matchValue: String,
    @ColumnInfo(name = "outcome") val outcome: String,
    @ColumnInfo(name = "enabled") val enabled: Boolean,
    @ColumnInfo(name = "created_at") val createdAt: Long,
)
