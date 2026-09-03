package com.leikwan.bridge.singbox

import com.google.gson.JsonParser
import com.leikwan.bridge.runtime.NativeOutboundCapability
import com.leikwan.bridge.runtime.NativeOutboundCapabilityDto
import com.leikwan.bridge.runtime.NativeOutboundCarrierDto
import com.leikwan.bridge.runtime.NativeOutboundLease
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test
import java.time.Instant

class NativeOutboundConfigBuilderTest {
    private val direction = "Hong Kong -> China Mainland"
    private val forwardId = 159L

    private fun lease(
        scope: String,
        leaseDirection: String = direction,
        leaseForwardId: Long = forwardId,
        routePolicy: String = "restricted",
    ): NativeOutboundLease {
        val now = Instant.now()
        return NativeOutboundLease(
            forwardId = leaseForwardId,
            leaseId = "lease-native-159",
            policyGeneration = "a".repeat(64),
            expiresAt = now.plusSeconds(120),
            transport = "tcp+udp",
            direction = leaseDirection,
            routeScope = scope,
            scopeDigest = if (routePolicy == "unrestricted") {
                NativeOutboundLease.scopeDigestV2(leaseForwardId, leaseDirection, "tcp+udp", scope, routePolicy)
            } else {
                NativeOutboundLease.scopeDigest(leaseForwardId, leaseDirection, "tcp+udp", scope)
            },
            endpointIp = "203.0.113.10",
            endpointPort = 8443,
            user = "native-user".toByteArray(),
            password = "native-password".toByteArray(),
            rateLimitMbps = 100,
            quotaRemainingBytes = 1_000_000,
            selectionRef = "native-selection-reference",
            selectionTier = "paid",
            routePolicy = routePolicy,
        )
    }

    private fun builder() = SingboxConfigBuilder(
        SingboxSettings(
            panelUrl = "https://panel.example.test",
            dnsServer = "1.1.1.1",
            dnsFallback = "8.8.8.8",
            dnsStrategy = "prefer_ipv4",
            bypassLan = true,
            bypassChina = false,
            customBypassRules = "",
        ),
    )

    @Test
    fun `capability is invisible when absent and accepts advertised carrier`() {
        assertEquals(null, NativeOutboundCapability.from(null))
        val carrier = NativeOutboundCarrierDto(
            forwardId = forwardId,
            direction = direction,
            scopeDigest = NativeOutboundLease.scopeDigest(forwardId, direction, "tcp+udp", "global"),
            transport = "tcp+udp",
            routeScopes = listOf("cn_only", "global"),
        )
        val capability = NativeOutboundCapability.from(
            NativeOutboundCapabilityDto(
                enabled = true,
                protocol = "socks5",
                leaseProtocol = "leikwan-ultra-native-socks5-v1",
                transport = "tcp+udp",
                forwardIds = listOf(forwardId),
                defaultForwardId = forwardId,
                routeScopes = listOf("cn_only", "global"),
                forwards = listOf(carrier),
            ),
        )
        assertTrue(capability?.isUsable() == true)
        assertEquals(listOf("cn_only", "global"), capability?.carrier(forwardId)?.routeScopes)
    }

    @Test
    fun `capability with an absent direction stays unavailable instead of guessing an old route`() {
        val capability = NativeOutboundCapability.from(
            NativeOutboundCapabilityDto(
                enabled = true,
                protocol = "socks5",
                leaseProtocol = "leikwan-ultra-native-socks5-v1",
                transport = "tcp+udp",
                forwardIds = listOf(180L),
                defaultForwardId = 180L,
                routeScopes = listOf("global"),
                defaultRouteScope = "global",
                forwards = listOf(
                    NativeOutboundCarrierDto(
                        forwardId = 180L,
                        direction = "",
                        scopeDigest = "a".repeat(64),
                        transport = "tcp+udp",
                        routeScopes = listOf("global"),
                        defaultRouteScope = "global",
                    ),
                ),
            ),
        )

        assertEquals(null, capability)
    }

