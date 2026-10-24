package com.leikwan.bridge.singbox

import com.google.gson.JsonParser
import com.leikwan.bridge.clientrules.ClientRouteRule
import com.leikwan.bridge.clientrules.ClientRouteRuleSnapshot
import com.leikwan.bridge.clientrules.ClientRuleEffectiveState
import com.leikwan.bridge.data.routing.RulePackManifest
import com.leikwan.bridge.data.ProxyNode
import com.leikwan.bridge.routing.RoutingCategoryCatalog
import com.leikwan.bridge.routing.RoutingPolicyEngine
import com.leikwan.bridge.routing.RoutingRule
import com.leikwan.bridge.routing.RoutingRuleKind
import com.leikwan.bridge.routing.SafePathRegistry
import com.leikwan.bridge.routing.UserTrafficOutcome
import com.leikwan.bridge.runtime.ClientRuleSingboxBinding
import com.leikwan.bridge.runtime.ForwardMode
import com.leikwan.bridge.runtime.NodeLeg
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
    fun `dedicated leigod build routes proxy tag through local runtime`() {
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
            forwardId = 65L,
            mode = ForwardMode.SINGLE,
            localListenPort = 11065,
            localLegPorts = emptyList(),
            processPlans = emptyList(),
            bypassHosts = linkedSetOf("162.245.221.78"),
        )

        val config = JsonParser.parseString(
            builder.build(
                proxyNodes = emptyList(),
                runtimeContext = runtimeContext,
                routingPolicySnapshot = null,
                connectionMode = ProxyConnectionMode.SERVICES,
                runtimeOnlyProxy = true,
            ).configJson
        ).asJsonObject
        val outbounds = config.getAsJsonArray("outbounds").map { it.asJsonObject }
        val dnsServers = config.getAsJsonObject("dns").getAsJsonArray("servers").map { it.asJsonObject }
        val remoteDns = dnsServers.first { it.get("tag").asString == "remote" }
        val directDns = dnsServers.first { it.get("tag").asString == "direct-dns" }
        val proxy = outbounds.first { it.get("tag").asString == "proxy" }
        val direct = outbounds.first { it.get("tag").asString == "direct" }

        assertEquals("direct", proxy.get("type").asString)
        assertFalse(proxy.has("override_address"))
        assertFalse(proxy.has("override_port"))
        assertEquals("udp", remoteDns.get("type").asString)
        assertFalse(remoteDns.has("detour"))
        assertFalse(directDns.has("detour"))
        assertEquals("direct", direct.get("type").asString)
        assertFalse(direct.has("override_address"))
        assertFalse(direct.has("override_port"))
        assertFalse(outbounds.any { it.get("tag").asString.startsWith("proxy-") })
        assertEquals("proxy", config.getAsJsonObject("route").get("final").asString)
        val bridgeRule = config.getAsJsonObject("route").getAsJsonArray("rules")
            .map { it.asJsonObject }
            .first { it.get("action")?.asString == "route" && it.get("outbound")?.asString == "proxy" }
        assertEquals("127.0.0.1", bridgeRule.get("override_address").asString)
        assertEquals(11065, bridgeRule.get("override_port").asInt)
    }

    @Test
    fun `ultra selected shadowsocks adapter preserves original target`() {
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
            forwardId = 90L,
            mode = ForwardMode.ULTRA,
            localListenPort = 11090,
            localLegPorts = emptyList(),
            processPlans = emptyList(),
            bypassHosts = emptySet(),
        )
        val node = ProxyNode(
            id = 7,
            name = "HKG-HKG1",
            type = "ss",
            originalServer = "sh36.ctyun.mcbar.cc",
            originalPort = 30522,
            configJson = """{"method":"2022-blake3-aes-256-gcm","password":"secret"}""",
            enabled = true,
        )

        val config = JsonParser.parseString(
            builder.build(
                proxyNodes = listOf(node),
                runtimeContext = runtimeContext,
                connectionMode = ProxyConnectionMode.SERVICES,
                runtimeOnlyProxy = true,
            ).configJson
        ).asJsonObject
        val outbounds = config.getAsJsonArray("outbounds").map { it.asJsonObject }
        val proxy = outbounds.first { it.get("tag").asString == "proxy-7" }
        assertEquals("shadowsocks", proxy.get("type").asString)
        assertEquals("127.0.0.1", proxy.get("server").asString)
        assertEquals(11090, proxy.get("server_port").asInt)
        assertEquals("2022-blake3-aes-256-gcm", proxy.get("method").asString)
        assertEquals("secret", proxy.get("password").asString)
        assertFalse(
            config.getAsJsonObject("route").getAsJsonArray("rules").any {
                val rule = it.asJsonObject
                rule.get("action")?.asString == "route" && rule.has("override_address")
            }
        )
        assertEquals("proxy-7", config.getAsJsonObject("route").get("final").asString)
    }

    @Test
    fun `sing-box v1_13 keeps runtime bridge override on route rule`() {
        val builder = SingboxConfigBuilder(
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
        val runtimeContext = RuntimeContext(
            forwardId = 65L,
            mode = ForwardMode.SINGLE,
            localListenPort = 11065,
            localLegPorts = emptyList(),
            processPlans = emptyList(),
            bypassHosts = emptySet(),
        )

        val config = JsonParser.parseString(
            builder.build(
                proxyNodes = emptyList(),
                runtimeContext = runtimeContext,
                connectionMode = ProxyConnectionMode.SERVICES,
                runtimeOnlyProxy = true,
            ).configJson,
        ).asJsonObject
        val proxy = config.getAsJsonArray("outbounds")
            .map { it.asJsonObject }
            .first { it.get("tag").asString == "proxy" }
        val bridgeRule = config.getAsJsonObject("route").getAsJsonArray("rules")
            .map { it.asJsonObject }
            .first { it.get("action")?.asString == "route" && it.get("outbound")?.asString == "proxy" }

        assertFalse(proxy.has("override_address"))
        assertFalse(proxy.has("override_port"))
        assertEquals("127.0.0.1", bridgeRule.get("override_address").asString)
        assertEquals(11065, bridgeRule.get("override_port").asInt)
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
    fun `tun inbound uses large mtu for throughput`() {
        val builder = SingboxConfigBuilder(settings = baseSettings())

        val config = JsonParser.parseString(
            builder.build(
                proxyNodes = listOf(ssNode(1)),
                runtimeContext = null,
                connectionMode = ProxyConnectionMode.DIRECT,
            ).configJson
        ).asJsonObject
        val tunInbound = config.getAsJsonArray("inbounds").first().asJsonObject

        assertEquals(9000, tunInbound.get("mtu").asInt)
    }

    @Test
    fun `tun inbound enables endpoint independent nat for full cone udp`() {
        val builder = SingboxConfigBuilder(settings = baseSettings())

        val config = JsonParser.parseString(
            builder.build(
                proxyNodes = listOf(ssNode(1)),
                runtimeContext = null,
                connectionMode = ProxyConnectionMode.DIRECT,
            ).configJson
        ).asJsonObject
        val tunInbound = config.getAsJsonArray("inbounds").first().asJsonObject

        assertTrue(tunInbound.get("endpoint_independent_nat").asBoolean)
    }

    @Test
    fun `tun inbound always captures ipv6 so it cannot leak around the tunnel`() {
        val builder = SingboxConfigBuilder(settings = baseSettings())

        val config = JsonParser.parseString(
            builder.build(
                proxyNodes = listOf(ssNode(1)),
                runtimeContext = null,
                connectionMode = ProxyConnectionMode.DIRECT,
            ).configJson
        ).asJsonObject
        val tunInbound = config.getAsJsonArray("inbounds").first().asJsonObject
        val addresses = tunInbound.getAsJsonArray("address").map { it.asString }

        assertTrue(addresses.any { it == "172.19.0.1/30" })
        assertTrue(addresses.any { it == "fdfe:dcba:9876::1/126" })
    }

    @Test
    fun `captured ipv6 is rejected in the tunnel while dns hands out ipv4 only`() {
        val builder = SingboxConfigBuilder(settings = baseSettings())

        val rules = JsonParser.parseString(
            builder.build(
                proxyNodes = listOf(ssNode(1)),
                runtimeContext = null,
                connectionMode = ProxyConnectionMode.DIRECT,
            ).configJson
        ).asJsonObject.getAsJsonObject("route").getAsJsonArray("rules").map { it.asJsonObject }

        val hijackIndex = rules.indexOfFirst { it.get("action")?.asString == "hijack-dns" }
        val rejectIndex = rules.indexOfFirst {
            it.get("ip_version")?.asInt == 6 && it.get("action")?.asString == "reject"
        }
        assertTrue(hijackIndex >= 0)
        assertTrue("v6 DNS is still answered by sing-box", rejectIndex > hijackIndex)
        val firstOutboundRule = rules.indexOfFirst { it.has("outbound") }
        assertTrue("v6 is refused before any direct or proxy rule", rejectIndex < firstOutboundRule)
    }

    @Test
    fun `ipv6 dns opt-in carries ipv6 instead of rejecting it`() {
        val builder = SingboxConfigBuilder(settings = baseSettings())

        val config = JsonParser.parseString(
            builder.build(
                proxyNodes = listOf(ssNode(1)),
                runtimeContext = null,
                connectionMode = ProxyConnectionMode.DIRECT,
                enableIpv6Dns = true,
            ).configJson
        ).asJsonObject
        val rules = config.getAsJsonObject("route").getAsJsonArray("rules").map { it.asJsonObject }

        assertFalse(rules.any { it.has("ip_version") })
        assertEquals(baseSettings().dnsStrategy, config.getAsJsonObject("dns").get("strategy").asString)
    }

    @Test
    fun `dns strategy is ipv4 only unless ipv6 dns is enabled`() {
        val builder = SingboxConfigBuilder(settings = baseSettings())

        val config = JsonParser.parseString(
            builder.build(
                proxyNodes = listOf(ssNode(1)),
                runtimeContext = null,
                connectionMode = ProxyConnectionMode.DIRECT,
            ).configJson
        ).asJsonObject

        assertEquals("ipv4_only", config.getAsJsonObject("dns").get("strategy").asString)
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
            globalDefaultNodeId = null,
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
    fun `active proxy and WireGuard peer endpoints are direct before user rules`() {
        val builder = SingboxConfigBuilder(settings = baseSettings())
        val snapshot = engine().compile(
            globalDefault = UserTrafficOutcome.TUNNEL,
            globalDefaultNodeId = null,
            countryRules = listOf(RoutingRule("c-cn", RoutingRuleKind.COUNTRY, "CN", UserTrafficOutcome.TUNNEL)),
            categoryRules = emptyList(),
            rulePackVersions = listOf("test"),
            compiledAtMs = 1L,
        )
        val nodes = listOf(
            ProxyNode(
                id = 44,
                name = "direct-ss",
                type = "ss",
                originalServer = "198.51.100.44",
                originalPort = 443,
                configJson = """{"method":"aes-128-gcm","password":"secret"}""",
            ),
            ProxyNode(
                id = 45,
                name = "peer-wg",
                type = "wireguard",
                originalServer = "wg.example.test",
                originalPort = 51820,
                configJson = """{"private_key":"private","address":["10.0.0.2/32"],"peers":[{"public_key":"public","address":"wg.example.test","port":51820,"allowed_ips":["0.0.0.0/0"]}]}""",
            ),
            ProxyNode(
                id = 46,
                name = "direct-vless",
                type = "vless",
                originalServer = "vless.example.test",
                originalPort = 443,
                configJson = """{"uuid":"uuid","sni":"vless.example.test"}""",
            ),
            ProxyNode(
                id = 47,
                name = "direct-socks",
                type = "socks5",
                originalServer = "socks.example.test",
                originalPort = 1080,
                configJson = """{"username":"user","password":"pass"}""",
            ),
        )

        val config = JsonParser.parseString(
            builder.build(
                proxyNodes = nodes,
                runtimeContext = null,
                routingPolicySnapshot = snapshot,
                connectionMode = ProxyConnectionMode.DIRECT,
            ).configJson,
        ).asJsonObject
        val rules = config.getAsJsonObject("route").getAsJsonArray("rules").map { it.asJsonObject }
        val ipIndex = rules.indexOfFirst { rule ->
            rule.has("ip_cidr") && rule.getAsJsonArray("ip_cidr").any { it.asString == "198.51.100.44/32" }
        }
        val peerIndex = rules.indexOfFirst { rule ->
            rule.has("domain") && rule.getAsJsonArray("domain").any { it.asString == "wg.example.test" }
        }
        val vlessIndex = rules.indexOfFirst { rule ->
            rule.has("domain") && rule.getAsJsonArray("domain").any { it.asString == "vless.example.test" }
        }
        val socksIndex = rules.indexOfFirst { rule ->
            rule.has("domain") && rule.getAsJsonArray("domain").any { it.asString == "socks.example.test" }
        }
        val countryIndex = rules.indexOfFirst { rule ->
            rule.has("rule_set") && rule.getAsJsonArray("rule_set").any { it.asString == "geoip-cn" }
        }

        assertTrue(ipIndex >= 0)
        assertTrue(peerIndex >= 0)
        assertTrue(vlessIndex >= 0)
        assertTrue(socksIndex >= 0)
        assertTrue(countryIndex >= 0)
        assertEquals("direct", rules[ipIndex].get("outbound").asString)
        assertEquals("direct", rules[peerIndex].get("outbound").asString)
        assertEquals("direct", rules[vlessIndex].get("outbound").asString)
        assertEquals("direct", rules[socksIndex].get("outbound").asString)
        assertTrue(ipIndex < countryIndex)
        assertTrue(peerIndex < countryIndex)
        assertTrue(vlessIndex < countryIndex)
        assertTrue(socksIndex < countryIndex)
    }

    @Test
    fun `builder emits a SOCKS5 outbound with optional credentials`() {
        val config = JsonParser.parseString(
            SingboxConfigBuilder(settings = baseSettings()).build(
                proxyNodes = listOf(
                    ProxyNode(
                        id = 50,
                        name = "socks",
                        type = "socks5",
                        originalServer = "198.51.100.50",
                        originalPort = 1080,
                        configJson = """{"username":"user","password":"pass"}""",
                    ),
                ),
                runtimeContext = null,
                connectionMode = ProxyConnectionMode.DIRECT,
            ).configJson,
        ).asJsonObject
        val outbound = config.getAsJsonArray("outbounds")
            .map { it.asJsonObject }
            .first { it.get("tag")?.asString == "proxy-50" }

        assertEquals("socks", outbound.get("type").asString)
        assertEquals("5", outbound.get("version").asString)
        assertEquals("user", outbound.get("username").asString)
        assertEquals("pass", outbound.get("password").asString)
    }

    @Test
    fun `country DIRECT rule emits geoip-cn direct rule and rule_set definition`() {
        val builder = SingboxConfigBuilder(settings = baseSettings())
        val snapshot = engine().compile(
            globalDefault = UserTrafficOutcome.TUNNEL,
            globalDefaultNodeId = null,
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
            globalDefaultNodeId = null,
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
            globalDefaultNodeId = null,
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

    @Test
    fun `category rule pinned to a node routes to that node tag`() {
        val builder = SingboxConfigBuilder(settings = baseSettings())
        val snapshot = engine().compile(
            globalDefault = UserTrafficOutcome.TUNNEL,
            globalDefaultNodeId = 1L,
            countryRules = emptyList(),
            categoryRules = listOf(
                RoutingRule(
                    "video",
                    RoutingRuleKind.WEBSITE_CATEGORY,
                    "video",
                    UserTrafficOutcome.TUNNEL,
                    targetNodeId = 2L,
                ),
            ),
            rulePackVersions = emptyList(),
            compiledAtMs = 0L,
        )
        val config = JsonParser.parseString(
            builder.build(
                proxyNodes = listOf(ssNode(1), ssNode(2)),
                runtimeContext = null,
                routingPolicySnapshot = snapshot,
                connectionMode = ProxyConnectionMode.DIRECT,
            ).configJson
        ).asJsonObject

        val outbounds = config.getAsJsonArray("outbounds").map { it.asJsonObject.get("tag").asString }
        assertTrue(outbounds.contains("proxy-1"))
        assertTrue(outbounds.contains("proxy-2"))

        val routeRules = config.getAsJsonObject("route").getAsJsonArray("rules")
        val videoRule = routeRules.first { r ->
            r.asJsonObject.has("rule_set") &&
                r.asJsonObject.getAsJsonArray("rule_set").any { it.asString == "geosite-youtube" }
        }.asJsonObject
        assertEquals("proxy-2", videoRule.get("outbound").asString)
        assertEquals("proxy-1", config.getAsJsonObject("route").get("final").asString)
    }

    @Test
    fun `slave and lan ips are direct and precede any node-targeted user rule`() {
        val builder = SingboxConfigBuilder(settings = baseSettings())
        val snapshot = engine().compile(
            UserTrafficOutcome.TUNNEL,
            2L,
            emptyList(),
            listOf(
                RoutingRule(
                    "video",
                    RoutingRuleKind.WEBSITE_CATEGORY,
                    "video",
                    UserTrafficOutcome.TUNNEL,
                    targetNodeId = 2L,
                ),
            ),
            emptyList(),
            0L,
        )
        val runtime = RuntimeContext(
            forwardId = 1L,
            mode = ForwardMode.BOND,
            localListenPort = 12000,
            localLegPorts = emptyList(),
            processPlans = emptyList(),
            bypassHosts = linkedSetOf("38.207.137.131"),
        )
        val config = JsonParser.parseString(
            builder.build(listOf(ssNode(2)), runtime, snapshot, ProxyConnectionMode.DIRECT).configJson
        ).asJsonObject
        val rules = config.getAsJsonObject("route").getAsJsonArray("rules")

        fun indexOfFirst(pred: (com.google.gson.JsonObject) -> Boolean) =
            rules.indexOfFirst { pred(it.asJsonObject) }

        val slaveIdx = indexOfFirst {
            it.has("ip_cidr") &&
                it.getAsJsonArray("ip_cidr").any { cidr -> cidr.asString.startsWith("38.207.137.131") } &&
                it.get("outbound")?.asString == "direct"
        }
        val lanIdx = indexOfFirst {
            it.has("ip_cidr") &&
                it.getAsJsonArray("ip_cidr").any { cidr -> cidr.asString == "192.168.0.0/16" } &&
                it.get("outbound")?.asString == "direct"
        }
        val nodeRuleIdx = indexOfFirst { it.get("outbound")?.asString == "proxy-2" }

        assertTrue("slave bypass present", slaveIdx >= 0)
        assertTrue("lan bypass present", lanIdx >= 0)
        assertTrue("node rule present", nodeRuleIdx >= 0)
        assertTrue("slave bypass precedes node rule", slaveIdx < nodeRuleIdx)
        assertTrue("lan bypass precedes node rule", lanIdx < nodeRuleIdx)
    }

    @Test
    fun `protected lan direct rule survives when bypassLan toggle is off`() {
        val builder = SingboxConfigBuilder(settings = baseSettings().copy(bypassLan = false))
        val snapshot = engine().compile(
            UserTrafficOutcome.TUNNEL,
            2L,
            emptyList(),
            listOf(
                RoutingRule(
                    "video",
                    RoutingRuleKind.WEBSITE_CATEGORY,
                    "video",
                    UserTrafficOutcome.TUNNEL,
                    targetNodeId = 2L,
                ),
            ),
            emptyList(),
            0L,
        )
        val config = JsonParser.parseString(
            builder.build(listOf(ssNode(2)), null, snapshot, ProxyConnectionMode.DIRECT).configJson
        ).asJsonObject
        val rules = config.getAsJsonObject("route").getAsJsonArray("rules")

        val lanIdx = rules.indexOfFirst {
            val obj = it.asJsonObject
            obj.has("ip_cidr") &&
                obj.getAsJsonArray("ip_cidr").any { cidr -> cidr.asString == "192.168.0.0/16" } &&
                obj.get("outbound")?.asString == "direct"
        }
        val nodeRuleIdx = rules.indexOfFirst { it.asJsonObject.get("outbound")?.asString == "proxy-2" }

        assertTrue("protected LAN bypass present", lanIdx >= 0)
        assertTrue("node rule present", nodeRuleIdx >= 0)
        assertTrue("protected LAN bypass precedes node rule", lanIdx < nodeRuleIdx)
    }

    @Test
    fun `services mode rewrites each node outbound to its own relay leg port`() {
        val builder = SingboxConfigBuilder(settings = baseSettings())
        val runtime = RuntimeContext(
            forwardId = 26L,
            mode = ForwardMode.BOND,
            localListenPort = 11024,
            localLegPorts = emptyList(),
            processPlans = emptyList(),
            bypassHosts = emptySet(),
            nodeLegs = mapOf(
                1L to NodeLeg(
                    nodeId = 1L,
                    forwardId = 26L,
                    processKey = 26001L,
                    mode = ForwardMode.BOND,
                    localListenPort = 12001,
                    localLegPorts = emptyList(),
                    processPlans = emptyList(),
                    bypassHosts = emptySet(),
                ),
                2L to NodeLeg(
                    nodeId = 2L,
                    forwardId = 26L,
                    processKey = 26002L,
                    mode = ForwardMode.BOND,
                    localListenPort = 12002,
                    localLegPorts = emptyList(),
                    processPlans = emptyList(),
                    bypassHosts = emptySet(),
                ),
            ),
        )

        val config = JsonParser.parseString(
            builder.build(
                proxyNodes = listOf(ssNode(1), ssNode(2)),
                runtimeContext = runtime,
                routingPolicySnapshot = null,
                connectionMode = ProxyConnectionMode.SERVICES,
            ).configJson
        ).asJsonObject
        val outbounds = config.getAsJsonArray("outbounds").map { it.asJsonObject }
        val node1 = outbounds.first { it.get("tag").asString == "proxy-1" }
        val node2 = outbounds.first { it.get("tag").asString == "proxy-2" }

        assertEquals("127.0.0.1", node1.get("server").asString)
        assertEquals(12001, node1.get("server_port").asInt)
        assertEquals("127.0.0.1", node2.get("server").asString)
        assertEquals(12002, node2.get("server_port").asInt)
    }

    @Test
    fun `bypassChina uses active rule pack refs when provided`() {
        val manifest = RulePackManifest.parse(
            """
                {
                  "version": "2026.05.31",
                  "geosite": [
                    {
                      "key": "custom",
                      "labelEn": "Custom",
                      "labelZh": "自定义",
                      "defaultDirect": false,
                      "tags": ["geosite-custom"]
                    }
                  ],
                  "geoip": {
                    "version": "2026.05.31",
                    "cnTag": "geoip-cn",
                    "refs": [
                      {"tag": "geoip-cn", "url": "https://cdn.example/geoip-cn.srs"},
                      {"tag": "geosite-cn", "url": "https://cdn.example/geosite-cn.srs"}
                    ]
                  }
                }
            """.trimIndent()
        )
        val builder = SingboxConfigBuilder(
            settings = baseSettings().copy(bypassChina = true),
            rulePackManifest = manifest,
        )

        val route = JsonParser.parseString(
            builder.build(listOf(ssNode(1)), null, null, ProxyConnectionMode.DIRECT).configJson
        ).asJsonObject.getAsJsonObject("route")

        val refs = route.getAsJsonArray("rule_set").map { it.asJsonObject }
        assertEquals(
            "https://cdn.example/geoip-cn.srs",
            refs.first { it.get("tag").asString == "geoip-cn" }.get("url").asString,
        )
        assertEquals(
            "https://cdn.example/geosite-cn.srs",
            refs.first { it.get("tag").asString == "geosite-cn" }.get("url").asString,
        )
    }

    @Test
    fun `client rules are exact ip port rules after protected paths and before country rules`() {
        val builder = SingboxConfigBuilder(settings = baseSettings())
        val runtimeContext = RuntimeContext(
            forwardId = 65L,
            mode = ForwardMode.SINGLE,
            localListenPort = 11065,
            localLegPorts = emptyList(),
            processPlans = emptyList(),
            bypassHosts = linkedSetOf("198.51.100.10"),
        )
        val routeRuleSnapshot = ClientRouteRuleSnapshot(
            rules = listOf(
                ClientRouteRule(
                    id = "rule_active",
                    serviceScopeId = "svc_scope_7",
                    destinationIp = "93.184.216.34",
                    destinationPort = 443,
                    directionId = "dir_sh_jp_1",
                    enabled = true,
                    effectiveState = ClientRuleEffectiveState.ACTIVE,
                    syncVersion = 1L,
                    updatedAtMs = 1L,
                ),
                ClientRouteRule(
                    id = "rule_missing",
                    serviceScopeId = "svc_scope_7",
                    destinationIp = "203.0.113.9",
                    destinationPort = 8443,
                    directionId = "dir_sh_hk_1",
                    enabled = true,
                    effectiveState = ClientRuleEffectiveState.RUNTIME_MISSING,
                    syncVersion = 1L,
                    updatedAtMs = 1L,
                ),
                ClientRouteRule(
                    id = "rule_disabled",
                    serviceScopeId = "svc_scope_7",
                    destinationIp = "203.0.113.10",
                    destinationPort = 9443,
                    directionId = "dir_sh_jp_1",
                    enabled = false,
                    effectiveState = ClientRuleEffectiveState.ACTIVE,
                    syncVersion = 1L,
                    updatedAtMs = 1L,
                ),
                ClientRouteRule(
                    id = "rule_deleted",
                    serviceScopeId = "svc_scope_7",
                    destinationIp = "203.0.113.11",
                    destinationPort = 10443,
                    directionId = "dir_sh_jp_1",
                    enabled = true,
                    effectiveState = ClientRuleEffectiveState.ACTIVE,
                    syncVersion = 1L,
                    deletedAtMs = 2L,
                    updatedAtMs = 1L,
                ),
            ),
        )
        val routeRuleBindings = listOf(
            ClientRuleSingboxBinding(
                directionId = "dir_sh_jp_1",
                tcpOutboundTag = "route-direction-dir_sh_jp_1-tcp",
                udpOutboundTag = "route-direction-dir_sh_jp_1-udp",
                localTcpPort = 12001,
                localUdpPort = 12002,
            )
        )
        val policySnapshot = RoutingPolicyEngine(
            SafePathRegistry.androidBaseline("https://panel.example.com", "v3-mirror.leikwanhost.com"),
            RoutingCategoryCatalog.default(),
        ).compile(
            globalDefault = UserTrafficOutcome.TUNNEL,
            globalDefaultNodeId = null,
            countryRules = listOf(RoutingRule("country-cn", RoutingRuleKind.COUNTRY, "CN", UserTrafficOutcome.DIRECT)),
            categoryRules = emptyList(),
            rulePackVersions = emptyList(),
            compiledAtMs = 1L,
        )

        val config = JsonParser.parseString(
            builder.build(
                proxyNodes = emptyList(),
                runtimeContext = runtimeContext,
                routingPolicySnapshot = policySnapshot,
                connectionMode = ProxyConnectionMode.SERVICES,
                routeRuleSnapshot = routeRuleSnapshot,
                routeRuleRuntimeBindings = routeRuleBindings,
            ).configJson
        ).asJsonObject
        val outbounds = config.getAsJsonArray("outbounds").map { it.asJsonObject }
        val rules = config.getAsJsonObject("route").getAsJsonArray("rules").map { it.asJsonObject }

        val tcpOutbound = outbounds.first { it.get("tag").asString == "route-direction-dir_sh_jp_1-tcp" }
        assertEquals("direct", tcpOutbound.get("type").asString)
        assertFalse(tcpOutbound.has("override_address"))
        assertFalse(tcpOutbound.has("override_port"))
        val udpOutbound = outbounds.first { it.get("tag").asString == "route-direction-dir_sh_jp_1-udp" }
        assertEquals("direct", udpOutbound.get("type").asString)
        assertFalse(udpOutbound.has("override_address"))
        assertFalse(udpOutbound.has("override_port"))

        val activeTcpIndex = rules.indexOfFirst { rule ->
                rule.get("network")?.asString == "tcp" &&
                rule.get("outbound")?.asString == "route-direction-dir_sh_jp_1-tcp" &&
                rule.getAsJsonArray("ip_cidr")?.any { it.asString == "93.184.216.34/32" } == true &&
                rule.getAsJsonArray("port")?.any { it.asInt == 443 } == true
        }
        val activeUdpIndex = rules.indexOfFirst { rule ->
                rule.get("network")?.asString == "udp" &&
                rule.get("outbound")?.asString == "route-direction-dir_sh_jp_1-udp" &&
                rule.getAsJsonArray("ip_cidr")?.any { it.asString == "93.184.216.34/32" } == true &&
                rule.getAsJsonArray("port")?.any { it.asInt == 443 } == true
        }
        val rejectTcpIndex = rules.indexOfFirst { rule ->
            rule.get("network")?.asString == "tcp" &&
                rule.get("action")?.asString == "reject" &&
                rule.getAsJsonArray("ip_cidr")?.any { it.asString == "203.0.113.9/32" } == true &&
                rule.getAsJsonArray("port")?.any { it.asInt == 8443 } == true
        }
        val rejectUdpIndex = rules.indexOfFirst { rule ->
            rule.get("network")?.asString == "udp" &&
                rule.get("action")?.asString == "reject" &&
                rule.getAsJsonArray("ip_cidr")?.any { it.asString == "203.0.113.9/32" } == true &&
                rule.getAsJsonArray("port")?.any { it.asInt == 8443 } == true
        }
        val countryRuleIndex = rules.indexOfFirst { rule ->
            rule.getAsJsonArray("rule_set")?.any { it.asString == "geoip-cn" } == true
        }
        val runtimeBypassIndex = rules.indexOfFirst { rule ->
            rule.getAsJsonArray("ip_cidr")?.any { it.asString == "198.51.100.10/32" } == true
        }

        assertTrue(runtimeBypassIndex >= 0)
        assertTrue(activeTcpIndex > runtimeBypassIndex)
        assertTrue(activeUdpIndex > runtimeBypassIndex)
        assertEquals("127.0.0.1", rules[activeTcpIndex].get("override_address").asString)
        assertEquals(12001, rules[activeTcpIndex].get("override_port").asInt)
        assertEquals("127.0.0.1", rules[activeUdpIndex].get("override_address").asString)
        assertEquals(12002, rules[activeUdpIndex].get("override_port").asInt)
        assertTrue(rejectTcpIndex > runtimeBypassIndex)
        assertTrue(rejectUdpIndex > runtimeBypassIndex)
        assertTrue(countryRuleIndex > activeTcpIndex)
        assertTrue(countryRuleIndex > activeUdpIndex)
        assertTrue(countryRuleIndex > rejectTcpIndex)
        assertTrue(countryRuleIndex > rejectUdpIndex)
        assertFalse(rules.any { rule ->
            rule.getAsJsonArray("ip_cidr")?.any { it.asString == "203.0.113.10/32" } == true
        })
        assertFalse(rules.any { rule ->
            rule.getAsJsonArray("ip_cidr")?.any { it.asString == "203.0.113.11/32" } == true
        })
    }

    @Test
    fun `wireguard node emits endpoint form and category rule can target it`() {
        val builder = SingboxConfigBuilder(settings = baseSettings())
        val snapshot = engine().compile(
            globalDefault = UserTrafficOutcome.TUNNEL,
            globalDefaultNodeId = 9L,
            countryRules = emptyList(),
            categoryRules = listOf(
                RoutingRule(
                    id = "ai",
                    kind = RoutingRuleKind.WEBSITE_CATEGORY,
                    matchValue = "ai",
                    outcome = UserTrafficOutcome.TUNNEL,
                    targetNodeId = 9L,
                )
            ),
            rulePackVersions = emptyList(),
            compiledAtMs = 0L,
        )

        val config = JsonParser.parseString(
            builder.build(
                proxyNodes = listOf(wireGuardNode(9L)),
                runtimeContext = null,
                routingPolicySnapshot = snapshot,
                connectionMode = ProxyConnectionMode.DIRECT,
            ).configJson
        ).asJsonObject
        val endpoints = config.getAsJsonArray("endpoints").map { it.asJsonObject }
        val endpoint = endpoints.single { it.get("tag").asString == "proxy-9" }
        val peer = endpoint.getAsJsonArray("peers").single().asJsonObject
        val outbounds = config.getAsJsonArray("outbounds").map { it.asJsonObject }
        val aiRule = config.getAsJsonObject("route").getAsJsonArray("rules").map { it.asJsonObject }
            .first { it.has("rule_set") && it.getAsJsonArray("rule_set").any { tag -> tag.asString == "geosite-openai" } }

        assertEquals("wireguard", endpoint.get("type").asString)
        assertEquals(false, endpoint.get("system").asBoolean)
        assertEquals("private-key", endpoint.get("private_key").asString)
        assertEquals(listOf("10.7.0.2/32"), endpoint.getAsJsonArray("address").map { it.asString })
        assertEquals(1420, endpoint.get("mtu").asInt)
        assertEquals("public-key", peer.get("public_key").asString)
        assertEquals("wg.example.com", peer.get("address").asString)
        assertEquals(51820, peer.get("port").asInt)
        assertEquals(listOf("0.0.0.0/0", "::/0"), peer.getAsJsonArray("allowed_ips").map { it.asString })
        assertEquals("proxy-9", aiRule.get("outbound").asString)
        assertEquals("proxy-9", config.getAsJsonObject("route").get("final").asString)
        assertFalse(outbounds.any { it.get("tag")?.asString == "proxy-9" })
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

    private fun wireGuardNode(id: Long) = ProxyNode(
        id = id,
        name = "wg-$id",
        type = "wireguard",
        originalServer = "wg.example.com",
        originalPort = 51820,
        configJson = """
            {
              "private_key": "private-key",
              "address": ["10.7.0.2/32"],
              "mtu": 1420,
              "peers": [
                {
                  "public_key": "public-key",
                  "address": "wg.example.com",
                  "port": 51820,
                  "allowed_ips": ["0.0.0.0/0", "::/0"]
                }
              ]
            }
        """.trimIndent(),
        forwardId = null,
    )
}
