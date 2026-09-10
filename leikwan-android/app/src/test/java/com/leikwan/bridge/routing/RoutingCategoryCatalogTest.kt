package com.leikwan.bridge.routing

import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Test

class RoutingCategoryCatalogTest {

    @Test
    fun `default catalog exposes expanded preset keys with labels`() {
        val catalog = RoutingCategoryCatalog.default()
        val expected = listOf(
            "google",
            "youtube",
            "streaming",
            "ai",
            "telegram",
            "twitter",
            "meta",
            "tiktok",
            "microsoft",
            "apple",
            "developer",
            "gaming",
            "music",
            "finance",
            "speedtest",
            "social",
            "china_direct",
        )

        assertEquals(expected, catalog.presetKeys())
        assertTrue(catalog.presets().all { it.labelEn.isNotBlank() && it.labelZh.isNotBlank() })
        assertTrue(catalog.presets().all { it.tags.isNotEmpty() })
        assertEquals("Google", catalog.labelFor("google", zh = false))
        assertEquals("谷歌服务", catalog.labelFor("google", zh = true))
        assertTrue(catalog.presetFor("china_direct")!!.defaultDirect)
    }

    @Test
    fun `ruleSetsFor streaming returns nonempty geosite refs`() {
        val refs = RoutingCategoryCatalog.default().ruleSetsFor("streaming")
        assertTrue(refs.isNotEmpty())
        assertTrue(refs.all { it.tag.startsWith("geosite-") })
        assertTrue(refs.all { it.url.startsWith("https://raw.githubusercontent.com/SagerNet/sing-geosite/rule-set/") })
        assertTrue(refs.all { it.url.endsWith(".srs") })
        // URL is derived from the tag
        val youtube = refs.firstOrNull { it.tag == "geosite-youtube" }
        assertTrue(youtube != null)
        assertEquals(
            "https://raw.githubusercontent.com/SagerNet/sing-geosite/rule-set/geosite-youtube.srs",
            youtube!!.url,
        )
    }

    @Test
    fun `ruleSetsFor ai returns nonempty geosite refs`() {
        val refs = RoutingCategoryCatalog.default().ruleSetsFor("ai")
        assertTrue(refs.isNotEmpty())
        assertTrue(refs.any { it.tag == "geosite-openai" })
    }

    @Test
    fun `ruleSetsFor google returns google tags`() {
        val refs = RoutingCategoryCatalog.default().ruleSetsFor("google")
        assertTrue(refs.any { it.tag == "geosite-google" })
    }

    @Test
    fun `matchValue lookup is case insensitive`() {
        assertEquals(
            RoutingCategoryCatalog.default().ruleSetsFor("youtube"),
            RoutingCategoryCatalog.default().ruleSetsFor("YOUTUBE"),
        )
    }

    @Test
    fun `unknown category returns empty list and never throws`() {
        assertEquals(emptyList<RuleSetRef>(), RoutingCategoryCatalog.default().ruleSetsFor("weather"))
        assertEquals(emptyList<RuleSetRef>(), RoutingCategoryCatalog.default().ruleSetsFor(""))
    }
}
