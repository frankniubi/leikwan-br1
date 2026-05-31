package com.leikwan.bridge.singbox

import com.google.gson.JsonParser
import com.leikwan.bridge.data.ProxyNode
import com.leikwan.bridge.routing.RoutingCategoryCatalog
import com.leikwan.bridge.routing.RoutingPolicyEngine
import com.leikwan.bridge.routing.RoutingRule
import com.leikwan.bridge.routing.RoutingRuleKind
import com.leikwan.bridge.routing.SafePathRegistry
import com.leikwan.bridge.routing.UserTrafficOutcome
import com.leikwan.bridge.runtime.ForwardMode
import com.leikwan.bridge.runtime.RuntimeContext
import com.leikwan.bridge.service.ProxyConnectionMode
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test

class SingboxConfigBuilderTest {

    @Test
    fun `builder uses selected runtime port and direct bypass hosts`() {
        val builder = SingboxConfigBuilder(
            settings = SingboxSettings(
                panelUrl = "https://panel.example.com",
                dnsServer = "1.1.1.1",
                dnsFallback = "8.8.8.8",
                dnsStrategy = "prefer_ipv4",
                bypassLan = true,
                bypassChina = false,
                customBypassRules = "",
            )
        )
        val runtimeContext = RuntimeContext(
            forwardId = 26L,
            mode = ForwardMode.BOND,
            localListenPort = 11024,
            localLegPorts = listOf(20962, 20963),
            processPlans = emptyList(),
            bypassHosts = linkedSetOf("8.163.90.132", "panel-slave.example.com"),
        )
        val nodes = listOf(
            ProxyNode(
                id = 1,
                name = "ss-main",
                type = "ss",
                originalServer = "remote.example.com",
                originalPort = 443,
                configJson = """{"method":"aes-128-gcm","password":"secret"}""",
                forwardId = null,
            ),
            ProxyNode(
                id = 2,
                name = "ss-other-forward",
                type = "ss",
                originalServer = "remote.example.com",
                originalPort = 443,
                configJson = """{"method":"aes-128-gcm","password":"secret"}""",
                forwardId = 99L,
            ),
        )

        val config = JsonParser.parseString(builder.build(nodes, runtimeContext).configJson).asJsonObject
        val outbounds = config.getAsJsonArray("outbounds")
        val mainProxy = outbounds.first { it.asJsonObject.get("tag").asString == "proxy-1" }.asJsonObject
        val routeRules = config.getAsJsonObject("route").getAsJsonArray("rules")
        val log = config.getAsJsonObject("log")
        val dnsServers = config.getAsJsonObject("dns").getAsJsonArray("servers")
        val remoteDns = dnsServers.first { it.asJsonObject.get("tag").asString == "remote" }.asJsonObject

        assertEquals(11024, mainProxy.get("server_port").asInt)
        assertEquals("debug", log.get("level").asString)
        assertFalse(outbounds.any { it.asJsonObject.get("type").asString == "selector" })
        assertEquals("proxy-1", config.getAsJsonObject("route").get("final").asString)
        assertEquals("tcp", remoteDns.get("type").asString)
        assertEquals("proxy-1", remoteDns.get("detour").asString)
        assertFalse(outbounds.any { it.asJsonObject.get("tag").asString == "proxy-2" })
        assertTrue(routeRules.any { rule ->
            val obj = rule.asJsonObject
            obj.has("domain") && obj.getAsJsonArray("domain").any { it.asString == "panel.example.com" }
        })
        assertTrue(routeRules.any { rule ->
            val obj = rule.asJsonObject
            obj.has("ip_cidr") && obj.getAsJsonArray("ip_cidr").any { it.asString == "8.163.90.132/32" }
        })
        assertTrue(routeRules.any { rule ->
            val obj = rule.asJsonObject
            obj.has("domain") && obj.getAsJsonArray("domain").any { it.asString == "panel-slave.example.com" }
        })
    }

