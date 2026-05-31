package com.leikwan.bridge.ui.proxies

import com.leikwan.bridge.data.ProxyNode
import org.junit.Assert.assertEquals
import org.junit.Test

class ProxyListSectionsTest {

    @Test
    fun `sections group nodes by protocol with stable preferred order`() {
        val sections = ProxyListSections.build(
            listOf(
                ProxyNode(id = 1, name = "vmess-1", type = "vmess", originalServer = "a", originalPort = 1, configJson = "{}"),
                ProxyNode(id = 2, name = "ss-1", type = "ss", originalServer = "b", originalPort = 2, configJson = "{}"),
                ProxyNode(id = 3, name = "vless-1", type = "vless", originalServer = "c", originalPort = 3, configJson = "{}"),
                ProxyNode(id = 4, name = "custom-1", type = "trojan", originalServer = "d", originalPort = 4, configJson = "{}"),
                ProxyNode(id = 5, name = "wg-1", type = "wireguard", originalServer = "e", originalPort = 5, configJson = "{}"),
            )
        )

        assertEquals(listOf("Shadowsocks", "VLESS", "VMess", "WireGuard", "Other"), sections.map { it.title })
        assertEquals(listOf(2L), sections[0].nodes.map { it.id })
        assertEquals(listOf(3L), sections[1].nodes.map { it.id })
        assertEquals(listOf(1L), sections[2].nodes.map { it.id })
        assertEquals(listOf(5L), sections[3].nodes.map { it.id })
        assertEquals(listOf(4L), sections[4].nodes.map { it.id })
    }

    @Test
    fun `sections preserve node order inside each protocol group`() {
        val sections = ProxyListSections.build(
            listOf(
                ProxyNode(id = 10, name = "ss-newest", type = "ss", originalServer = "a", originalPort = 1, configJson = "{}"),
                ProxyNode(id = 9, name = "ss-older", type = "ss", originalServer = "b", originalPort = 2, configJson = "{}"),
            )
        )

        assertEquals(1, sections.size)
        assertEquals(listOf(10L, 9L), sections.single().nodes.map { it.id })
    }
}