    @Test
    fun `mainland to international capability accepts intl only scope and aliases`() {
        val intlDirection = "Mainland China -> International"
        val carrier = NativeOutboundCarrierDto(
            forwardId = forwardId,
            direction = intlDirection,
            scopeDigest = NativeOutboundLease.scopeDigest(forwardId, intlDirection, "tcp+udp", "global"),
            transport = "tcp+udp",
            routeScopes = listOf("intl_only", "global"),
            defaultRouteScope = "global",
        )
        val capability = NativeOutboundCapability.from(
            NativeOutboundCapabilityDto(
                enabled = true,
                protocol = "socks5",
                leaseProtocol = "leikwan-ultra-native-socks5-v1",
                transport = "tcp+udp",
                forwardIds = listOf(forwardId),
                defaultForwardId = forwardId,
                routeScopes = listOf("intl_only", "global"),
                defaultRouteScope = "global",
                forwards = listOf(carrier),
            ),
        )

        assertTrue(capability?.isUsable() == true)
        assertEquals(listOf("intl_only", "global"), capability?.carrier(forwardId)?.routeScopes)
        assertEquals("intl_only", NativeOutboundCapability.normalizeScope("international_only"))
    }

    @Test
    fun `cn only sends mainland traffic to native and leaves final direct`() {
        val lease = lease("cn_only")
        val root = JsonParser.parseString(builder().build(
            proxyNodes = emptyList(),
            runtimeContext = null,
            nativeOutboundLease = lease,
            nativeRouteScope = "cn_only",
        ).configJson).asJsonObject
        val dnsServers = root.getAsJsonObject("dns").getAsJsonArray("servers")
            .map { it.asJsonObject }
            .associateBy { it.get("tag").asString }
        assertEquals("https", dnsServers.getValue("remote").get("type").asString)
        assertEquals("1.1.1.1", dnsServers.getValue("remote").get("server").asString)
        assertEquals("cloudflare-dns.com", dnsServers.getValue("remote")
            .getAsJsonObject("tls").get("server_name").asString)
        assertFalse(dnsServers.getValue("remote").has("detour"))
        assertEquals("https", dnsServers.getValue("china").get("type").asString)
        assertEquals("223.5.5.5", dnsServers.getValue("china").get("server").asString)
        assertEquals("dns.alidns.com", dnsServers.getValue("china")
            .getAsJsonObject("tls").get("server_name").asString)
        assertEquals("proxy-native", dnsServers.getValue("china").get("detour").asString)
        assertEquals("local", dnsServers.getValue("local").get("type").asString)
        assertEquals("remote", root.getAsJsonObject("dns").get("final").asString)
        assertEquals("remote", root.getAsJsonObject("route").get("default_domain_resolver").asString)
        assertEquals("direct", root.getAsJsonObject("route").get("final").asString)
        val outbound = root.getAsJsonArray("outbounds")
            .first { it.asJsonObject.get("tag").asString == "proxy-native" }
            .asJsonObject
        assertFalse(outbound.has("network"))
        assertTrue(root.toString().contains("\"outbound\":\"proxy-native\""))
        assertTrue(root.toString().contains("geoip-cn"))
        assertTrue(lease.user.all { it.toInt() == 0 })
        assertTrue(lease.password.all { it.toInt() == 0 })
    }

    @Test
    fun `global can bypass mainland while native remains the default`() {
        val lease = lease("global")
        val json = builder().build(
            proxyNodes = emptyList(),
            runtimeContext = null,
            nativeOutboundLease = lease,
            nativeRouteScope = "global",
            nativeBypassChina = true,
        ).configJson
        val route = JsonParser.parseString(json).asJsonObject.getAsJsonObject("route")
        assertEquals("proxy-native", route.get("final").asString)
        assertTrue(route.toString().contains("geoip-cn"))
        assertTrue(route.toString().contains("\"outbound\":\"direct\""))
        assertFalse(route.toString().contains("\"final\":\"direct\""))
    }

    @Test
    fun `intl only sends mainland traffic direct and overseas traffic native`() {
        val intlDirection = "Mainland China -> International"
        val lease = lease("intl_only", leaseDirection = intlDirection)
        val root = JsonParser.parseString(
            builder().build(
                proxyNodes = emptyList(),
                runtimeContext = null,
                nativeOutboundLease = lease,
                nativeRouteScope = "intl_only",
            ).configJson,
        ).asJsonObject
        val dnsServers = root.getAsJsonObject("dns").getAsJsonArray("servers")
            .map { it.asJsonObject }
            .associateBy { it.get("tag").asString }
        assertEquals("https", dnsServers.getValue("remote").get("type").asString)
        assertEquals("proxy-native", dnsServers.getValue("remote").get("detour").asString)
        assertEquals("local", dnsServers.getValue("local").get("type").asString)
        assertEquals("local", root.getAsJsonObject("dns").getAsJsonArray("rules")
            .first { it.asJsonObject.getAsJsonArray("rule_set")?.any { tag -> tag.asString == "geosite-cn" } == true }
            .asJsonObject.get("server").asString)
        assertEquals("remote", root.getAsJsonObject("route").get("default_domain_resolver").asString)
        val route = root.getAsJsonObject("route")
        assertEquals("proxy-native", route.get("final").asString)
        val mainlandRule = route.getAsJsonArray("rules").first { rule ->
            rule.asJsonObject.getAsJsonArray("rule_set")?.any { it.asString == "geoip-cn" } == true
        }.asJsonObject
        assertEquals("direct", mainlandRule.get("outbound").asString)
        assertTrue(route.getAsJsonArray("rule_set").any { it.asJsonObject.get("tag").asString == "geoip-cn" })
        assertTrue(route.getAsJsonArray("rule_set").any { it.asJsonObject.get("tag").asString == "geosite-cn" })
        assertTrue(
            route.getAsJsonArray("rule_set").all {
                it.asJsonObject.get("download_detour").asString == "proxy-native"
            },
        )
    }