    @Test
    fun `builder falls back to direct selector when no nodes match selected forward`() {
        val builder = SingboxConfigBuilder(
            settings = SingboxSettings(
                panelUrl = "https://panel.example.com",
                dnsServer = "1.1.1.1",
                dnsFallback = "8.8.8.8",
                dnsStrategy = "prefer_ipv4",
                bypassLan = true,
                bypassChina = false,
                customBypassRules = "",
            )
        )
        val runtimeContext = RuntimeContext(
            forwardId = 55L,
            mode = ForwardMode.SINGLE,
            localListenPort = 11055,
            localLegPorts = emptyList(),
            processPlans = emptyList(),
            bypassHosts = emptySet(),
        )
        val nodes = listOf(
            ProxyNode(
                id = 2,
                name = "ss-other-forward",
                type = "ss",
                originalServer = "remote.example.com",
                originalPort = 443,
                configJson = """{"method":"aes-128-gcm","password":"secret"}""",
                forwardId = 99L,
            ),
        )

        val config = JsonParser.parseString(builder.build(nodes, runtimeContext).configJson).asJsonObject
        val outbounds = config.getAsJsonArray("outbounds")
        val proxySelector = outbounds.first { it.asJsonObject.get("tag").asString == "proxy" }.asJsonObject
        val log = config.getAsJsonObject("log")
        val dnsServers = config.getAsJsonObject("dns").getAsJsonArray("servers")
        val remoteDns = dnsServers.first { it.asJsonObject.get("tag").asString == "remote" }.asJsonObject

        assertEquals("direct", proxySelector.get("type").asString)
        assertEquals("udp", remoteDns.get("type").asString)
        assertFalse(remoteDns.has("detour"))
        assertEquals("debug", log.get("level").asString)
    }

    @Test
    fun `builder uses current sing-box actions and vmess local loopback`() {
        val builder = SingboxConfigBuilder(
            settings = SingboxSettings(
                panelUrl = "https://panel.example.com",
                dnsServer = "1.1.1.1",
                dnsFallback = "8.8.8.8",
                dnsStrategy = "prefer_ipv4",
                bypassLan = true,
                bypassChina = false,
                customBypassRules = "",
            )
        )
        val runtimeContext = RuntimeContext(
            forwardId = 45L,
            mode = ForwardMode.BOND,
            localListenPort = 32216,
            localLegPorts = listOf(21667, 21668),
            processPlans = emptyList(),
            bypassHosts = linkedSetOf("8.163.90.132", "relay.example.com"),
        )
        val nodes = listOf(
            ProxyNode(
                id = 3,
                name = "vmess-main",
                type = "vmess",
                originalServer = "remote.example.com",
                originalPort = 443,
                configJson = """{"id":"uuid-123","aid":0,"net":"ws","path":"/ws","host":"relay.example.com"}""",
                forwardId = null,
            ),
        )

        val config = JsonParser.parseString(
            builder.build(
                proxyNodes = nodes,
                runtimeContext = runtimeContext,
                connectionMode = ProxyConnectionMode.SERVICES,
            ).configJson
        ).asJsonObject
        val outbounds = config.getAsJsonArray("outbounds")
        val routeRules = config.getAsJsonObject("route").getAsJsonArray("rules")
        val inbounds = config.getAsJsonArray("inbounds")
        val dnsServers = config.getAsJsonObject("dns").getAsJsonArray("servers")
        val vmess = outbounds.first { it.asJsonObject.get("tag").asString == "proxy-3" }.asJsonObject
        val tunInbound = inbounds.first().asJsonObject
        val directDns = dnsServers.first { it.asJsonObject.get("tag").asString == "direct-dns" }.asJsonObject
        val remoteDns = dnsServers.first { it.asJsonObject.get("tag").asString == "remote" }.asJsonObject

        assertEquals("127.0.0.1", vmess.get("server").asString)
        assertEquals(32216, vmess.get("server_port").asInt)
        assertFalse(directDns.has("detour"))
        assertFalse(outbounds.any { it.asJsonObject.get("type").asString == "selector" })
        assertEquals("proxy-3", config.getAsJsonObject("route").get("final").asString)
        assertFalse(config.getAsJsonObject("route").has("auto_detect_interface"))
        assertEquals("proxy-3", remoteDns.get("detour").asString)
        assertFalse(outbounds.any { it.asJsonObject.get("type").asString == "dns" })
        assertFalse(outbounds.any { it.asJsonObject.get("type").asString == "block" })
        assertFalse(tunInbound.has("sniff"))
        assertFalse(tunInbound.has("sniff_override_destination"))
        assertTrue(routeRules.any { it.asJsonObject.get("action")?.asString == "sniff" })
        assertTrue(routeRules.any { it.asJsonObject.get("action")?.asString == "hijack-dns" })
    }

