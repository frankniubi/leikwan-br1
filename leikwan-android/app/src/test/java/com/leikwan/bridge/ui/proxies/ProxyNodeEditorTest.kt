package com.leikwan.bridge.ui.proxies

import com.google.gson.JsonParser
import com.leikwan.bridge.data.ProxyNode
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Test

class ProxyNodeEditorTest {

    @Test
    fun `editor applies vmess changes and syncs config json`() {
        val node = ProxyNode(
            id = 7,
            name = "old-node",
            type = "vmess",
            originalServer = "old.example.com",
            originalPort = 443,
            configJson = """{"add":"old.example.com","port":443,"ps":"old-node","id":"uuid"}""",
        )

        val result = ProxyNodeEditor.applyDraft(
            node = node,
            draft = ProxyNodeEditDraft(
                name = "new-node",
                originalServer = "new.example.com",
                originalPort = "8443",
                protocol = ProxyProtocolDraft.Vmess(
                    uuid = "uuid",
                    alterId = "0",
                    security = "auto",
                    network = "ws",
                    host = "ws.example.com",
                    path = "/ws",
                ),
            ),
        )

        require(result is ProxyNodeEditResult.Success)
        val updated = result.node
        val config = JsonParser.parseString(updated.configJson).asJsonObject

        assertEquals("new-node", updated.name)
        assertEquals("new.example.com", updated.originalServer)
        assertEquals(8443, updated.originalPort)
        assertEquals("new.example.com", config.get("add").asString)
        assertEquals(8443, config.get("port").asInt)
        assertEquals("new-node", config.get("ps").asString)
        assertEquals("ws", config.get("net").asString)
        assertEquals("ws.example.com", config.get("host").asString)
        assertEquals("/ws", config.get("path").asString)
    }

    @Test
    fun `editor rejects invalid port`() {
        val node = ProxyNode(
            id = 8,
            name = "node",
            type = "ss",
            originalServer = "old.example.com",
            originalPort = 443,
            configJson = """{"method":"aes-128-gcm","password":"secret"}""",
        )

        val result = ProxyNodeEditor.applyDraft(
            node = node,
            draft = ProxyNodeEditDraft(
                name = "node",
                originalServer = "host",
                originalPort = "abc",
                protocol = ProxyProtocolDraft.Shadowsocks(
                    method = "aes-128-gcm",
                    password = "secret",
                ),
            ),
        )

        require(result is ProxyNodeEditResult.Error)
        assertTrue(result.message.contains("port", ignoreCase = true))
    }

    @Test
    fun `editor requires shadowsocks method and password`() {
        val node = ProxyNode(
            id = 9,
            name = "ss-node",
            type = "ss",
            originalServer = "old.example.com",
            originalPort = 443,
            configJson = """{"method":"aes-128-gcm","password":"secret"}""",
        )

        val result = ProxyNodeEditor.applyDraft(
            node = node,
            draft = ProxyNodeEditDraft(
                name = "node",
                originalServer = "host",
                originalPort = "443",
                protocol = ProxyProtocolDraft.Shadowsocks(
                    method = "",
                    password = "",
                ),
            ),
        )

        require(result is ProxyNodeEditResult.Error)
        assertTrue(result.message.contains("method", ignoreCase = true))
    }

    @Test
    fun `editor applies shadowsocks typed fields and syncs config json`() {
        val node = ProxyNode(
            id = 10,
            name = "old-ss",
            type = "ss",
            originalServer = "old.example.com",
            originalPort = 443,
            configJson = """{"method":"aes-128-gcm","password":"secret"}""",
        )

        val result = ProxyNodeEditor.applyDraft(
            node = node,
            draft = ProxyNodeEditDraft(
                name = "new-ss",
                originalServer = "new.example.com",
                originalPort = "8443",
                protocol = ProxyProtocolDraft.Shadowsocks(
                    method = "2022-blake3-aes-256-gcm",
                    password = "server:user",
                    plugin = "v2ray-plugin",
                    pluginOpts = "tls;host=example.com",
                    udpOverTcp = true,
                    multiplexEnabled = true,
                ),
            ),
        )

        require(result is ProxyNodeEditResult.Success)
        val updated = result.node
        val config = JsonParser.parseString(updated.configJson).asJsonObject

        assertEquals("new-ss", updated.name)
        assertEquals("new.example.com", updated.originalServer)
        assertEquals(8443, updated.originalPort)
        assertEquals("2022-blake3-aes-256-gcm", config.get("method").asString)
        assertEquals("server:user", config.get("password").asString)
        assertEquals("v2ray-plugin", config.get("plugin").asString)
        assertEquals("tls;host=example.com", config.get("plugin_opts").asString)
        assertTrue(config.get("udp_over_tcp").asBoolean)
        assertTrue(config.get("multiplex_enabled").asBoolean)
    }

    @Test
    fun `editor applies wireguard typed fields and syncs config json`() {
        val node = ProxyNode(
            id = 11,
            name = "old-wg",
            type = "wireguard",
            originalServer = "old.example.com",
            originalPort = 51820,
            configJson = """{"private_key":"old","address":["10.0.0.2/32"],"peers":[{"public_key":"old-peer","address":"old.example.com","port":51820,"allowed_ips":["0.0.0.0/0"]}]}""",
        )

        val result = ProxyNodeEditor.applyDraft(
            node = node,
            draft = ProxyNodeEditDraft(
                name = "new-wg",
                originalServer = "wg.example.com",
                originalPort = "51821",
                protocol = ProxyProtocolDraft.WireGuard(
                    privateKey = "private",
                    addresses = "10.7.0.2/32, fd00::2/128",
                    publicKey = "public",
                    preSharedKey = "psk",
                    allowedIps = "0.0.0.0/0, ::/0",
                    mtu = "1420",
                    persistentKeepalive = "25",
                ),
            ),
        )

        require(result is ProxyNodeEditResult.Success)
        val updated = result.node
        val config = JsonParser.parseString(updated.configJson).asJsonObject
        val peer = config.getAsJsonArray("peers").single().asJsonObject

        assertEquals("new-wg", updated.name)
        assertEquals("wg.example.com", updated.originalServer)
        assertEquals(51821, updated.originalPort)
        assertEquals("private", config.get("private_key").asString)
        assertEquals(listOf("10.7.0.2/32", "fd00::2/128"), config.getAsJsonArray("address").map { it.asString })
        assertEquals(1420, config.get("mtu").asInt)
        assertEquals("public", peer.get("public_key").asString)
        assertEquals("psk", peer.get("pre_shared_key").asString)
        assertEquals("wg.example.com", peer.get("address").asString)
        assertEquals(51821, peer.get("port").asInt)
        assertEquals(listOf("0.0.0.0/0", "::/0"), peer.getAsJsonArray("allowed_ips").map { it.asString })
        assertEquals(25, peer.get("persistent_keepalive_interval").asInt)
    }
}
