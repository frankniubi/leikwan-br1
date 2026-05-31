package com.leikwan.bridge.proxy

import com.google.gson.Gson
import com.google.gson.JsonArray
import com.google.gson.JsonObject
import com.google.gson.JsonParser
import com.leikwan.bridge.data.ProxyNode
import java.net.URI
import java.net.URLDecoder
import java.nio.charset.StandardCharsets
import java.util.Base64

object ProxyUriParser {
    private val gson = Gson()

    fun parse(uri: String): ProxyNode? {
        val trimmed = uri.trim()
        return when {
            trimmed.startsWith("ss://") -> parseShadowsocks(trimmed)
            trimmed.startsWith("vmess://") -> parseVmess(trimmed)
            trimmed.startsWith("vless://") -> parseVless(trimmed)
            trimmed.startsWith("wg://", ignoreCase = true) -> parseWireGuard(trimmed)
            looksLikeWireGuardConfig(trimmed) -> parseWireGuardConfig(trimmed, explicitName = null)
            else -> null
        }
    }

    fun parseMultiple(text: String): List<ProxyNode> {
        val decoded = try {
            val bytes = decodeBase64Flexible(text.trim())
            if (bytes != null) String(bytes, Charsets.UTF_8) else text
        } catch (_: Exception) {
            text
        }
        parse(decoded.trim())?.let { return listOf(it) }
        return decoded.lines()
            .map { it.trim() }
            .filter { it.isNotEmpty() }
            .mapNotNull { parse(it) }
    }

    private fun parseShadowsocks(uri: String): ProxyNode? {
        return try {
            val withoutScheme = uri.removePrefix("ss://")
            val name = withoutScheme.substringAfter("#", "").let {
                URLDecoder.decode(it, "UTF-8").ifEmpty { null }
            }
            val mainPart = withoutScheme.substringBefore("#")
            val authorityPart = mainPart.substringBefore("?")
            val queryPart = mainPart.substringAfter("?", "")

            val server: String
            val port: Int
            val method: String
            val password: String

            if (authorityPart.contains("@")) {
                val userInfo = authorityPart.substringBefore("@")
                val hostPort = authorityPart.substringAfter("@")

                val decoded = tryBase64Decode(userInfo)
                if (decoded != null && decoded.contains(":")) {
                    method = decoded.substringBefore(":")
                    password = decoded.substringAfter(":")
                } else {
                    val plaintext = URLDecoder.decode(userInfo, "UTF-8")
                    val firstColon = plaintext.indexOf(":")
                    if (firstColon < 0) return null
                    method = plaintext.substring(0, firstColon)
                    password = plaintext.substring(firstColon + 1)
                }

                val hostAndPort = parseHostPort(hostPort) ?: return null
                server = hostAndPort.first
                port = hostAndPort.second
            } else {
                val decodedBytes = decodeBase64Flexible(authorityPart) ?: return null
                val decoded = String(decodedBytes, Charsets.UTF_8)
                method = decoded.substringBefore(":")
                val rest = decoded.substringAfter(":")
                password = rest.substringBefore("@")
                val hostPort = rest.substringAfter("@")
                val hostAndPort = parseHostPort(hostPort) ?: return null
                server = hostAndPort.first
                port = hostAndPort.second
            }

            val config = JsonObject().apply {
                addProperty("method", method)
                addProperty("password", password)
            }
            applyShadowsocksQueryParams(queryPart, config)

            ProxyNode(
                name = name ?: "$server:$port",
                type = "ss",
                originalServer = server,
                originalPort = port,
                configJson = gson.toJson(config),
            )
        } catch (_: Exception) {
            null
        }
    }

    private fun tryBase64Decode(input: String): String? {
        return try {
            val bytes = decodeBase64Flexible(input) ?: return null
            val decoded = String(bytes, Charsets.UTF_8)
            if (decoded.any { it.code < 32 && it != '\n' && it != '\r' && it != '\t' }) null
            else decoded
        } catch (_: Exception) {
            null
        }
    }

    private fun decodeBase64Flexible(input: String): ByteArray? {
        val normalized = input.trim()
        val padded = normalized.padEnd(((normalized.length + 3) / 4) * 4, '=')
        val sanitized = normalized.replace('-', '+').replace('_', '/')
        val paddedSanitized = sanitized.padEnd(((sanitized.length + 3) / 4) * 4, '=')
        val candidates = listOf(normalized, padded, sanitized, paddedSanitized)
        val decoders = listOf(
            Base64.getUrlDecoder(),
            Base64.getDecoder(),
        )
        for (candidate in candidates) {
            for (decoder in decoders) {
                val decoded = runCatching { decoder.decode(candidate) }.getOrNull()
                if (decoded != null) {
                    return decoded
                }
            }
        }
        return null
    }

