package com.leikwan.bridge.proxy

import com.leikwan.bridge.data.ProxyNode

object ImportRouter {

    sealed class ImportResult {
        data class ProxyNodes(val nodes: List<ProxyNode>) : ImportResult()
        data class SubscriptionUrl(val url: String) : ImportResult()
        data class Mixed(val nodes: List<ProxyNode>, val subscriptionUrls: List<String>) : ImportResult()
        data class Empty(val rawInput: String) : ImportResult()
    }

    private val proxySchemes = listOf("ss://", "vmess://", "vless://")

    fun process(text: String): ImportResult {
        val trimmed = text.trim()
        if (trimmed.isEmpty()) return ImportResult.Empty(text)

        val nodes = mutableListOf<ProxyNode>()
        val subUrls = mutableListOf<String>()

        val lines = trimmed.lines().map { it.trim() }.filter { it.isNotEmpty() }

        for (line in lines) {
            when {
                proxySchemes.any { line.startsWith(it, ignoreCase = true) } -> {
                    ProxyUriParser.parse(line)?.let { nodes.add(it) }
                }
                line.startsWith("http://", ignoreCase = true) ||
                    line.startsWith("https://", ignoreCase = true) -> {
                    subUrls.add(line)
                }
            }
        }

        // If nothing matched line-by-line, try base64 decode of the whole input
        if (nodes.isEmpty() && subUrls.isEmpty()) {
            val decoded = ProxyUriParser.parseMultiple(trimmed)
            if (decoded.isNotEmpty()) {
                return ImportResult.ProxyNodes(decoded)
            }
        }

        return when {
            nodes.isNotEmpty() && subUrls.isNotEmpty() -> ImportResult.Mixed(nodes, subUrls)
            nodes.isNotEmpty() -> ImportResult.ProxyNodes(nodes)
            subUrls.isNotEmpty() -> ImportResult.SubscriptionUrl(subUrls.first())
            else -> ImportResult.Empty(text)
        }
    }

    fun looksImportable(text: String): Boolean {
        val trimmed = text.trim()
        return proxySchemes.any { trimmed.startsWith(it, ignoreCase = true) } ||
            trimmed.startsWith("http://", ignoreCase = true) ||
            trimmed.startsWith("https://", ignoreCase = true)
    }
}
