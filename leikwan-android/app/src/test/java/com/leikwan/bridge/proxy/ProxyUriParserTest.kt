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

    @Test
    fun `wireguard config parser preserves interface and peer fields`() {
        val node = ProxyUriParser.parse(
            """
            [Interface]
            PrivateKey = private-key-123
            Address = 10.66.66.2/32, fd00::2/128
            MTU = 1420

            [Peer]
            PublicKey = public-key-456
            PresharedKey = psk-789
            Endpoint = wg.example.com:51820
            AllowedIPs = 0.0.0.0/0, ::/0
            PersistentKeepalive = 25
            """.trimIndent()
        ) ?: error("expected wireguard node")

        val config = JsonParser.parseString(node.configJson).asJsonObject
        val peer = config.getAsJsonArray("peers").single().asJsonObject

        assertEquals("WireGuard wg.example.com:51820", node.name)
        assertEquals("wireguard", node.type)
        assertEquals("wg.example.com", node.originalServer)
        assertEquals(51820, node.originalPort)
        assertEquals("private-key-123", config.get("private_key").asString)
        assertEquals(listOf("10.66.66.2/32", "fd00::2/128"), config.getAsJsonArray("address").map { it.asString })
        assertEquals(1420, config.get("mtu").asInt)
        assertEquals("public-key-456", peer.get("public_key").asString)
        assertEquals("psk-789", peer.get("pre_shared_key").asString)
        assertEquals("wg.example.com", peer.get("address").asString)
        assertEquals(51820, peer.get("port").asInt)
        assertEquals(listOf("0.0.0.0/0", "::/0"), peer.getAsJsonArray("allowed_ips").map { it.asString })
        assertEquals(25, peer.get("persistent_keepalive_interval").asInt)
    }

    @Test
    fun `wg uri parser accepts base64 encoded wireguard config`() {
        val encoded = java.util.Base64.getUrlEncoder().withoutPadding().encodeToString(
            """
            [Interface]
            PrivateKey = key
            Address = 10.7.0.2/32
            [Peer]
            PublicKey = peer
            Endpoint = 203.0.113.7:51820
            AllowedIPs = 0.0.0.0/0
            """.trimIndent().toByteArray()
        )

        val node = ProxyUriParser.parse("wg://$encoded#Tokyo-WG") ?: error("expected wireguard node")

        assertEquals("Tokyo-WG", node.name)
        assertEquals("wireguard", node.type)
        assertEquals("203.0.113.7", node.originalServer)
        assertEquals(51820, node.originalPort)
    }

    @Test
    fun `socks5 parser decodes credentials and IPv6 endpoint`() {
        val node = ProxyUriParser.parse(
            "socks5://user%40name:pass%3Aword@[2001:db8::10]:1080#edge",
        ) ?: error("expected socks5 node")

        val config = JsonParser.parseString(node.configJson).asJsonObject

        assertEquals("socks5", node.type)
        assertEquals("2001:db8::10", node.originalServer)
        assertEquals(1080, node.originalPort)
        assertEquals("edge", node.name)
        assertEquals("user@name", config.get("username").asString)
        assertEquals("pass:word", config.get("password").asString)
    }
}
