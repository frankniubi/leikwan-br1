package com.leikwan.bridge.singbox

import com.google.gson.Gson
import com.google.gson.JsonArray
import com.google.gson.JsonObject
import com.leikwan.bridge.data.Prefs
import com.leikwan.bridge.data.ProxyNode
import com.leikwan.bridge.data.routing.RulePackManifest
import com.leikwan.bridge.routing.CompiledConfigResult
import com.leikwan.bridge.routing.RoutingCategoryCatalog
import com.leikwan.bridge.routing.RoutingPolicyEngine
import com.leikwan.bridge.routing.RoutingPolicySnapshot
import com.leikwan.bridge.routing.SafePathRegistry
import com.leikwan.bridge.routing.UserTrafficOutcome
import com.leikwan.bridge.runtime.RuntimeContext
import com.leikwan.bridge.service.ProxyConnectionMode
import java.net.URI

data class SingboxSettings(
    val panelUrl: String,
    val dnsServer: String,
    val dnsFallback: String,
    val dnsStrategy: String,
    val bypassLan: Boolean,
    val bypassChina: Boolean,
    val customBypassRules: String,
) {
    companion object {
        fun fromPrefs(prefs: Prefs): SingboxSettings {
            return SingboxSettings(
                panelUrl = prefs.panelUrl,
                dnsServer = prefs.dnsServer,
                dnsFallback = prefs.dnsFallback,
                dnsStrategy = prefs.dnsStrategy,
                bypassLan = prefs.bypassLan,
                bypassChina = prefs.bypassChina,
                customBypassRules = prefs.customBypassRules,
            )
        }
    }
}

