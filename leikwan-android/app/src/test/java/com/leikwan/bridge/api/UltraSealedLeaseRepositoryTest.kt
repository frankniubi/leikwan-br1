package com.leikwan.bridge.api

import android.content.Context
import androidx.test.core.app.ApplicationProvider
import com.google.gson.JsonObject
import com.google.gson.JsonParser
import com.leikwan.bridge.data.Prefs
import com.leikwan.bridge.data.clientrules.ClientRouteRuleStore
import com.leikwan.bridge.runtime.UltraPolicy
import com.leikwan.bridge.security.DeviceSecurityResult
import com.leikwan.bridge.security.UltraLeaseKey
import com.leikwan.bridge.security.UltraLeaseSeal
import com.leikwan.bridge.security.UltraLeaseTestSealer
import com.leikwan.bridge.security.WrappedUltraLeaseKeyFile
import kotlinx.coroutines.runBlocking
import okhttp3.OkHttpClient
import okhttp3.mockwebserver.MockResponse
import okhttp3.mockwebserver.MockWebServer
import org.junit.After
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertThrows
import org.junit.Before
import org.junit.Rule
import org.junit.Test
import org.junit.rules.TemporaryFolder
import org.junit.runner.RunWith
import org.robolectric.RobolectricTestRunner
import org.robolectric.annotation.Config
import java.io.File
import java.time.Instant
import java.util.Base64
import javax.crypto.spec.SecretKeySpec

/** docs/specs/2026-10-02-ultra-sealed-lease-v1.md: header on every lease call, sealed leases open. */
@RunWith(RobolectricTestRunner::class)
@Config(sdk = [34])
class UltraSealedLeaseRepositoryTest {
    @get:Rule val temp = TemporaryFolder()

    private lateinit var server: MockWebServer
    private lateinit var context: Context
    private lateinit var prefs: Prefs
    private lateinit var deviceKey: UltraLeaseKey
    private val now = Instant.parse("2030-01-01T00:00:00Z")
    private val target = "origin.example:443"
    private val generation = "a".repeat(64)
    private val expiresAt = now.plusSeconds(300)

    @Before
    fun setUp() {
        server = MockWebServer().apply { start() }
        context = ApplicationProvider.getApplicationContext()
        prefs = Prefs(
            context.getSharedPreferences("ultra-sealed-lease-test", Context.MODE_PRIVATE).also {
                it.edit().clear().commit()
            },
        ).apply {
            panelUrl = server.url("/").toString()
            clientId = 17L
            agentToken = "agent-token"
            enrolledDeviceId = "android-client-17-test"
            deviceSessionToken = "device-session"
            deviceSessionExpiresAtMs = now.plusSeconds(3_600).toEpochMilli()
        }
        deviceKey = WrappedUltraLeaseKeyFile(File(temp.root, "lease.bin")) {
            SecretKeySpec(ByteArray(32) { 5 }, "AES")
        }.create()
    }

    @After
    fun tearDown() {
        server.shutdown()
    }

    private fun repository(keyProvider: () -> UltraLeaseKey?): PanelRepository = PanelRepository(
        context = context,
        prefsOverride = prefs,
        routeRuleStore = ClientRouteRuleStore.from(context),
        httpClientOverride = OkHttpClient.Builder().addInterceptor { chain ->
            val request = chain.request()
            val localUrl = request.url.newBuilder()
                .scheme("http")
                .host(server.hostName)
                .port(server.port)
                .build()
            chain.proceed(request.newBuilder().url(localUrl).build())
        }.build(),
        deviceIdProvider = { "android-client-17-test" },
        deviceSecurityCheck = { DeviceSecurityResult.Allowed },
        nowMs = { now.toEpochMilli() },
        ultraLeaseKeyProvider = keyProvider,
    )

    private fun policy(repository: PanelRepository): UltraPolicy = runBlocking {
        server.enqueue(MockResponse().setResponseCode(200).setBody("""{"ok":true}"""))
        server.enqueue(MockResponse().setResponseCode(200).setBody(pullBody()))
        repository.pullCatalog(90L)
        server.takeRequest()
        server.takeRequest()
        UltraPolicy.from(repository.getCachedEffectiveForward(90L)!!.runtimeContract, now)!!
    }

    @Test
    fun `sealed lease opens and the lease key header is sent`() = runBlocking {
        val repository = repository { deviceKey }
        val policy = policy(repository)
        server.enqueue(leaseResponse(UltraLeaseTestSealer.sealLease(clearLease(), deviceKey.publicKeyRaw())))

        val lease = repository.resolveUltraLeases(policy, maxCandidates = 1).single()

        val request = server.takeRequest()
        assertEquals("/api/client/ultra/lease", request.path)
        val header = request.getHeader(UltraLeaseSeal.HEADER)!!
        assertFalse(header.contains('=') || header.contains('+') || header.contains('/'))
        assertEquals(65, Base64.getUrlDecoder().decode(header).size)
        assertEquals(UltraLeaseSeal.headerValue(deviceKey), header)
        assertEquals("203.0.113.90", lease.endpointIp)
        assertEquals(5438, lease.endpointPort)
        assertEquals("line-user", lease.user.toString(Charsets.UTF_8))
        assertEquals("line-password", lease.password.toString(Charsets.UTF_8))
        lease.wipe()
    }

