package com.leikwan.bridge.ui.proxies

import com.google.gson.Gson
import com.google.gson.JsonArray
import com.google.gson.JsonObject
import com.google.gson.JsonParser
import com.leikwan.bridge.data.ProxyNode

sealed interface ProxyProtocolDraft {
    data class Shadowsocks(
        val method: String = "",
        val password: String = "",
        val plugin: String = "",
        val pluginOpts: String = "",
        val network: String = "",
        val udpOverTcp: Boolean = false,
        val multiplexEnabled: Boolean = false,
    ) : ProxyProtocolDraft

    data class Vless(
        val uuid: String = "",
        val flow: String = "",
        val security: String = "",
        val transportType: String = "tcp",
        val host: String = "",
        val path: String = "",
        val sni: String = "",
        val serviceName: String = "",
        val publicKey: String = "",
        val shortId: String = "",
    ) : ProxyProtocolDraft

    data class Vmess(
        val uuid: String = "",
        val alterId: String = "0",
        val security: String = "auto",
        val network: String = "tcp",
        val host: String = "",
        val path: String = "",
    ) : ProxyProtocolDraft

    data class WireGuard(
        val privateKey: String = "",
        val addresses: String = "",
        val publicKey: String = "",
        val preSharedKey: String = "",
        val allowedIps: String = "0.0.0.0/0, ::/0",
        val mtu: String = "",
        val persistentKeepalive: String = "",
        val reserved: String = "",
    ) : ProxyProtocolDraft

    data class Socks5(
        val username: String = "",
        val password: String = "",
    ) : ProxyProtocolDraft

    data class Unknown(
        val configJson: String,
    ) : ProxyProtocolDraft
}

data class ProxySummaryText(
    val unknown: String,
    val methodFormat: String,
    val uuidFormat: String,
    val publicKeyFormat: String,
    val addressFormat: String,
)

object ProxyProtocolDraftParser {
    private val gson = Gson()

    fun parse(node: ProxyNode): ProxyProtocolDraft {
        val config = runCatching {
            JsonParser.parseString(node.configJson).asJsonObject
        }.getOrNull() ?: return ProxyProtocolDraft.Unknown(node.configJson)

        return when (node.type.lowercase()) {
            "ss" -> ProxyProtocolDraft.Shadowsocks(
                method = config.stringOrEmpty("method"),
                password = config.stringOrEmpty("password"),
                plugin = config.stringOrEmpty("plugin"),
                pluginOpts = config.stringOrEmpty("plugin_opts"),
                network = config.stringOrEmpty("network"),
                udpOverTcp = config.boolOrFalse("udp_over_tcp"),
                multiplexEnabled = config.boolOrFalse("multiplex_enabled")
                    || (config.objectOrNull("multiplex")?.boolOrFalse("enabled") == true),
            )

            "vless" -> ProxyProtocolDraft.Vless(
                uuid = config.stringOrEmpty("uuid"),
                flow = config.stringOrEmpty("flow"),
                security = config.stringOrEmpty("security"),
                transportType = config.stringOrEmpty("type").ifBlank { "tcp" },
                host = config.stringOrEmpty("host"),
                path = config.stringOrEmpty("path"),
                sni = config.stringOrEmpty("sni"),
                serviceName = config.stringOrEmpty("serviceName"),
                publicKey = config.stringOrEmpty("pbk"),
                shortId = config.stringOrEmpty("sid"),
            )

            "vmess" -> ProxyProtocolDraft.Vmess(
                uuid = config.stringOrEmpty("id"),
                alterId = config.stringOrEmpty("aid").ifBlank { "0" },
                security = config.stringOrEmpty("scy").ifBlank { "auto" },
                network = config.stringOrEmpty("net").ifBlank { "tcp" },
                host = config.stringOrEmpty("host"),
                path = config.stringOrEmpty("path"),
            )

            "wireguard" -> {
                val peer = config.arrayOrNull("peers")?.firstOrNull()?.asJsonObject
                ProxyProtocolDraft.WireGuard(
                    privateKey = config.stringOrEmpty("private_key"),
                    addresses = config.csvString("address"),
                    publicKey = peer?.stringOrEmpty("public_key").orEmpty(),
                    preSharedKey = peer?.stringOrEmpty("pre_shared_key").orEmpty(),
                    allowedIps = peer?.csvString("allowed_ips").orEmpty(),
                    mtu = config.stringOrEmpty("mtu"),
                    persistentKeepalive = peer?.stringOrEmpty("persistent_keepalive_interval")
                        ?: peer?.stringOrEmpty("persistent_keepalive")
                        ?: "",
                    reserved = peer?.csvString("reserved").orEmpty(),
                )
            }

            "socks", "socks5" -> ProxyProtocolDraft.Socks5(
                username = config.stringOrEmpty("username"),
                password = config.stringOrEmpty("password"),
            )

            else -> ProxyProtocolDraft.Unknown(prettyJson(node.configJson))
        }
    }

