package com.leikwan.bridge.data.hosted

import com.google.gson.JsonObject
import com.google.gson.JsonParser
import com.leikwan.bridge.data.ProxyNode

/**
 * Connection identity of a node, independent of its name and of how the share link spelled it.
 *
 * Used for dedup in both directions:
 *  - cloud -> local: a hosted node that equals a node already imported on this device adopts that
 *    row (keeps its id, so the forward pairing and selected node stay valid) instead of adding a
 *    second copy;
 *  - local imports: a pasted link or subscription entry that equals a hosted node is skipped.
 *
 * The panel dedups uploads separately with its own keyed fingerprint, so an upload of the same node
 * from two devices always maps to one hosted id.
 *
 * TLS cosmetics (uTLS fingerprint, ALPN, allowInsecure) are not part of the identity: the panel fills
 * defaults for them, and they do not change which server and account a node reaches.
 */
object HostedNodeIdentity {
    /** Local ProxyNode.type values that the platform can host. */
    val UPLOADABLE_TYPES = setOf("ss", "socks", "socks5", "vless")

    fun isUploadable(node: ProxyNode): Boolean = node.type.lowercase() in UPLOADABLE_TYPES

    /** Null for protocols the platform does not host (vmess, wireguard, ...). */
    fun key(node: ProxyNode): String? {
        val type = when (node.type.lowercase()) {
            "ss" -> "ss"
            "socks", "socks5" -> "socks5"
            "vless" -> "vless"
            else -> return null
        }
        val config = runCatching { JsonParser.parseString(node.configJson).asJsonObject }.getOrNull() ?: JsonObject()
        val server = node.originalServer.trim().removeSurrounding("[", "]").lowercase()
        val parts = mutableListOf(type, server, node.originalPort.toString())
        when (type) {
            "ss" -> {
                val plugin = config.str("plugin").lowercase().let { if (it == "simple-obfs") "obfs-local" else it }
                parts += config.str("method").lowercase()
                parts += config.str("password")
                parts += plugin
                parts += if (plugin.isEmpty()) "" else config.str("plugin_opts")
            }
            "socks5" -> {
                parts += config.str("username")
                parts += config.str("password")
            }
            "vless" -> {
                val security = config.str("security").lowercase().ifEmpty { "none" }
                val transport = config.str("type").lowercase().let { if (it.isEmpty() || it == "raw" || it == "none") "tcp" else it }
                parts += config.str("uuid").lowercase()
                parts += config.str("flow").lowercase()
                parts += security
                parts += if (security == "none") "" else config.str("sni").lowercase()
                parts += if (security == "reality") config.str("pbk") else ""
                parts += if (security == "reality") config.str("sid").lowercase() else ""
                parts += transport
                when (transport) {
                    "ws", "httpupgrade" -> {
                        parts += config.str("path").ifEmpty { "/" }
                        parts += config.str("host").lowercase()
                    }
                    "grpc" -> parts += config.str("serviceName")
                }
            }
        }
        return parts.joinToString("\u0000")
    }

    /** Drops nodes that equal an already hosted node, so an import never duplicates a cloud node. */
    fun withoutHostedDuplicates(incoming: List<ProxyNode>, hosted: List<ProxyNode>): List<ProxyNode> {
        if (hosted.isEmpty() || incoming.isEmpty()) return incoming
        val hostedKeys = hosted.mapNotNullTo(mutableSetOf()) { key(it) }
        return incoming.filter { node -> key(node)?.let { it !in hostedKeys } ?: true }
    }

    private fun JsonObject.str(name: String): String =
        get(name)?.takeIf { it.isJsonPrimitive }?.asString?.trim().orEmpty()
}
