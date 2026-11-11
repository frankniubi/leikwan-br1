package com.leikwan.bridge.routing

/**
 * Allowed geosite/rule-set tags for Clash rule imports.
 * Union of all values from [RoutingCategoryCatalog.seedPresets] plus common aliases.
 */
object ClashRuleCatalog {
    /**
     * Returns the set of allowed geosite tags (lowercase) that can be used in Clash rule imports.
     */
    fun allowedGeositeTags(): Set<String> {
        val tags = mutableSetOf<String>()

        // Add all tags from RoutingCategoryCatalog.seedPresets
        val catalog = RoutingCategoryCatalog.default()
        catalog.presets().forEach { preset ->
            preset.tags.forEach { tag ->
                tags.add(tag)
            }
        }

        // Add known common tags and aliases
        tags.addAll(
            listOf(
                "geosite-cn",
                "geolocation-cn",
                "geoip-cn",
                "geosite-openai",
                "geosite-anthropic",
                "geosite-telegram",
                "geosite-netflix",
                "geosite-youtube",
                "geosite-google",
            )
        )

        return tags
    }
}
