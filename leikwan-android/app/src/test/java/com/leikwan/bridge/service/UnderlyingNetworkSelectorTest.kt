package com.leikwan.bridge.service

import org.junit.Assert.assertEquals
import org.junit.Assert.assertNull
import org.junit.Test

class UnderlyingNetworkSelectorTest {

    @Test
    fun `selector keeps active non vpn internet network`() {
        val selected = UnderlyingNetworkSelector.select(
            active = "wifi",
            candidates = listOf(
                UnderlyingNetworkCandidate("wifi", isVpn = false, hasInternet = true),
                UnderlyingNetworkCandidate("cell", isVpn = false, hasInternet = true),
            ),
        )

        assertEquals("wifi", selected)
    }

    @Test
    fun `selector skips active vpn and chooses first non vpn internet network`() {
        val selected = UnderlyingNetworkSelector.select(
            active = "vpn",
            candidates = listOf(
                UnderlyingNetworkCandidate("vpn", isVpn = true, hasInternet = true),
                UnderlyingNetworkCandidate("wifi", isVpn = false, hasInternet = true),
                UnderlyingNetworkCandidate("cell", isVpn = false, hasInternet = true),
            ),
        )

        assertEquals("wifi", selected)
    }

    @Test
    fun `selector returns null when no usable underlying network exists`() {
        val selected = UnderlyingNetworkSelector.select(
            active = "vpn",
            candidates = listOf(
                UnderlyingNetworkCandidate("vpn", isVpn = true, hasInternet = true),
                UnderlyingNetworkCandidate("wifi", isVpn = false, hasInternet = false),
            ),
        )

        assertNull(selected)
    }

    @Test
    fun `select all lists every physical network with the active one first`() {
        val networks = UnderlyingNetworkSelector.selectAll(
            active = "vpn",
            candidates = listOf(
                UnderlyingNetworkCandidate("vpn", isVpn = true, hasInternet = true),
                UnderlyingNetworkCandidate("wifi", isVpn = false, hasInternet = true),
                UnderlyingNetworkCandidate("cell", isVpn = false, hasInternet = true),
            ),
        )

        assertEquals(listOf("wifi", "cell"), networks)
    }

    @Test
    fun `select all keeps the active network first and appends the others`() {
        val networks = UnderlyingNetworkSelector.selectAll(
            active = "cell",
            candidates = listOf(
                UnderlyingNetworkCandidate("wifi", isVpn = false, hasInternet = true),
                UnderlyingNetworkCandidate("cell", isVpn = false, hasInternet = true),
            ),
        )

        assertEquals(listOf("cell", "wifi"), networks)
    }

    @Test
    fun `select all drops networks without internet`() {
        val networks = UnderlyingNetworkSelector.selectAll(
            active = "cell",
            candidates = listOf(
                UnderlyingNetworkCandidate("cell", isVpn = false, hasInternet = true),
                UnderlyingNetworkCandidate("wifi", isVpn = false, hasInternet = false),
            ),
        )

        assertEquals(listOf("cell"), networks)
    }
}
