package com.leikwan.bridge.proxy

import com.google.gson.JsonParser
import org.junit.Assert.assertNotNull
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Test

class ProxyUriParserTest {

    @Test
    fun `ss parser preserves plugin and network query params`() {
        val node = ProxyUriParser.parse(
            "ss://YWVzLTI1Ni1nY206c2VjcmV0@14.103.220.245:32469?plugin=v2ray-plugin%3Btlsx%3Bhost%3Dexample.com&network=tcp#JPN-TOK4"
        ) ?: error("expected node")

        val config = JsonParser.parseString(node.configJson).asJsonObject

        assertEquals("JPN-TOK4", node.name)
        assertEquals("14.103.220.245", node.originalServer)
        assertEquals(32469, node.originalPort)
        assertEquals("v2ray-plugin", config.get("plugin").asString)
        assertEquals("tlsx;host=example.com", config.get("plugin_opts").asString)
        assertEquals("tcp", config.get("network").asString)
    }

    @Test
    fun `ss parser maps uot query to udp over tcp`() {
        val node = ProxyUriParser.parse(
            "ss://YWVzLTI1Ni1nY206c2VjcmV0@14.103.220.245:32469?uot=1#JPN-TOK4"
        ) ?: error("expected node")

        val config = JsonParser.parseString(node.configJson).asJsonObject

        assertTrue(config.get("udp_over_tcp").asBoolean)
    }

    @Test
    fun `ss parser accepts Nekobox ss2022 base64 userinfo`() {
        val node = ProxyUriParser.parse(
            "ss://MjAyMi1ibGFrZTMtYWVzLTI1Ni1nY206TVdJMVlUSTRaRGs0TXpjNFpqaGpNR00xWlRBNU1URmhaalExTkdWaFl6Zz06TXpCa05HUTFPV010TldZNU1TMDBPVGM1TFRnNU9UTXROMlZtTUdFMFpUWT0@14.103.220.245:32469#JPN-TOK4"
        )

        assertNotNull(node)
        node ?: return

        val config = JsonParser.parseString(node.configJson).asJsonObject

        assertEquals("JPN-TOK4", node.name)
        assertEquals("14.103.220.245", node.originalServer)
        assertEquals(32469, node.originalPort)
        assertEquals("2022-blake3-aes-256-gcm", config.get("method").asString)
        assertEquals(
            "MWI1YTI4ZDk4Mzc4ZjhjMGM1ZTA5MTFhZjQ1NGVhYzg=:MzBkNGQ1OWMtNWY5MS00OTc5LTg5OTMtN2VmMGE0ZTY=",
            config.get("password").asString,
        )
    }
}
