package com.leikwan.bridge.routing

import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Test

class CountryCatalogTest {
    @Test
    fun `search resolves english chinese and code to JP`() {
        assertEquals("JP", CountryCatalog.search("japan").first().code)
        assertEquals("JP", CountryCatalog.search("日本").first().code)
        assertEquals("JP", CountryCatalog.search("jp").first().code)
        assertEquals("日本", CountryCatalog.nameFor("JP", zh = true))
    }

    @Test
    fun `search is case and diacritic insensitive`() {
        assertEquals("CI", CountryCatalog.search("cote d ivoire").first().code)
        assertTrue(CountryCatalog.search("").isEmpty())
    }

    @Test
    fun `bundled catalog covers the full ISO country table`() {
        assertTrue("Expected the bundled ISO table to include at least 240 regions", CountryCatalog.all().size >= 240)
        assertEquals(CountryCatalog.all().size, CountryCatalog.all().map { it.code }.distinct().size)
    }
}