class SingboxConfigBuilder(
    val settings: SingboxSettings,
    private val safePathRegistry: SafePathRegistry =
        SafePathRegistry.androidBaseline(settings.panelUrl, "v3-mirror.leikwanhost.com"),
    private val rulePackManifest: RulePackManifest? = null,
    private val routingEngine: RoutingPolicyEngine =
        RoutingPolicyEngine(
            safePathRegistry,
            rulePackManifest?.let { RoutingCategoryCatalog.fromManifest(it) } ?: RoutingCategoryCatalog.default(),
        ),
) {
    private val gson = Gson()
    private val effectiveRoutingEngine =
        rulePackManifest?.let { routingEngine.withCategoryCatalog(RoutingCategoryCatalog.fromManifest(it)) }
            ?: routingEngine

    fun build(
        proxyNodes: List<ProxyNode>,
        runtimeContext: RuntimeContext?,
        routingPolicySnapshot: RoutingPolicySnapshot? = null,
        connectionMode: ProxyConnectionMode = ProxyConnectionMode.SERVICES,
        enableIpv6Tun: Boolean = false,
    ): CompiledConfigResult {
        val activeProxyNodes = activeProxyNodes(proxyNodes, runtimeContext, connectionMode)
        val finalProxyTag = when {
            activeProxyNodes.isEmpty() -> "proxy"
            activeProxyNodes.size == 1 -> "proxy-${activeProxyNodes.first().id}"
            else -> "proxy"
        }
        val root = JsonObject()
        root.add("log", JsonObject().apply {
            addProperty("level", "debug")
        })
        val dnsProxyDetourTag = finalProxyTag.takeIf { activeProxyNodes.isNotEmpty() }
        val dnsBootstrapDomains = if (connectionMode == ProxyConnectionMode.DIRECT || activeProxyNodes.any { it.type.equals("wireguard", ignoreCase = true) }) {
            activeProxyNodes
                .filter { connectionMode == ProxyConnectionMode.DIRECT || it.type.equals("wireguard", ignoreCase = true) }
                .mapNotNull { it.originalServer.trim().lowercase().takeIf { host -> host.isNotEmpty() && !isIpLike(host) } }
                .toSet()
        } else {
            emptySet()
        }
        root.add("dns", buildDns(dnsProxyDetourTag, dnsBootstrapDomains, enableIpv6Tun))
        root.add("inbounds", buildInbounds(enableIpv6Tun))
        root.add("outbounds", buildOutbounds(proxyNodes, runtimeContext, connectionMode))
        buildEndpoints(activeProxyNodes).takeIf { it.size() > 0 }?.let { root.add("endpoints", it) }
        root.add("route", buildRoute(runtimeContext?.allBypassHosts() ?: emptySet(), finalProxyTag, routingPolicySnapshot))
        return CompiledConfigResult(
            configJson = gson.toJson(root),
            finalProxyTag = finalProxyTag,
            warnings = routingPolicySnapshot?.warnings ?: emptyList(),
        )
    }

    private fun buildDns(
        proxyDetourTag: String?,
        bootstrapDomains: Set<String>,
        enableIpv6Tun: Boolean,
    ): JsonObject {
        val dns = JsonObject()
        val servers = JsonArray()
        servers.add(JsonObject().apply {
            addProperty("tag", "remote")
            addProperty("type", if (proxyDetourTag != null) "tcp" else "udp")
            addProperty("server", settings.dnsServer)
            if (proxyDetourTag != null) {
                addProperty("detour", proxyDetourTag)
            }
        })
        servers.add(JsonObject().apply {
            addProperty("tag", "direct-dns")
            addProperty("type", "udp")
            addProperty("server", settings.dnsFallback)
        })
        dns.add("servers", servers)

        val rules = JsonArray()
        rules.add(JsonObject().apply {
            add("domain", JsonArray().apply {
                (panelDirectDomains() + bootstrapDomains).forEach { add(it) }
            })
            addProperty("server", "direct-dns")
        })
        if (settings.bypassChina) {
            rules.add(JsonObject().apply {
                add("rule_set", JsonArray().apply { add("geosite-cn") })
                addProperty("server", "direct-dns")
            })
        }
        dns.add("rules", rules)
        dns.addProperty("final", "remote")
        dns.addProperty("strategy", if (enableIpv6Tun) settings.dnsStrategy else "ipv4_only")
        return dns
    }

    private fun buildInbounds(enableIpv6Tun: Boolean): JsonArray {
        return JsonArray().apply {
            add(JsonObject().apply {
                addProperty("type", "tun")
                addProperty("tag", "tun-in")
                add("address", JsonArray().apply {
                    add("172.19.0.1/30")
                    if (enableIpv6Tun) {
                        add("fdfe:dcba:9876::1/126")
                    }
                })
                addProperty("auto_route", true)
                addProperty("strict_route", true)
                // gVisor userspace stack: required for an unprivileged Android VpnService.
                // The "mixed"/"system" TCP stacks need kernel socket redirection (NET_ADMIN)
                // the app does not have, so TCP connections were never accepted by the TUN
                // (UDP/DNS worked via gVisor, all TCP browsing timed out). See
                // docs/2026-05-31-android-direct-vpn-still-bad-handoff.md.
                addProperty("stack", "gvisor")
                addProperty("endpoint_independent_nat", true)
                addProperty("mtu", 9000)
            })
        }
    }

    private fun buildOutbounds(
        proxyNodes: List<ProxyNode>,
        runtimeContext: RuntimeContext?,
        connectionMode: ProxyConnectionMode,
    ): JsonArray {
        val outbounds = JsonArray()
        val proxyTags = mutableListOf<String>()

        activeProxyNodes(proxyNodes, runtimeContext, connectionMode)
            .forEach { node ->
                if (node.type.equals("wireguard", ignoreCase = true)) {
                    proxyTags += "proxy-${node.id}"
                    return@forEach
                }
                val outbound = buildProxyOutbound(node, runtimeContext, connectionMode) ?: return@forEach
                outbounds.add(outbound)
                proxyTags += "proxy-${node.id}"
            }

        if (proxyTags.size > 1) {
            outbounds.add(JsonObject().apply {
                addProperty("type", "selector")
                addProperty("tag", "proxy")
                add("outbounds", JsonArray().apply { proxyTags.forEach { add(it) } })
                addProperty("default", proxyTags.first())
            })
        } else if (proxyTags.isEmpty()) {
            outbounds.add(JsonObject().apply {
                addProperty("type", "direct")
                addProperty("tag", "proxy")
            })
        }

        outbounds.add(JsonObject().apply {
            addProperty("type", "direct")
            addProperty("tag", "direct")
        })
        return outbounds
    }

    private fun buildEndpoints(proxyNodes: List<ProxyNode>): JsonArray {
        val endpoints = JsonArray()
        proxyNodes
            .filter { it.type.equals("wireguard", ignoreCase = true) }
            .forEach { node ->
                buildWireGuardEndpoint(node)?.let { endpoints.add(it) }
            }
        return endpoints
    }

    private fun buildWireGuardEndpoint(node: ProxyNode): JsonObject? {
        val config = gson.fromJson(node.configJson, JsonObject::class.java) ?: return null
        val peers = config.getAsJsonArray("peers") ?: return null
        if (peers.size() == 0) return null

        return JsonObject().apply {
            addProperty("type", "wireguard")
            addProperty("tag", "proxy-${node.id}")
            // Bundled libbox is a sing-box devel build with WireGuard endpoint support
            // (Go build info: github.com/sagernet/sing-box (devel), with >=1.11 endpoint
            // deprecation strings); use endpoint form instead of deprecated wireguard outbound.
            addProperty("system", false)
            config.copyString("private_key", this)
            config.copyString("listen_port", this)
            config.copyInt("mtu", this)
            config.copyArray("address", this)
            add("peers", JsonArray().apply {
                peers.forEach { peerElement ->
                    val peer = peerElement.asJsonObject
                    val peerAddress = peer.stringOrNull("address") ?: node.originalServer
                    val peerPort = peer.intOrNull("port") ?: node.originalPort
                    add(JsonObject().apply {
                        peer.copyString("public_key", this)
                        peer.copyString("pre_shared_key", this)
                        addProperty("address", peerAddress)
                        addProperty("port", peerPort)
                        peer.copyArray("allowed_ips", this)
                        peer.copyInt("persistent_keepalive_interval", this)
                        peer.copyArray("reserved", this)
                    })
                }
            })
        }
    }

    private fun buildProxyOutbound(
        node: ProxyNode,
        runtimeContext: RuntimeContext?,
        connectionMode: ProxyConnectionMode,
    ): JsonObject? {
        val config = gson.fromJson(node.configJson, JsonObject::class.java)
        val useLocalRelay = connectionMode == ProxyConnectionMode.SERVICES
        val relayPort = runtimeContext?.relayListenPortForNode(node.id)
        val outboundServer = if (useLocalRelay) "127.0.0.1" else node.originalServer
        val outboundPort = if (useLocalRelay) {
            relayPort ?: return null
        } else {
            node.originalPort
        }

        return when (node.type.lowercase()) {
            "ss" -> JsonObject().apply {
                addProperty("type", "shadowsocks")
                addProperty("tag", "proxy-${node.id}")
                addProperty("server", outboundServer)
                addProperty("server_port", outboundPort)
                addProperty("method", config.get("method")?.asString ?: "")
                addProperty("password", config.get("password")?.asString ?: "")
                config.get("plugin")?.asString?.takeIf { it.isNotBlank() }?.let {
                    addProperty("plugin", it)
                }
                config.get("plugin_opts")?.asString?.takeIf { it.isNotBlank() }?.let {
                    addProperty("plugin_opts", it)
                }
                val network = config.get("network")?.asString?.takeIf { it.isNotBlank() }
                network?.let { addProperty("network", it) }
                when {
                    config.has("udp_over_tcp") && !config.get("udp_over_tcp").isJsonNull -> {
                        add("udp_over_tcp", config.get("udp_over_tcp").deepCopy())
                    }
                    config.has("multiplex") && !config.get("multiplex").isJsonNull -> {
                        add("multiplex", config.get("multiplex").deepCopy())
                    }
                    config.get("multiplex_enabled")?.asBoolean == true -> {
                        add("multiplex", JsonObject().apply {
                            addProperty("enabled", true)
                        })
                    }
                }
            }
            "vmess" -> JsonObject().apply {
                addProperty("type", "vmess")
                addProperty("tag", "proxy-${node.id}")
                addProperty("server", outboundServer)
                addProperty("server_port", outboundPort)
                addProperty("uuid", config.get("id")?.asString ?: "")
                addProperty("alter_id", config.get("aid")?.asInt ?: 0)
                addProperty("security", config.get("scy")?.asString ?: "auto")
                val net = config.get("net")?.asString ?: "tcp"
                if (net != "tcp") {
                    add("transport", JsonObject().apply {
                        addProperty("type", net)
                        if (net == "ws") {
                            addProperty("path", config.get("path")?.asString ?: "/")
                            add("headers", JsonObject().apply {
                                config.get("host")?.asString?.let { addProperty("Host", it) }
                            })
                        }
                    })
                }
            }
            "vless" -> JsonObject().apply {
                addProperty("type", "vless")
                addProperty("tag", "proxy-${node.id}")
                addProperty("server", outboundServer)
                addProperty("server_port", outboundPort)
                addProperty("uuid", config.get("uuid")?.asString ?: "")
                config.get("flow")?.asString?.let { addProperty("flow", it) }
                val tType = config.get("type")?.asString ?: "tcp"
                if (tType != "tcp" && tType != "none") {
                    add("transport", JsonObject().apply {
                        addProperty("type", tType)
                        if (tType == "ws") {
                            addProperty("path", config.get("path")?.asString ?: "/")
                            add("headers", JsonObject().apply {
                                config.get("host")?.asString?.let { addProperty("Host", it) }
                            })
                        }
                        if (tType == "grpc") {
                            addProperty("service_name", config.get("serviceName")?.asString ?: "")
                        }
                    })
                }
                val security = config.get("security")?.asString ?: ""
                if (security == "tls" || security == "reality") {
                    add("tls", JsonObject().apply {
                        addProperty("enabled", true)
                        config.get("sni")?.asString?.let { addProperty("server_name", it) }
                        if (security == "reality") {
                            add("reality", JsonObject().apply {
                                addProperty("enabled", true)
                                config.get("pbk")?.asString?.let { addProperty("public_key", it) }
                                config.get("sid")?.asString?.let { addProperty("short_id", it) }
                            })
                        }
                    })
                }
            }
            else -> null
        }
    }

    private fun buildRoute(
        runtimeBypassHosts: Set<String>,
        finalProxyTag: String,
        snapshot: RoutingPolicySnapshot?,
    ): JsonObject {
        val route = JsonObject()
        val rules = JsonArray()

        rules.add(JsonObject().apply {
            addProperty("action", "sniff")
        })

        rules.add(JsonObject().apply {
            addProperty("protocol", "dns")
            addProperty("action", "hijack-dns")
        })

        // (1) System-protected paths (non-overridable), one rule per registry entry, ordered before
        //     user rules. Replaces the legacy panelDirectDomains() route injection so there is a single
        //     source of truth and no duplicate panel/mirror rules.
        val protectedEntries = snapshot?.protectedPaths ?: safePathRegistry.protectedEntries()
        protectedEntries.forEach { entry ->
            if (entry.domains.isNotEmpty()) {
                rules.add(JsonObject().apply {
                    add("domain", JsonArray().apply { entry.domains.forEach { add(it) } })
                    addProperty("outbound", "direct")
                })
            }
            if (entry.ipCidrs.isNotEmpty()) {
                rules.add(JsonObject().apply {
                    add("ip_cidr", JsonArray().apply { entry.ipCidrs.forEach { add(normalizeIpCidr(it)) } })
                    addProperty("outbound", "direct")
                })
            }
        }

        // (2) Runtime slave/sidecar reachability (non-overridable), before user rules so a user TUNNEL
        //     rule can never capture the slave egress and create a loop.
        val bypassIps = JsonArray()
        val bypassDomains = JsonArray()
        runtimeBypassHosts.forEach { host ->
            if (isIpLike(host)) {
                bypassIps.add(normalizeIpCidr(host))
            } else if (host.isNotBlank()) {
                bypassDomains.add(host)
            }
        }
        if (bypassIps.size() > 0) {
            rules.add(JsonObject().apply {
                add("ip_cidr", bypassIps)
                addProperty("outbound", "direct")
            })
        }
        if (bypassDomains.size() > 0) {
            rules.add(JsonObject().apply {
                add("domain", bypassDomains)
                addProperty("outbound", "direct")
            })
        }

        // (3) User country/category rules (the 用户自定义分流 surface). Each compiled rule routes its
        //     rule_set(s) to "direct" (DIRECT) or the proxy tag (TUNNEL, sentinel swapped here).
        if (snapshot != null) {
            effectiveRoutingEngine.compileRouteRules(snapshot).forEach { compiled ->
                val outbound = when {
                    compiled.outbound == RoutingPolicyEngine.PROXY_OUTBOUND_SENTINEL -> finalProxyTag
                    compiled.outbound.startsWith("<NODE:") ->
                        "proxy-" + compiled.outbound.removePrefix("<NODE:").removeSuffix(">")
                    else -> compiled.outbound
                }
                rules.add(JsonObject().apply {
                    add("rule_set", JsonArray().apply { compiled.ruleSetTags.forEach { add(it) } })
                    addProperty("outbound", outbound)
                })
            }
        }

        // (4) bypassChina toggle (also expressible as a CN->DIRECT user rule; kept for back-compat).
        if (settings.bypassChina) {
            rules.add(JsonObject().apply {
                add("rule_set", JsonArray().apply {
                    add("geoip-cn")
                    add("geosite-cn")
                })
                addProperty("outbound", "direct")
            })
        }

        // (5) Custom bypass rules.
        val custom = settings.customBypassRules.trim()
        if (custom.isNotEmpty()) {
            val ips = JsonArray()
            val domains = JsonArray()
            custom.lines()
                .map { it.trim() }
                .filter { it.isNotEmpty() }
                .forEach { line ->
                    if (isIpLike(line)) {
                        ips.add(normalizeIpCidr(line))
                    } else {
                        domains.add(line)
                    }
                }
            if (ips.size() > 0) {
                rules.add(JsonObject().apply {
                    add("ip_cidr", ips)
                    addProperty("outbound", "direct")
                })
            }
            if (domains.size() > 0) {
                rules.add(JsonObject().apply {
                    add("domain", domains)
                    addProperty("outbound", "direct")
                })
            }
        }

        route.add("rules", rules)
        // route.final follows the user-settable global default: TUNNEL -> proxy tag; DIRECT -> direct.
        val defaultTunnelTag = snapshot?.globalDefaultNodeId?.let { "proxy-$it" } ?: finalProxyTag
        route.addProperty(
            "final",
            if (snapshot?.globalDefaultOutcome == UserTrafficOutcome.DIRECT) "direct" else defaultTunnelTag,
        )

        // route.rule_set: China sets (if toggle) + user-rule remote sets, de-duplicated by tag.
        val ruleSetArray = JsonArray()
        val seenTags = linkedSetOf<String>()
        if (settings.bypassChina) {
            if (seenTags.add("geoip-cn")) {
                ruleSetArray.add(remoteRuleSet("geoip-cn", chinaRuleSetUrl("geoip-cn")))
            }
            if (seenTags.add("geosite-cn")) {
                ruleSetArray.add(remoteRuleSet("geosite-cn", chinaRuleSetUrl("geosite-cn")))
            }
        }
        if (snapshot != null) {
            effectiveRoutingEngine.requiredRuleSets(snapshot).forEach { ref ->
                if (seenTags.add(ref.tag)) {
                    ruleSetArray.add(remoteRuleSet(ref.tag, ref.url))
                }
            }
        }
        if (ruleSetArray.size() > 0) {
            route.add("rule_set", ruleSetArray)
        }

        return route
    }

    private fun remoteRuleSet(tag: String, url: String): JsonObject = JsonObject().apply {
        addProperty("tag", tag)
        addProperty("type", "remote")
        addProperty("format", "binary")
        addProperty("url", url)
    }

    private fun chinaRuleSetUrl(tag: String): String =
        rulePackManifest
            ?.geoip
            ?.refs
            ?.firstOrNull { it.tag == tag }
            ?.url
            ?: when (tag) {
                "geoip-cn" -> "https://raw.githubusercontent.com/SagerNet/sing-geoip/rule-set/geoip-cn.srs"
                "geosite-cn" -> "https://raw.githubusercontent.com/SagerNet/sing-geosite/rule-set/geosite-cn.srs"
                else -> RoutingCategoryCatalog.geositeUrl(tag)
            }

    private fun panelDirectDomains(): Set<String> {
        val domains = linkedSetOf("v3-mirror.leikwanhost.com")
        val parsed = runCatching { URI(settings.panelUrl).host }.getOrNull()?.trim()?.lowercase()
        if (!parsed.isNullOrBlank()) {
            domains += parsed
        }
        return domains
    }

    private fun activeProxyNodes(
        proxyNodes: List<ProxyNode>,
        runtimeContext: RuntimeContext?,
        connectionMode: ProxyConnectionMode,
    ): List<ProxyNode> {
        return proxyNodes
            .filter { it.enabled }
            .filter {
                if (connectionMode != ProxyConnectionMode.SERVICES || runtimeContext == null) {
                    true
                } else if (runtimeContext.nodeLegs.isNotEmpty()) {
                    it.type.equals("wireguard", ignoreCase = true) || runtimeContext.nodeLegs.containsKey(it.id)
                } else {
                    it.type.equals("wireguard", ignoreCase = true) ||
                        it.forwardId == null ||
                        it.forwardId == runtimeContext.forwardId
                }
            }
    }

    private fun isIpLike(value: String): Boolean {
        return value.matches(Regex("^[0-9a-fA-F:.]+(?:/[0-9]+)?$"))
    }

    private fun normalizeIpCidr(value: String): String {
        if ("/" in value) return value
        return if (value.contains(':')) "$value/128" else "$value/32"
    }

    private fun JsonObject.copyString(name: String, target: JsonObject) {
        val value = get(name) ?: return
        if (!value.isJsonNull) {
            target.add(name, value.deepCopy())
        }
    }

    private fun JsonObject.copyInt(name: String, target: JsonObject) {
        val value = get(name) ?: return
        if (value.isJsonNull) return
        runCatching { value.asInt }
            .onSuccess { target.addProperty(name, it) }
    }

    private fun JsonObject.copyArray(name: String, target: JsonObject) {
        val value = get(name) ?: return
        if (value.isJsonArray) {
            target.add(name, value.deepCopy())
        }
    }

    private fun JsonObject.stringOrNull(name: String): String? {
        val value = get(name) ?: return null
        if (value.isJsonNull) return null
        return runCatching { value.asString.trim().takeIf { it.isNotEmpty() } }.getOrNull()
    }

    private fun JsonObject.intOrNull(name: String): Int? {
        val value = get(name) ?: return null
        if (value.isJsonNull) return null
        return runCatching { value.asInt }.getOrNull()
    }

    companion object {
        fun fromPrefs(prefs: Prefs, rulePackManifest: RulePackManifest? = null): SingboxConfigBuilder {
            return SingboxConfigBuilder(SingboxSettings.fromPrefs(prefs), rulePackManifest = rulePackManifest)
        }
    }
}
