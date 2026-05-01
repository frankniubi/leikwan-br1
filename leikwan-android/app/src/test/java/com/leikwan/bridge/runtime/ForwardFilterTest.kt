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
    fun `filter hides mptcp forwards and clears stale selection`() {
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
        assertEquals(1, catalog.hiddenUnsupportedMptcpCount)
        assertNull(catalog.selectedForwardId)
        assertTrue(catalog.hiddenForwardIds.contains(22L))
    }

    private fun sampleContract(
        forwardId: Long,
        mode: ForwardMode,
        serviceName: String,
    ): ForwardContract {
        return ForwardContract(
            forwardId = forwardId,
            serviceName = serviceName,
            target = "example.com:443",
            listenPort = 11000 + forwardId.toInt(),
            mode = mode,
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
