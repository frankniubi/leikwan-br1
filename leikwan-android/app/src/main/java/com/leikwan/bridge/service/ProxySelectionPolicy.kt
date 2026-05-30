package com.leikwan.bridge.service

import com.leikwan.bridge.data.ProxyNode

sealed interface ProxySelectionResult {
    data class Success(val selectedNode: ProxyNode) : ProxySelectionResult
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
        if (
            connectionMode == ProxyConnectionMode.SERVICES &&
            selectedNode.forwardId != null &&
            selectedNode.forwardId != forwardId
        ) {
            return ProxySelectionResult.Error("Selected proxy does not match the chosen service")
        }
        return ProxySelectionResult.Success(selectedNode)
    }
}
