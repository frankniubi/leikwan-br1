package com.leikwan.bridge.proxy

import com.google.gson.JsonObject
import com.google.gson.JsonParser
import com.leikwan.bridge.data.ProxyNode
import java.net.URLEncoder
import java.nio.charset.StandardCharsets
import java.util.Base64

/**
 * Renders a local node as a share link, the format the panel accepts for hosted nodes. Inverse of
 * [ProxyUriParser] for the protocols the platform hosts (Shadowsocks, SOCKS5, VLESS); null for any
 * other protocol.
 */
object ProxyShareUri {
    fun fromNode(node: ProxyNode): String? {
        val config = runCatching { JsonParser.parseString(node.configJson).asJsonObject }.getOrNull() ?: JsonObject()
        val host = node.originalServer.trim().removeSurrounding("[", "]").let { if (it.contains(':')) "[$it]" else it }
        if (host.isEmpty() || node.originalPort !in 1..65535) return null
        val authority = "$host:${node.originalPort}"
        val fragment = "#" + encode(node.name)
        return when (node.type.lowercase()) {
            "ss" -> {
                val method = config.str("method")
                val password = config.raw("password")
                if (method.isEmpty() || password.isEmpty()) return null
                val userInfo = Base64.getUrlEncoder().withoutPadding()
                    .encodeToString("$method:$password".toByteArray(StandardCharsets.UTF_8))
                val query = mutableListOf<String>()
                val plugin = config.str("plugin")
                if (plugin.isNotEmpty()) {
                    val opts = config.str("plugin_opts")
                    query += "plugin=" + encode(if (opts.isEmpty()) plugin else "$plugin;$opts")
                }
                val uot = config.get("udp_over_tcp")
                if (uot != null && uot.isJsonPrimitive && runCatching { uot.asBoolean }.getOrDefault(false)) {
                    query += "uot=1"
                }
                "ss://$userInfo@$authority" + (if (query.isEmpty()) "" else "?" + query.joinToString("&")) + fragment
            }
            "socks", "socks5" -> {
                val username = config.raw("username")
                val password = config.raw("password")
                val auth = if (username.isNotEmpty()) "${encode(username)}:${encode(password)}@" else ""
                "socks5://$auth$authority$fragment"
            }
            "vless" -> {
                val uuid = config.str("uuid")
                if (uuid.isEmpty()) return null
                val query = config.entrySet()
                    .filter { (key, value) -> key != "uuid" && value.isJsonPrimitive && value.asString.isNotEmpty() }
                    .sortedBy { it.key }
                    .joinToString("&") { (key, value) -> encode(key) + "=" + encode(value.asString) }
                "vless://${encode(uuid)}@$authority" + (if (query.isEmpty()) "" else "?$query") + fragment
            }
            else -> null
        }
    }

    private fun encode(value: String): String =
        URLEncoder.encode(value, StandardCharsets.UTF_8.name()).replace("+", "%20")

    private fun JsonObject.str(name: String): String = raw(name).trim()

    /** Credentials are passed through untrimmed. */
    private fun JsonObject.raw(name: String): String =
        get(name)?.takeIf { it.isJsonPrimitive }?.asString.orEmpty()
}