    @Test
    fun `tampered sealed lease fails with lease_unseal_failed`() = runBlocking {
        val repository = repository { deviceKey }
        val policy = policy(repository)
        val sealed = UltraLeaseTestSealer.sealLease(clearLease(), deviceKey.publicKeyRaw())
        val envelope = sealed.getAsJsonObject("sealed")
        val ct = Base64.getUrlDecoder().decode(envelope.get("ct").asString)
        ct[0] = (ct[0].toInt() xor 0x01).toByte()
        envelope.addProperty("ct", Base64.getUrlEncoder().withoutPadding().encodeToString(ct))
        server.enqueue(leaseResponse(sealed))

        val error = assertThrows(IllegalStateException::class.java) {
            runBlocking { repository.resolveUltraLeases(policy, maxCandidates = 1) }
        }
        assertEquals(UltraLeaseSeal.UNSEAL_FAILED, error.message)
    }

    @Test
    fun `unsealed lease from an optional or older panel still works`() = runBlocking {
        val repository = repository { deviceKey }
        val policy = policy(repository)
        server.enqueue(leaseResponse(clearLease()))

        val lease = repository.resolveUltraLeases(policy, maxCandidates = 1).single()

        assertEquals(UltraLeaseSeal.headerValue(deviceKey), server.takeRequest().getHeader(UltraLeaseSeal.HEADER))
        assertEquals("203.0.113.90", lease.endpointIp)
        lease.wipe()
    }

    @Test
    fun `without a usable key the header is omitted and a sealed lease is refused`() = runBlocking {
        val repository = repository { null }
        val policy = policy(repository)
        server.enqueue(leaseResponse(clearLease()))
        server.enqueue(leaseResponse(UltraLeaseTestSealer.sealLease(clearLease(), deviceKey.publicKeyRaw())))

        repository.resolveUltraLeases(policy, maxCandidates = 1).forEach { it.wipe() }
        assertNull(server.takeRequest().getHeader(UltraLeaseSeal.HEADER))
        val error = assertThrows(IllegalStateException::class.java) {
            runBlocking { repository.resolveUltraLeases(policy, maxCandidates = 1) }
        }
        assertEquals(UltraLeaseSeal.UNSEAL_FAILED, error.message)
    }

    @Test
    fun `panel key refusals surface as lease errors and never rotate the key`() = runBlocking {
        val repository = repository { deviceKey }
        val policy = policy(repository)
        listOf(
            400 to "lease_key_invalid",
            409 to "lease_sealing_required",
            409 to "lease_device_key_conflict",
        ).forEach { (status, code) ->
            server.enqueue(MockResponse().setResponseCode(status).setBody("""{"ok":false,"error":"$code"}"""))
            val error = assertThrows(IllegalStateException::class.java) {
                runBlocking { repository.resolveUltraLeases(policy, maxCandidates = 1) }
            }
            assertEquals(code, error.message)
            assertEquals(UltraLeaseSeal.headerValue(deviceKey), server.takeRequest().getHeader(UltraLeaseSeal.HEADER))
        }
    }

    private fun leaseResponse(lease: JsonObject): MockResponse = MockResponse()
        .setResponseCode(200)
        .setBody(JsonObject().apply {
            addProperty("ok", true)
            add("lease_seal", JsonObject().apply { addProperty("v", 1) })
            add("leases", com.google.gson.JsonArray().apply { add(lease) })
        }.toString())

    private fun clearLease(): JsonObject = JsonParser.parseString(
        """
        {
          "protocol":"leikwan-ultra-policy-v2",
          "forward_id":90,
          "session_ticket":"ticket-90",
          "policy_generation":"$generation",
          "lease_id":"ul2_sealed_lease_1234567890",
          "expires_at":"$expiresAt",
          "transport":"tcp",
          "rate_limit_mbps":20,
          "quota_remaining_bytes":1048576,
          "selection_ref":"selection_ref_1234567890",
          "target_digest":"${UltraPolicy.targetDigest(target)}",
          "nonce_hash":"${"b".repeat(64)}",
          "endpoint":{"ip":"203.0.113.90","port":5438},
          "credential":{"user":"line-user","password":"line-password"},
          "direct_ip_cidrs":["203.0.113.90/32"]
        }
        """.trimIndent(),
    ).asJsonObject

    private fun pullBody(): String = """
        {
          "ok":true,
          "client":{},
          "forwards":[{
            "forward_id":90,
            "service_name":"Ultra service",
            "target":"$target",
            "listen_port":11090,
            "mode":"ultra",
            "backend_mode":"ultra",
            "client_mode":"ultra",
            "transport":"tcp",
            "service_status":"active",
            "endpoint_mode":"managed",
            "endpoints":[],
            "ultra_access":{
              "protocol":"leikwan-ultra-policy-v2",
              "forward_id":90,
              "session_ticket":"ticket-90",
              "policy_generation":"$generation",
              "issued_at":"$now",
              "renew_after":"${now.plusSeconds(240)}",
              "expires_at":"$expiresAt",
              "service_state":"active",
              "transport":"tcp",
              "rate_limit_mbps":20,
              "quota_remaining_bytes":1048576,
              "target_digest":"${UltraPolicy.targetDigest(target)}"
            }
          }]
        }
    """.trimIndent()
}
