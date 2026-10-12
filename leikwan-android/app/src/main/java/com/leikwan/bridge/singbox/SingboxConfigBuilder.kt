package com.leikwan.bridge.singbox

import com.leikwan.bridge.BuildConfig
import com.google.gson.Gson
import com.google.gson.JsonArray
import com.google.gson.JsonObject
import com.leikwan.bridge.clientrules.ClientRouteRule
import com.leikwan.bridge.clientrules.ClientRouteRuleSnapshot
import com.leikwan.bridge.clientrules.PendingClientRuleOperation
import com.leikwan.bridge.data.Prefs
import com.leikwan.bridge.data.ProxyNode
import com.leikwan.bridge.data.routing.RulePackManifest
import com.leikwan.bridge.routing.CompiledConfigResult
import com.leikwan.bridge.routing.RoutingCategoryCatalog
import com.leikwan.bridge.routing.AppRoutingConfig
import com.leikwan.bridge.routing.RoutingPolicyEngine
import com.leikwan.bridge.routing.RoutingPolicySnapshot
import com.leikwan.bridge.routing.SafePathRegistry
import com.leikwan.bridge.routing.UserTrafficOutcome
import com.leikwan.bridge.runtime.ClientRuleSingboxBinding
import com.leikwan.bridge.runtime.NATIVE_ROUTE_SCOPE_CN_ONLY
import com.leikwan.bridge.runtime.NATIVE_ROUTE_SCOPE_GLOBAL
import com.leikwan.bridge.runtime.NATIVE_ROUTE_SCOPE_INTL_ONLY
import com.leikwan.bridge.runtime.NativeOutboundCapability
import com.leikwan.bridge.runtime.NativeOutboundLease
import com.leikwan.bridge.runtime.NATIVE_ROUTE_POLICY_RESTRICTED
import com.leikwan.bridge.runtime.NATIVE_ROUTE_POLICY_UNRESTRICTED
import com.leikwan.bridge.runtime.isNativeRoutePolicyValue
import com.leikwan.bridge.runtime.normalizeNativeRoutePolicy
import com.leikwan.bridge.runtime.ProtectedConnectivitySet
import com.leikwan.bridge.runtime.RuntimeContext
import com.leikwan.bridge.service.ProxyConnectionMode
import java.net.URI

/** Loopback endpoint exposed by the app-private native Ultra adapter. */
data class NativeOutboundLocalProxy(
    val host: String = "127.0.0.1",
    val port: Int,
) {
    init {
        require(host == "127.0.0.1" || host == "::1") { "native_local_proxy_host_invalid" }
        require(port in 1..65535) { "native_local_proxy_port_invalid" }
    }
}

private const val NATIVE_DOH_REMOTE_IP = "1.1.1.1"
private const val NATIVE_DOH_REMOTE_SNI = "cloudflare-dns.com"
private const val NATIVE_DOH_CHINA_IP = "223.5.5.5"
private const val NATIVE_DOH_CHINA_SNI = "dns.alidns.com"
private const val NATIVE_TUN_GATEWAY_IPV4 = "172.19.0.2/32"
private const val NATIVE_TUN_GATEWAY_IPV6 = "fdfe:dcba:9876::2/128"
private val CUSTOM_DNS_HOSTNAME = Regex("^[a-z0-9](?:[a-z0-9.-]{0,251}[a-z0-9])?$")

