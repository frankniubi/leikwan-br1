package com.leikwan.bridge.api

import android.content.Context
import androidx.test.core.app.ApplicationProvider
import com.leikwan.bridge.BuildConfig
import com.leikwan.bridge.data.Prefs
import com.leikwan.bridge.data.clientrules.ClientRouteRuleStore
import com.leikwan.bridge.security.AttestationProof
import com.leikwan.bridge.security.DeviceSecurityResult
import com.leikwan.bridge.session.PanelControlException
import kotlinx.coroutines.runBlocking
import okhttp3.OkHttpClient
import okhttp3.mockwebserver.MockResponse
import okhttp3.mockwebserver.MockWebServer
import org.junit.After
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Before
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.RobolectricTestRunner
import org.robolectric.annotation.Config
import java.time.Instant
import java.util.Base64

@RunWith(RobolectricTestRunner::class)
@Config(sdk = [34])
class AndroidEnrollmentRepositoryTest {
    private lateinit var server: MockWebServer
    private lateinit var prefs: Prefs
    private lateinit var repository: PanelRepository
    private val nowMs = Instant.parse("2030-01-01T00:00:00Z").toEpochMilli()
    private val deviceId = "android-client-17-aabbccddeeff00112233445566778899"
    private val identitySpki = "fixture-identity-spki".toByteArray()
    private val identitySignature = "fixture-identity-signature".toByteArray()
    private var identitySigningAvailable = true
    private var identityKeyAvailable = true

    @Before
    fun setUp() {
        server = MockWebServer().apply { start() }
        val context = ApplicationProvider.getApplicationContext<Context>()
        val shared = context.getSharedPreferences("android-enrollment-repository-test", Context.MODE_PRIVATE)
        shared.edit().clear().commit()
        prefs = Prefs(shared).apply { panelUrl = server.url("/").toString() }
        repository = PanelRepository(
            context = context,
            prefsOverride = prefs,
            routeRuleStore = ClientRouteRuleStore.from(context),
            httpClientOverride = OkHttpClient(),
            deviceIdProvider = { deviceId },
            identityPublicKeyProvider = {
                if (!identityKeyAvailable) throw IllegalStateException("identity key unavailable")
                identitySpki.copyOf()
            },
            identitySigner = { message ->
                if (!identitySigningAvailable) throw IllegalStateException("identity signing unavailable")
                assertTrue(message.toString(Charsets.UTF_8).startsWith("LEIKWAN_ANDROID_SESSION_RENEWAL_V1|"))
                identitySignature.copyOf()
            },
            attestationProofProvider = { nonce, clientId, requestedDeviceId ->
                assertEquals("challenge-a", nonce)
                assertEquals(17L, clientId)
                assertEquals(deviceId, requestedDeviceId)
                AttestationProof(
                    certificateChain = listOf("leaf-certificate"),
                    identityPublicKey = "identity-public-key",
                    bindingSignature = "binding-signature",
                )
            },
            deviceNameProvider = { "Pixel QA" },
            deviceSecurityCheck = { DeviceSecurityResult.Allowed },
            nowMs = { nowMs },
        )
    }

    @After
    fun tearDown() {
        server.shutdown()
    }