    fun serialize(
        type: String,
        protocol: ProxyProtocolDraft,
        name: String,
        server: String,
        port: Int,
    ): String {
        val config = when (protocol) {
            is ProxyProtocolDraft.Shadowsocks -> JsonObject().apply {
                addProperty("method", protocol.method)
                addProperty("password", protocol.password)
                protocol.plugin.takeIf { it.isNotBlank() }?.let { addProperty("plugin", it) }
                protocol.pluginOpts.takeIf { it.isNotBlank() }?.let { addProperty("plugin_opts", it) }
                protocol.network.takeIf { it.isNotBlank() }?.let { addProperty("network", it) }
                if (protocol.udpOverTcp) {
                    addProperty("udp_over_tcp", true)
                }
                if (protocol.multiplexEnabled) {
                    addProperty("multiplex_enabled", true)
                }
            }

            is ProxyProtocolDraft.Vless -> JsonObject().apply {
                addProperty("uuid", protocol.uuid)
                protocol.flow.takeIf { it.isNotBlank() }?.let { addProperty("flow", it) }
                protocol.security.takeIf { it.isNotBlank() }?.let { addProperty("security", it) }
                protocol.transportType.takeIf { it.isNotBlank() && it != "tcp" }?.let { addProperty("type", it) }
                protocol.host.takeIf { it.isNotBlank() }?.let { addProperty("host", it) }
                protocol.path.takeIf { it.isNotBlank() }?.let { addProperty("path", it) }
                protocol.sni.takeIf { it.isNotBlank() }?.let { addProperty("sni", it) }
                protocol.serviceName.takeIf { it.isNotBlank() }?.let { addProperty("serviceName", it) }
                protocol.publicKey.takeIf { it.isNotBlank() }?.let { addProperty("pbk", it) }
                protocol.shortId.takeIf { it.isNotBlank() }?.let { addProperty("sid", it) }
            }

            is ProxyProtocolDraft.Vmess -> JsonObject().apply {
                addProperty("id", protocol.uuid)
                protocol.alterId.toIntOrNull()?.let { addProperty("aid", it) } ?: addProperty("aid", protocol.alterId)
                protocol.security.takeIf { it.isNotBlank() }?.let { addProperty("scy", it) }
                protocol.network.takeIf { it.isNotBlank() && it != "tcp" }?.let { addProperty("net", it) }
                protocol.host.takeIf { it.isNotBlank() }?.let { addProperty("host", it) }
                protocol.path.takeIf { it.isNotBlank() }?.let { addProperty("path", it) }
            }

            is ProxyProtocolDraft.WireGuard -> JsonObject().apply {
                addProperty("private_key", protocol.privateKey)
                addCsvArray("address", protocol.addresses)
                protocol.mtu.trim().toIntOrNull()?.let { addProperty("mtu", it) }
                add("peers", JsonArray().apply {
                    add(JsonObject().apply {
                        addProperty("public_key", protocol.publicKey)
                        protocol.preSharedKey.takeIf { it.isNotBlank() }?.let { addProperty("pre_shared_key", it) }
                        addProperty("address", server)
                        addProperty("port", port)
                        addCsvArray("allowed_ips", protocol.allowedIps)
                        protocol.persistentKeepalive.trim().toIntOrNull()
                            ?.let { addProperty("persistent_keepalive_interval", it) }
                        val reservedInts = csvValues(protocol.reserved).mapNotNull { it.toIntOrNull() }
                        if (reservedInts.isNotEmpty()) {
                            add("reserved", JsonArray().apply { reservedInts.forEach { add(it) } })
                        }
                    })
                })
            }

            is ProxyProtocolDraft.Socks5 -> JsonObject().apply {
                protocol.username.takeIf { it.isNotBlank() }?.let { addProperty("username", it) }
                protocol.password.takeIf { it.isNotBlank() }?.let { addProperty("password", it) }
            }

            is ProxyProtocolDraft.Unknown -> runCatching {
                JsonParser.parseString(protocol.configJson).asJsonObject
            }.getOrElse { JsonObject() }
        }

        syncSummaryFields(
            type = type,
            config = config,
            name = name,
            server = server,
            port = port,
        )
        return gson.toJson(config)
    }

