package com.leikwan.bridge.service

import com.leikwan.bridge.data.ProxyNode

sealed interface ProxySelectionResult {
    data class Success(
        val selectedNode: ProxyNode,
        val activeNodes: List<ProxyNode> = listOf(selectedNode),
    ) : ProxySelectionResult
    data class Error(val message: String) : ProxySelectionResult
}

object ProxySelectionPolicy {
    fun validate(
        selectedProxyId: Long,
        forwardId: Long,
        connectionMode: ProxyConnectionMode,
        proxyNodes: List<ProxyNode>,
    ): ProxySelectionResult {
        if (selectedProxyId == 0L) {
            return ProxySelectionResult.Error("Select a proxy before starting this service")
        }
        val selectedNode = proxyNodes.firstOrNull { it.id == selectedProxyId }
            ?: return ProxySelectionResult.Error("Selected proxy no longer exists")
        if (!selectedNode.enabled) {
            return ProxySelectionResult.Error("Selected proxy is disabled")
        }
        val activeNodes = if (connectionMode == ProxyConnectionMode.SERVICES) {
            proxyNodes.filter { node -> node.enabled }.ifEmpty { listOf(selectedNode) }
        } else {
            proxyNodes.filter { it.enabled }.ifEmpty { listOf(selectedNode) }
        }
        return ProxySelectionResult.Success(selectedNode, activeNodes)
    }
}
