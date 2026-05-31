package com.leikwan.bridge.net

import android.util.Log
import okhttp3.Interceptor
import okhttp3.OkHttpClient
import okhttp3.Request
import java.security.MessageDigest
import java.security.cert.Certificate
import java.security.cert.CertificateException
import java.security.cert.X509Certificate
import javax.net.ssl.HostnameVerifier
import javax.net.ssl.SSLContext
import javax.net.ssl.SSLPeerUnverifiedException
import javax.net.ssl.SSLSession
import javax.net.ssl.TrustManager
import javax.net.ssl.X509TrustManager

/**
 * SNI-free fallback for the public panel mirror.
 *
 * Field probe on 2026-05-31 showed the mirror's normal SNI path resets before
 * TLS completes on affected networks, while connecting to the same Photon edge
 * IP without SNI and preserving the HTTP Host header reaches `/api/client/login`.
 *
 * This is intentionally narrow:
 * - only `v3-mirror.leikwanhost.com`
 * - only a pinned Photon edge certificate
 * - only the known mirror edge address family
 */
object MirrorNoSni {
    const val mirrorHost: String = "v3-mirror.leikwanhost.com"

    val edgeIps: List<String> = listOf(
        "23.147.52.11",
        "23.147.52.12",
        "23.147.52.13",
        "23.147.52.14",
        "23.147.52.15",
        "23.147.52.16",
        "23.147.52.21",
        "23.147.52.22",
        "23.147.52.23",
        "23.147.52.24",
        "23.147.52.25",
        "23.147.52.26",
        "23.147.52.31",
        "23.147.52.32",
        "23.147.52.33",
        "23.147.52.34",
        "23.147.52.35",
        "23.147.52.36",
    )

    val pinnedLeafSha256: Set<String> = setOf(
        "58CC3F15EFC94E18836E72C2E71703D1E5DA90DECC0149476089689F6D85A642",
    )

    fun shouldRewrite(host: String?): Boolean =
        host.equals(mirrorHost, ignoreCase = true)

    fun rewrite(request: Request, edgeIp: String = edgeIps.first()): Request {
        val originalHost = request.url.host
        if (!shouldRewrite(originalHost)) return request
        require(edgeIp in edgeIps) { "unsupported mirror edge IP: $edgeIp" }
        val rewrittenUrl = request.url.newBuilder()
            .host(edgeIp)
            .build()
        return request.newBuilder()
            .url(rewrittenUrl)
            .header("Host", originalHost)
            .build()
    }

    fun applyTo(builder: OkHttpClient.Builder): OkHttpClient.Builder {
        val trustManager = PinnedLeafTrustManager(pinnedLeafSha256)
        val sslContext = SSLContext.getInstance("TLS")
        sslContext.init(null, arrayOf<TrustManager>(trustManager), null)
        return builder
            .addInterceptor(MirrorNoSniInterceptor())
            .sslSocketFactory(sslContext.socketFactory, trustManager)
            .hostnameVerifier(MirrorNoSniHostnameVerifier)
    }

    fun isPinned(certificate: Certificate): Boolean =
        sha256(certificate.encoded) in pinnedLeafSha256

    private fun sha256(bytes: ByteArray): String =
        MessageDigest.getInstance("SHA-256")
            .digest(bytes)
            .joinToString("") { "%02X".format(it.toInt() and 0xff) }
}

class MirrorNoSniInterceptor(
    private val edgeIp: String = MirrorNoSni.edgeIps.first(),
) : Interceptor {
    override fun intercept(chain: Interceptor.Chain): okhttp3.Response {
        val original = chain.request()
        val rewritten = MirrorNoSni.rewrite(original, edgeIp)
        if (rewritten !== original) {
            Log.i("LKMirrorNoSni", "rewriting ${original.url.host} to $edgeIp with preserved Host header")
        }
        return chain.proceed(rewritten)
    }
}

private class PinnedLeafTrustManager(
    private val pins: Set<String>,
) : X509TrustManager {
    override fun checkClientTrusted(chain: Array<out X509Certificate>?, authType: String?) = Unit

    override fun checkServerTrusted(chain: Array<out X509Certificate>?, authType: String?) {
        val leaf = chain?.firstOrNull()
            ?: throw CertificateException("missing mirror edge certificate")
        val digest = MessageDigest.getInstance("SHA-256")
            .digest(leaf.encoded)
            .joinToString("") { "%02X".format(it.toInt() and 0xff) }
        if (digest !in pins) {
            throw CertificateException("mirror edge certificate pin mismatch")
        }
    }

    override fun getAcceptedIssuers(): Array<X509Certificate> = emptyArray()
}

private object MirrorNoSniHostnameVerifier : HostnameVerifier {
    override fun verify(hostname: String?, session: SSLSession?): Boolean {
        if (hostname !in MirrorNoSni.edgeIps || session == null) return false
        return try {
            MirrorNoSni.isPinned(session.peerCertificates.first())
        } catch (_: SSLPeerUnverifiedException) {
            false
        } catch (_: Exception) {
            false
        }
    }
}