data class SingboxSettings(
    val panelUrl: String,
    val dnsServer: String,
    val dnsFallback: String,
    val dnsStrategy: String,
    val bypassLan: Boolean,
    val bypassChina: Boolean,
    val customBypassRules: String,
    /** Per-app VPN scope; its in-VPN rules reach the route through the routing snapshot. */
    val appRouting: AppRoutingConfig = AppRoutingConfig(),
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
                appRouting = prefs.appRouting,
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
        routeRuleSnapshot: ClientRouteRuleSnapshot? = null,
        routeRuleRuntimeBindings: List<ClientRuleSingboxBinding> = emptyList(),
        runtimeOnlyProxy: Boolean = false,
        nativeOutboundLease: NativeOutboundLease? = null,
        nativeLocalProxy: NativeOutboundLocalProxy? = null,
        nativeProtectedEndpoints: Set<String> = emptySet(),
        nativeRouteScope: String = "global",
        nativeBypassChina: Boolean = false,
        nativeRoutePolicy: String? = null,
    ): CompiledConfigResult {
        require(nativeOutboundLease == null || nativeLocalProxy == null) { "native_proxy_endpoint_ambiguous" }
        require(nativeOutboundLease == null || nativeOutboundLease.isUsable()) { "native_lease_invalid" }
        val nativeMode = nativeOutboundLease != null || nativeLocalProxy != null
        val normalizedNativeScope = NativeOutboundCapability.normalizeScope(nativeRouteScope)
            ?: if (nativeMode) throw IllegalArgumentException("native_route_scope_invalid") else NATIVE_ROUTE_SCOPE_GLOBAL
        val requestedNativePolicy = nativeRoutePolicy ?: nativeOutboundLease?.routePolicy ?: NATIVE_ROUTE_POLICY_RESTRICTED
        if (nativeMode && !isNativeRoutePolicyValue(requestedNativePolicy)) {
            throw IllegalArgumentException("native_route_policy_invalid")
        }
        val normalizedNativePolicy = normalizeNativeRoutePolicy(requestedNativePolicy)
        if (nativeOutboundLease != null && normalizedNativePolicy != nativeOutboundLease.routePolicy) {
            throw IllegalArgumentException("native_route_policy_mismatch")
        }
        val activeProxyNodes = if (!nativeMode) {
            activeProxyNodes(proxyNodes, runtimeContext, connectionMode)
        } else {
            emptyList()
        }
        val protectedConnectivity = (runtimeContext?.allProtectedConnectivity() ?: ProtectedConnectivitySet.empty())
            .merge(protectedProxyConnectivity(activeProxyNodes))
            .merge(ProtectedConnectivitySet.fromEndpoints(nativeProtectedEndpoints))
            .let { set ->
                nativeOutboundLease?.let { set.merge(ProtectedConnectivitySet.fromEndpoints(listOf(it.endpointIp))) } ?: set
            }
        val finalProxyTag = when {
            nativeMode -> "proxy-native"
            activeProxyNodes.isEmpty() -> "proxy"
            activeProxyNodes.size == 1 -> "proxy-${activeProxyNodes.first().id}"
            else -> "proxy"
        }
        val root = JsonObject()
        root.add("log", JsonObject().apply {
            addProperty("level", "debug")
        })
        val dnsProxyDetourTag = finalProxyTag.takeIf {
            nativeMode || activeProxyNodes.isNotEmpty()
        }
        val dnsBootstrapDomains = protectedConnectivity.hosts + customBypassDomains()
        root.add(
            "dns",
            buildDns(
                dnsProxyDetourTag,
                dnsBootstrapDomains,
                enableIpv6Tun,
                nativeMode = nativeMode,
                nativeRouteScope = normalizedNativeScope,
                nativeBypassChina = nativeMode && nativeBypassChina,
            ),
        )
        root.add(
            "inbounds",
            buildInbounds(
                enableIpv6Tun = enableIpv6Tun,
                routeExcludeCidrs = if (nativeMode) protectedConnectivity.ipCidrs else emptySet(),
            ),
        )
        root.add(
            "outbounds",
            buildOutbounds(
                proxyNodes,
                runtimeContext,
                connectionMode,
                runtimeOnlyProxy,
                routeRuleRuntimeBindings,
                nativeOutboundLease,
                nativeLocalProxy,
            ),
        )
        buildEndpoints(activeProxyNodes).takeIf { it.size() > 0 }?.let { root.add("endpoints", it) }
        val bridgeOverride = if (
            !nativeMode &&
            runtimeOnlyProxy &&
            connectionMode == ProxyConnectionMode.SERVICES &&
            runtimeContext != null &&
            activeProxyNodes.isEmpty()
        ) {
            "127.0.0.1" to runtimeContext.localListenPort
        } else {
            null
        }
        root.add(
            "route",
            buildRoute(
                protectedConnectivity,
                finalProxyTag,
                routingPolicySnapshot,
                routeRuleSnapshot,
                routeRuleRuntimeBindings,
                bridgeOverride,
                nativeMode,
                normalizedNativeScope,
                nativeBypassChina,
                normalizedNativePolicy,
                enableIpv6Tun,
            )
        )
        return try {
            CompiledConfigResult(
                configJson = gson.toJson(root),
                finalProxyTag = finalProxyTag,
                warnings = routingPolicySnapshot?.warnings ?: emptyList(),
            )
        } finally {
            nativeOutboundLease?.wipe()
        }
    }

    private fun buildDns(
        proxyDetourTag: String?,
        bootstrapDomains: Set<String>,
        enableIpv6Tun: Boolean,
        nativeMode: Boolean = false,
        nativeRouteScope: String = NATIVE_ROUTE_SCOPE_GLOBAL,
        nativeBypassChina: Boolean = false,
    ): JsonObject {
        val dns = JsonObject()
        val servers = JsonArray()
        val restrictedNative = nativeMode && nativeRouteScope in setOf(
            NATIVE_ROUTE_SCOPE_CN_ONLY,
            NATIVE_ROUTE_SCOPE_INTL_ONLY,
        )
        if (restrictedNative && nativeRouteScope == NATIVE_ROUTE_SCOPE_CN_ONLY) {
            addNativeDohServer(
                servers,
                tag = "remote",
                server = NATIVE_DOH_REMOTE_IP,
                serverName = NATIVE_DOH_REMOTE_SNI,
            )
            addNativeDohServer(
                servers,
                tag = "china",
                server = NATIVE_DOH_CHINA_IP,
                serverName = NATIVE_DOH_CHINA_SNI,
                detour = proxyDetourTag ?: "proxy-native",
            )
        } else if (restrictedNative && nativeRouteScope == NATIVE_ROUTE_SCOPE_INTL_ONLY) {
            addNativeDohServer(
                servers,
                tag = "remote",
                server = NATIVE_DOH_REMOTE_IP,
                serverName = NATIVE_DOH_REMOTE_SNI,
                detour = proxyDetourTag ?: "proxy-native",
            )
        } else {
            servers.add(JsonObject().apply {
                addProperty("tag", "remote")
                addProperty("type", if (proxyDetourTag != null) "tcp" else "udp")
                addProperty("server", settings.dnsServer)
                if (proxyDetourTag != null) {
                    addProperty("detour", proxyDetourTag)
                }
            })
        }
        val directDnsTag = if (restrictedNative) "local" else "direct-dns"
        if (restrictedNative) {
            servers.add(JsonObject().apply {
                addProperty("tag", "local")
                addProperty("type", "local")
            })
        } else {
            servers.add(JsonObject().apply {
                addProperty("tag", "direct-dns")
                addProperty("type", "udp")
                addProperty("server", settings.dnsFallback)
            })
        }
        dns.add("servers", servers)

        val rules = JsonArray()
        rules.add(JsonObject().apply {
            add("domain", JsonArray().apply {
                (panelDirectDomains() + bootstrapDomains).forEach { add(it) }
            })
            addProperty("server", directDnsTag)
        })
        val nativeChinaDirect = nativeMode && (
            nativeRouteScope == NATIVE_ROUTE_SCOPE_INTL_ONLY ||
                (nativeRouteScope == NATIVE_ROUTE_SCOPE_GLOBAL && nativeBypassChina)
            )
        if (restrictedNative) {
            rules.add(JsonObject().apply {
                add("rule_set", JsonArray().apply { add("geosite-cn") })
                addProperty(
                    "server",
                    if (nativeRouteScope == NATIVE_ROUTE_SCOPE_CN_ONLY) "china" else "local",
                )
            })
        } else if ((!nativeMode && settings.bypassChina) || nativeChinaDirect) {
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

    private fun addNativeDohServer(
        servers: JsonArray,
        tag: String,
        server: String,
        serverName: String,
        detour: String? = null,
    ) {
        servers.add(JsonObject().apply {
            addProperty("tag", tag)
            addProperty("type", "https")
            addProperty("server", server)
            addProperty("server_port", 443)
            addProperty("path", "/dns-query")
            add("tls", JsonObject().apply {
                addProperty("enabled", true)
                addProperty("server_name", serverName)
            })
            detour?.let { addProperty("detour", it) }
        })
    }

    private fun buildInbounds(
        enableIpv6Tun: Boolean,
        routeExcludeCidrs: Set<String> = emptySet(),
    ): JsonArray {
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
                val effectiveRouteExcludes = routeExcludeCidrs.filter { enableIpv6Tun || !it.contains(':') }
                if (effectiveRouteExcludes.isNotEmpty()) {
                    add("route_exclude_address", JsonArray().apply {
                        effectiveRouteExcludes.forEach { add(it) }
                    })
                }
                // Per-app scope, applied by VpnService.Builder in openTun. This app is always
                // outside its own VPN, so neither list may name it.
                val include = settings.appRouting.includePackages(BuildConfig.APPLICATION_ID)
                val exclude = settings.appRouting.excludePackages(BuildConfig.APPLICATION_ID)
                if (include.isNotEmpty()) {
                    add("include_package", JsonArray().apply { include.forEach { add(it) } })
                } else if (exclude.isNotEmpty()) {
                    add("exclude_package", JsonArray().apply { exclude.forEach { add(it) } })
                }
            })
        }
    }

    private fun buildOutbounds(
        proxyNodes: List<ProxyNode>,
        runtimeContext: RuntimeContext?,
        connectionMode: ProxyConnectionMode,
        runtimeOnlyProxy: Boolean,
        routeRuleRuntimeBindings: List<ClientRuleSingboxBinding>,
        nativeOutboundLease: NativeOutboundLease?,
        nativeLocalProxy: NativeOutboundLocalProxy?,
    ): JsonArray {
        val outbounds = JsonArray()
        val proxyTags = mutableListOf<String>()

        if (nativeOutboundLease != null || nativeLocalProxy != null) {
            outbounds.add(JsonObject().apply {
                addProperty("type", "socks")
                addProperty("tag", "proxy-native")
                addProperty("server", nativeLocalProxy?.host ?: nativeOutboundLease!!.endpointIp)
                addProperty("server_port", nativeLocalProxy?.port ?: nativeOutboundLease!!.endpointPort)
                addProperty("version", "5")
                // sing-box accepts only a single value ("tcp" or "udp") here.
                // Omitting the field explicitly enables both networks, which is
                // required for the native TCP+UDP SOCKS5 lease.
                add("udp_over_tcp", JsonObject().apply { addProperty("enabled", false) })
                nativeOutboundLease?.let { lease ->
                    addProperty("username", lease.user.toString(Charsets.UTF_8))
                    addProperty("password", lease.password.toString(Charsets.UTF_8))
                }
            })
        }

        if (nativeOutboundLease == null && nativeLocalProxy == null) activeProxyNodes(proxyNodes, runtimeContext, connectionMode)
            .forEach { node ->
                if (node.type.equals("wireguard", ignoreCase = true)) {
                    proxyTags += "proxy-${node.id}"
                    return@forEach
                }
                val outbound = buildProxyOutbound(node, runtimeContext, connectionMode) ?: return@forEach
                outbounds.add(outbound)
                proxyTags += "proxy-${node.id}"
            }

        if (nativeOutboundLease != null || nativeLocalProxy != null) {
            // Native mode intentionally has no user-node selector; the lease is the sole proxy path.
        } else if (proxyTags.size > 1) {
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
        routeRuleRuntimeBindings.forEach { binding ->
            outbounds.add(routeRuleDirectOutbound(binding.tcpOutboundTag))
            outbounds.add(routeRuleDirectOutbound(binding.udpOutboundTag))
        }
        return outbounds
    }

    private fun routeRuleDirectOutbound(tag: String): JsonObject = JsonObject().apply {
        addProperty("type", "direct")
        addProperty("tag", tag)
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

    private fun protectedProxyConnectivity(nodes: List<ProxyNode>): ProtectedConnectivitySet {
        val endpoints = linkedSetOf<String>()
        nodes.forEach { node ->
            node.originalServer.takeIf { it.isNotBlank() }?.let(endpoints::add)
            val config = runCatching { gson.fromJson(node.configJson, JsonObject::class.java) }.getOrNull()
                ?: return@forEach
            when (node.type.lowercase()) {
                "vless", "vmess" -> {
                    listOf("server", "sni", "host").forEach { field ->
                        config.stringOrNull(field)?.let(endpoints::add)
                    }
                }
                "wireguard" -> {
                    config.getAsJsonArray("peers")?.forEach { peerElement ->
                        peerElement.takeIf { it.isJsonObject }
                            ?.asJsonObject
                            ?.stringOrNull("address")
                            ?.let(endpoints::add)
                    }
                }
            }
        }
        return ProtectedConnectivitySet.fromEndpoints(endpoints)
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
                if (tType != "tcp" && tType != "none" && tType != "raw") {
                    add("transport", JsonObject().apply {
                        addProperty("type", tType)
                        if (tType == "ws") {
                            addProperty("path", config.get("path")?.asString ?: "/")
                            add("headers", JsonObject().apply {
                                config.get("host")?.asString?.let { addProperty("Host", it) }
                            })
                        }
                        if (tType == "httpupgrade") {
                            addProperty("path", config.stringOrNull("path") ?: "/")
                            config.stringOrNull("host")?.let { addProperty("host", it) }
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
                        // The outbound dials 127.0.0.1 through the forward, so without an explicit
                        // SNI the handshake would name the loopback address; use the node's own
                        // host name instead.
                        val serverName = config.stringOrNull("sni")
                            ?: config.stringOrNull("peer")
                            ?: node.originalServer.trim().takeUnless { it.isEmpty() || isIpLike(it) }
                        serverName?.let { addProperty("server_name", it) }
                        if (security == "tls" && config.stringOrNull("allowInsecure")?.lowercase() in setOf("1", "true")) {
                            addProperty("insecure", true)
                        }
                        config.stringOrNull("alpn")?.split(',')?.map { it.trim() }?.filter { it.isNotEmpty() }
                            ?.takeIf { it.isNotEmpty() }
                            ?.let { values -> add("alpn", JsonArray().apply { values.forEach { add(it) } }) }
                        // sing-box requires uTLS for REALITY; chrome is the common default.
                        val fingerprint = config.stringOrNull("fp") ?: if (security == "reality") "chrome" else null
                        fingerprint?.let { fp ->
                            add("utls", JsonObject().apply {
                                addProperty("enabled", true)
                                addProperty("fingerprint", fp)
                            })
                        }
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
            "socks", "socks5" -> JsonObject().apply {
                addProperty("type", "socks")
                addProperty("tag", "proxy-${node.id}")
                addProperty("server", outboundServer)
                addProperty("server_port", outboundPort)
                addProperty("version", "5")
                config.get("username")?.asString?.takeIf { it.isNotBlank() }?.let {
                    addProperty("username", it)
                }
                config.get("password")?.asString?.takeIf { it.isNotBlank() }?.let {
                    addProperty("password", it)
                }
            }
            else -> null
        }
    }

    private fun buildRoute(
        protectedConnectivity: ProtectedConnectivitySet,
        finalProxyTag: String,
        snapshot: RoutingPolicySnapshot?,
        routeRuleSnapshot: ClientRouteRuleSnapshot?,
        routeRuleRuntimeBindings: List<ClientRuleSingboxBinding>,
        bridgeOverride: Pair<String, Int>? = null,
        nativeMode: Boolean = false,
        nativeRouteScope: String = "global",
        nativeBypassChina: Boolean = false,
        nativeRoutePolicy: String = NATIVE_ROUTE_POLICY_RESTRICTED,
        enableIpv6Tun: Boolean = false,
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

        // Reject packets aimed at the TUN peer before the RFC-1918 direct rule.
        // Otherwise strict routing can feed gateway discovery back into the VPN.
        if (nativeMode) {
            listOf("tcp", "udp").forEach { network ->
                rules.add(JsonObject().apply {
                    add("inbound", JsonArray().apply { add("tun-in") })
                    addProperty("network", network)
                    add("ip_cidr", JsonArray().apply {
                        add(NATIVE_TUN_GATEWAY_IPV4)
                        if (enableIpv6Tun) add(NATIVE_TUN_GATEWAY_IPV6)
                    })
                    addProperty("action", "reject")
                    addProperty("method", "drop")
                })
            }
        }

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
        if (protectedConnectivity.ipCidrs.isNotEmpty()) {
            rules.add(JsonObject().apply {
                add("ip_cidr", JsonArray().apply { protectedConnectivity.ipCidrs.forEach(::add) })
                addProperty("outbound", "direct")
            })
        }
        if (protectedConnectivity.hosts.isNotEmpty()) {
            rules.add(JsonObject().apply {
                add("domain", JsonArray().apply { protectedConnectivity.hosts.forEach(::add) })
                addProperty("outbound", "direct")
            })
        }

        // (3) Server-synced Client Rules: exact destination IP:PORT rules. These sit after
        //     protected/runtime reachability and before country/category/default routing.
        addClientRuleRoutes(rules, routeRuleSnapshot, routeRuleRuntimeBindings)

        val nativeRestrictedScope = nativeMode && nativeRouteScope in setOf(
            NATIVE_ROUTE_SCOPE_CN_ONLY,
            NATIVE_ROUTE_SCOPE_INTL_ONLY,
        )
        if (nativeMode && nativeRoutePolicy == NATIVE_ROUTE_POLICY_RESTRICTED) {
            // Native relay traffic is game-oriented. Keep privileged/control
            // ports on the direct path; only high ports enter the relay.
            rules.add(JsonObject().apply {
                add("network", JsonArray().apply {
                    add("tcp")
                    add("udp")
                })
                addProperty("port_range", "1:999")
                addProperty("outbound", "direct")
            })
        }
        if (nativeRestrictedScope) {
            // Resolve the hostname once using the selected split-DNS server before
            // handing the destination to SOCKS; do not let the upstream resolve it again.
            rules.add(JsonObject().apply {
                addProperty("action", "resolve")
                addProperty("strategy", "ipv4_only")
            })
            rules.add(JsonObject().apply {
                add("rule_set", JsonArray().apply {
                    add("geoip-cn")
                    add("geosite-cn")
                })
                add("network", JsonArray().apply {
                    add("tcp")
                    add("udp")
                })
                addProperty(
                    "port_range",
                    if (nativeRoutePolicy == NATIVE_ROUTE_POLICY_RESTRICTED) "1000:65535" else "1:65535",
                )
                addProperty(
                    "outbound",
                    if (nativeRouteScope == NATIVE_ROUTE_SCOPE_CN_ONLY) finalProxyTag else "direct",
                )
            })
        }

        // (4) User country/category rules (the 用户自定义分流 surface). Each compiled rule routes its
        //     rule_set(s) to "direct" (DIRECT) or the proxy tag (TUNNEL, sentinel swapped here).
        if (snapshot != null) {
            effectiveRoutingEngine.compileRouteRules(snapshot).forEach { compiled ->
                val outbound = when {
                    compiled.outbound == RoutingPolicyEngine.PROXY_OUTBOUND_SENTINEL -> finalProxyTag
                    compiled.outbound.startsWith("<NODE:") ->
                        if (nativeMode) finalProxyTag
                        else "proxy-" + compiled.outbound.removePrefix("<NODE:").removeSuffix(">")
                    else -> compiled.outbound
                }
                rules.add(JsonObject().apply {
                    if (compiled.packageNames.isNotEmpty()) {
                        add("package_name", JsonArray().apply { compiled.packageNames.forEach { add(it) } })
                    } else {
                        add("rule_set", JsonArray().apply { compiled.ruleSetTags.forEach { add(it) } })
                    }
                    addProperty("outbound", outbound)
                })
            }
        }

        // (5) bypassChina toggle (also expressible as a CN->DIRECT user rule; kept for back-compat).
        if ((settings.bypassChina && !nativeMode) ||
            (nativeMode && nativeRouteScope == NATIVE_ROUTE_SCOPE_GLOBAL && nativeBypassChina)) {
            rules.add(JsonObject().apply {
                add("rule_set", JsonArray().apply {
                    add("geoip-cn")
                    add("geosite-cn")
                })
                addProperty("outbound", "direct")
            })
        }

        // (6) Custom bypass rules.
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

        if (bridgeOverride != null) {
            rules.add(JsonObject().apply {
                addProperty("action", "route")
                addProperty("outbound", finalProxyTag)
                addProperty("override_address", bridgeOverride.first)
                addProperty("override_port", bridgeOverride.second)
            })
        }

        route.add("rules", rules)
        if (nativeRestrictedScope) {
            route.addProperty("default_domain_resolver", "remote")
        }
        // route.final follows the user-settable global default: TUNNEL -> proxy tag; DIRECT -> direct.
        val defaultTunnelTag = snapshot?.globalDefaultNodeId?.let { "proxy-$it" } ?: finalProxyTag
        route.addProperty(
            "final",
            if (nativeMode) {
                if (nativeRouteScope == NATIVE_ROUTE_SCOPE_CN_ONLY) "direct" else finalProxyTag
            } else if (snapshot?.globalDefaultOutcome == UserTrafficOutcome.DIRECT) {
                "direct"
            } else {
                defaultTunnelTag
            },
        )

        // route.rule_set: China sets (if toggle) + user-rule remote sets, de-duplicated by tag.
        val ruleSetArray = JsonArray()
        val seenTags = linkedSetOf<String>()
        val nativeRuleSetScope = nativeRouteScope in setOf(
            NATIVE_ROUTE_SCOPE_CN_ONLY,
            NATIVE_ROUTE_SCOPE_INTL_ONLY,
        )
        val needsNativeChinaSets = nativeMode && (
            nativeRuleSetScope ||
                (nativeRouteScope == NATIVE_ROUTE_SCOPE_GLOBAL && nativeBypassChina)
            )
        if ((settings.bypassChina && !nativeMode) || needsNativeChinaSets) {
            val downloadDetour = when {
                !nativeMode -> null
                nativeRouteScope == NATIVE_ROUTE_SCOPE_CN_ONLY -> "direct"
                nativeRouteScope == NATIVE_ROUTE_SCOPE_INTL_ONLY -> finalProxyTag
                else -> null
            }
            if (seenTags.add("geoip-cn")) {
                ruleSetArray.add(remoteRuleSet("geoip-cn", chinaRuleSetUrl("geoip-cn"), downloadDetour))
            }
            if (seenTags.add("geosite-cn")) {
                ruleSetArray.add(remoteRuleSet("geosite-cn", chinaRuleSetUrl("geosite-cn"), downloadDetour))
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

    private fun addClientRuleRoutes(
        rules: JsonArray,
        snapshot: ClientRouteRuleSnapshot?,
        runtimeBindings: List<ClientRuleSingboxBinding>,
    ) {
        snapshot ?: return
        val bindingByDirection = runtimeBindings.associateBy { it.directionId }
        snapshot.rules
            .filter { it.enabled && it.deletedAtMs == null && it.pendingOperation != PendingClientRuleOperation.DELETE }
            .forEach { rule ->
                val binding = bindingByDirection[rule.directionId]
                if (rule.installable && binding != null) {
                    rules.add(routeRuleMatch(rule, "tcp", binding.tcpOutboundTag, binding.localTcpPort))
                    rules.add(routeRuleMatch(rule, "udp", binding.udpOutboundTag, binding.localUdpPort))
                } else {
                    rules.add(routeRuleReject(rule, "tcp"))
                    rules.add(routeRuleReject(rule, "udp"))
                }
            }
    }

    private fun routeRuleMatch(
        rule: ClientRouteRule,
        network: String,
        outbound: String,
        overridePort: Int? = null,
    ): JsonObject =
        routeRuleBase(rule, network).apply {
            addProperty("outbound", outbound)
            if (overridePort != null) {
                addProperty("override_address", "127.0.0.1")
                addProperty("override_port", overridePort)
            }
        }

    private fun routeRuleReject(rule: ClientRouteRule, network: String): JsonObject =
        routeRuleBase(rule, network).apply {
            addProperty("action", "reject")
        }

    private fun routeRuleBase(rule: ClientRouteRule, network: String): JsonObject = JsonObject().apply {
        add("ip_cidr", JsonArray().apply { add(normalizeIpCidr(rule.destinationIp)) })
        add("port", JsonArray().apply { add(rule.destinationPort) })
        addProperty("network", network)
    }

    private fun remoteRuleSet(tag: String, url: String, downloadDetour: String? = null): JsonObject = JsonObject().apply {
        addProperty("tag", tag)
        addProperty("type", "remote")
        addProperty("format", "binary")
        addProperty("url", url)
        downloadDetour?.let { addProperty("download_detour", it) }
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

    private fun customBypassDomains(): Set<String> = settings.customBypassRules
        .lineSequence()
        .map(String::trim)
        .filter { it.isNotEmpty() && !isIpLike(it) }
        .map { it.lowercase().removeSuffix(".") }
        .filter { it.matches(CUSTOM_DNS_HOSTNAME) }
        .toCollection(linkedSetOf())

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
