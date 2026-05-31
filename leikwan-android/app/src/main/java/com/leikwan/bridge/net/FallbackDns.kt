package com.leikwan.bridge.net

import okhttp3.Dns
import java.net.InetAddress
import java.net.UnknownHostException

/**
 * An [okhttp3.Dns] that consults a list of resolvers in order and returns the first
 * non-empty answer. If a resolver throws or returns nothing, the next one is tried.
 * If every resolver fails, an [UnknownHostException] is thrown (honouring the [Dns]
 * contract).
 *
 * Used to try DoH providers first and fall back to the system resolver only as a last
 * resort.
 */
class FallbackDns(private val resolvers: List<Dns>) : Dns {
    override fun lookup(hostname: String): List<InetAddress> {
        var lastError: Exception? = null
        for (resolver in resolvers) {
            try {
                val result = resolver.lookup(hostname)
                if (result.isNotEmpty()) return result
            } catch (e: Exception) {
                // Try the next resolver; remember the failure for diagnostics.
                lastError = e
            }
        }
        throw UnknownHostException("No DNS resolver could resolve $hostname").apply {
            lastError?.let { initCause(it) }
        }
    }
}