    @Test
    fun `restricted native route resolves before scope match and rejects tun gateway`() {
        val lease = lease("cn_only")
        val route = JsonParser.parseString(
            builder().build(
                proxyNodes = emptyList(),
                runtimeContext = null,
                nativeOutboundLease = lease,
                nativeRouteScope = "cn_only",
            ).configJson,
        ).asJsonObject.getAsJsonObject("route")
        val rules = route.getAsJsonArray("rules").map { it.asJsonObject }
        val resolveIndex = rules.indexOfFirst { it.get("action")?.asString == "resolve" }
        val scopeIndex = rules.indexOfFirst {
            it.getAsJsonArray("rule_set")?.any { tag -> tag.asString == "geoip-cn" } == true
        }
        val gatewayIndex = rules.indexOfFirst {
            it.get("action")?.asString == "reject" &&
                it.getAsJsonArray("ip_cidr")?.any { cidr -> cidr.asString == "172.19.0.2/32" } == true
        }
        assertTrue("resolve action is present", resolveIndex >= 0)
        assertEquals("ipv4_only", rules[resolveIndex].get("strategy").asString)
        assertTrue("resolve precedes native scope", resolveIndex < scopeIndex)
        assertTrue("TUN gateway guard is present", gatewayIndex >= 0)
        assertEquals("drop", rules[gatewayIndex].get("method").asString)
        assertTrue("gateway guard precedes scope", gatewayIndex < scopeIndex)
    }

    @Test
    fun `restricted native route gates low ports before scoped routing`() {
        val lease = lease("cn_only")
        val route = JsonParser.parseString(
            builder().build(
                proxyNodes = emptyList(),
                runtimeContext = null,
                nativeOutboundLease = lease,
                nativeRouteScope = "cn_only",
            ).configJson,
        ).asJsonObject.getAsJsonObject("route")
        val rules = route.getAsJsonArray("rules").map { it.asJsonObject }
        val lowPortIndex = rules.indexOfFirst { rule ->
            rule.get("network")?.isJsonArray == true
                && rule.getAsJsonArray("network").map { it.asString } == listOf("tcp", "udp")
                && rule.get("port_range")?.asString == "1:999"
                && rule.get("outbound")?.asString == "direct"
        }
        val scopedIndex = rules.indexOfFirst { rule ->
            rule.getAsJsonArray("rule_set")?.any { it.asString == "geoip-cn" } == true
        }
        assertTrue("restricted low-port direct rule is present", lowPortIndex >= 0)
        assertEquals("1:999", rules[lowPortIndex].get("port_range").asString)
        assertTrue("low-port gate precedes scoped native rule", lowPortIndex < scopedIndex)
        assertEquals("1000:65535", rules[scopedIndex].get("port_range").asString)
    }

    @Test
    fun `unrestricted native route omits low-port gate and uses all ports`() {
        val lease = lease("intl_only", leaseDirection = "Mainland China -> International", routePolicy = "unrestricted")
        val route = JsonParser.parseString(
            builder().build(
                proxyNodes = emptyList(),
                runtimeContext = null,
                nativeOutboundLease = lease,
                nativeRouteScope = "intl_only",
                nativeRoutePolicy = "unrestricted",
            ).configJson,
        ).asJsonObject.getAsJsonObject("route")
        val rules = route.getAsJsonArray("rules").map { it.asJsonObject }
        assertTrue(rules.none { it.get("port_range")?.asString == "1:999" })
        val scoped = rules.first { it.getAsJsonArray("rule_set")?.any { tag -> tag.asString == "geoip-cn" } == true }
        assertEquals("1:65535", scoped.get("port_range").asString)
    }

