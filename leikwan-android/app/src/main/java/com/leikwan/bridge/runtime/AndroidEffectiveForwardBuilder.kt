package com.leikwan.bridge.runtime

/**
 * Android-effective view of a [ForwardContract]. Narrower than the raw panel contract: it only
 * only represents accepted runtime modes and pre-computes
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
    val protectedConnectivity: ProtectedConnectivitySet,
    val runtimeContract: ForwardContract,
) {
    fun toListItem(): ForwardListItem = runtimeContract.toListItem(
        methodDisplay = if (mode == ForwardMode.ULTRA) "Ultra" else "Host",
        runtimeMode = mode,
    )
}

object AndroidEffectiveForwardBuilder {
    /**
     * Accept only the current Android contract family. Raw panel fields are checked before any
     * legacy mode normalization so unknown values can never become a usable `single` forward.
     */
    fun build(contract: ForwardContract): AndroidEffectiveForward? {
        val runtimeMode = resolveRuntimeMode(contract) ?: return null
        val runtimeContract = contract.copy(mode = runtimeMode)
        val bypassHosts = bypassHosts(runtimeContract)
        val protectedConnectivity = protectedConnectivity(runtimeContract)
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
            protectedConnectivity = protectedConnectivity,
            runtimeContract = runtimeContract,
        )
    }

    private fun resolveRuntimeMode(contract: ForwardContract): ForwardMode? {
        return when (contract.backendMode.trim().lowercase()) {
            "current_mode" -> currentMode(contract)
            // Listing must not depend on the short-lived (300s) ultra lease: the forward list has to
            // be stable, and the lease is only enforced at connect time (startForForward). Otherwise
            // forwards flicker out every time the lease lapses between pulls.
            "ultra" -> ForwardMode.ULTRA
            else -> null
        }
    }

    private fun currentMode(contract: ForwardContract): ForwardMode? {
        val clientMode = contract.clientMode.trim().lowercase()
        val rawMode = contract.rawMode.trim().lowercase()
        if (rawMode.isNotEmpty() && rawMode != clientMode) return null
        return when (clientMode) {
            "single" -> ForwardMode.SINGLE
            "bond" -> ForwardMode.BOND
            else -> null
        }
    }

    private fun protectedConnectivity(contract: ForwardContract): ProtectedConnectivitySet {
        return ProtectedConnectivitySet.fromEndpoints(bypassHosts(contract))
    }

    private fun bypassHosts(contract: ForwardContract): Set<String> {
        val endpoints = linkedSetOf<String>()
        contract.endpoints.forEach { endpoint ->
            endpoints += endpoint.host
            endpoint.cdnHost?.takeIf { it.isNotBlank() }?.let(endpoints::add)
        }
        contract.sni.takeIf { it.isNotBlank() }?.let(endpoints::add)
        if (contract.mode == ForwardMode.BOND) {
            contract.bonding?.quicServerName?.takeIf { it.isNotBlank() }?.let(endpoints::add)
        }
        return endpoints
    }
}
