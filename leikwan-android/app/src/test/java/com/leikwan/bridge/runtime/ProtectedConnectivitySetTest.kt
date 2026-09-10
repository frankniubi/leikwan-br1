package com.leikwan.bridge.runtime

import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test

class ProtectedConnectivitySetTest {
    @Test
    fun `normalizes endpoint hosts and accepts only narrow non-default CIDRs`() {
        val protected = ProtectedConnectivitySet.of(
            hosts = listOf("EDGE.EXAMPLE.TEST.", "198.51.100.44", "2001:db8::44"),
            cidrs = listOf(
                "198.51.100.0/24",
                "10.0.0.0/7",
                "0.0.0.0/0",
                "2001:db8::/32",
                "2001:db8::/31",
                "::/0",
            ),
        )

        assertEquals(setOf("edge.example.test"), protected.hosts)
        assertTrue(protected.ipCidrs.contains("198.51.100.44/32"))
        assertTrue(protected.ipCidrs.contains("198.51.100.0/24"))
        assertTrue(protected.ipCidrs.any { it.endsWith("/128") })
        assertTrue(protected.ipCidrs.any { it.endsWith("/32") && it.contains(':') })
        assertFalse(protected.ipCidrs.contains("10.0.0.0/7"))
        assertFalse(protected.ipCidrs.contains("0.0.0.0/0"))
        assertFalse(protected.ipCidrs.any { it.endsWith("/31") && it.contains(':') })
        assertFalse(protected.ipCidrs.any { it.endsWith("/0") })
    }
}
