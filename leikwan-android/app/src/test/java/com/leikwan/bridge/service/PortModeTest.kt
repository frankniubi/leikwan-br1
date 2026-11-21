package com.leikwan.bridge.service

import android.content.Intent
import androidx.test.core.app.ApplicationProvider
import com.leikwan.bridge.data.Prefs
import com.leikwan.bridge.net.DirectPortNetwork
import com.leikwan.bridge.net.PortNetworkChoice
import com.leikwan.bridge.runtime.*
import com.leikwan.bridge.R
import org.junit.After
import org.junit.Assert.*
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.Robolectric
import org.robolectric.RobolectricTestRunner
import org.robolectric.Shadows.shadowOf
import org.robolectric.annotation.Config
import java.io.IOException

@RunWith(RobolectricTestRunner::class)
@Config(sdk = [34])
class PortModeTest {
    private val context get() = ApplicationProvider.getApplicationContext<android.content.Context>()
    @After fun resetNetwork() { DirectPortNetwork.stop() }

    @Test fun `ports mode never needs VPN consent and settings persist independently`() {
        assertFalse(PortModePolicy.needsVpnConsent(AppConnectionMode.PORTS))
        assertTrue(PortModePolicy.needsVpnConsent(AppConnectionMode.VPN))
        val shared = context.getSharedPreferences("port-mode-test", 0).also { it.edit().clear().commit() }
        val prefs = Prefs(shared)
        assertEquals(AppConnectionMode.VPN, prefs.connectionMode)
        prefs.connectionMode = AppConnectionMode.PORTS
        prefs.portForwardIds = setOf(21L, 22L)
        prefs.portNetworkChoice = PortNetworkChoice.ETHERNET
        val restored = Prefs(shared)
        assertEquals(AppConnectionMode.PORTS, restored.connectionMode)
        assertEquals(setOf(21L, 22L), restored.portForwardIds)
        assertEquals(PortNetworkChoice.ETHERNET, restored.portNetworkChoice)
        assertFalse(restored.vpnConnectIntent)
    }

    private fun forward(id: Long = 1L, port: Int = 2080, mode: ForwardMode = ForwardMode.ULTRA) = ForwardContract(
        forwardId = id, serviceName = "fixture", target = "example.invalid:443", listenPort = port,
        mode = mode, backendMode = if (mode == ForwardMode.ULTRA) "ultra" else mode.wireValue,
        transport = "tcp+udp", serviceStatus = "active", endpointMode = "", sni = "", pathPrefix = "",
        speedLimitMbps = null, endpoints = emptyList(), bonding = null, mptcp = null)

    @Test fun `raw fixed-target rules need no user proxy node`() {
        PortModePolicy.validate(listOf(forward(), forward(2L, 2081)))
    }
    @Test fun `single bond and native SOCKS are explicitly unsupported`() {
        for (mode in listOf(ForwardMode.SINGLE, ForwardMode.BOND)) {
            try { PortModePolicy.validate(listOf(forward(mode = mode))); fail("unsupported core accepted") }
            catch (error: IllegalArgumentException) { assertTrue(error.message!!.contains("unsupported")) }
        }
        assertThrows(IllegalArgumentException::class.java) { PortModePolicy.validate(listOf(forward().copy(nativeOutbound = true))) }
    }
    @Test fun `duplicate listener ports and empty selections fail before starting cores`() {
        assertThrows(IllegalArgumentException::class.java) { PortModePolicy.validate(emptyList()) }
        assertThrows(IllegalArgumentException::class.java) { PortModePolicy.validate(listOf(forward(), forward(2L))) }
    }
    @Test fun `panel outage keeps only unexpired runtime on unchanged physical network`() {
        val now = java.time.Instant.parse("2026-11-21T10:00:00Z")
        assertTrue(PortModePolicy.retainAuthorizedRuntime(true, now.plusSeconds(1), now))
        assertFalse(PortModePolicy.retainAuthorizedRuntime(false, now.plusSeconds(3600), now))
        assertFalse(PortModePolicy.retainAuthorizedRuntime(true, now, now))
        assertFalse(PortModePolicy.retainAuthorizedRuntime(true, now.minusSeconds(1), now))
        assertFalse(PortModePolicy.retainAuthorizedRuntime(true, null, now))
    }
    @Test fun `occupied TCP or UDP listeners fail before readiness can be mistaken for another core`() {
        val local = java.net.InetAddress.getByName("127.0.0.1")
        java.net.ServerSocket(0, 1, local).use { listener ->
            assertThrows(IOException::class.java) { PortModePolicy.requireListenerAvailable(listener.localPort, "tcp") }
        }
        java.net.DatagramSocket(java.net.InetSocketAddress(local, 0)).use { listener ->
            assertThrows(IOException::class.java) { PortModePolicy.requireListenerAvailable(listener.localPort, "udp") }
        }
    }
    @Test fun `ordinary foreground service promotes before rejecting an invalid start`() {
        val service = Robolectric.buildService(LeikwanPortsService::class.java).create().get()
        assertFalse(android.net.VpnService::class.java.isAssignableFrom(service.javaClass))
        assertEquals(android.app.Service.START_NOT_STICKY, service.onStartCommand(Intent(), 0, 1))
        assertTrue(shadowOf(service).isForegroundStopped)
        assertEquals("ports_stop_vpn_and_select_forward", LeikwanPortsService.state.value.error)
        assertFalse(LeikwanVpnService.isRunning)
    }
    @Test fun `strict network absence fails DNS and sockets without default fallback`() {
        DirectPortNetwork.start(context, PortNetworkChoice.ETHERNET)
        assertTrue(DirectPortNetwork.required)
        assertThrows(IOException::class.java) { DirectPortNetwork.socketFactory.createSocket() }
        assertThrows(IOException::class.java) { com.leikwan.bridge.net.AppDns.strict().lookup("example.invalid") }
        assertThrows(IOException::class.java) { com.leikwan.bridge.net.PanelNetwork.socketFactory.createSocket() }
    }
}