    @Test
    fun `enroll uses agent token identity headers and persists short device session`() = runBlocking {
        server.enqueue(json(200, """{"ok":true,"nonce":"challenge-a","expires_at":"2030-01-01T00:05:00Z"}"""))
        server.enqueue(json(200, """{"ok":true,"client_id":17,"device_session":"device-session","expires_at":"2030-01-01T00:15:00Z","command_version":0}"""))

        val result = repository.enroll(17L, "agent-token")

        assertEquals(17L, result.clientId)
        assertEquals(deviceId, result.deviceId)
        assertEquals("device-session", prefs.deviceSessionToken)
        assertTrue(prefs.isLoggedIn(nowMs))

        val challenge = server.takeRequest()
        assertEquals("/api/client/devices/challenge", challenge.path)
        assertEquals("Bearer agent-token", challenge.getHeader("Authorization"))
        assertEquals(deviceId, challenge.getHeader("X-Device-Id"))
        assertEquals("Pixel QA", challenge.getHeader("X-Device-Name"))
        assertEquals("android-host", challenge.getHeader("X-Device-Platform"))
        assertEquals(BuildConfig.VERSION_NAME, challenge.getHeader("X-Client-Version"))
        assertTrue(challenge.getHeader("User-Agent")!!.startsWith("LeiKwan-Host-Android/"))
        assertNull(challenge.getHeader("X-Device-Session"))
        assertFalse(challenge.body.readUtf8().contains("password", ignoreCase = true))

        val enroll = server.takeRequest()
        assertEquals("/api/client/devices/identity/enroll", enroll.path)
        assertEquals("Bearer agent-token", enroll.getHeader("Authorization"))
        val body = enroll.body.readUtf8()
        assertTrue(body.contains("\"client_id\":17"))
        assertTrue(body.contains("\"identity_public_key\""))
        assertFalse(body.contains("attestation_proof"))
        assertFalse(body.contains("email", ignoreCase = true))

        server.enqueue(json(200, """{"ok":true}"""))
        assertTrue(repository.heartbeat())
        val heartbeat = server.takeRequest()
        assertEquals("device-session", heartbeat.getHeader("X-Device-Session"))
    }

    @Test
    fun `enroll uses identity enrollment without generating hardware attestation`() = runBlocking {
        server.enqueue(json(200, """{"ok":true,"client_id":17,"nonce":"challenge-a","expires_at":"2030-01-01T00:05:00Z"}"""))
        server.enqueue(json(200, """{"ok":true,"client_id":17,"device_session":"renewed-session","expires_at":"2030-01-01T00:15:00Z","command_version":0}"""))

        val result = repository.enroll(17L, "agent-token")

        assertEquals(deviceId, result.deviceId)
        assertEquals("renewed-session", prefs.deviceSessionToken)
        val challenge = server.takeRequest()
        assertEquals("/api/client/devices/challenge", challenge.path)
        assertEquals("Bearer agent-token", challenge.getHeader("Authorization"))
        val enrollment = server.takeRequest()
        assertEquals("/api/client/devices/identity/enroll", enrollment.path)
        val body = enrollment.body.readUtf8()
        assertTrue(body.contains("\"identity_public_key\":\"${Base64.getEncoder().encodeToString(identitySpki)}\""))
        assertFalse(body.contains("attestation_proof"))
    }

    @Test
    fun `expired session uses trusted identity renewal before attestation fallback`() = runBlocking {
        prefs.clientId = 17L
        prefs.agentToken = "agent-token"
        prefs.enrolledDeviceId = deviceId
        prefs.deviceSessionToken = "expired-session"
        prefs.deviceSessionExpiresAtMs = Instant.parse("2029-12-31T23:59:59Z").toEpochMilli()
        server.enqueue(json(200, """{"ok":true,"client_id":17,"nonce":"challenge-a","expires_at":"2030-01-01T00:05:00Z"}"""))
        server.enqueue(json(200, """{"ok":true,"client_id":17,"device_session":"renewed-session","expires_at":"2030-01-01T00:15:00Z","command_version":0}"""))

        val result = repository.requireActiveSession()

        assertEquals("renewed-session", prefs.deviceSessionToken)
        assertEquals(deviceId, result.deviceId)
        assertEquals("/api/client/devices/challenge", server.takeRequest().path)
        assertEquals("/api/client/devices/identity/enroll", server.takeRequest().path)
    }