    @Test
    fun `tun inbound uses mobile safe mtu to avoid oversized udp proxy packets`() {
        val builder = SingboxConfigBuilder(settings = baseSettings())

        val config = JsonParser.parseString(
            builder.build(
                proxyNodes = listOf(ssNode(1)),
                runtimeContext = null,
                connectionMode = ProxyConnectionMode.DIRECT,
            ).configJson
        ).asJsonObject
        val tunInbound = config.getAsJsonArray("inbounds").first().asJsonObject

        assertEquals(1280, tunInbound.get("mtu").asInt)
    }

    @Test
    fun `builder keeps original proxy address in direct mode`() {
        val builder = SingboxConfigBuilder(
            settings = SingboxSettings(
                panelUrl = "https://panel.example.com",
                dnsServer = "1.1.1.1",
                dnsFallback = "8.8.8.8",
                dnsStrategy = "prefer_ipv4",
                bypassLan = true,
                bypassChina = false,
                customBypassRules = "",
            )
        )
        val node = ProxyNode(
            id = 4,
            name = "ss-direct",
            type = "ss",
            originalServer = "203.0.113.7",
            originalPort = 443,
            configJson = """{"method":"aes-128-gcm","password":"secret"}""",
            forwardId = null,
        )

        val config = JsonParser.parseString(
            builder.build(
                proxyNodes = listOf(node),
                runtimeContext = null,
                connectionMode = ProxyConnectionMode.DIRECT,
            ).configJson
        ).asJsonObject
        val outbounds = config.getAsJsonArray("outbounds")
        val directProxy = outbounds.first { it.asJsonObject.get("tag").asString == "proxy-4" }.asJsonObject
        val dnsServers = config.getAsJsonObject("dns").getAsJsonArray("servers")
        val remoteDns = dnsServers.first { it.asJsonObject.get("tag").asString == "remote" }.asJsonObject
        val directDns = dnsServers.first { it.asJsonObject.get("tag").asString == "direct-dns" }.asJsonObject

        assertEquals("203.0.113.7", directProxy.get("server").asString)
        assertEquals(443, directProxy.get("server_port").asInt)
        assertFalse(outbounds.any { it.asJsonObject.get("type").asString == "selector" })
        assertEquals("proxy-4", config.getAsJsonObject("route").get("final").asString)
        assertFalse(config.getAsJsonObject("route").has("auto_detect_interface"))
        assertEquals("tcp", remoteDns.get("type").asString)
        assertEquals("proxy-4", remoteDns.get("detour").asString)
        assertFalse(directDns.has("detour"))
    }

    @Test
    fun `builder does not inject ss2022 transport overrides in direct mode`() {
        val builder = SingboxConfigBuilder(
            settings = SingboxSettings(
                panelUrl = "https://panel.example.com",
                dnsServer = "1.1.1.1",
                dnsFallback = "8.8.8.8",
                dnsStrategy = "prefer_ipv4",
                bypassLan = true,
                bypassChina = false,
                customBypassRules = "",
            )
        )
        val node = ProxyNode(
            id = 5,
            name = "ss2022-direct",
            type = "ss",
            originalServer = "14.103.220.245",
            originalPort = 32469,
            configJson = """{"method":"2022-blake3-aes-256-gcm","password":"server:user"}""",
            forwardId = null,
        )

        val config = JsonParser.parseString(
            builder.build(
                proxyNodes = listOf(node),
                runtimeContext = null,
                connectionMode = ProxyConnectionMode.DIRECT,
            ).configJson
        ).asJsonObject
        val outbounds = config.getAsJsonArray("outbounds")
        val directProxy = outbounds.first { it.asJsonObject.get("tag").asString == "proxy-5" }.asJsonObject
        val dnsServers = config.getAsJsonObject("dns").getAsJsonArray("servers")
        val remoteDns = dnsServers.first { it.asJsonObject.get("tag").asString == "remote" }.asJsonObject
        val directDns = dnsServers.first { it.asJsonObject.get("tag").asString == "direct-dns" }.asJsonObject

        assertFalse(directProxy.has("network"))
        assertFalse(directProxy.has("multiplex"))
        assertEquals("tcp", remoteDns.get("type").asString)
        assertEquals("proxy-5", remoteDns.get("detour").asString)
        assertFalse(directDns.has("detour"))
    }

