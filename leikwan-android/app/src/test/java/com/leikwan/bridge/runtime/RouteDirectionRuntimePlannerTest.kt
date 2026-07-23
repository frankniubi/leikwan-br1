package com.leikwan.bridge.runtime

import com.google.gson.JsonParser
import com.leikwan.bridge.clientrules.ClientRouteRule
import com.leikwan.bridge.clientrules.ClientRouteRuleSnapshot
import com.leikwan.bridge.clientrules.ClientRuleEffectiveState
import com.leikwan.bridge.clientrules.DirectionRuntimeBinding
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNotEquals
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
    fun `planner builds real dual transport runtime for parsed tcp underscore udp helper forward`() {
        val snapshot = ClientRouteRuleSnapshot(
            rules = listOf(routeRule("rule_1", "dir_sh_jp_1", ClientRuleEffectiveState.ACTIVE)),
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
            ),
        )
        val parsedHelperForward = ForwardContractParser.parse(
            JsonParser.parseString(
                """
                {
                  "forward_id": 77,
                  "service_name": "helper-dir-sh-jp-1",
                  "target": "93.184.216.34:443",
                  "listen_port": 11077,
                  "mode": "single",
                  "transport": "tcp_udp",
                  "service_status": "active",
                  "endpoint_mode": "manual",
                  "route_rule_helper_only": true,
                  "endpoints": [
                    {"endpoint_id":1,"node":"n1","host":"198.51.100.10","port":7001,"priority":1,"weight":1,"health_status":"healthy"}
                  ]
                }
                """.trimIndent()
            )
        )!!
        val binaries = RuntimeBinaryPaths(
            wstunnelBinary = "/runtime/wstunnel",
            ingressBinary = "/runtime/leikwan-ingress",
            bondBinary = "/runtime/leikwan-bond",
        )

        val plan = RouteDirectionRuntimePlanner.plan(
            snapshot = snapshot,
            forwardContractsById = mapOf(77L to parsedHelperForward),
            buildRuntimeContext = { directionId, forward, listenPort ->
                SingleForwardRunner.buildPlan(forward.withListenPort(listenPort), binaries)
                    .copy(routeRuleProcessKey = RouteDirectionRuntimePlanner.processKey(directionId))
            },
        )

        val command = plan.runtimeContexts.single().processPlans.single().command
        assertTrue(command.contains("tcp://127.0.0.1:12001:93.184.216.34:443"))
        assertTrue(command.contains("udp://127.0.0.1:12001:93.184.216.34:443?timeout_sec=0"))
        assertEquals(12001, plan.singboxBindings.single().localTcpPort)
        assertEquals(12001, plan.singboxBindings.single().localUdpPort)
    }

    @Test
    fun `bond runner treats tcp underscore udp helper transport as dual tcp and udp`() {
        val forward = helperForward(
            mode = ForwardMode.BOND,
            sni = "relay.example.com",
            pathPrefix = "/route-key",
            bonding = BondingContract(
                ingressMode = "wss_path_mux",
                routeKey = "/route-key",
                sessionToken = "tcp-token",
                udpSessionToken = "udp-token",
                chunkSize = null,
                maxWindowBytes = null,
                retransmitMs = null,
                connectTimeoutMs = null,
                joinTimeoutMs = null,
                interactiveChunkBytes = null,
                latencyGuardBytes = null,
                interactiveRttSlackMs = null,
                warmupTriggerBytes = null,
                warmupSliceBytes = null,
                warmupLegDelayMs = null,
                bulkPromoteBytes = null,
                legCooldownMs = null,
                remoteTarget = "138.252.162.120:9000",
                adaptiveJoinTimeout = false,
                joinTimeoutFloorMs = null,
                joinTimeoutCeilingMs = null,
                joinTimeoutPerLegMs = null,
                quicEnabled = false,
                quicViaSlave = false,
                activeLegLimit = 0,
                quicActiveLegLimit = 0,
                wssStandbyEnabled = true,
                disableLocalRateLimit = true,
                quicServerName = null,
                quicInsecure = true,
                quicPriority = 0,
                wsPriority = 10,
            ),
        )
        val binaries = RuntimeBinaryPaths(
            wstunnelBinary = "/runtime/wstunnel",
            ingressBinary = "/runtime/leikwan-ingress",
            bondBinary = "/runtime/leikwan-bond",
        )

        val context = BondForwardRunner.buildPlan(forward, binaries)

        val bondCommands = context.processPlans.filter { it.role == RuntimeProcessRole.BOND }.map { it.command }
        assertEquals(2, bondCommands.size)
        assertTrue(bondCommands.any { command -> command.contains("--protocol") && command.contains("tcp") })
        assertTrue(bondCommands.any { command -> command.contains("--protocol") && command.contains("udp") })
        assertEquals(listOf(22849, 23049), context.localLegPorts)
    }

    @Test
    fun `planner assigns unique process keys when active direction ids collide`() {
        val snapshot = ClientRouteRuleSnapshot(
            rules = listOf(
                routeRule("rule_1", "dir_5soa6056", ClientRuleEffectiveState.ACTIVE),
                routeRule("rule_2", "dir_3xb4ea4_", ClientRuleEffectiveState.ACTIVE),
            ),
            bindings = listOf(
                DirectionRuntimeBinding(
                    serviceScopeId = "svc_scope_7",
                    directionId = "dir_5soa6056",
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
                    directionId = "dir_3xb4ea4_",
                    bindingState = "ready",
                    clientRuntimeBindingId = "binding_2",
                    localTcpPort = 12002,
                    localUdpPort = 12002,
                    runtimeForwardId = 88L,
                    selectable = false,
                    transport = "tcp_udp",
                ),
            ),
        )
        val helperForwardOne = helperForward(forwardId = 77L)
        val helperForwardTwo = helperForward(forwardId = 88L)

        val plan = RouteDirectionRuntimePlanner.plan(
            snapshot = snapshot,
            forwardContractsById = mapOf(77L to helperForwardOne, 88L to helperForwardTwo),
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

        val keys = plan.runtimeContexts.map { it.routeRuleProcessKey }
        assertEquals(2, keys.size)
        assertNotEquals(keys[0], keys[1])
        assertEquals(listOf(12001, 12002), plan.runtimeContexts.map { it.localListenPort })
        assertEquals(listOf("dir_5soa6056", "dir_3xb4ea4_"), plan.singboxBindings.map { it.directionId })
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

    private fun helperForward(
        mode: ForwardMode = ForwardMode.SINGLE,
        bonding: BondingContract? = null,
        sni: String = "",
        pathPrefix: String = "",
        forwardId: Long = 77L,
    ): ForwardContract = ForwardContract(
        forwardId = forwardId,
        serviceName = "helper-dir-sh-jp-1",
        target = "93.184.216.34:443",
        listenPort = 11077,
        mode = mode,
        transport = "tcp_udp",
        serviceStatus = "active",
        endpointMode = "manual",
        sni = sni,
        pathPrefix = pathPrefix,
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
        bonding = bonding,
        mptcp = null,
        routeRuleHelperOnly = true,
    )
}
