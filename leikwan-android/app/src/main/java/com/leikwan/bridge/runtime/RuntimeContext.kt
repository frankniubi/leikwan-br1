package com.leikwan.bridge.runtime

enum class RuntimeProcessRole {
    SINGLE,
    INGRESS,
    BOND,
    ULTRA,
}

data class RuntimeProcessPlan(
    val role: RuntimeProcessRole,
    val command: List<String>,
    val readyPort: Int? = null,
    /**
     * Exit codes that mean "configuration/validation error": a guard that sees one of them on
     * repeated fast exits stops restarting. Any other fast exit is retried with bounded backoff.
     */
    val permanentExitCodes: Set<Int> = emptySet(),
)

/** ultra-client exits through Go's log.Fatal (status 1) only on invalid configuration. */
val ULTRA_PERMANENT_EXIT_CODES: Set<Int> = setOf(1)

data class RuntimeBinaryPaths(
    val wstunnelBinary: String?,
    val ingressBinary: String?,
    val bondBinary: String?,
    val ultraBinary: String? = null,
)

data class NodeLeg(
    val nodeId: Long,
    val forwardId: Long,
    val processKey: Long,
    val mode: ForwardMode,
    val localListenPort: Int,
    val localLegPorts: List<Int>,
    val processPlans: List<RuntimeProcessPlan>,
    val bypassHosts: Set<String>,
    val protectedConnectivity: ProtectedConnectivitySet = ProtectedConnectivitySet.fromEndpoints(bypassHosts),
) {
    companion object {
        fun processKey(forwardId: Long, nodeId: Long): Long = forwardId * 100_000L + (nodeId % 100_000L)
    }

    fun toRuntimeContext(): RuntimeContext = RuntimeContext(
        forwardId = forwardId,
        mode = mode,
        localListenPort = localListenPort,
        localLegPorts = localLegPorts,
        processPlans = processPlans,
        bypassHosts = bypassHosts,
        protectedConnectivity = protectedConnectivity,
    )
}

data class RuntimeContext(
    val forwardId: Long,
    val mode: ForwardMode,
    val localListenPort: Int,
    val localLegPorts: List<Int>,
    val processPlans: List<RuntimeProcessPlan>,
    val bypassHosts: Set<String>,
    val protectedConnectivity: ProtectedConnectivitySet = ProtectedConnectivitySet.fromEndpoints(bypassHosts),
    val nodeLegs: Map<Long, NodeLeg> = emptyMap(),
    val routeRuleProcessKey: Long? = null,
    /** App-private runtime files that must disappear when a forward stops or fails to start. */
    val privateRuntimePaths: List<String> = emptyList(),
) {
    fun relayListenPortForNode(nodeId: Long): Int? =
        nodeLegs[nodeId]?.localListenPort ?: localListenPort

    fun allBypassHosts(): Set<String> =
        if (nodeLegs.isEmpty()) {
            bypassHosts
        } else {
            linkedSetOf<String>().apply {
                addAll(bypassHosts)
                nodeLegs.values.forEach { addAll(it.bypassHosts) }
            }
        }

    fun allProtectedConnectivity(): ProtectedConnectivitySet {
        var combined = protectedConnectivity.merge(ProtectedConnectivitySet.fromEndpoints(bypassHosts))
        nodeLegs.values.forEach { leg ->
            combined = combined
                .merge(leg.protectedConnectivity)
                .merge(ProtectedConnectivitySet.fromEndpoints(leg.bypassHosts))
        }
        return combined
    }
}
