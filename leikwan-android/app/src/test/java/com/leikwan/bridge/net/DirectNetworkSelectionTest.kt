package com.leikwan.bridge.net

import org.junit.Assert.*
import org.junit.Test

class DirectNetworkSelectionTest {
    private val wifi = PanelNetworkCandidate("wifi", true, false, true, PanelTransport.WIFI)
    private val cell = PanelNetworkCandidate("cell", true, false, true, PanelTransport.CELLULAR)
    private val ethernet = PanelNetworkCandidate("ethernet", true, false, true, PanelTransport.ETHERNET)
    private val vpn = PanelNetworkCandidate("vpn", true, true, true, PanelTransport.WIFI)
    @Test fun `automatic never chooses VPN even when default`() {
        assertEquals("ethernet", DirectNetworkSelection.select(PortNetworkChoice.AUTO, "vpn", listOf(vpn, wifi, cell, ethernet)))
        assertNull(DirectNetworkSelection.select(PortNetworkChoice.AUTO, "vpn", listOf(vpn)))
    }
    @Test fun `manual missing transport does not fall back to another physical network`() {
        assertNull(DirectNetworkSelection.select(PortNetworkChoice.WIFI, "cell", listOf(cell, vpn)))
        assertNull(DirectNetworkSelection.select(PortNetworkChoice.CELLULAR, "wifi", listOf(wifi)))
        assertNull(DirectNetworkSelection.select(PortNetworkChoice.ETHERNET, "wifi", listOf(wifi, cell)))
    }
    @Test fun `manual selection uses requested physical transport`() {
        val all = listOf(vpn, wifi, cell, ethernet)
        assertEquals("wifi", DirectNetworkSelection.select(PortNetworkChoice.WIFI, "vpn", all))
        assertEquals("cell", DirectNetworkSelection.select(PortNetworkChoice.CELLULAR, "wifi", all))
        assertEquals("ethernet", DirectNetworkSelection.select(PortNetworkChoice.ETHERNET, "cell", all))
    }
    @Test fun `unusable and unknown transport cannot become egress`() {
        assertNull(DirectNetworkSelection.select(PortNetworkChoice.AUTO, "wifi", listOf(wifi.copy(hasInternet = false),
            PanelNetworkCandidate("other", true, false, true))))
    }
}