    fun summaryLines(node: ProxyNode, text: ProxySummaryText): List<String> {
        val hostLine = "${node.type.uppercase()} | ${node.originalServer}:${node.originalPort}"
        return when (val details = parse(node)) {
            is ProxyProtocolDraft.Shadowsocks -> buildList {
                add(hostLine)
                add(text.methodFormat.formatOne(details.method.ifBlank { text.unknown }))
                val flags = buildList {
                    details.plugin.takeIf { it.isNotBlank() }?.let { add("plugin=$it") }
                    if (details.udpOverTcp) add("uot")
                    if (details.multiplexEnabled) add("mux")
                }
                if (flags.isNotEmpty()) {
                    add(flags.joinToString(" • "))
                }
            }

            is ProxyProtocolDraft.Vless -> buildList {
                add(hostLine)
                add(text.uuidFormat.formatOne(details.uuid.ifBlank { text.unknown }))
                val transport = buildList {
                    add(details.transportType.ifBlank { "tcp" }.uppercase())
                    details.security.takeIf { it.isNotBlank() }?.let { add(it.uppercase()) }
                    details.sni.takeIf { it.isNotBlank() }?.let { add("SNI=$it") }
                }
                if (transport.isNotEmpty()) {
                    add(transport.joinToString(" • "))
                }
            }

            is ProxyProtocolDraft.Vmess -> buildList {
                add(hostLine)
                add(text.uuidFormat.formatOne(details.uuid.ifBlank { text.unknown }))
                add("${details.network.ifBlank { "tcp" }.uppercase()} • ${details.security.ifBlank { "auto" }}")
            }

            is ProxyProtocolDraft.WireGuard -> buildList {
                add(hostLine)
                add(text.publicKeyFormat.formatOne(details.publicKey.ifBlank { text.unknown }))
                details.addresses.takeIf { it.isNotBlank() }?.let { add(text.addressFormat.formatOne(it)) }
            }

            is ProxyProtocolDraft.Socks5 -> listOf(hostLine)

            is ProxyProtocolDraft.Unknown -> listOf(hostLine)
        }
    }

    private fun syncSummaryFields(
        type: String,
        config: JsonObject,
        name: String,
        server: String,
        port: Int,
    ) {
        when (type.lowercase()) {
            "vmess" -> {
                config.addProperty("add", server)
                config.addProperty("port", port)
                config.addProperty("ps", name)
            }

            "vless" -> {
                config.addProperty("server", server)
                config.addProperty("server_port", port)
                config.addProperty("name", name)
            }

            "ss" -> {
                config.addProperty("server", server)
                config.addProperty("server_port", port)
                config.addProperty("name", name)
            }

            "wireguard" -> {
                config.arrayOrNull("peers")?.firstOrNull()?.asJsonObject?.let { peer ->
                    peer.addProperty("address", server)
                    peer.addProperty("port", port)
                }
                config.addProperty("name", name)
            }

            "socks", "socks5" -> {
                config.addProperty("server", server)
                config.addProperty("server_port", port)
                config.addProperty("name", name)
            }
        }
    }

    private fun prettyJson(rawJson: String): String {
        return runCatching {
            gson.toJson(JsonParser.parseString(rawJson))
        }.getOrElse { rawJson }
    }
}

private fun String.formatOne(value: String): String = String.format(this, value)

private fun JsonObject.stringOrEmpty(name: String): String {
    val value = get(name) ?: return ""
    if (value.isJsonNull) return ""
    return runCatching { value.asString.trim() }.getOrDefault("")
}

private fun JsonObject.boolOrFalse(name: String): Boolean {
    val value = get(name) ?: return false
    if (value.isJsonNull) return false
    return runCatching { value.asBoolean }.getOrElse {
        when (value.asString.trim().lowercase()) {
            "1", "true", "yes", "on" -> true
            else -> false
        }
    }
}

private fun JsonObject.objectOrNull(name: String): JsonObject? {
    val value = get(name) ?: return null
    return value.takeIf { it.isJsonObject }?.asJsonObject
}

private fun JsonObject.arrayOrNull(name: String): JsonArray? {
    val value = get(name) ?: return null
    return value.takeIf { it.isJsonArray }?.asJsonArray
}

private fun JsonObject.csvString(name: String): String {
    val value = get(name) ?: return ""
    return when {
        value.isJsonArray -> value.asJsonArray.joinToString(", ") { it.asString }
        value.isJsonPrimitive -> value.asString.trim()
        else -> ""
    }
}

private fun JsonObject.addCsvArray(name: String, csv: String) {
    val values = csvValues(csv)
    if (values.isNotEmpty()) {
        add(name, JsonArray().apply { values.forEach { add(it) } })
    }
}

private fun csvValues(csv: String): List<String> =
    csv.split(",")
        .map { it.trim() }
        .filter { it.isNotEmpty() }
