package com.leikwan.bridge.runtime

import com.leikwan.bridge.clientrules.ClientRouteRuleSnapshot

data class ClientRuleSingboxBinding(
    val directionId: String,
    val tcpOutboundTag: String,
    val udpOutboundTag: String,
    val localTcpPort: Int,
    val localUdpPort: Int,
)

data class RouteDirectionRuntimePlan(
    val runtimeContexts: List<RuntimeContext>,
    val singboxBindings: List<ClientRuleSingboxBinding>,
    val bypassHosts: Set<String>,
)

object RouteDirectionRuntimePlanner {
    private const val ROUTE_RULE_PROCESS_KEY_BASE = 9_000_000_000L
    private const val ROUTE_RULE_PROCESS_KEY_RANGE = 999_999_999L

    fun processKey(directionId: String): Long {
        var hash = 1_125_899_906_842_597L
        directionId.trim().forEach { ch -> hash = 31L * hash + ch.code.toLong() }
        return ROUTE_RULE_PROCESS_KEY_BASE + ((hash and Long.MAX_VALUE) % ROUTE_RULE_PROCESS_KEY_RANGE)
    }

    fun plan(
        snapshot: ClientRouteRuleSnapshot,
        forwardContractsById: Map<Long, ForwardContract>,
        buildRuntimeContext: (directionId: String, forward: ForwardContract, listenPort: Int) -> RuntimeContext,
    ): RouteDirectionRuntimePlan {
        val activeDirectionIds = snapshot.rules
            .filter { it.installable }
            .map { it.directionId }
            .distinct()
        val runtimeContexts = mutableListOf<RuntimeContext>()
        val singboxBindings = mutableListOf<ClientRuleSingboxBinding>()
        val bypassHosts = linkedSetOf<String>()

        activeDirectionIds.forEach { directionId ->
            val binding = snapshot.bindingFor(directionId) ?: return@forEach
            if (!binding.readyForAndroid) return@forEach
            val forwardId = binding.runtimeForwardId ?: return@forEach
            val forward = forwardContractsById[forwardId] ?: return@forEach
            val runtimeContext = buildRuntimeContext(directionId, forward, binding.localTcpPort)
            runtimeContexts += runtimeContext
            bypassHosts += runtimeContext.allBypassHosts()
            singboxBindings += ClientRuleSingboxBinding(
                directionId = directionId,
                tcpOutboundTag = "route-direction-$directionId-tcp",
                udpOutboundTag = "route-direction-$directionId-udp",
                localTcpPort = binding.localTcpPort,
                localUdpPort = binding.localUdpPort,
            )
        }

        return RouteDirectionRuntimePlan(runtimeContexts, singboxBindings, bypassHosts)
    }
}
