package com.leikwan.bridge.data.routing

/**
 * A point-in-time, validated set of routing rule packs (country + category geoip/geosite versions)
 * persisted in the `rule_pack_snapshots` table. Two slots are kept: `active` and `previous`
 * (for rollback).
 */
data class RulePackSnapshot(
    val countryPackVersion: String,
    val categoryPackVersion: String,
    val sourceLabel: String,
    val fetchedAtMs: Long,
    val validatedAtMs: Long,
    val integrityState: IntegrityState,
    val stale: Boolean,
    val rollbackAvailable: Boolean,
    val payloadJson: String,
) {
    enum class IntegrityState { VERIFIED, STALE, REJECTED }
}
