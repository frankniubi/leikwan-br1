package com.leikwan.bridge.runtime

/**
 * Android-effective view of a [ForwardContract]. Narrower than the raw panel contract: it only
 * ever represents the runtime modes Android can actually run (`single` / `bond`) and pre-computes
 * the set of slave/sidecar hosts that must stay `direct` (the runtime reachability bypass set).
 */
data class AndroidEffectiveForward(
    val forwardId: Long,
    val serviceName: String,
    val target: String,
    val listenPort: Int,
    val mode: ForwardMode,
    val transport: String,
    val endpoints: List<ForwardEndpoint>,
    val bonding: BondingContract?,
    val bypassHosts: Set<String>,
    val runtimeContract: ForwardContract,
) {
    val isDedicatedLeiGod: Boolean
        get() = runtimeContract.targetContract.equals("dedicated_forward_node", ignoreCase = true) &&
            !runtimeContract.sidecarRequired &&
            !runtimeContract.aggregationEnabled

    fun toListItem(): ForwardListItem = runtimeContract.toListItem(
        methodDisplay = "LeiGod",
        runtimeMode = mode,
    )
}

object AndroidEffectiveForwardBuilder {
    /**
     * Returns the Android-effective forward, or `null` for runtime modes Android does not support.
     * Canonical LeiGod contracts are interpreted before the legacy `mode` field: dedicated LeiGod
     * stays sidecar-less `single`, while sidecar aggregation uses Android's bond runtime, including
     * `mptcp_preferred_bond_fallback` contracts.
     */
    fun build(contract: ForwardContract): AndroidEffectiveForward? {
        val runtimeMode = resolveRuntimeMode(contract) ?: return null
        val runtimeContract = contract.copy(mode = runtimeMode)
        val bypassHosts = bypassHosts(runtimeContract)
        return AndroidEffectiveForward(
            forwardId = runtimeContract.forwardId,
            serviceName = runtimeContract.serviceName,
            target = runtimeContract.target,
            listenPort = runtimeContract.listenPort,
            mode = runtimeMode,
            transport = runtimeContract.transport,
            endpoints = runtimeContract.endpoints,
            bonding = runtimeContract.bonding,
            bypassHosts = bypassHosts,
            runtimeContract = runtimeContract,
        )
    }

    private fun resolveRuntimeMode(contract: ForwardContract): ForwardMode? {
        if (!hasCanonicalContract(contract)) {
            return contract.mode.takeIf { it == ForwardMode.SINGLE || it == ForwardMode.BOND }
        }

        if (isSidecarAggregation(contract)) {
            return ForwardMode.BOND.takeIf { hasUsableBondAggregation(contract) }
        }

        if (isDedicatedLeiGod(contract)) {
            return ForwardMode.SINGLE.takeIf {
                !contract.sidecarRequired &&
                    !contract.aggregationEnabled &&
                    contract.target.isNotBlank() &&
                    contract.endpoints.isNotEmpty()
            }
        }

        val clientMode = normalizeMode(contract.clientMode) ?: contract.mode
        return clientMode.takeIf { it == ForwardMode.SINGLE || it == ForwardMode.BOND }
    }

    private fun hasCanonicalContract(contract: ForwardContract): Boolean {
        return contract.contractVersion > 0 ||
            contract.clientMode.isNotBlank() ||
            contract.runtimePreference.isNotBlank() ||
            contract.activeFamily.isNotBlank() ||
            contract.targetContract.isNotBlank() ||
            contract.aggregationEnabled ||
            contract.sidecarRequired ||
            contract.bondSidecarPointId != null ||
            SlaveSidecarIngressContract.hasAggregationPlacement(contract.bonding)
    }

    private fun isDedicatedLeiGod(contract: ForwardContract): Boolean {
        return contract.backendMode.equals("leigod_mode", ignoreCase = true) &&
            (contract.activeFamily.equals("leigod_only", ignoreCase = true) ||
                contract.targetContract.equals("dedicated_forward_node", ignoreCase = true))
    }

    private fun isSidecarAggregation(contract: ForwardContract): Boolean {
        val clientMode = normalizeMode(contract.clientMode) ?: contract.mode
        return SlaveSidecarIngressContract.hasAggregationPlacement(contract.bonding) ||
            contract.activeFamily.equals("sidecar_aggregation", ignoreCase = true) ||
            contract.targetContract.equals("sidecar_aggregation", ignoreCase = true) ||
            contract.runtimePreference.equals("mptcp_preferred_bond_fallback", ignoreCase = true) ||
            contract.aggregationEnabled ||
            contract.sidecarRequired ||
            clientMode == ForwardMode.BOND ||
            clientMode == ForwardMode.MPTCP
    }

    private fun hasUsableBondAggregation(contract: ForwardContract): Boolean {
        val bonding = contract.bonding ?: return false
        if (SlaveSidecarIngressContract.hasAggregationPlacement(bonding)) {
            return SlaveSidecarIngressContract.resolve(contract) != null
        }
        if (!contract.sidecarRequired || !contract.aggregationEnabled) return false
        if (contract.bondSidecarPointId == null) return false
        if (contract.endpoints.isEmpty()) return false
        val ingressMode = bonding.ingressMode?.ifBlank { null } ?: "wss_path_mux"
        return if (ingressMode == "plain_tcp_mux") {
            !bonding.routeKey.isNullOrBlank() &&
                contract.endpoints.any { !it.ingressAuthToken.isNullOrBlank() }
        } else {
            !bonding.remoteTarget.isNullOrBlank()
        }
    }

    private fun normalizeMode(value: String): ForwardMode? {
        val normalized = value.trim().lowercase().takeIf { it.isNotEmpty() } ?: return null
        return ForwardMode.normalize(normalized)
    }

    private fun bypassHosts(contract: ForwardContract): Set<String> {
        val hosts = linkedSetOf<String>()
        contract.endpoints.forEach { endpoint ->
            endpoint.host.takeIf { it.isNotBlank() }?.let { hosts += it }
            endpoint.cdnHost?.takeIf { it.isNotBlank() }?.let { hosts += it }
        }
        contract.sni.takeIf { it.isNotBlank() }?.let { hosts += it }
        if (contract.mode == ForwardMode.BOND) {
            contract.bonding?.quicServerName?.takeIf { it.isNotBlank() }?.let { hosts += it }
            contract.mptcp?.sidecarHost?.takeIf { it.isNotBlank() }?.let { hosts += it }
        }
        return hosts
    }
}
