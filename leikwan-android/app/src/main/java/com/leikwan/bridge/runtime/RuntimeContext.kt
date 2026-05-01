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

data class RuntimeContext(
    val forwardId: Long,
    val mode: ForwardMode,
    val localListenPort: Int,
    val localLegPorts: List<Int>,
    val processPlans: List<RuntimeProcessPlan>,
    val bypassHosts: Set<String>,
)
