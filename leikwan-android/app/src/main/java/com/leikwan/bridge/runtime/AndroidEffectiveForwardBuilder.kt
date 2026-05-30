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
)

object AndroidEffectiveForwardBuilder {
    /**
     * Returns the Android-effective forward, or `null` for runtime modes Android does not support
     * (`race` / `mptcp`). `bypassHosts` mirrors what the single/bond runners compute today:
     * every endpoint host + cdnHost, the contract SNI, and (for bond) the bonding quicServerName.
     */
    fun build(contract: ForwardContract): AndroidEffectiveForward? {
        if (contract.mode != ForwardMode.SINGLE && contract.mode != ForwardMode.BOND) {
            return null
        }
        val bypassHosts = linkedSetOf<String>()
        contract.endpoints.forEach { endpoint ->
            endpoint.host.takeIf { it.isNotBlank() }?.let { bypassHosts += it }
            endpoint.cdnHost?.takeIf { it.isNotBlank() }?.let { bypassHosts += it }
        }
        contract.sni.takeIf { it.isNotBlank() }?.let { bypassHosts += it }
        if (contract.mode == ForwardMode.BOND) {
            contract.bonding?.quicServerName?.takeIf { it.isNotBlank() }?.let { bypassHosts += it }
        }
        return AndroidEffectiveForward(
            forwardId = contract.forwardId,
            serviceName = contract.serviceName,
            target = contract.target,
            listenPort = contract.listenPort,
            mode = contract.mode,
            transport = contract.transport,
            endpoints = contract.endpoints,
            bonding = contract.bonding,
            bypassHosts = bypassHosts,
        )
    }
}
