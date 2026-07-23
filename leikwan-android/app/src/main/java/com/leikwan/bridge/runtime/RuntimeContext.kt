package com.leikwan.bridge.runtime

enum class RuntimeProcessRole {
    SINGLE,
    INGRESS,
    BOND,
}

data class RuntimeProcessPlan(
    val role: RuntimeProcessRole,
    val command: List<String>,
    val readyPort: Int? = null,
)

data class RuntimeBinaryPaths(
    val wstunnelBinary: String?,
    val ingressBinary: String?,
    val bondBinary: String?,
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
    )
}

data class RuntimeContext(
    val forwardId: Long,
    val mode: ForwardMode,
    val localListenPort: Int,
    val localLegPorts: List<Int>,
    val processPlans: List<RuntimeProcessPlan>,
    val bypassHosts: Set<String>,
    val nodeLegs: Map<Long, NodeLeg> = emptyMap(),
    val routeRuleProcessKey: Long? = null,
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
}
