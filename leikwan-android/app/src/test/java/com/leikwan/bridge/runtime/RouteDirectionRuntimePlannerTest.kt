package com.leikwan.bridge.runtime

import com.leikwan.bridge.clientrules.ClientRouteRule
import com.leikwan.bridge.clientrules.ClientRouteRuleSnapshot
import com.leikwan.bridge.clientrules.ClientRuleEffectiveState
import com.leikwan.bridge.clientrules.DirectionRuntimeBinding
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Test

class RouteDirectionRuntimePlannerTest {
    @Test
    fun `planner starts one helper runtime per active direction and exposes sing-box bindings`() {
        val snapshot = ClientRouteRuleSnapshot(
            rules = listOf(
                routeRule("rule_1", "dir_sh_jp_1", ClientRuleEffectiveState.ACTIVE),
                routeRule("rule_2", "dir_sh_jp_1", ClientRuleEffectiveState.ACTIVE),
                routeRule("rule_3", "dir_sh_hk_1", ClientRuleEffectiveState.RUNTIME_MISSING),
            ),
            bindings = listOf(
                DirectionRuntimeBinding(
                    serviceScopeId = "svc_scope_7",
                    directionId = "dir_sh_jp_1",
                    bindingState = "ready",
                    clientRuntimeBindingId = "binding_1",
                    localTcpPort = 12001,
                    localUdpPort = 12001,
                    runtimeForwardId = 77L,
                    selectable = false,
                    transport = "tcp_udp",
                ),
                DirectionRuntimeBinding(
                    serviceScopeId = "svc_scope_7",
                    directionId = "dir_sh_hk_1",
                    bindingState = "missing",
                    clientRuntimeBindingId = "binding_2",
                    localTcpPort = 0,
                    localUdpPort = 0,
                    runtimeForwardId = null,
                    selectable = false,
                    transport = "tcp_udp",
                ),
            ),
        )
        val helperForward = helperForward()

        val plan = RouteDirectionRuntimePlanner.plan(
            snapshot = snapshot,
            forwardContractsById = mapOf(77L to helperForward),
            buildRuntimeContext = { directionId, forward, listenPort ->
                RuntimeContext(
                    forwardId = forward.forwardId,
                    mode = forward.mode,
                    localListenPort = listenPort,
                    localLegPorts = emptyList(),
                    processPlans = emptyList(),
                    bypassHosts = AndroidEffectiveForwardBuilder.build(forward)!!.bypassHosts,
                    routeRuleProcessKey = RouteDirectionRuntimePlanner.processKey(directionId),
                )
            },
        )

        assertEquals(listOf(RouteDirectionRuntimePlanner.processKey("dir_sh_jp_1")), plan.runtimeContexts.map { it.routeRuleProcessKey })
        assertEquals(12001, plan.runtimeContexts.single().localListenPort)
        assertEquals("route-direction-dir_sh_jp_1-tcp", plan.singboxBindings.single().tcpOutboundTag)
        assertEquals("route-direction-dir_sh_jp_1-udp", plan.singboxBindings.single().udpOutboundTag)
        assertEquals(setOf("198.51.100.10"), plan.bypassHosts)
    }

    @Test
    fun `planner leaves directions fail closed when binding is not Android ready`() {
        val snapshot = ClientRouteRuleSnapshot(
            rules = listOf(routeRule("rule_1", "dir_sh_jp_1", ClientRuleEffectiveState.ACTIVE)),
            bindings = listOf(
                DirectionRuntimeBinding(
                    serviceScopeId = "svc_scope_7",
                    directionId = "dir_sh_jp_1",
                    bindingState = "ready",
                    clientRuntimeBindingId = "binding_1",
                    localTcpPort = 12001,
                    localUdpPort = 12002,
                    runtimeForwardId = 77L,
                    selectable = false,
                    transport = "tcp_udp",
                ),
            ),
        )

        val plan = RouteDirectionRuntimePlanner.plan(snapshot, mapOf(77L to helperForward())) { _, _, _ -> error("not called") }

        assertTrue(plan.runtimeContexts.isEmpty())
        assertTrue(plan.singboxBindings.isEmpty())
    }

    private fun routeRule(id: String, directionId: String, state: ClientRuleEffectiveState): ClientRouteRule = ClientRouteRule(
        id = id,
        serviceScopeId = "svc_scope_7",
        destinationIp = "93.184.216.34",
        destinationPort = 443,
        directionId = directionId,
        enabled = true,
        effectiveState = state,
        syncVersion = 1L,
        updatedAtMs = 1L,
    )

    private fun helperForward(): ForwardContract = ForwardContract(
        forwardId = 77L,
        serviceName = "helper-dir-sh-jp-1",
        target = "93.184.216.34:443",
        listenPort = 11077,
        mode = ForwardMode.SINGLE,
        transport = "tcp_udp",
        serviceStatus = "active",
        endpointMode = "manual",
        sni = "",
        pathPrefix = "",
        speedLimitMbps = null,
        endpoints = listOf(
            ForwardEndpoint(
                endpointId = 1,
                node = "n1",
                host = "198.51.100.10",
                port = 7001,
                cdnHost = null,
                priority = 1,
                weight = 1,
                healthStatus = "healthy",
                supportsQuicLeg = false,
                ingressProtocol = null,
                ingressAuthToken = null,
            )
        ),
        bonding = null,
        mptcp = null,
        routeRuleHelperOnly = true,
    )
}
