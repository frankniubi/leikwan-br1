package com.leikwan.bridge.net

import okhttp3.Dns
import org.junit.Assert.assertEquals
import org.junit.Assert.assertThrows
import org.junit.Test
import java.net.InetAddress
import java.net.UnknownHostException

class FallbackDnsTest {

    private fun addr(ip: String): InetAddress = InetAddress.getByName(ip)

    /** A controllable [Dns] that either returns a fixed result or throws, and counts calls. */
    private class FakeDns(
        private val result: List<InetAddress>? = null,
        private val error: Exception? = null,
    ) : Dns {
        var calls = 0
            private set

        override fun lookup(hostname: String): List<InetAddress> {
            calls++
            error?.let { throw it }
            return result ?: emptyList()
        }
    }

    @Test
    fun `returns first resolver result without consulting later resolvers`() {
        val first = FakeDns(result = listOf(addr("1.2.3.4")))
        val second = FakeDns(result = listOf(addr("5.6.7.8")))

        val result = FallbackDns(listOf(first, second)).lookup("example.com")

        assertEquals(listOf("1.2.3.4"), result.map { it.hostAddress })
        assertEquals(1, first.calls)
        assertEquals(0, second.calls)
    }

    @Test
    fun `falls through to next resolver when first throws`() {
        val first = FakeDns(error = UnknownHostException("poisoned"))
        val second = FakeDns(result = listOf(addr("5.6.7.8")))

        val result = FallbackDns(listOf(first, second)).lookup("example.com")

        assertEquals(listOf("5.6.7.8"), result.map { it.hostAddress })
        assertEquals(1, first.calls)
        assertEquals(1, second.calls)
    }

    @Test
    fun `falls through to next resolver when first returns empty list`() {
        val first = FakeDns(result = emptyList())
        val second = FakeDns(result = listOf(addr("5.6.7.8")))

        val result = FallbackDns(listOf(first, second)).lookup("example.com")

        assertEquals(listOf("5.6.7.8"), result.map { it.hostAddress })
    }

    @Test
    fun `throws UnknownHostException when all resolvers fail`() {
        val first = FakeDns(error = UnknownHostException("a"))
        val second = FakeDns(error = RuntimeException("b"))

        val dns = FallbackDns(listOf(first, second))

        assertThrows(UnknownHostException::class.java) { dns.lookup("example.com") }
    }

    @Test
    fun `throws UnknownHostException when resolver list is empty`() {
        val dns = FallbackDns(emptyList())

        assertThrows(UnknownHostException::class.java) { dns.lookup("example.com") }
    }
}