    private fun parseVmess(uri: String): ProxyNode? {
        return try {
            val encoded = uri.removePrefix("vmess://")
            val json = String(decodeBase64Flexible(encoded) ?: return null, Charsets.UTF_8)
            val obj = gson.fromJson(json, JsonObject::class.java)

            val server = obj.get("add")?.asString ?: return null
            val port = obj.get("port")?.asInt
                ?: obj.get("port")?.asString?.toIntOrNull()
                ?: return null
            val name = obj.get("ps")?.asString ?: "$server:$port"

            ProxyNode(
                name = name,
                type = "vmess",
                originalServer = server,
                originalPort = port,
                configJson = json,
            )
        } catch (_: Exception) {
            null
        }
    }

    private fun parseVless(uri: String): ProxyNode? {
        return try {
            val parsed = URI(uri)
            val uuid = parsed.userInfo ?: return null
            val server = parsed.host ?: return null
            val port = parsed.port.takeIf { it > 0 } ?: return null
            val name = parsed.fragment?.let {
                URLDecoder.decode(it, "UTF-8")
            } ?: "$server:$port"

            val params = mutableMapOf<String, String>()
            parsed.query?.split("&")?.forEach { pair ->
                val parts = pair.split("=", limit = 2)
                if (parts.size == 2) {
                    params[parts[0]] = URLDecoder.decode(parts[1], "UTF-8")
                }
            }

            val config = JsonObject().apply {
                addProperty("uuid", uuid)
                params.forEach { (k, v) -> addProperty(k, v) }
            }

            ProxyNode(
                name = name,
                type = "vless",
                originalServer = server,
                originalPort = port,
                configJson = gson.toJson(config),
            )
        } catch (_: Exception) {
            null
        }
    }

    private fun parseWireGuard(uri: String): ProxyNode? {
        return try {
            val withoutScheme = uri.substringAfter("://")
            val name = withoutScheme.substringAfter("#", "").let {
                URLDecoder.decode(it, "UTF-8").ifEmpty { null }
            }
            val payloadPart = withoutScheme.substringBefore("#").substringBefore("?")
            val decodedPayload = decodeBase64Flexible(urlDecode(payloadPart))?.let { String(it, Charsets.UTF_8) }
                ?: urlDecode(payloadPart)
            when {
                looksLikeWireGuardConfig(decodedPayload) -> parseWireGuardConfig(decodedPayload, explicitName = name)
                decodedPayload.trim().startsWith("{") -> parseWireGuardJson(decodedPayload, explicitName = name)
                else -> null
            }
        } catch (_: Exception) {
            null
        }
    }

    private fun parseWireGuardConfig(configText: String, explicitName: String?): ProxyNode? {
        return try {
            val (interfaceFields, peerFields) = parseWireGuardIni(configText)
            val privateKey = interfaceFields["privatekey"] ?: return null
            val addresses = splitCsv(interfaceFields["address"])
            val mtu = interfaceFields["mtu"]?.toIntOrNull()
            val firstPeer = peerFields.firstOrNull() ?: return null
            val endpoint = firstPeer["endpoint"] ?: return null
            val (server, port) = parseEndpoint(endpoint) ?: return null
            val publicKey = firstPeer["publickey"] ?: return null
            val allowedIps = splitCsv(firstPeer["allowedips"])
            val preSharedKey = firstPeer["presharedkey"]
            val keepalive = firstPeer["persistentkeepalive"]?.toIntOrNull()
            val reserved = splitCsv(firstPeer["reserved"]).mapNotNull { it.toIntOrNull() }

            val canonical = JsonObject().apply {
                addProperty("private_key", privateKey)
                if (addresses.isNotEmpty()) add("address", JsonArray().apply { addresses.forEach { add(it) } })
                mtu?.let { addProperty("mtu", it) }
                add("peers", JsonArray().apply {
                    add(JsonObject().apply {
                        addProperty("public_key", publicKey)
                        preSharedKey?.takeIf { it.isNotBlank() }?.let { addProperty("pre_shared_key", it) }
                        addProperty("address", server)
                        addProperty("port", port)
                        if (allowedIps.isNotEmpty()) {
                            add("allowed_ips", JsonArray().apply { allowedIps.forEach { add(it) } })
                        }
                        keepalive?.let { addProperty("persistent_keepalive_interval", it) }
                        if (reserved.isNotEmpty()) {
                            add("reserved", JsonArray().apply { reserved.forEach { add(it) } })
                        }
                    })
                })
            }

            ProxyNode(
                name = explicitName ?: "WireGuard $server:$port",
                type = "wireguard",
                originalServer = server,
                originalPort = port,
                configJson = gson.toJson(canonical),
            )
        } catch (_: Exception) {
            null
        }
    }

