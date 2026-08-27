package com.leikwan.bridge.runtime

import com.google.gson.JsonObject
import com.google.gson.JsonParser
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNotNull
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test
import java.time.Instant

class AndroidEffectiveForwardBuilderTest {

    @Test
    fun `valid ultra policy is accepted while expired policy is dropped`() {
        val valid = sampleContract(
            forwardId = 90L,
            mode = ForwardMode.ULTRA,
            backendMode = "ultra",
            clientMode = "ultra",
            ultraAccess = ultraAccess(90L),
        )
        val expired = valid.copy(
            forwardId = 91L,
            ultraAccess = ultraAccess(91L, "2020-01-01T00:00:00Z"),
        )

        assertEquals(ForwardMode.ULTRA, AndroidEffectiveForwardBuilder.build(valid)?.mode)
        assertNull(AndroidEffectiveForwardBuilder.build(expired))
    }

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
    fun `legacy leigod contract is rejected`() {
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

        assertNull(effective)
    }

    @Test
    fun `legacy mptcp aggregation is rejected`() {
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

        assertNull(effective)
    }

    @Test
    fun `current mode does not promote mptcp metadata to bond`() {
        val aggregation = sampleContract(
            forwardId = 67L,
            mode = ForwardMode.MPTCP,
            aggregationEnabled = true,
            sidecarRequired = true,
            bondSidecarPointId = 4L,
            bonding = sampleBonding(remoteTarget = "overseas-sidecar.example:9000"),
        )

        val effective = AndroidEffectiveForwardBuilder.build(aggregation)

        assertNull(effective)
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
    fun `catalog clears a dropped selection without retaining its id`() {
        val single = sampleContract(forwardId = 11L, mode = ForwardMode.SINGLE)
        val hidden = sampleContract(forwardId = 22L, mode = ForwardMode.MPTCP)

        val catalog = ForwardFilter.buildCatalog(
            contracts = listOf(single, hidden),
            selectedForwardId = 22L,
        )

        assertNull(catalog.selectedForwardId)
    }

    private fun sampleContract(
        forwardId: Long,
        mode: ForwardMode,
        cdnHost: String? = null,
        dynamicPriority: Int? = null,
        nodeId: Int? = null,
        bonding: BondingContract? = null,
        backendMode: String = "current_mode",
        contractVersion: Int = 0,
        clientMode: String = when (mode) {
            ForwardMode.SINGLE -> "single"
            ForwardMode.BOND -> "bond"
            else -> mode.wireValue
        },
        runtimePreference: String = "",
        activeFamily: String = "",
        aggregationEnabled: Boolean = false,
        targetContract: String = "",
        sidecarRequired: Boolean = false,
        bondSidecarPointId: Long? = null,
        ultraAccess: JsonObject? = null,
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
            ultraAccess = ultraAccess,
        )
    }

    private fun ultraAccess(forwardId: Long, expiresAt: String? = null): JsonObject {
        val now = Instant.now()
        val effectiveExpiry = expiresAt ?: now.plusSeconds(240).toString()
        return JsonParser.parseString(
        """
        {
          "protocol":"leikwan-ultra-policy-v2",
          "forward_id":$forwardId,
          "session_ticket":"ticket",
          "policy_generation":"${"a".repeat(64)}",
          "issued_at":"${now.minusSeconds(10)}",
          "renew_after":"${now.plusSeconds(120)}",
          "expires_at":"$effectiveExpiry",
          "service_state":"active",
          "transport":"tcp",
          "rate_limit_mbps":0,
          "quota_remaining_bytes":1048576,
          "target_digest":"${UltraPolicy.targetDigest("example.com:443")}"
        }
        """.trimIndent(),
        ).asJsonObject
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