    @Test
    fun `direct mode resolves proxy hostnames with direct dns to avoid dns proxy bootstrap loops`() {
        val builder = SingboxConfigBuilder(
            settings = SingboxSettings(
                panelUrl = "https://panel.example.com",
                dnsServer = "1.1.1.1",
                dnsFallback = "8.8.8.8",
                dnsStrategy = "prefer_ipv4",
                bypassLan = true,
                bypassChina = false,
                customBypassRules = "",
            )
        )
        val node = ProxyNode(
            id = 8,
            name = "domain-direct",
            type = "ss",
            originalServer = "proxy.example.net",
            originalPort = 443,
            configJson = """{"method":"aes-128-gcm","password":"secret"}""",
            forwardId = null,
        )

        val config = JsonParser.parseString(
            builder.build(
                proxyNodes = listOf(node),
                runtimeContext = null,
                connectionMode = ProxyConnectionMode.DIRECT,
            ).configJson
        ).asJsonObject
        val dnsRules = config.getAsJsonObject("dns").getAsJsonArray("rules")

        assertTrue(dnsRules.any { rule ->
            val obj = rule.asJsonObject
            obj.get("server")?.asString == "direct-dns" &&
                obj.has("domain") &&
                obj.getAsJsonArray("domain").any { it.asString == "proxy.example.net" }
        })
    }

    @Test
    fun `builder passes through ss plugin options`() {
        val builder = SingboxConfigBuilder(
            settings = SingboxSettings(
                panelUrl = "https://panel.example.com",
                dnsServer = "1.1.1.1",
                dnsFallback = "8.8.8.8",
                dnsStrategy = "prefer_ipv4",
                bypassLan = true,
                bypassChina = false,
                customBypassRules = "",
            )
        )
        val node = ProxyNode(
            id = 6,
            name = "ss-plugin",
            type = "ss",
            originalServer = "14.103.220.245",
            originalPort = 32469,
            configJson = """{"method":"aes-256-gcm","password":"secret","plugin":"v2ray-plugin","plugin_opts":"tls;host=example.com","network":"tcp"}""",
            forwardId = null,
        )

        val config = JsonParser.parseString(
            builder.build(
                proxyNodes = listOf(node),
                runtimeContext = null,
                connectionMode = ProxyConnectionMode.DIRECT,
            ).configJson
        ).asJsonObject
        val outbounds = config.getAsJsonArray("outbounds")
        val directProxy = outbounds.first { it.asJsonObject.get("tag").asString == "proxy-6" }.asJsonObject

        assertEquals("v2ray-plugin", directProxy.get("plugin").asString)
        assertEquals("tls;host=example.com", directProxy.get("plugin_opts").asString)
        assertEquals("tcp", directProxy.get("network").asString)
    }

    // ---- v2 routing-compilation tests (user country/category rules reach the route JSON) ----

    @Test
    fun `protected panel rule is direct and precedes user country rule`() {
        val builder = SingboxConfigBuilder(settings = baseSettings())
        val snapshot = engine().compile(
            globalDefault = UserTrafficOutcome.TUNNEL,
            countryRules = listOf(RoutingRule("c-cn", RoutingRuleKind.COUNTRY, "CN", UserTrafficOutcome.TUNNEL)),
            categoryRules = emptyList(),
            rulePackVersions = listOf("test"),
            compiledAtMs = 1L,
        )
        val config = JsonParser.parseString(
            builder.build(listOf(ssNode(1)), null, snapshot, ProxyConnectionMode.DIRECT).configJson
        ).asJsonObject
        val rules = config.getAsJsonObject("route").getAsJsonArray("rules").map { it.asJsonObject }

        val panelIdx = rules.indexOfFirst { r ->
            r.has("domain") && r.getAsJsonArray("domain").any { it.asString == "panel.example.com" }
        }
        val cnIdx = rules.indexOfFirst { r ->
            r.has("rule_set") && r.getAsJsonArray("rule_set").any { it.asString == "geoip-cn" }
        }
        assertTrue("panel rule present", panelIdx >= 0)
        assertTrue("country rule present", cnIdx >= 0)
        assertTrue("protected panel precedes user country rule", panelIdx < cnIdx)
        assertEquals("direct", rules[panelIdx].get("outbound").asString)
    }

