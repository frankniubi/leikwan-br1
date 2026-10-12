package com.leikwan.bridge.ui.proxies

import com.leikwan.bridge.data.ProxyNode

object ProxyNodeDuplicator {
    fun duplicate(node: ProxyNode): ProxyNode {
        return node.copy(
            id = 0,
            name = "${node.name} Copy",
            createdAt = System.currentTimeMillis(),
            // A copy is the user's own local node, never a second link to the hosted one.
            hostedId = null,
        )
    }
}
