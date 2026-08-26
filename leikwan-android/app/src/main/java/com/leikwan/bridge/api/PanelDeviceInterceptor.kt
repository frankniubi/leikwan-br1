package com.leikwan.bridge.api

import com.google.gson.Gson
import com.leikwan.bridge.data.Prefs
import com.leikwan.bridge.session.DeviceControlEnvelope
import com.leikwan.bridge.session.PanelControlException
import okhttp3.Interceptor
import okhttp3.Response

class PanelDeviceInterceptor(
    private val prefs: Prefs,
    private val deviceNameProvider: () -> String,
    private val clientVersion: String,
    private val nowMs: () -> Long,
) : Interceptor {
    override fun intercept(chain: Interceptor.Chain): Response {
        val builder = chain.request().newBuilder()
            .header("X-Device-Platform", "android-host")
            .header("X-Client-Version", clientVersion)
            .header("User-Agent", "LeiKwan-Host-Android/$clientVersion")

        prefs.agentToken?.takeIf { it.isNotBlank() }?.let { builder.header("Authorization", "Bearer $it") }
        prefs.enrolledDeviceId?.takeIf { it.isNotBlank() }?.let { builder.header("X-Device-Id", it) }
        sanitizeHeader(deviceNameProvider())?.let { builder.header("X-Device-Name", it) }
        prefs.deviceSessionToken
            ?.takeIf { it.isNotBlank() && prefs.deviceSessionExpiresAtMs > nowMs() }
            ?.let { builder.header("X-Device-Session", it) }

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
