package com.leikwan.bridge.net

import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Test
import java.net.InetAddress

class DohEndpointsTest {

    @Test
    fun `china-first list prioritises AliDNS then DNSPod then 360`() {
        val names = DohEndpoints.CHINA_FIRST.map { it.name }

        assertEquals(listOf("AliDNS", "DNSPod", "360"), names)
    }

    @Test
    fun `AliDNS endpoint uses correct url and bootstrap ips`() {
        val ali = DohEndpoints.CHINA_FIRST.first { it.name == "AliDNS" }

        assertEquals("https://dns.alidns.com/dns-query", ali.url)
        assertTrue(ali.bootstrapIps.containsAll(listOf("223.5.5.5", "223.6.6.6")))
    }

    @Test
    fun `every endpoint url is https`() {
        for (endpoint in DohEndpoints.CHINA_FIRST) {
            assertTrue("endpoint ${endpoint.name} must be https", endpoint.url.startsWith("https://"))
        }
    }

    @Test
    fun `every bootstrap ip is a literal ipv4 address requiring no dns lookup`() {
        assertTrue("expected at least one endpoint", DohEndpoints.CHINA_FIRST.isNotEmpty())
        for (endpoint in DohEndpoints.CHINA_FIRST) {
            assertTrue("endpoint ${endpoint.name} has no bootstrap IPs", endpoint.bootstrapIps.isNotEmpty())
            for (ip in endpoint.bootstrapIps) {
                // getByName on a literal dotted-quad performs no name-service lookup; it
                // only validates the format and round-trips to the same canonical string.
                val parsed = InetAddress.getByName(ip)
                assertEquals(ip, parsed.hostAddress)
            }
        }
    }
}