    private fun parseWireGuardJson(json: String, explicitName: String?): ProxyNode? {
        return try {
            val config = JsonParser.parseString(json).asJsonObject
            val peer = config.getAsJsonArray("peers")?.firstOrNull()?.asJsonObject ?: return null
            val server = peer.get("address")?.asString
                ?: peer.get("server")?.asString
                ?: config.get("server")?.asString
                ?: return null
            val port = peer.get("port")?.asInt
                ?: peer.get("server_port")?.asInt
                ?: config.get("server_port")?.asInt
                ?: return null
            ProxyNode(
                name = explicitName
                    ?: config.get("name")?.asString?.takeIf { it.isNotBlank() }
                    ?: "WireGuard $server:$port",
                type = "wireguard",
                originalServer = server,
                originalPort = port,
                configJson = gson.toJson(config),
            )
        } catch (_: Exception) {
            null
        }
    }

    private fun parseHostPort(hostPort: String): Pair<String, Int>? {
        val server = hostPort.substringBeforeLast(":", "")
        val port = hostPort.substringAfterLast(":", "").toIntOrNull()
        if (server.isBlank() || port == null) return null
        return server to port
    }

    private fun parseEndpoint(endpoint: String): Pair<String, Int>? {
        val trimmed = endpoint.trim()
        if (trimmed.startsWith("[")) {
            val host = trimmed.substringAfter("[").substringBefore("]")
            val port = trimmed.substringAfter("]:", "").toIntOrNull()
            if (host.isBlank() || port == null) return null
            return host to port
        }
        return parseHostPort(trimmed)
    }

    private fun looksLikeWireGuardConfig(value: String): Boolean {
        val trimmed = value.trimStart()
        return trimmed.startsWith("[Interface]", ignoreCase = true) &&
            trimmed.contains("[Peer]", ignoreCase = true)
    }

    private fun parseWireGuardIni(configText: String): Pair<Map<String, String>, List<Map<String, String>>> {
        val interfaceFields = linkedMapOf<String, String>()
        val peers = mutableListOf<MutableMap<String, String>>()
        var currentSection = ""
        var currentPeer: MutableMap<String, String>? = null

        configText.lines().forEach { rawLine ->
            val line = rawLine.substringBefore("#").substringBefore(";").trim()
            if (line.isBlank()) return@forEach
            if (line.startsWith("[") && line.endsWith("]")) {
                currentSection = line.removePrefix("[").removeSuffix("]").trim().lowercase()
                if (currentSection == "peer") {
                    currentPeer = linkedMapOf<String, String>().also { peers += it }
                }
                return@forEach
            }
            val key = line.substringBefore("=", "").trim().lowercase().replace("_", "")
            val value = line.substringAfter("=", "").trim()
            if (key.isBlank() || value.isBlank()) return@forEach
            when (currentSection) {
                "interface" -> interfaceFields[key] = value
                "peer" -> currentPeer?.set(key, value)
            }
        }
        return interfaceFields to peers
    }

    private fun splitCsv(value: String?): List<String> =
        value.orEmpty()
            .split(",")
            .map { it.trim() }
            .filter { it.isNotEmpty() }

    private fun applyShadowsocksQueryParams(query: String, config: JsonObject) {
        if (query.isBlank()) return
        query.split("&")
            .filter { it.isNotBlank() }
            .forEach { pair ->
                val parts = pair.split("=", limit = 2)
                val key = urlDecode(parts[0]).trim()
                val value = urlDecode(parts.getOrElse(1) { "" }).trim()
                when (key) {
                    "plugin" -> {
                        val pluginName = value.substringBefore(";").trim()
                        val pluginOpts = value.substringAfter(";", "").trim()
                        if (pluginName.isNotBlank()) {
                            config.addProperty("plugin", pluginName)
                        }
                        if (pluginOpts.isNotBlank()) {
                            config.addProperty("plugin_opts", pluginOpts)
                        }
                    }
                    "plugin_opts", "network" -> {
                        if (value.isNotBlank()) config.addProperty(key, value)
                    }
                    "uot", "udp_over_tcp" -> {
                        config.addProperty("udp_over_tcp", truthy(value))
                    }
                    "multiplex" -> {
                        config.addProperty("multiplex_enabled", truthy(value))
                    }
                    else -> if (value.isNotBlank()) {
                        config.addProperty(key, value)
                    }
                }
            }
    }

    private fun urlDecode(value: String): String {
        return URLDecoder.decode(value, StandardCharsets.UTF_8.name())
    }

    private fun truthy(value: String): Boolean {
        return when (value.lowercase()) {
            "1", "true", "yes", "on" -> true
            else -> false
        }
    }
}
