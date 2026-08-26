package com.leikwan.bridge.session

import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test

class SessionRevocationCoordinatorTest {
    @Test
    fun `terminal revoke is ordered and idempotent`() {
        val events = mutableListOf<String>()
        val coordinator = SessionRevocationCoordinator(
            stopVpn = { events += "vpn" },
            stopRuntimes = { events += "runtimes" },
            clearLeaseState = { events += "lease" },
            clearEnrollment = { events += "prefs" },
        )

        val first = coordinator.revoke(PanelControlException.kicked(version = 4)) { version ->
            events += "ack:$version"
        }
        val second = coordinator.revoke(PanelControlException.kicked(version = 4)) { version ->
            events += "ack:$version"
        }

        assertTrue(first)
        assertFalse(second)
        assertEquals(listOf("vpn", "runtimes", "lease", "prefs", "ack:4"), events)
    }

    @Test
    fun `cleanup failures do not skip enrollment clear or terminal acknowledgement`() {
        val events = mutableListOf<String>()
        val coordinator = SessionRevocationCoordinator(
            stopVpn = { throw IllegalStateException("vpn unavailable") },
            stopRuntimes = { events += "runtimes" },
            clearLeaseState = { events += "lease" },
            clearEnrollment = { events += "prefs" },
        )

        assertTrue(coordinator.revoke(PanelControlException.banned(version = 9)) { events += "ack:$it" })
        assertEquals(listOf("runtimes", "lease", "prefs", "ack:9"), events)
    }
}
