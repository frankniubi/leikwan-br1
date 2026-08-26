package com.leikwan.bridge.runtime

import org.junit.Assert.assertEquals
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test

class ForwardFilterTest {

    @Test
    fun `normalize matches linux aliases`() {
        assertEquals(ForwardMode.SINGLE, ForwardMode.normalize("single"))
        assertEquals(ForwardMode.UNKNOWN, ForwardMode.normalize("unknown"))
        assertEquals(ForwardMode.RACE, ForwardMode.normalize("failover"))
        assertEquals(ForwardMode.BOND, ForwardMode.normalize("aggregated"))
        assertEquals(ForwardMode.MPTCP, ForwardMode.normalize("multipath-tcp"))
    }

    @Test
    fun `only current single and bond contracts are accepted`() {
        val currentSingle = sampleContract(
            forwardId = 1L,
            mode = ForwardMode.SINGLE,
            serviceName = "current-single",
            backendMode = "current_mode",
            clientMode = "single",
        )
        val currentBond = sampleContract(
            forwardId = 2L,
            mode = ForwardMode.BOND,
            serviceName = "current-bond",
            backendMode = "current_mode",
            clientMode = "bond",
        )
        val rejected = listOf(
            sampleContract(3L, ForwardMode.SINGLE, "cf", backendMode = "cf_mode", clientMode = "single"),
            sampleContract(4L, ForwardMode.SINGLE, "legacy", backendMode = "leigod_mode", clientMode = "single"),
            sampleContract(5L, ForwardMode.SINGLE, "unknown", backendMode = "unknown", clientMode = "single"),
            sampleContract(6L, ForwardMode.RACE, "race", backendMode = "current_mode", clientMode = "race"),
            sampleContract(7L, ForwardMode.MPTCP, "mptcp", backendMode = "current_mode", clientMode = "mptcp"),
            sampleContract(8L, ForwardMode.SINGLE, "bogus", backendMode = "current_mode", clientMode = "bogus"),
        )

        assertTrue(AndroidEffectiveForwardBuilder.build(currentSingle) != null)
        assertTrue(AndroidEffectiveForwardBuilder.build(currentBond) != null)
        rejected.forEach { contract -> assertNull(AndroidEffectiveForwardBuilder.build(contract)) }
    }

    @Test
    fun `filter hides unsupported runtime without exposing old mptcp count`() {
        val supportedSingle = sampleContract(
            forwardId = 11L,
            mode = ForwardMode.SINGLE,
            serviceName = "single-11",
        )
        val hiddenMptcp = sampleContract(
            forwardId = 22L,
            mode = ForwardMode.MPTCP,
            serviceName = "mptcp-22",
        )
        val supportedBond = sampleContract(
            forwardId = 33L,
            mode = ForwardMode.BOND,
            serviceName = "bond-33",
        )

        val catalog = ForwardFilter.buildCatalog(
            contracts = listOf(supportedSingle, hiddenMptcp, supportedBond),
            selectedForwardId = 22L,
        )

        assertEquals(listOf(11L, 33L), catalog.visibleForwards.map { it.forwardId })
        assertNull(catalog.selectedForwardId)
    }

    @Test
    fun `legacy leigod contracts are silently absent from catalog`() {
        val dedicated = sampleContract(
            forwardId = 44L,
            mode = ForwardMode.MPTCP,
            serviceName = "dedicated-44",
            backendMode = "leigod_mode",
            contractVersion = 1,
            clientMode = "single",
            activeFamily = "leigod_only",
            targetContract = "dedicated_forward_node",
        )

        val catalog = ForwardFilter.buildCatalog(
            contracts = listOf(dedicated),
            selectedForwardId = 44L,
        )

        assertTrue(catalog.visibleForwards.isEmpty())
        assertNull(catalog.selectedForwardId)
    }

    @Test
    fun `selecting a dropped forward clears selection without exposing it`() {
        val single = sampleContract(
            forwardId = 11L,
            mode = ForwardMode.SINGLE,
            serviceName = "single-11",
        )
        val hiddenMptcp = sampleContract(
            forwardId = 22L,
            mode = ForwardMode.MPTCP,
            serviceName = "mptcp-22",
        )

        val catalog = ForwardFilter.buildCatalog(
            contracts = listOf(single, hiddenMptcp),
            selectedForwardId = 22L,
        )

        assertNull(catalog.selectedForwardId)
    }

    private fun sampleContract(
        forwardId: Long,
        mode: ForwardMode,
        serviceName: String,
        backendMode: String = "current_mode",
        contractVersion: Int = 0,
        clientMode: String = when (mode) {
            ForwardMode.SINGLE -> "single"
            ForwardMode.BOND -> "bond"
            else -> mode.wireValue
        },
        activeFamily: String = "",
        targetContract: String = "",
    ): ForwardContract {
        return ForwardContract(
            forwardId = forwardId,
            serviceName = serviceName,
            target = "example.com:443",
            listenPort = 11000 + forwardId.toInt(),
            mode = mode,
            backendMode = backendMode,
            contractVersion = contractVersion,
            clientMode = clientMode,
            activeFamily = activeFamily,
            targetContract = targetContract,
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
        )
    }
}
