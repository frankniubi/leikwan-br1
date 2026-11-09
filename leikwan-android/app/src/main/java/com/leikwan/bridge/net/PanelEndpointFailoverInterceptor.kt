package com.leikwan.bridge.net

import com.leikwan.bridge.data.PanelEndpoint
import okhttp3.HttpUrl
import okhttp3.HttpUrl.Companion.toHttpUrl
import okhttp3.Interceptor
import okhttp3.Response
import java.io.IOException

/**
 * Origin allowlisted control-plane failover, shared by Retrofit endpoints.
 * Transport failures may retry replayable POSTs; a received POST response is never replayed.
 * Business refusals (401/403/409) never trigger failover. No redirects or arbitrary origins.
 */
class PanelEndpointFailoverInterceptor(
    private val origins: List<HttpUrl> = PanelEndpoint.entries.map { it.url.toHttpUrl() },
) : Interceptor {
    @Volatile private var active: HttpUrl? = null
    private fun sameOrigin(a: HttpUrl, b: HttpUrl) =
        a.scheme == b.scheme && a.host == b.host && a.port == b.port
    private fun at(url: HttpUrl, origin: HttpUrl): HttpUrl = url.newBuilder()
        .scheme(origin.scheme).host(origin.host).port(origin.port).build()

    override fun intercept(chain: Interceptor.Chain): Response {
        val request = chain.request()
        val configured = origins.firstOrNull { sameOrigin(it, request.url) } ?: return chain.proceed(request)
        val first = active?.takeIf { candidate -> origins.any { sameOrigin(it, candidate) } } ?: configured
        val ordered = listOf(first) + origins.filterNot { sameOrigin(it, first) }
        var last: IOException? = null
        for ((index, endpoint) in ordered.withIndex()) {
            val next = request.newBuilder().url(at(request.url, endpoint)).build()
            try {
                val response = chain.proceed(next)
                val safeGet = request.method == "GET" || request.method == "HEAD"
                if (safeGet && response.code in setOf(502, 503, 504, 530) && index < ordered.lastIndex) {
                    response.close()
                    continue
                }
                if (response.code !in setOf(502, 503, 504, 530)) active = endpoint
                return response
            } catch (error: IOException) {
                if (chain.call().isCanceled() || request.body?.isOneShot() == true ||
                    request.body?.isDuplex() == true) throw error
                last = error
                if (index == ordered.lastIndex) throw error
            }
        }
        throw last ?: IOException("panel endpoints unavailable")
    }
}
