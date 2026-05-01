package com.leikwan.bridge.singbox

import com.google.gson.Gson
import com.google.gson.JsonArray
import com.google.gson.JsonObject
import com.leikwan.bridge.data.Prefs
import com.leikwan.bridge.data.ProxyNode
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

class SingboxConfigBuilder(val settings: SingboxSettings) {
    private val gson = Gson()

    fun build(
        proxyNodes: List<ProxyNode>,
        runtimeContext: RuntimeContext?,
        connectionMode: ProxyConnectionMode = ProxyConnectionMode.SERVICES,
    ): String {
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
        root.add("dns", buildDns(finalProxyTag, connectionMode))
        root.add("inbounds", buildInbounds())
        root.add("outbounds", buildOutbounds(proxyNodes, runtimeContext, connectionMode))
        root.add("route", buildRoute(runtimeContext?.bypassHosts ?: emptySet(), finalProxyTag))
        return gson.toJson(root)
    }

    private fun buildDns(
        proxyDetourTag: String,
        connectionMode: ProxyConnectionMode,
    ): JsonObject {
        val dns = JsonObject()
        val servers = JsonArray()
        servers.add(JsonObject().apply {
            addProperty("tag", "remote")
            addProperty("type", "udp")
            addProperty("server", settings.dnsServer)
            addProperty(
                "detour",
                if (connectionMode == ProxyConnectionMode.SERVICES) proxyDetourTag else "direct"
            )
        })
        servers.add(JsonObject().apply {
            addProperty("tag", "direct-dns")
            addProperty("type", "udp")
            addProperty("server", settings.dnsFallback)
            addProperty("detour", "direct")
        })
        dns.add("servers", servers)

        val rules = JsonArray()
        rules.add(JsonObject().apply {
            add("domain", JsonArray().apply {
                panelDirectDomains().forEach { add(it) }
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
        dns.addProperty("strategy", settings.dnsStrategy)
        return dns
    }

    private fun buildInbounds(): JsonArray {
        return JsonArray().apply {
            add(JsonObject().apply {
                addProperty("type", "tun")
                addProperty("tag", "tun-in")
                add("address", JsonArray().apply {
                    add("172.19.0.1/30")
                    add("fdfe:dcba:9876::1/126")
                })
                addProperty("auto_route", true)
                addProperty("strict_route", true)
                addProperty("stack", "mixed")
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

    private fun buildProxyOutbound(
        node: ProxyNode,
        runtimeContext: RuntimeContext?,
        connectionMode: ProxyConnectionMode,
    ): JsonObject? {
        val config = gson.fromJson(node.configJson, JsonObject::class.java)
        val useLocalRelay = connectionMode == ProxyConnectionMode.SERVICES
        val relayPort = runtimeContext?.localListenPort
        val outboundServer = if (useLocalRelay) "127.0.0.1" else node.originalServer
        val outboundPort = if (useLocalRelay) {
            relayPort ?: return null
        } else {
            node.originalPort
        }

        return when (node.type) {
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

    private fun buildRoute(runtimeBypassHosts: Set<String>, finalProxyTag: String): JsonObject {
        val route = JsonObject()
        val rules = JsonArray()

        rules.add(JsonObject().apply {
            addProperty("action", "sniff")
        })

        rules.add(JsonObject().apply {
            addProperty("protocol", "dns")
            addProperty("action", "hijack-dns")
        })

        val bypassHosts = linkedSetOf<String>()
        bypassHosts += panelDirectDomains()
        bypassHosts += runtimeBypassHosts

        val bypassIps = JsonArray()
        val bypassDomains = JsonArray()
        bypassHosts.forEach { host ->
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

        if (settings.bypassLan) {
            rules.add(JsonObject().apply {
                add("ip_cidr", JsonArray().apply {
                    add("10.0.0.0/8")
                    add("172.16.0.0/12")
                    add("192.168.0.0/16")
                    add("127.0.0.0/8")
                })
                addProperty("outbound", "direct")
            })
        }

        if (settings.bypassChina) {
            rules.add(JsonObject().apply {
                add("rule_set", JsonArray().apply {
                    add("geoip-cn")
                    add("geosite-cn")
                })
                addProperty("outbound", "direct")
            })
        }

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
        route.addProperty("final", finalProxyTag)
        route.addProperty("auto_detect_interface", true)

        if (settings.bypassChina) {
            route.add("rule_set", JsonArray().apply {
                add(JsonObject().apply {
                    addProperty("tag", "geoip-cn")
                    addProperty("type", "remote")
                    addProperty("format", "binary")
                    addProperty("url", "https://raw.githubusercontent.com/SagerNet/sing-geoip/rule-set/geoip-cn.srs")
                })
                add(JsonObject().apply {
                    addProperty("tag", "geosite-cn")
                    addProperty("type", "remote")
                    addProperty("format", "binary")
                    addProperty("url", "https://raw.githubusercontent.com/SagerNet/sing-geosite/rule-set/geosite-cn.srs")
                })
            })
        }

        return route
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
                } else {
                    it.forwardId == null || it.forwardId == runtimeContext.forwardId
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

    companion object {
        fun fromPrefs(prefs: Prefs): SingboxConfigBuilder {
            return SingboxConfigBuilder(SingboxSettings.fromPrefs(prefs))
        }
    }
}
