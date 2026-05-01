package com.leikwan.bridge.ui.proxies

import com.leikwan.bridge.data.ProxyNode
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Test

class ProxyProtocolDraftTest {

    @Test
    fun `details parser reads shadowsocks method and optional flags`() {
        val node = ProxyNode(
            id = 1,
            name = "JPN-TOK4",
            type = "ss",
            originalServer = "14.103.220.245",
            originalPort = 32469,
            configJson = """{"method":"2022-blake3-aes-256-gcm","password":"secret","plugin":"v2ray-plugin","plugin_opts":"tls;host=example.com","udp_over_tcp":true,"multiplex_enabled":true}""",
        )

        val details = ProxyProtocolDraftParser.parse(node)

        require(details is ProxyProtocolDraft.Shadowsocks)
        assertEquals("2022-blake3-aes-256-gcm", details.method)
        assertEquals("secret", details.password)
        assertEquals("v2ray-plugin", details.plugin)
        assertEquals("tls;host=example.com", details.pluginOpts)
        assertTrue(details.udpOverTcp)
        assertTrue(details.multiplexEnabled)
    }

    @Test
    fun `details parser reads vless transport fields`() {
        val node = ProxyNode(
            id = 2,
            name = "VLESS-WS",
            type = "vless",
            originalServer = "edge.example.com",
            originalPort = 443,
            configJson = """{"uuid":"abc-uuid","flow":"xtls-rprx-vision","security":"tls","type":"ws","host":"cdn.example.com","path":"/ws","sni":"edge.example.com"}""",
        )

        val details = ProxyProtocolDraftParser.parse(node)

        require(details is ProxyProtocolDraft.Vless)
        assertEquals("abc-uuid", details.uuid)
        assertEquals("xtls-rprx-vision", details.flow)
        assertEquals("tls", details.security)
        assertEquals("ws", details.transportType)
        assertEquals("cdn.example.com", details.host)
        assertEquals("/ws", details.path)
        assertEquals("edge.example.com", details.sni)
    }
}
