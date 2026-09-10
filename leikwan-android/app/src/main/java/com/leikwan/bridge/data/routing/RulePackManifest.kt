package com.leikwan.bridge.data.routing

import com.google.gson.Gson
import com.google.gson.JsonSyntaxException
import com.leikwan.bridge.routing.CategoryPreset
import com.leikwan.bridge.routing.RuleSetRef

data class RulePackManifest(
    val version: String,
    val geosite: List<CategoryPreset>,
    val geoip: GeoIpPack,
) {
    data class GeoIpPack(
        val version: String,
        val cnTag: String,
        val refs: List<RuleSetRef>,
    )

    fun toSnapshot(
        sourceLabel: String,
        fetchedAtMs: Long,
        validatedAtMs: Long = fetchedAtMs,
    ): RulePackSnapshot = RulePackSnapshot(
        countryPackVersion = geoip.version,
        categoryPackVersion = version,
        sourceLabel = sourceLabel,
        fetchedAtMs = fetchedAtMs,
        validatedAtMs = validatedAtMs,
        integrityState = RulePackSnapshot.IntegrityState.VERIFIED,
        stale = false,
        rollbackAvailable = false,
        payloadJson = gson.toJson(this),
    )

    companion object {
        private const val MAX_MANIFEST_BYTES = 1_000_000
        private val gson = Gson()

        fun parse(rawJson: String): RulePackManifest =
            parseOrNull(rawJson) ?: throw IllegalArgumentException("Invalid rule-pack manifest")

        fun parseOrNull(rawJson: String): RulePackManifest? {
            if (rawJson.isBlank() || rawJson.length > MAX_MANIFEST_BYTES) return null
            return try {
                val manifest = gson.fromJson(rawJson, RulePackManifest::class.java) ?: return null
                manifest.takeIf { it.isValid() }
            } catch (_: JsonSyntaxException) {
                null
            } catch (_: IllegalStateException) {
                null
            }
        }

        fun fromSnapshot(snapshot: RulePackSnapshot?): RulePackManifest? =
            snapshot?.payloadJson?.let { parseOrNull(it) }

        fun seed(): RulePackManifest = RulePackManifest(
            version = "seed-2026.05.31",
            geosite = com.leikwan.bridge.routing.RoutingCategoryCatalog.seedPresets(),
            geoip = GeoIpPack(
                version = "seed-2026.05.31",
                cnTag = "geoip-cn",
                refs = listOf(
                    RuleSetRef(
                        tag = "geoip-cn",
                        url = "https://raw.githubusercontent.com/SagerNet/sing-geoip/rule-set/geoip-cn.srs",
                    ),
                    RuleSetRef(
                        tag = "geosite-cn",
                        url = "https://raw.githubusercontent.com/SagerNet/sing-geosite/rule-set/geosite-cn.srs",
                    ),
                ),
            ),
        )

        private fun RulePackManifest.isValid(): Boolean {
            if (version.isBlank()) return false
            if (geoip.version.isBlank() || geoip.cnTag.isBlank()) return false
            if (geosite.any { !it.isValidPreset() }) return false
            if (geoip.refs.any { !it.isValidRef() }) return false
            return geoip.refs.any { it.tag == geoip.cnTag }
        }

        private fun CategoryPreset.isValidPreset(): Boolean =
            key.matches(Regex("^[a-z0-9_!-]+$")) &&
                labelEn.isNotBlank() &&
                labelZh.isNotBlank() &&
                tags.isNotEmpty() &&
                tags.all { it.matches(Regex("^geosite-[A-Za-z0-9_@!+.-]+$")) }

        private fun RuleSetRef.isValidRef(): Boolean =
            tag.matches(Regex("^(geoip|geosite)-[A-Za-z0-9_@!+.-]+$")) &&
                (url.startsWith("https://") || url.startsWith("http://")) &&
                url.endsWith(".srs")
    }
}
