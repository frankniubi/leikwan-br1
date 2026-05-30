package com.leikwan.bridge.routing

/**
 * Curated, code-owned mapping from a preset website-category key to a small set of known-good
 * SagerNet `geosite` rule-set tags.
 *
 * Tags are curated here; their `.srs` URL liveness (HTTP 200) is verified in Task 7. An unknown
 * category resolves to an empty list (the caller warns) and NEVER throws, so a bad category can
 * never poison the whole config.
 *
 * `.srs` URL template: `https://raw.githubusercontent.com/SagerNet/sing-geosite/rule-set/<tag>.srs`.
 */
class RoutingCategoryCatalog private constructor(
    private val presets: Map<String, List<String>>,
) {
    /** Ordered list of supported preset category keys (e.g. `[video, ai]`). */
    fun presetKeys(): List<String> = presets.keys.toList()

    /**
     * Rule-set refs for a category match value (case-insensitive). Unknown category → empty list.
     */
    fun ruleSetsFor(matchValue: String): List<RuleSetRef> {
        val tags = presets[matchValue.trim().lowercase()] ?: return emptyList()
        return tags.map { tag -> RuleSetRef(tag, geositeUrl(tag)) }
    }

    companion object {
        private const val GEOSITE_BASE =
            "https://raw.githubusercontent.com/SagerNet/sing-geosite/rule-set/"

        fun geositeUrl(tag: String): String = "$GEOSITE_BASE$tag.srs"

        fun default(): RoutingCategoryCatalog = RoutingCategoryCatalog(
            linkedMapOf(
                "video" to listOf(
                    "geosite-youtube",
                    "geosite-netflix",
                    "geosite-disney",
                    "geosite-primevideo",
                    "geosite-hbo",
                    "geosite-bilibili",
                ),
                "ai" to listOf(
                    "geosite-openai",
                    "geosite-category-ai-!cn",
                ),
            )
        )
    }
}
