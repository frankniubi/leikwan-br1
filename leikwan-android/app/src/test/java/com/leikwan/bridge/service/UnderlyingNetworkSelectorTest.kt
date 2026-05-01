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
}
