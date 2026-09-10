package com.leikwan.bridge.net

import okhttp3.Dns
import okhttp3.HttpUrl.Companion.toHttpUrl
import okhttp3.OkHttpClient
import okhttp3.dnsoverhttps.DnsOverHttps
import java.net.InetAddress

/**
 * Builds the application's shared DNS resolver: DoH providers reachable inside mainland
 * China tried in order, with the platform system resolver as a last-resort fallback so
 * login still works on networks where DoH endpoints are unreachable.
 *
 * Wiring this as OkHttp's [okhttp3.Dns] makes panel login / heartbeat / config and
 * subscription requests resolve hostnames over HTTPS, bypassing GFW UDP/53 poisoning
 * that otherwise returns a bogus IP for the panel domain.
 */
object AppDns {

    @Volatile
    private var cached: Dns? = null
    @Volatile
    private var strictCached: Dns? = null

    /** Shared DoH-first resolver, built once and reused across OkHttp clients. */
    fun shared(): Dns = cached ?: synchronized(this) {
        cached ?: build(includeSystemFallback = true).also { cached = it }
    }

    /** DoH-only resolver for panel control-plane requests on poisoned networks. */
    fun strict(): Dns = strictCached ?: synchronized(this) {
        strictCached ?: build(includeSystemFallback = false).also { strictCached = it }
    }

    private fun build(includeSystemFallback: Boolean): Dns {
        // One bootstrap client carries the DoH HTTPS requests for every provider, so all
        // resolvers share its connection pool.
        val bootstrapClient = OkHttpClient.Builder().build()
        val resolvers = DohEndpoints.CHINA_FIRST.map { endpoint ->
            DnsOverHttps.Builder()
                .client(bootstrapClient)
                .url(endpoint.url.toHttpUrl())
                // Literal IPs — getByName performs no name-service lookup, so the very
                // first DoH request needs no prior (poisoned) system DNS resolution.
                .bootstrapDnsHosts(endpoint.bootstrapIps.map { InetAddress.getByName(it) })
                // Panel/subscription hosts are IPv4; skipping AAAA reduces latency and
                // failure surface on IPv4-only mobile networks.
                .includeIPv6(false)
                .build()
        }.toMutableList<Dns>()
        if (includeSystemFallback) {
            // Last resort: in a poisoned environment this is no worse than the pre-DoH
            // behaviour; on clean networks it keeps resolution working if every DoH endpoint
            // is unreachable.
            resolvers += Dns.SYSTEM
        }
        return FallbackDns(resolvers)
    }
}