    @Test
    fun `cn only keeps custom bypass domains local without overriding protected doh`() {
        val lease = lease("cn_only")
        val customBuilder = SingboxConfigBuilder(
            settings = SingboxSettings(
                panelUrl = "https://panel.example.test",
                dnsServer = "9.9.9.9",
                dnsFallback = "8.8.8.8",
                dnsStrategy = "prefer_ipv4",
                bypassLan = true,
                bypassChina = false,
                customBypassRules = "example.cn\n203.0.113.9",
            ),
        )
        val root = JsonParser.parseString(
            customBuilder.build(
                proxyNodes = emptyList(),
                runtimeContext = null,
                nativeOutboundLease = lease,
                nativeRouteScope = "cn_only",
            ).configJson,
        ).asJsonObject
        val dns = root.getAsJsonObject("dns")
        val servers = dns.getAsJsonArray("servers").map { it.asJsonObject }
            .associateBy { it.get("tag").asString }
        assertEquals("1.1.1.1", servers.getValue("remote").get("server").asString)
        assertEquals("223.5.5.5", servers.getValue("china").get("server").asString)
        val localRule = dns.getAsJsonArray("rules").first { rule ->
            rule.asJsonObject.getAsJsonArray("domain")?.any { it.asString == "example.cn" } == true
        }.asJsonObject
        assertEquals("local", localRule.get("server").asString)
    }

    @Test
    fun `native cn only ignores a stale ordinary bypass setting for dns`() {
        val lease = lease("cn_only")
        val json = SingboxConfigBuilder(
            settings = SingboxSettings(
                panelUrl = "https://panel.example.test",
                dnsServer = "1.1.1.1",
                dnsFallback = "8.8.8.8",
                dnsStrategy = "prefer_ipv4",
                bypassLan = true,
                bypassChina = true,
                customBypassRules = "",
            ),
        ).build(
            proxyNodes = emptyList(),
            runtimeContext = null,
            nativeOutboundLease = lease,
            nativeRouteScope = "cn_only",
        ).configJson

        val dnsRules = JsonParser.parseString(json)
            .asJsonObject
            .getAsJsonObject("dns")
            .getAsJsonArray("rules")
        val protectedChinaRules = dnsRules.filter { rule ->
            rule.asJsonObject.getAsJsonArray("rule_set")?.any { tag -> tag.asString == "geosite-cn" } == true
        }
        assertEquals(1, protectedChinaRules.size)
        assertEquals("china", protectedChinaRules.single().asJsonObject.get("server").asString)
        assertFalse(dnsRules.any { rule -> rule.asJsonObject.get("server")?.asString == "direct-dns" })
    }

    @Test
    fun `native local adapter config contains only loopback endpoint`() {
        val json = builder().build(
            proxyNodes = emptyList(),
            runtimeContext = null,
            nativeLocalProxy = NativeOutboundLocalProxy(port = 31245),
            nativeRouteScope = "global",
        ).configJson
        val outbound = JsonParser.parseString(json).asJsonObject
            .getAsJsonArray("outbounds")
            .first { it.asJsonObject.get("tag").asString == "proxy-native" }
            .asJsonObject
        assertEquals("127.0.0.1", outbound.get("server").asString)
        assertEquals(31245, outbound.get("server_port").asInt)
        assertFalse(outbound.has("username"))
        assertFalse(outbound.has("password"))
    }

    @Test
    fun `native local adapter excludes provider endpoints from tun routes`() {
        val root = JsonParser.parseString(
            builder().build(
                proxyNodes = emptyList(),
                runtimeContext = null,
                nativeLocalProxy = NativeOutboundLocalProxy(port = 31245),
                nativeProtectedEndpoints = setOf("203.0.113.10", "2001:db8::10"),
                nativeRouteScope = "cn_only",
                enableIpv6Tun = true,
            ).configJson,
        ).asJsonObject

        val excludes = root.getAsJsonArray("inbounds")
            .single()
            .asJsonObject
            .getAsJsonArray("route_exclude_address")
            .map { it.asString }
            .toSet()
        assertEquals(setOf("203.0.113.10/32", "2001:db8:0:0:0:0:0:10/128"), excludes)
    }

    @Test
    fun `native local adapter rejects non loopback hosts`() {
        assertTrue(runCatching { NativeOutboundLocalProxy(port = 31245) }.isSuccess)
        assertTrue(runCatching { NativeOutboundLocalProxy(host = "::1", port = 31245) }.isSuccess)
        assertFalse(runCatching {
            NativeOutboundLocalProxy(host = "203.0.113.10", port = 31245)
        }.isSuccess)
    }
}
