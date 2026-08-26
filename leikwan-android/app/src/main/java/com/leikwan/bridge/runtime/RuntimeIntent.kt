package com.leikwan.bridge.runtime

/**
 * Orchestration input describing what should be launched for one forward. Structurally mirrors
 * [RuntimeContext] for now; later tasks can move the orchestrator/config builders onto this seam
 * without changing the runner command generation in this slice.
 */
data class RuntimeIntent(
    val forwardId: Long,
    val mode: ForwardMode,
    val localListenPort: Int,
    val localLegPorts: List<Int>,
    val processPlans: List<RuntimeProcessPlan>,
    val bypassHosts: Set<String>,
    val protectedConnectivity: ProtectedConnectivitySet = ProtectedConnectivitySet.fromEndpoints(bypassHosts),
    val nodeLegs: Map<Long, NodeLeg> = emptyMap(),
)

fun RuntimeContext.toRuntimeIntent(): RuntimeIntent {
    return RuntimeIntent(
        forwardId = forwardId,
        mode = mode,
        localListenPort = localListenPort,
        localLegPorts = localLegPorts,
        processPlans = processPlans,
        bypassHosts = bypassHosts,
        protectedConnectivity = protectedConnectivity,
        nodeLegs = nodeLegs,
    )
}
