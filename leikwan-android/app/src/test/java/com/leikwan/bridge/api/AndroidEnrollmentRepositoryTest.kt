package com.leikwan.bridge.api

import android.content.Context
import androidx.test.core.app.ApplicationProvider
import com.leikwan.bridge.BuildConfig
import com.leikwan.bridge.data.PanelEndpoint
import com.leikwan.bridge.data.Prefs
import com.leikwan.bridge.data.clientrules.ClientRouteRuleStore
import com.leikwan.bridge.security.AttestationProof
import com.leikwan.bridge.security.DeviceSecurityResult
import com.leikwan.bridge.session.DeviceLimitGate
import com.leikwan.bridge.session.DeviceLimitNotice
import com.leikwan.bridge.session.DeviceLimitReachedException
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

@RunWith(RobolectricTestRunner::class)
@Config(sdk = [34])
class AndroidEnrollmentRepositoryTest {
    private lateinit var server: MockWebServer
    private lateinit var prefs: Prefs
    private lateinit var repository: PanelRepository
    private val observedHosts = mutableListOf<String>()
    private val nowMs = Instant.parse("2030-01-01T00:00:00Z").toEpochMilli()
    private val deviceId = "android-client-17-aabbccddeeff00112233445566778899"

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
            // Release Prefs intentionally ignores panelUrl overrides. Rewrite every
            // test request to MockWebServer so fixtures never contact the live Panel.
            httpClientOverride = OkHttpClient.Builder().addInterceptor { chain ->
                val request = chain.request()
                observedHosts += request.url.host
                val localUrl = request.url.newBuilder()
                    .scheme("http")
                    .host(server.hostName)
                    .port(server.port)
                    .build()
                chain.proceed(request.newBuilder().url(localUrl).build())
            }.build(),
            deviceIdProvider = { deviceId },
            // Robolectric does not provide the AndroidKeyStore provider. Keep
            // the production Keystore default intact and inject a stable
            // public-key fixture for the HTTP contract tests.
            identityPublicKeyProvider = { "identity-public-key".toByteArray() },
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
        DeviceLimitGate.clear()
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
        assertFalse(body.contains("email", ignoreCase = true))

        server.enqueue(json(200, """{"ok":true}"""))
        assertTrue(repository.heartbeat())
        val heartbeat = server.takeRequest()
        assertEquals("device-session", heartbeat.getHeader("X-Device-Session"))
    }

    @Test
    fun `selected panel endpoint controls enrollment request host`() = runBlocking {
        prefs.panelEndpoint = PanelEndpoint.ALIYUN_CDN
        val context = ApplicationProvider.getApplicationContext<Context>()
        val selectedRepository = PanelRepository(
            context = context,
            prefsOverride = prefs,
            routeRuleStore = ClientRouteRuleStore.from(context),
            httpClientOverride = OkHttpClient.Builder().addInterceptor { chain ->
                val request = chain.request()
                observedHosts += request.url.host
                val localUrl = request.url.newBuilder()
                    .scheme("http")
                    .host(server.hostName)
                    .port(server.port)
                    .build()
                chain.proceed(request.newBuilder().url(localUrl).build())
            }.build(),
            deviceIdProvider = { deviceId },
            identityPublicKeyProvider = { "identity-public-key".toByteArray() },
            attestationProofProvider = { _, _, _ ->
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
        server.enqueue(json(200, """{"ok":true,"nonce":"challenge-a","expires_at":"2030-01-01T00:05:00Z"}"""))
        server.enqueue(json(200, """{"ok":true,"client_id":17,"device_session":"device-session","expires_at":"2030-01-01T00:15:00Z","command_version":0}"""))

        selectedRepository.enroll(17L, "agent-token")

        assertEquals(
            listOf("api-leikwanbridge.nbwly.com", "api-leikwanbridge.nbwly.com"),
            observedHosts,
        )
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

    @Test
    fun `heartbeat does not swallow a device limit refusal and keeps enrollment`() = runBlocking {
        seedActiveEnrollment()
        server.enqueue(json(429, LIMIT_BODY))

        val thrown = runCatching { repository.heartbeat() }.exceptionOrNull()

        assertTrue(thrown is DeviceLimitReachedException)
        thrown as DeviceLimitReachedException
        assertEquals(2, thrown.limit)
        assertEquals(3, thrown.active)
        assertEquals(DeviceLimitNotice(limit = 2, active = 3), DeviceLimitGate.state.value)
        assertEquals(17L, prefs.clientId)
        assertEquals("agent-token", prefs.agentToken)
        assertEquals(deviceId, prefs.enrolledDeviceId)
        assertTrue(prefs.isLoggedIn(nowMs))
    }

    @Test
    fun `plain config pull refusal becomes a device limit and a later pull clears it`() = runBlocking {
        seedActiveEnrollment()
        server.enqueue(json(200, """{"ok":true}"""))
        server.enqueue(json(429, """{"ok":false,"error":"client_online_limit_reached","details":{"online_device_limit":1,"active_online_devices":1}}"""))

        val thrown = runCatching { repository.pullCatalog() }.exceptionOrNull()

        assertTrue("got $thrown", thrown is DeviceLimitReachedException)
        assertEquals(DeviceLimitNotice(limit = 1, active = 1), DeviceLimitGate.state.value)
        assertTrue(prefs.isLoggedIn(nowMs))

        server.enqueue(json(200, """{"ok":true}"""))
        server.enqueue(json(200, """{"ok":true,"client":{},"forwards":[]}"""))
        repository.pullCatalog()

        assertNull(DeviceLimitGate.state.value)
    }

    @Test
    fun `refused enrollment reports the device limit instead of the raw code`() = runBlocking {
        server.enqueue(json(200, """{"ok":true,"nonce":"challenge-a","expires_at":"2030-01-01T00:05:00Z"}"""))
        server.enqueue(json(429, LIMIT_BODY))

        val thrown = runCatching { repository.enroll(17L, "agent-token") }.exceptionOrNull()

        assertTrue("got $thrown", thrown is DeviceLimitReachedException)
        assertEquals(3, (thrown as DeviceLimitReachedException).active)
    }

    private fun seedActiveEnrollment() {
        prefs.clientId = 17L
        prefs.agentToken = "agent-token"
        prefs.enrolledDeviceId = deviceId
        prefs.deviceSessionToken = "device-session"
        prefs.deviceSessionExpiresAtMs = Instant.parse("2030-01-01T00:15:00Z").toEpochMilli()
    }

    private companion object {
        const val LIMIT_BODY =
            """{"ok":false,"error":"client_online_limit_reached","protocol_version":1,"online_device_limit":2,"active_online_devices":3,"details":{"online_device_limit":2,"active_online_devices":3}}"""
    }

    private fun json(status: Int, body: String): MockResponse = MockResponse()
        .setResponseCode(status)
        .setHeader("Content-Type", "application/json")
        .setBody(body)
}
