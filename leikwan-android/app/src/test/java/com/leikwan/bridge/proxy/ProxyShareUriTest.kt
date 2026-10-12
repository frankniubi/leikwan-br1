package com.leikwan.bridge.proxy

import com.google.gson.JsonParser
import com.leikwan.bridge.data.ProxyNode
import com.leikwan.bridge.data.hosted.HostedNodeIdentity
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNotNull
import org.junit.Assert.assertNull
import org.junit.Test

class ProxyShareUriTest {

    private fun roundTrip(uri: String): Pair<ProxyNode, ProxyNode> {
        val original = parsed(uri)
        val exported = ProxyShareUri.fromNode(original)
        assertNotNull("exported $uri", exported)
        val again = parsed(exported!!)
        assertEquals(original.type, again.type)
        assertEquals(original.name, again.name)
        assertEquals(original.originalServer, again.originalServer)
        assertEquals(original.originalPort, again.originalPort)
        assertEquals(JsonParser.parseString(original.configJson), JsonParser.parseString(again.configJson))
        return original to again
    }

    private fun parsed(uri: String): ProxyNode = ProxyUriParser.parse(uri) ?: error("could not parse $uri")

    @Test
    fun `shadowsocks round trips including plugin and uot`() {
        roundTrip("ss://YWVzLTI1Ni1nY206cGE6c3M@ss.example.com:8388?plugin=obfs-local%3Bobfs%3Dhttp&uot=1#My%20SS")
    }

    @Test
    fun `socks5 round trips with and without auth`() {
        roundTrip("socks5://us%3Aer:p%40ss@198.51.100.7:1080#Home")
        roundTrip("socks5://proxy.example.net:1080#Plain")
    }

    @Test
    fun `vless round trips reality and ws params`() {
        roundTrip(
            "vless://1b4e28ba-2fa1-11d2-883f-0016d3cca427@203.0.113.9:443?security=reality&sni=www.microsoft.com" +
                "&fp=chrome&pbk=Z84J2IelR9ch3k8VtlVhhs5ycBUlXA7wHBWcBrjqnAw&sid=6ba8&flow=xtls-rprx-vision&type=tcp#Reality",
        )
        val (node, _) = roundTrip(
            "vless://1b4e28ba-2fa1-11d2-883f-0016d3cca427@cdn.example.com:443?security=tls&type=ws" +
                "&path=%2Fray%3Fed%3D2048&host=cdn.example.com#WS%2BTLS",
        )
        val config = JsonParser.parseString(node.configJson).asJsonObject
        assertEquals("ws path is decoded exactly once", "/ray?ed=2048", config.get("path").asString)
        assertEquals("a literal plus in the name survives", "WS+TLS", node.name)
    }

    @Test
    fun `ipv6 servers are unbracketed on import and bracketed on export`() {
        val node = parsed("vless://1b4e28ba-2fa1-11d2-883f-0016d3cca427@[2001:db8::1]:443?security=none#v6")
        assertEquals("2001:db8::1", node.originalServer)
        assertEquals(true, ProxyShareUri.fromNode(node)!!.contains("@[2001:db8::1]:443"))
        assertEquals("2001:db8::1", parsed("ss://YWVzLTI1Ni1nY206cHc@[2001:db8::1]:8388").originalServer)
    }

    @Test
    fun `protocols the platform does not host are not exported`() {
        val wg = ProxyNode(name = "wg", type = "wireguard", originalServer = "wg.example.com", originalPort = 51820, configJson = "{}")
        val vmess = ProxyNode(name = "vm", type = "vmess", originalServer = "vm.example.com", originalPort = 443, configJson = "{}")
        assertNull(ProxyShareUri.fromNode(wg))
        assertNull(ProxyShareUri.fromNode(vmess))
        assertEquals(false, HostedNodeIdentity.isUploadable(wg))
    }

    @Test
    fun `identity ignores names and defaults so the same node dedups across spellings`() {
        val pasted = parsed(
            "vless://1B4E28BA-2FA1-11D2-883F-0016D3CCA427@Cdn.Example.com:443?security=tls&type=ws&host=cdn.example.com&sni=cdn.example.com#Pasted",
        )
        val canonical = parsed(
            "vless://1b4e28ba-2fa1-11d2-883f-0016d3cca427@cdn.example.com:443?encryption=none&type=ws&security=tls" +
                "&sni=cdn.example.com&path=%2F&host=cdn.example.com&fp=chrome&alpn=h2#From%20cloud",
        )
        assertEquals(HostedNodeIdentity.key(pasted), HostedNodeIdentity.key(canonical))

        val otherPassword = parsed("socks5://alice:other@198.51.100.7:1080")
        val socks = parsed("socks5://alice:wonder@198.51.100.7:1080")
        assertEquals(false, HostedNodeIdentity.key(otherPassword) == HostedNodeIdentity.key(socks))

        val kept = HostedNodeIdentity.withoutHostedDuplicates(listOf(pasted, otherPassword), hosted = listOf(canonical, socks))
        assertEquals(listOf(otherPassword), kept)
    }
}
