package com.leikwan.bridge.runtime

import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNotNull
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test

class AndroidEffectiveForwardBuilderTest {

    @Test
    fun `builder keeps single and bond but rejects unsupported legacy runtime modes`() {
        val single = sampleContract(forwardId = 11L, mode = ForwardMode.SINGLE)
        val bond = sampleContract(forwardId = 22L, mode = ForwardMode.BOND)
        val race = sampleContract(forwardId = 33L, mode = ForwardMode.RACE)
        val mptcp = sampleContract(forwardId = 44L, mode = ForwardMode.MPTCP)

        val visible = listOfNotNull(
            AndroidEffectiveForwardBuilder.build(single),
            AndroidEffectiveForwardBuilder.build(bond),
            AndroidEffectiveForwardBuilder.build(race),
            AndroidEffectiveForwardBuilder.build(mptcp),
        )

        assertEquals(listOf(11L, 22L), visible.map { it.forwardId })
        assertFalse(visible.any { it.mode == ForwardMode.RACE })
        assertFalse(visible.any { it.mode == ForwardMode.MPTCP })
    }

    @Test
    fun `canonical dedicated leigod resolves to sidecarless single`() {
        val dedicated = sampleContract(
            forwardId = 65L,
            mode = ForwardMode.MPTCP,
            backendMode = "leigod_mode",
            contractVersion = 1,
            clientMode = "single",
            runtimePreference = "single",
            activeFamily = "leigod_only",
            aggregationEnabled = false,
            targetContract = "dedicated_forward_node",
            sidecarRequired = false,
            bondSidecarPointId = null,
        )

        val effective = AndroidEffectiveForwardBuilder.build(dedicated)

        assertNotNull(effective)
        effective!!
        assertEquals(ForwardMode.SINGLE, effective.mode)
        assertEquals(ForwardMode.SINGLE, effective.runtimeContract.mode)
        assertTrue(effective.isDedicatedLeiGod)
        assertEquals("LeiGod", effective.toListItem().methodDisplay)
        assertEquals("dedicated_forward_node", effective.runtimeContract.targetContract)
    }

    @Test
    fun `canonical mptcp preferred aggregation uses android bond fallback`() {
        val aggregation = sampleContract(
            forwardId = 66L,
            mode = ForwardMode.MPTCP,
            backendMode = "leigod_mode",
            contractVersion = 1,
            clientMode = "mptcp",
            runtimePreference = "mptcp_preferred_bond_fallback",
            activeFamily = "sidecar_aggregation",
            aggregationEnabled = true,
            targetContract = "sidecar_aggregation",
            sidecarRequired = true,
            bondSidecarPointId = 4L,
            bonding = sampleBonding(remoteTarget = "overseas-sidecar.example:9000"),
        )

        val effective = AndroidEffectiveForwardBuilder.build(aggregation)

        assertNotNull(effective)
        effective!!
        assertEquals(ForwardMode.BOND, effective.mode)
        assertEquals(ForwardMode.BOND, effective.runtimeContract.mode)
        assertFalse(effective.isDedicatedLeiGod)
        assertEquals("sidecar_aggregation", effective.runtimeContract.targetContract)
        assertEquals(ForwardMode.BOND, effective.toListItem().mode)
    }

    @Test
    fun `canonical aggregation can be detected from boolean and sidecar id markers`() {
        val aggregation = sampleContract(
            forwardId = 67L,
            mode = ForwardMode.MPTCP,
            aggregationEnabled = true,
            sidecarRequired = true,
            bondSidecarPointId = 4L,
            bonding = sampleBonding(remoteTarget = "overseas-sidecar.example:9000"),
        )

        val effective = AndroidEffectiveForwardBuilder.build(aggregation)

        assertNotNull(effective)
        effective!!
        assertEquals(ForwardMode.BOND, effective.mode)
        assertEquals(ForwardMode.BOND, effective.runtimeContract.mode)
    }

    @Test
    fun `canonical aggregation requires usable sidecar metadata`() {
        val missingSidecar = sampleContract(
            forwardId = 67L,
            mode = ForwardMode.MPTCP,
            backendMode = "leigod_mode",
            contractVersion = 1,
            clientMode = "mptcp",
            runtimePreference = "mptcp_preferred_bond_fallback",
            activeFamily = "sidecar_aggregation",
            aggregationEnabled = true,
            targetContract = "sidecar_aggregation",
            sidecarRequired = true,
            bondSidecarPointId = null,
            bonding = sampleBonding(remoteTarget = "overseas-sidecar.example:9000"),
        )

        assertNull(AndroidEffectiveForwardBuilder.build(missingSidecar))
    }

    @Test
    fun `single bypass hosts include endpoint host cdn host and sni`() {
        val single = sampleContract(
            forwardId = 11L,
            mode = ForwardMode.SINGLE,
            cdnHost = "cdn.example.com",
        )

        val effective = AndroidEffectiveForwardBuilder.build(single)

        assertNotNull(effective)
        effective!!
        assertTrue(effective.bypassHosts.contains("8.8.8.11"))
        assertTrue(effective.bypassHosts.contains("cdn.example.com"))
        assertTrue(effective.bypassHosts.contains("example.com"))
    }

