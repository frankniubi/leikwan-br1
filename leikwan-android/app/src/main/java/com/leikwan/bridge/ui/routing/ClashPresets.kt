package com.leikwan.bridge.ui.routing

/**
 * Ready-made Clash rule blocks offered as chips above the paste box in the Routing screen.
 * Geosite names may be bare (openai) — the parser normalizes them to the full
 * `geosite-` tags stored by [com.leikwan.bridge.routing.ClashRuleCatalog.allowedGeositeTags].
 */
object ClashPresets {
    data class Preset(val name: String, val lines: String)

    val all: List<Preset> = listOf(
        Preset(
            name = "AI 服务",
            lines = """
                GEOSITE,openai
                GEOSITE,category-ai-!cn
                GEOSITE,anthropic
            """.trimIndent(),
        ),
        Preset(
            name = "流媒体",
            lines = """
                GEOSITE,youtube
                GEOSITE,netflix
                GEOSITE,disney
                GEOSITE,primevideo
            """.trimIndent(),
        ),
        Preset(
            name = "Telegram",
            lines = """
                GEOSITE,telegram
                IP-CIDR,91.108.4.0/22,no-resolve
                IP-CIDR6,2001:b28:f23d::/48,no-resolve
            """.trimIndent(),
        ),
        Preset(
            name = "国内直连",
            lines = """
                GEOSITE,cn,DIRECT
                GEOIP,cn,DIRECT
            """.trimIndent(),
        ),
    )
}
