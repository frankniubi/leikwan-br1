package com.leikwan.bridge.api

import android.content.Context
import androidx.test.core.app.ApplicationProvider
import com.leikwan.bridge.data.Prefs
import okhttp3.OkHttpClient
import okhttp3.Request
import okhttp3.mockwebserver.MockResponse
import okhttp3.mockwebserver.MockWebServer
import org.junit.Assert.*
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.RobolectricTestRunner
import org.robolectric.annotation.Config
import java.io.IOException

@RunWith(RobolectricTestRunner::class)
@Config(sdk = [34])
class DebugAuthSnapshotTest {
    private fun prefs(): Prefs {
        val context: Context = ApplicationProvider.getApplicationContext()
        return Prefs(context.getSharedPreferences("diagnostic-auth-test", Context.MODE_PRIVATE).also {
            it.edit().clear().commit()
        }).apply {
            clientId = 7
            agentToken = "old-agent"
            enrolledDeviceId = "old-device"
            deviceSessionToken = "old-session"
            deviceSessionExpiresAtMs = 5000
        }
    }

    @Test
    fun `account panel device session and expiry changes pause captured diagnostic auth`() {
        val mutations: List<(Prefs) -> Unit> = listOf(
            { it.clientId = 8 }, { it.agentToken = "new-agent" },
            { it.enrolledDeviceId = "new-device" }, { it.deviceSessionToken = "new-session" },
            { it.deviceSessionExpiresAtMs = 6000 }, { it.panelUrl = "https://other.example.com" },
            { it.logout() },
        )
        for (mutate in mutations) {
            val prefs = prefs()
            val snapshot = DebugAuthSnapshot.capture(prefs, 1000)
            snapshot.requireCurrent(prefs, 1000)
            mutate(prefs)
            try {
                snapshot.requireCurrent(prefs, 1000)
                fail("changed auth context must pause")
            } catch (error: IOException) {
                assertEquals("debug_upload_context_changed", error.message)
            }
        }
        val prefs = prefs()
        try {
            DebugAuthSnapshot.capture(prefs, 1000).requireCurrent(prefs, 5000)
            fail("expired session must pause")
        } catch (error: IOException) {
            assertEquals("debug_upload_session_expired", error.message)
        }
    }

    @Test
    fun `diagnostic interceptor pins headers even if prefs change after context check`() {
        val prefs = prefs()
        val snapshot = DebugAuthSnapshot.capture(prefs, 1000)
        MockWebServer().use { server ->
            server.enqueue(MockResponse().setBody("ok"))
            val client = OkHttpClient.Builder().addInterceptor(PanelDeviceInterceptor(
                prefs, deviceNameProvider = {
                    // Simulate a logout/login race after the interceptor checked the snapshot.
                    prefs.agentToken = "new-agent"
                    prefs.enrolledDeviceId = "new-device"
                    prefs.deviceSessionToken = "new-session"
                    "test-device"
                }, clientVersion = "0.2.52", nowMs = { 1000 }, debugAuthSnapshot = snapshot,
            )).build()
            client.newCall(Request.Builder().url(server.url("/api/client/debug/log")).build()).execute().close()
            val request = server.takeRequest()
            assertEquals("Bearer old-agent", request.getHeader("Authorization"))
            assertEquals("old-device", request.getHeader("X-Device-Id"))
            assertEquals("old-session", request.getHeader("X-Device-Session"))
        }
    }

    @Test
    fun `diagnostic interceptor refuses changed identity before any network send`() {
        val prefs = prefs()
        val snapshot = DebugAuthSnapshot.capture(prefs, 1000)
        prefs.agentToken = "new-agent"
        MockWebServer().use { server ->
            val client = OkHttpClient.Builder().retryOnConnectionFailure(false)
                .addInterceptor(PanelDeviceInterceptor(prefs, { "test" }, "0.2.52", { 1000 }, snapshot)).build()
            try {
                client.newCall(Request.Builder().url(server.url("/api/client/debug/log")).build()).execute().close()
                fail("changed enrollment must not send")
            } catch (error: IOException) {
                assertEquals("debug_upload_context_changed", error.message)
            }
            assertEquals(0, server.requestCount)
        }
    }
}
