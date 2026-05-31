package com.leikwan.bridge.ui.proxies

import com.leikwan.bridge.data.ProxyNode

data class ProxyListSection(
    val title: String,
    val nodes: List<ProxyNode>,
)

object ProxyListSections {
    private val preferredOrder = linkedMapOf(
        "ss" to "Shadowsocks",
        "vless" to "VLESS",
        "vmess" to "VMess",
        "wireguard" to "WireGuard",
    )

    fun build(nodes: List<ProxyNode>): List<ProxyListSection> {
        if (nodes.isEmpty()) return emptyList()
        val grouped = linkedMapOf<String, MutableList<ProxyNode>>()
        nodes.forEach { node ->
            grouped.getOrPut(node.type.lowercase()) { mutableListOf() }.add(node)
        }

        val sections = mutableListOf<ProxyListSection>()
        preferredOrder.forEach { (type, title) ->
            val items = grouped.remove(type)
            if (!items.isNullOrEmpty()) {
                sections += ProxyListSection(title = title, nodes = items.toList())
            }
        }

        grouped
            .toSortedMap()
            .forEach { (_, items) ->
                if (items.isNotEmpty()) {
                    sections += ProxyListSection(title = "Other", nodes = items.toList())
                }
            }

        return sections
    }
}
