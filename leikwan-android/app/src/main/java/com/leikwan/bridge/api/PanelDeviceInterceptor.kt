package com.leikwan.bridge.api

import com.google.gson.Gson
import com.leikwan.bridge.data.Prefs
import com.leikwan.bridge.session.DeviceControlEnvelope
import com.leikwan.bridge.session.PanelControlException
import okhttp3.Interceptor
import okhttp3.Response
import java.io.IOException
import java.security.MessageDigest

/** Diagnostic calls pin one enrollment/session; secrets are never persisted or logged. */
class DebugAuthSnapshot internal constructor(
    internal val panelUrl: String,
    internal val clientId: Long,
    internal val agentToken: String,
    internal val deviceId: String,
    internal val sessionToken: String,
    internal val sessionExpiresAtMs: Long,
) {
    internal val authorization: String get() = "Bearer $agentToken"
    // Stable across session renewal, but never across account, panel, device or agent-token changes.
    internal val binding: String get() = MessageDigest.getInstance("SHA-256")
        .digest(listOf(panelUrl, clientId.toString(), agentToken, deviceId).joinToString("\u0000").toByteArray())
        .joinToString("") { "%02x".format(it) }

    internal fun requireCurrent(prefs: Prefs, nowMs: Long) {
        if (prefs.panelUrl != panelUrl || prefs.clientId != clientId || prefs.agentToken != agentToken ||
            prefs.enrolledDeviceId != deviceId || prefs.deviceSessionToken != sessionToken ||
            prefs.deviceSessionExpiresAtMs != sessionExpiresAtMs
        ) throw IOException("debug_upload_context_changed")
        if (sessionExpiresAtMs <= nowMs) throw IOException("debug_upload_session_expired")
    }

    internal companion object {
        fun capture(prefs: Prefs, nowMs: Long): DebugAuthSnapshot {
            val snapshot = DebugAuthSnapshot(prefs.panelUrl, prefs.clientId, prefs.agentToken.orEmpty(),
                prefs.enrolledDeviceId.orEmpty(), prefs.deviceSessionToken.orEmpty(), prefs.deviceSessionExpiresAtMs)
            if (snapshot.clientId <= 0 || snapshot.agentToken.isBlank() || snapshot.deviceId.isBlank() ||
                snapshot.sessionToken.isBlank()) throw IOException("debug_upload_not_enrolled")
            snapshot.requireCurrent(prefs, nowMs)
            return snapshot
        }
    }
}

class PanelDeviceInterceptor(
    private val prefs: Prefs,
    private val deviceNameProvider: () -> String,
    private val clientVersion: String,
    private val nowMs: () -> Long,
    private val debugAuthSnapshot: DebugAuthSnapshot? = null,
) : Interceptor {
    override fun intercept(chain: Interceptor.Chain): Response {
        val builder = chain.request().newBuilder()
            .header("X-Device-Platform", "android-host")
            .header("X-Client-Version", clientVersion)
            .header("User-Agent", "LeiKwan-Host-Android/$clientVersion")

        if (debugAuthSnapshot != null) {
            debugAuthSnapshot.requireCurrent(prefs, nowMs())
            builder.header("Authorization", debugAuthSnapshot.authorization)
                .header("X-Device-Id", debugAuthSnapshot.deviceId)
                .header("X-Device-Session", debugAuthSnapshot.sessionToken)
        } else {
            prefs.agentToken?.takeIf { it.isNotBlank() }?.let { builder.header("Authorization", "Bearer $it") }
            prefs.enrolledDeviceId?.takeIf { it.isNotBlank() }?.let { builder.header("X-Device-Id", it) }
            prefs.deviceSessionToken
                ?.takeIf { it.isNotBlank() && prefs.deviceSessionExpiresAtMs > nowMs() }
                ?.let { builder.header("X-Device-Session", it) }
        }
        sanitizeHeader(deviceNameProvider())?.let { builder.header("X-Device-Name", it) }

        return chain.proceed(builder.build())
    }

    private fun sanitizeHeader(value: String): String? = value
        .replace('\r', ' ')
        .replace('\n', ' ')
        .trim()
        .take(128)
        .takeIf(String::isNotBlank)
}

class PanelControlInterceptor(
    private val gson: Gson = Gson(),
) : Interceptor {
    override fun intercept(chain: Interceptor.Chain): Response {
        val response = chain.proceed(chain.request())
        if (response.code == 429) {
            // A full online device limit is recoverable, never terminal: surface it as its own
            // exception so every endpoint (Response<T> or plain body) reports it the same way.
            val limit = PanelErrorParser.deviceLimitFromHttp(
                response.code,
                runCatching { response.peekBody(MAX_CONTROL_BODY_BYTES).string() }.getOrNull(),
            ) ?: return response
            response.close()
            throw limit
        }
        if (response.code != 403 && response.code != 409) return response

        val envelope = runCatching {
            gson.fromJson(response.peekBody(MAX_CONTROL_BODY_BYTES).string(), DeviceControlEnvelope::class.java)
        }.getOrNull() ?: return response
        if (envelope.error !in terminalCodes) return response

        response.close()
        throw PanelControlException(envelope.error, envelope.command)
    }

    private companion object {
        const val MAX_CONTROL_BODY_BYTES = 16_384L
        val terminalCodes = setOf("device_kicked", "device_banned", "device_untrusted")
    }
}
