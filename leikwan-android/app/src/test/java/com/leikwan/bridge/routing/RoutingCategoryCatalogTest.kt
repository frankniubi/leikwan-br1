package com.leikwan.bridge.routing

import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Test

class RoutingCategoryCatalogTest {

    @Test
    fun `presetKeys are video and ai`() {
        assertEquals(listOf("video", "ai"), RoutingCategoryCatalog.default().presetKeys())
    }

    @Test
    fun `ruleSetsFor video returns nonempty geosite refs`() {
        val refs = RoutingCategoryCatalog.default().ruleSetsFor("video")
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
    fun `matchValue lookup is case insensitive`() {
        assertEquals(
            RoutingCategoryCatalog.default().ruleSetsFor("video"),
            RoutingCategoryCatalog.default().ruleSetsFor("VIDEO"),
        )
    }

    @Test
    fun `unknown category returns empty list and never throws`() {
        assertEquals(emptyList<RuleSetRef>(), RoutingCategoryCatalog.default().ruleSetsFor("weather"))
        assertEquals(emptyList<RuleSetRef>(), RoutingCategoryCatalog.default().ruleSetsFor(""))
    }
}