    @Test
    fun `bond bypass hosts include quic server name`() {
        val bond = sampleContract(
            forwardId = 22L,
            mode = ForwardMode.BOND,
            cdnHost = "cdn-bond.example.com",
            bonding = sampleBonding(quicServerName = "sidecar.example.com"),
        )

        val effective = AndroidEffectiveForwardBuilder.build(bond)

        assertNotNull(effective)
        effective!!
        assertTrue(effective.bypassHosts.contains("8.8.8.22"))
        assertTrue(effective.bypassHosts.contains("cdn-bond.example.com"))
        assertTrue(effective.bypassHosts.contains("example.com"))
        assertTrue(effective.bypassHosts.contains("sidecar.example.com"))
    }

    @Test
    fun `builder preserves endpoint dynamic priority and node id`() {
        val single = sampleContract(
            forwardId = 11L,
            mode = ForwardMode.SINGLE,
            dynamicPriority = 7,
            nodeId = 99,
        )

        val effective = AndroidEffectiveForwardBuilder.build(single)

        assertNotNull(effective)
        effective!!
        val endpoint = effective.endpoints.single()
        assertEquals(7, endpoint.dynamicPriority)
        assertEquals(99, endpoint.nodeId)
    }

    @Test
    fun `runtime context converts to runtime intent preserving fields`() {
        val context = RuntimeContext(
            forwardId = 55L,
            mode = ForwardMode.SINGLE,
            localListenPort = 11055,
            localLegPorts = listOf(20001, 20002),
            processPlans = listOf(
                RuntimeProcessPlan(
                    role = RuntimeProcessRole.SINGLE,
                    command = listOf("wstunnel", "client"),
                )
            ),
            bypassHosts = linkedSetOf("8.8.8.55", "example.com"),
        )

        val intent = context.toRuntimeIntent()

        assertEquals(55L, intent.forwardId)
        assertEquals(ForwardMode.SINGLE, intent.mode)
        assertEquals(11055, intent.localListenPort)
        assertEquals(listOf(20001, 20002), intent.localLegPorts)
        assertEquals(context.processPlans, intent.processPlans)
        assertEquals(context.bypassHosts, intent.bypassHosts)
        assertEquals(emptyMap<Long, NodeLeg>(), intent.nodeLegs)
    }

    @Test
    fun `catalog hides unsupported runtime without exposing old mptcp copy`() {
        val single = sampleContract(forwardId = 11L, mode = ForwardMode.SINGLE)
        val hidden = sampleContract(forwardId = 22L, mode = ForwardMode.MPTCP)

        val catalog = ForwardFilter.buildCatalog(
            contracts = listOf(single, hidden),
            selectedForwardId = 22L,
        )

        assertNull(catalog.selectedForwardId)
        assertTrue(catalog.hiddenForwardIds.contains(22L))
        assertEquals(0, catalog.hiddenUnsupportedMptcpCount)
    }

    private fun sampleContract(
        forwardId: Long,
        mode: ForwardMode,
        cdnHost: String? = null,
        dynamicPriority: Int? = null,
        nodeId: Int? = null,
        bonding: BondingContract? = null,
        backendMode: String = "",
        contractVersion: Int = 0,
        clientMode: String = "",
        runtimePreference: String = "",
        activeFamily: String = "",
        aggregationEnabled: Boolean = false,
        targetContract: String = "",
        sidecarRequired: Boolean = false,
        bondSidecarPointId: Long? = null,
    ): ForwardContract {
        return ForwardContract(
            forwardId = forwardId,
            serviceName = "forward-$forwardId",
            target = "example.com:443",
            listenPort = 11000 + forwardId.toInt(),
            mode = mode,
            backendMode = backendMode,
            contractVersion = contractVersion,
            clientMode = clientMode,
            runtimePreference = runtimePreference,
            activeFamily = activeFamily,
            aggregationEnabled = aggregationEnabled,
            targetContract = targetContract,
            sidecarRequired = sidecarRequired,
            bondSidecarPointId = bondSidecarPointId,
            transport = "tcp",
            serviceStatus = "active",
            endpointMode = "manual",
            sni = "example.com",
            pathPrefix = "/test",
            speedLimitMbps = null,
            endpoints = listOf(
                ForwardEndpoint(
                    endpointId = forwardId.toInt(),
                    node = "node-$forwardId",
                    host = "8.8.8.${forwardId}",
                    port = 7001,
                    cdnHost = cdnHost,
                    priority = 1,
                    weight = 1,
                    healthStatus = "healthy",
                    supportsQuicLeg = false,
                    ingressProtocol = null,
                    ingressAuthToken = null,
                    dynamicPriority = dynamicPriority,
                    nodeId = nodeId,
                )
            ),
            bonding = bonding,
            mptcp = null,
        )
    }

    private fun sampleBonding(
        quicServerName: String? = null,
        remoteTarget: String? = null,
    ): BondingContract {
        return BondingContract(
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
            remoteTarget = remoteTarget,
            quicServerName = quicServerName,
        )
    }
}
