package com.leikwan.bridge.singbox

import com.google.gson.JsonObject
import com.google.gson.JsonParser
import com.leikwan.bridge.proxy.ProxyUriParser
import com.leikwan.bridge.runtime.ForwardMode
import com.leikwan.bridge.runtime.RuntimeContext
import com.leikwan.bridge.service.ProxyConnectionMode
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Test

/** VLESS nodes (hosted or local) dial 127.0.0.1 through the forward; TLS must still name the real server. */
class HostedVlessOutboundTest {
    private val builder = SingboxConfigBuilder(
        settings = SingboxSettings(
            panelUrl = "https://panel.example.com",
            dnsServer = "1.1.1.1",
            dnsFallback = "8.8.8.8",
            dnsStrategy = "prefer_ipv4",
            bypassLan = true,
            bypassChina = false,
            customBypassRules = "",
        ),
    )

    private fun outbound(uri: String): JsonObject {
        val node = ProxyUriParser.parse(uri)!!.copy(id = 9)
        val context = RuntimeContext(
            forwardId = 45L,
            mode = ForwardMode.BOND,
            localListenPort = 32216,
            localLegPorts = listOf(21667),
            processPlans = emptyList(),
            bypassHosts = linkedSetOf(),
        )
        val config = JsonParser.parseString(
            builder.build(proxyNodes = listOf(node), runtimeContext = context, connectionMode = ProxyConnectionMode.SERVICES).configJson,
        ).asJsonObject
        return config.getAsJsonArray("outbounds").first { it.asJsonObject.get("tag").asString == "proxy-9" }.asJsonObject
    }

    @Test
    fun `reality gets uTLS by default and keeps its server name`() {
        val vless = outbound(
            "vless://1b4e28ba-2fa1-11d2-883f-0016d3cca427@203.0.113.9:443?security=reality&sni=www.microsoft.com" +
                "&pbk=Z84J2IelR9ch3k8VtlVhhs5ycBUlXA7wHBWcBrjqnAw&sid=6ba8&flow=xtls-rprx-vision&type=tcp",
        )
        val tls = vless.getAsJsonObject("tls")
        assertEquals("127.0.0.1", vless.get("server").asString)
        assertEquals("www.microsoft.com", tls.get("server_name").asString)
        assertEquals("chrome", tls.getAsJsonObject("utls").get("fingerprint").asString)
        assertEquals("6ba8", tls.getAsJsonObject("reality").get("short_id").asString)
        assertEquals("xtls-rprx-vision", vless.get("flow").asString)
        assertFalse("raw tcp has no transport block", vless.has("transport"))
    }

    @Test
    fun `tls without sni names the node host rather than the loopback address`() {
        val vless = outbound(
            "vless://1b4e28ba-2fa1-11d2-883f-0016d3cca427@cdn.example.com:443?security=tls&type=ws&path=%2Fray" +
                "&alpn=h2%2Chttp%2F1.1&fp=firefox&allowInsecure=1",
        )
        val tls = vless.getAsJsonObject("tls")
        assertEquals("cdn.example.com", tls.get("server_name").asString)
        assertEquals(listOf("h2", "http/1.1"), tls.getAsJsonArray("alpn").map { it.asString })
        assertEquals("firefox", tls.getAsJsonObject("utls").get("fingerprint").asString)
        assertEquals(true, tls.get("insecure").asBoolean)
        assertEquals("/ray", vless.getAsJsonObject("transport").get("path").asString)
    }

    @Test
    fun `tls to an ip literal without sni sets no server name`() {
        val tls = outbound("vless://1b4e28ba-2fa1-11d2-883f-0016d3cca427@203.0.113.9:443?security=tls").getAsJsonObject("tls")
        assertFalse(tls.has("server_name"))
        assertFalse(tls.has("utls"))
    }

    @Test
    fun `httpupgrade transport carries path and host`() {
        val transport = outbound(
            "vless://1b4e28ba-2fa1-11d2-883f-0016d3cca427@h.example.com:80?type=httpupgrade&path=%2Fup&host=h.example.com",
        ).getAsJsonObject("transport")
        assertEquals("httpupgrade", transport.get("type").asString)
        assertEquals("/up", transport.get("path").asString)
        assertEquals("h.example.com", transport.get("host").asString)
    }
}