    @Test
    fun `identity enrollment does not require identity signing`() = runBlocking {
        identitySigningAvailable = false
        server.enqueue(json(200, """{"ok":true,"client_id":17,"nonce":"challenge-a","expires_at":"2030-01-01T00:05:00Z"}"""))
        server.enqueue(json(200, """{"ok":true,"client_id":17,"device_session":"simple-renewed-session","expires_at":"2030-01-01T00:15:00Z","command_version":0}"""))

        val result = repository.enroll(17L, "agent-token")

        assertEquals("simple-renewed-session", prefs.deviceSessionToken)
        assertEquals("/api/client/devices/challenge", server.takeRequest().path)
        val enrollment = server.takeRequest()
        assertEquals("/api/client/devices/identity/enroll", enrollment.path)
        val body = enrollment.body.readUtf8()
        assertTrue(body.contains("\"identity_public_key\":\"${Base64.getEncoder().encodeToString(identitySpki)}\""))
        assertFalse(body.contains("attestation_proof"))
        assertEquals(deviceId, result.deviceId)
    }

    @Test
    fun `identity enrollment falls back to device id when keystore public key is unavailable`() = runBlocking {
        identityKeyAvailable = false
        server.enqueue(json(200, """{"ok":true,"client_id":17,"nonce":"challenge-a","expires_at":"2030-01-01T00:05:00Z"}"""))
        server.enqueue(json(200, """{"ok":true,"client_id":17,"device_session":"device-id-session","expires_at":"2030-01-01T00:15:00Z","command_version":0}"""))

        val result = repository.enroll(17L, "agent-token")

        assertEquals("device-id-session", prefs.deviceSessionToken)
        assertEquals("/api/client/devices/challenge", server.takeRequest().path)
        val enrollment = server.takeRequest()
        assertEquals("/api/client/devices/identity/enroll", enrollment.path)
        assertTrue(enrollment.body.readUtf8().contains("\"identity_public_key\":\"\""))
        assertEquals(deviceId, result.deviceId)
    }

    @Test
    fun `expired enrollment response is rejected and session is not persisted`() = runBlocking {
        server.enqueue(json(200, """{"ok":true,"nonce":"challenge-a","expires_at":"2030-01-01T00:05:00Z"}"""))
        server.enqueue(json(200, """{"ok":true,"client_id":17,"device_session":"expired-session","expires_at":"2029-12-31T23:59:59Z","command_version":0}"""))

        val thrown = runCatching { repository.enroll(17L, "agent-token") }.exceptionOrNull()

        assertEquals("device_session_expired", thrown?.message)
        assertNull(prefs.deviceSessionToken)
        assertFalse(prefs.isLoggedIn(nowMs))
    }

    @Test
    fun `kick ban and untrusted responses become redacted control exceptions`() = runBlocking {
        seedActiveEnrollment()
        val fixtures = listOf(
            Triple("device_kicked", 409, "kick"),
            Triple("device_banned", 403, "ban"),
            Triple("device_untrusted", 403, null),
        )

        fixtures.forEachIndexed { index, (code, status, action) ->
            server.enqueue(
                json(
                    status,
                    """{"ok":false,"error":"$code","command":{"version":${index + 1},"action":"${action.orEmpty()}","reason":"panel"},"endpoint":"must-not-leak"}""",
                ),
            )

            val thrown = runCatching { repository.heartbeat() }.exceptionOrNull()
            assertTrue(thrown is PanelControlException)
            thrown as PanelControlException
            assertEquals(code, thrown.code)
            assertEquals(action, thrown.command?.action?.takeIf { it.isNotBlank() })
            assertEquals(code, thrown.message)
            assertFalse(thrown.toString().contains("must-not-leak"))
        }
    }

    private fun seedActiveEnrollment() {
        prefs.clientId = 17L
        prefs.agentToken = "agent-token"
        prefs.enrolledDeviceId = deviceId
        prefs.deviceSessionToken = "device-session"
        prefs.deviceSessionExpiresAtMs = Instant.parse("2030-01-01T00:15:00Z").toEpochMilli()
    }

    private fun json(status: Int, body: String): MockResponse = MockResponse()
        .setResponseCode(status)
        .setHeader("Content-Type", "application/json")
        .setBody(body)
}
