package com.leikwan.bridge.runtime

import org.junit.Assert.assertEquals
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test

class ForwardFilterTest {

    @Test
    fun `normalize matches linux aliases`() {
        assertEquals(ForwardMode.SINGLE, ForwardMode.normalize("single"))
        assertEquals(ForwardMode.SINGLE, ForwardMode.normalize("unknown"))
        assertEquals(ForwardMode.RACE, ForwardMode.normalize("failover"))
        assertEquals(ForwardMode.BOND, ForwardMode.normalize("aggregated"))
        assertEquals(ForwardMode.MPTCP, ForwardMode.normalize("multipath-tcp"))
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
        assertEquals(0, catalog.hiddenUnsupportedMptcpCount)
        assertNull(catalog.selectedForwardId)
        assertTrue(catalog.hiddenForwardIds.contains(22L))
    }

    @Test
    fun `canonical dedicated leigod appears as leigod method`() {
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

        assertEquals(listOf(44L), catalog.visibleForwards.map { it.forwardId })
        assertEquals(44L, catalog.selectedForwardId)
        assertEquals("LeiGod", catalog.visibleForwards.single().methodDisplay)
        assertEquals(ForwardMode.SINGLE, catalog.visibleForwards.single().mode)
        assertEquals(0, catalog.hiddenUnsupportedMptcpCount)
    }

    @Test
    fun `selecting a hidden mptcp forward clears selection and reports only hidden ids`() {
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
        assertTrue(catalog.hiddenForwardIds.contains(22L))
        assertEquals(0, catalog.hiddenUnsupportedMptcpCount)
    }

    private fun sampleContract(
        forwardId: Long,
        mode: ForwardMode,
        serviceName: String,
        backendMode: String = "",
        contractVersion: Int = 0,
        clientMode: String = "",
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