    @Test
    fun `country DIRECT rule emits geoip-cn direct rule and rule_set definition`() {
        val builder = SingboxConfigBuilder(settings = baseSettings())
        val snapshot = engine().compile(
            globalDefault = UserTrafficOutcome.TUNNEL,
            countryRules = listOf(RoutingRule("c-cn", RoutingRuleKind.COUNTRY, "CN", UserTrafficOutcome.DIRECT)),
            categoryRules = emptyList(),
            rulePackVersions = listOf("test"),
            compiledAtMs = 1L,
        )
        val route = JsonParser.parseString(
            builder.build(listOf(ssNode(1)), null, snapshot, ProxyConnectionMode.DIRECT).configJson
        ).asJsonObject.getAsJsonObject("route")

        val cnRule = route.getAsJsonArray("rules").map { it.asJsonObject }
            .first { it.has("rule_set") && it.getAsJsonArray("rule_set").any { t -> t.asString == "geoip-cn" } }
        assertEquals("direct", cnRule.get("outbound").asString)

        val geoipCn = route.getAsJsonArray("rule_set").map { it.asJsonObject }
            .first { it.get("tag").asString == "geoip-cn" }
        assertEquals("remote", geoipCn.get("type").asString)
        assertEquals("binary", geoipCn.get("format").asString)
        assertTrue(geoipCn.get("url").asString.contains("sing-geoip"))
    }

    @Test
    fun `category TUNNEL rule under DIRECT default routes to proxy tag and sets final direct`() {
        val builder = SingboxConfigBuilder(settings = baseSettings())
        val snapshot = engine().compile(
            globalDefault = UserTrafficOutcome.DIRECT,
            countryRules = emptyList(),
            categoryRules = listOf(
                RoutingRule("cat-video", RoutingRuleKind.WEBSITE_CATEGORY, "video", UserTrafficOutcome.TUNNEL)
            ),
            rulePackVersions = listOf("test"),
            compiledAtMs = 1L,
        )
        val result = builder.build(listOf(ssNode(7)), null, snapshot, ProxyConnectionMode.DIRECT)
        val route = JsonParser.parseString(result.configJson).asJsonObject.getAsJsonObject("route")

        assertEquals("proxy-7", result.finalProxyTag)
        assertEquals("direct", route.get("final").asString)
        val videoRule = route.getAsJsonArray("rules").map { it.asJsonObject }
            .first { it.has("rule_set") && it.getAsJsonArray("rule_set").any { t -> t.asString == "geosite-youtube" } }
        assertEquals(result.finalProxyTag, videoRule.get("outbound").asString)
    }

    @Test
    fun `no duplicate panel rule when snapshot present`() {
        val builder = SingboxConfigBuilder(settings = baseSettings())
        val snapshot = engine().compile(
            globalDefault = UserTrafficOutcome.TUNNEL,
            countryRules = emptyList(),
            categoryRules = emptyList(),
            rulePackVersions = listOf("test"),
            compiledAtMs = 1L,
        )
        val rules = JsonParser.parseString(
            builder.build(listOf(ssNode(1)), null, snapshot, ProxyConnectionMode.DIRECT).configJson
        ).asJsonObject.getAsJsonObject("route").getAsJsonArray("rules").map { it.asJsonObject }

        val panelOccurrences = rules.count { r ->
            r.has("domain") && r.getAsJsonArray("domain").any { it.asString == "panel.example.com" }
        }
        assertEquals(1, panelOccurrences)
    }

    private fun engine() = RoutingPolicyEngine(
        SafePathRegistry.androidBaseline("https://panel.example.com", "v3-mirror.leikwanhost.com"),
        RoutingCategoryCatalog.default(),
    )

    private fun baseSettings() = SingboxSettings(
        panelUrl = "https://panel.example.com",
        dnsServer = "1.1.1.1",
        dnsFallback = "8.8.8.8",
        dnsStrategy = "prefer_ipv4",
        bypassLan = true,
        bypassChina = false,
        customBypassRules = "",
    )

    private fun ssNode(id: Long) = ProxyNode(
        id = id,
        name = "ss-$id",
        type = "ss",
        originalServer = "remote.example.com",
        originalPort = 443,
        configJson = """{"method":"aes-128-gcm","password":"secret"}""",
        forwardId = null,
    )
}
