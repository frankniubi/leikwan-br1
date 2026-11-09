package com.leikwan.bridge.runtime
import org.junit.Assert.*
import org.junit.Test
import java.time.Instant

class UltraNetworkGraceTest {
    @Test fun absoluteBound() {
        val expiry = Instant.parse("2026-11-09T00:00:00Z")
        assertNull(UltraNetworkGrace.deadline(expiry, expiry.minusSeconds(61)))
        assertEquals(expiry.plusSeconds(600), UltraNetworkGrace.deadline(expiry, expiry.minusSeconds(60)))
        assertEquals(expiry.plusSeconds(600), UltraNetworkGrace.deadline(expiry, expiry.plusSeconds(599)))
        assertNull(UltraNetworkGrace.deadline(expiry, expiry.plusSeconds(600)))
    }
}
