package com.leikwan.bridge.proxy

import android.util.Base64
import com.google.gson.Gson
import com.google.gson.JsonObject
import com.leikwan.bridge.data.ProxyNode
import java.net.URI
import java.net.URLDecoder

object ProxyUriParser {
    private val gson = Gson()

    fun parse(uri: String): ProxyNode? {
        val trimmed = uri.trim()
        return when {
            trimmed.startsWith("ss://") -> parseShadowsocks(trimmed)
            trimmed.startsWith("vmess://") -> parseVmess(trimmed)
            trimmed.startsWith("vless://") -> parseVless(trimmed)
            else -> null
        }
    }

    fun parseMultiple(text: String): List<ProxyNode> {
        val decoded = try {
            String(Base64.decode(text.trim(), Base64.DEFAULT))
        } catch (_: Exception) {
            text
        }
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

            val server: String
            val port: Int
            val method: String
            val password: String

            if (mainPart.contains("@")) {
                val userInfo = mainPart.substringBefore("@")
                val hostPort = mainPart.substringAfter("@")

                val decoded = tryBase64Decode(userInfo)
                if (decoded != null && decoded.contains(":") && !decoded.startsWith("2022-")) {
                    method = decoded.substringBefore(":")
                    password = decoded.substringAfter(":")
                } else {
                    val plaintext = URLDecoder.decode(userInfo, "UTF-8")
                    val firstColon = plaintext.indexOf(":")
                    if (firstColon < 0) return null
                    method = plaintext.substring(0, firstColon)
                    password = plaintext.substring(firstColon + 1)
                }

                server = hostPort.substringBeforeLast(":")
                port = hostPort.substringAfterLast(":").toInt()
            } else {
                val decoded = String(Base64.decode(mainPart, Base64.URL_SAFE or Base64.NO_PADDING or Base64.NO_WRAP))
                method = decoded.substringBefore(":")
                val rest = decoded.substringAfter(":")
                password = rest.substringBefore("@")
                val hostPort = rest.substringAfter("@")
                server = hostPort.substringBeforeLast(":")
                port = hostPort.substringAfterLast(":").toInt()
            }

            val config = JsonObject().apply {
                addProperty("method", method)
                addProperty("password", password)
            }

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
            val bytes = Base64.decode(input, Base64.URL_SAFE or Base64.NO_PADDING or Base64.NO_WRAP)
            val decoded = String(bytes, Charsets.UTF_8)
            if (decoded.any { it.code < 32 && it != '\n' && it != '\r' && it != '\t' }) null
            else decoded
        } catch (_: Exception) {
            null
        }
    }

    private fun parseVmess(uri: String): ProxyNode? {
        return try {
            val encoded = uri.removePrefix("vmess://")
            val json = String(Base64.decode(encoded, Base64.DEFAULT))
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
}
