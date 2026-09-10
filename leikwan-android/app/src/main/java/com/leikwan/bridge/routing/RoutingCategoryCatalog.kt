package com.leikwan.bridge.routing

/**
 * A labelled category preset backed by SagerNet `geosite` rule-set tags.
 *
 * [defaultDirect] is a UI hint: `china_direct` should be inserted as DIRECT by default while
 * the rest are tunnel-oriented presets.
 */
data class CategoryPreset(
    val key: String,
    val labelEn: String,
    val labelZh: String,
    val defaultDirect: Boolean,
    val tags: List<String>,
)

/**
 * Curated, code-owned mapping from website-category presets to remote SagerNet `.srs` refs.
 * Unknown categories resolve to an empty list and never poison config generation.
 */
class RoutingCategoryCatalog private constructor(
    private val presetList: List<CategoryPreset>,
    private val aliasPresets: Map<String, CategoryPreset> = emptyMap(),
) {
    private val presetsByKey = presetList.associateBy { it.key.trim().lowercase() }

    /** Ordered list of supported preset category keys. */
    fun presetKeys(): List<String> = presetList.map { it.key }

    fun presets(): List<CategoryPreset> = presetList

    fun presetFor(key: String): CategoryPreset? {
        val normalized = key.trim().lowercase()
        return presetsByKey[normalized] ?: aliasPresets[normalized]
    }

    fun supports(key: String): Boolean = presetFor(key) != null

    fun labelFor(key: String, zh: Boolean): String =
        presetFor(key)?.let { if (zh) it.labelZh else it.labelEn } ?: key

    /**
     * Rule-set refs for a category match value (case-insensitive). Unknown category -> empty list.
     */
    fun ruleSetsFor(matchValue: String): List<RuleSetRef> {
        val preset = presetFor(matchValue) ?: return emptyList()
        return preset.tags.map { tag -> RuleSetRef(tag, geositeUrl(tag)) }
    }

    companion object {
        private const val GEOSITE_BASE =
            "https://raw.githubusercontent.com/SagerNet/sing-geosite/rule-set/"

        fun geositeUrl(tag: String): String = "$GEOSITE_BASE$tag.srs"

        fun default(): RoutingCategoryCatalog = RoutingCategoryCatalog(
            presetList = seedPresets(),
            aliasPresets = mapOf(
                "video" to CategoryPreset(
                    key = "video",
                    labelEn = "Video",
                    labelZh = "视频",
                    defaultDirect = false,
                    tags = listOf(
                        "geosite-youtube",
                        "geosite-netflix",
                        "geosite-disney",
                        "geosite-primevideo",
                        "geosite-hbo",
                        "geosite-bilibili",
                    ),
                )
            ),
        )

        fun fromManifest(manifest: com.leikwan.bridge.data.routing.RulePackManifest): RoutingCategoryCatalog =
            RoutingCategoryCatalog(manifest.geosite)

        fun seedPresets(): List<CategoryPreset> = listOf(
            CategoryPreset(
                key = "google",
                labelEn = "Google",
                labelZh = "谷歌服务",
                defaultDirect = false,
                tags = listOf("geosite-google"),
            ),
            CategoryPreset(
                key = "youtube",
                labelEn = "YouTube",
                labelZh = "YouTube 视频",
                defaultDirect = false,
                tags = listOf("geosite-youtube"),
            ),
            CategoryPreset(
                key = "streaming",
                labelEn = "Streaming",
                labelZh = "流媒体",
                defaultDirect = false,
                tags = listOf(
                    "geosite-youtube",
                    "geosite-netflix",
                    "geosite-disney",
                    "geosite-primevideo",
                    "geosite-hbo",
                    "geosite-bilibili",
                ),
            ),
            CategoryPreset(
                key = "ai",
                labelEn = "AI",
                labelZh = "AI 服务",
                defaultDirect = false,
                tags = listOf("geosite-openai", "geosite-category-ai-!cn"),
            ),
            CategoryPreset(
                key = "telegram",
                labelEn = "Telegram",
                labelZh = "Telegram",
                defaultDirect = false,
                tags = listOf("geosite-telegram"),
            ),
            CategoryPreset(
                key = "twitter",
                labelEn = "Twitter/X",
                labelZh = "Twitter/X",
                defaultDirect = false,
                tags = listOf("geosite-twitter"),
            ),
            CategoryPreset(
                key = "meta",
                labelEn = "Meta",
                labelZh = "Meta 服务",
                defaultDirect = false,
                tags = listOf("geosite-facebook", "geosite-instagram", "geosite-whatsapp"),
            ),
            CategoryPreset(
                key = "tiktok",
                labelEn = "TikTok",
                labelZh = "TikTok",
                defaultDirect = false,
                tags = listOf("geosite-tiktok"),
            ),
            CategoryPreset(
                key = "microsoft",
                labelEn = "Microsoft",
                labelZh = "微软服务",
                defaultDirect = false,
                tags = listOf("geosite-microsoft"),
            ),
            CategoryPreset(
                key = "apple",
                labelEn = "Apple",
                labelZh = "苹果服务",
                defaultDirect = false,
                tags = listOf("geosite-apple"),
            ),
            CategoryPreset(
                key = "developer",
                labelEn = "Developer",
                labelZh = "开发者服务",
                defaultDirect = false,
                tags = listOf("geosite-github", "geosite-gitlab", "geosite-stackoverflow"),
            ),
            CategoryPreset(
                key = "gaming",
                labelEn = "Gaming",
                labelZh = "游戏",
                defaultDirect = false,
                tags = listOf("geosite-steam", "geosite-epicgames", "geosite-playstation"),
            ),
            CategoryPreset(
                key = "music",
                labelEn = "Music",
                labelZh = "音乐",
                defaultDirect = false,
                tags = listOf("geosite-spotify", "geosite-applemusic"),
            ),
            CategoryPreset(
                key = "finance",
                labelEn = "Finance",
                labelZh = "金融",
                defaultDirect = false,
                tags = listOf("geosite-paypal", "geosite-stripe"),
            ),
            CategoryPreset(
                key = "speedtest",
                labelEn = "Speedtest",
                labelZh = "测速",
                defaultDirect = false,
                tags = listOf("geosite-speedtest"),
            ),
            CategoryPreset(
                key = "social",
                labelEn = "Social",
                labelZh = "社交",
                defaultDirect = false,
                tags = listOf(
                    "geosite-twitter",
                    "geosite-facebook",
                    "geosite-instagram",
                    "geosite-tiktok",
                    "geosite-reddit",
                ),
            ),
            CategoryPreset(
                key = "china_direct",
                labelEn = "China direct",
                labelZh = "中国直连",
                defaultDirect = true,
                tags = listOf("geosite-cn", "geolocation-cn"),
            ),
        )
    }
}
