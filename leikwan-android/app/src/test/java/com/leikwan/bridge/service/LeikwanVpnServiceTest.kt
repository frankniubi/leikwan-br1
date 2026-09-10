package com.leikwan.bridge.service

import org.junit.After
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Assert.assertNull
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.Robolectric
import org.robolectric.RobolectricTestRunner
import org.robolectric.annotation.Config

/**
 * Verifies the same-module-testable startup-state seam: tests can set [LeikwanVpnService.startupState]
 * and [LeikwanVpnService.lastStartError] (now `@VisibleForTesting internal set`) and read them back
 * through [LeikwanVpnService.startupSnapshot], which the orchestrator polls via [VpnStartupMonitor].
 */
@RunWith(RobolectricTestRunner::class)
@Config(sdk = [34])
class LeikwanVpnServiceTest {

    @After
    fun resetState() {
        LeikwanVpnService.startupState = VpnStartupState.IDLE
        LeikwanVpnService.lastStartError = null
        LeikwanVpnService.configJson = null
    }

    @Test
    fun `startupSnapshot reflects the current startup state and error`() {
        LeikwanVpnService.startupState = VpnStartupState.STARTING
        LeikwanVpnService.lastStartError = null

        val snapshot = LeikwanVpnService.startupSnapshot()

        assertEquals(VpnStartupState.STARTING, snapshot.state)
        assertNull(snapshot.errorMessage)
    }

    @Test
    fun `libbox outbound sockets use Android protect callback`() {
        val service = Robolectric.buildService(LeikwanVpnService::class.java)
            .create()
            .get()

        assertTrue(service.usePlatformAutoDetectInterfaceControl())
    }

    @Test
    fun `stopping the vpn drops the ephemeral config`() {
        val service = Robolectric.buildService(LeikwanVpnService::class.java)
            .create()
            .get()
        LeikwanVpnService.configJson = "{\"password\":\"ephemeral\"}"

        service.stopVpn()

        assertNull(LeikwanVpnService.configJson)
    }
}
