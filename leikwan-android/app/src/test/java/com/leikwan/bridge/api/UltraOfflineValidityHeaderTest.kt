package com.leikwan.bridge.api

import android.content.Context
import androidx.test.core.app.ApplicationProvider
import com.leikwan.bridge.data.Prefs
import com.leikwan.bridge.data.clientrules.ClientRouteRuleStore
import com.leikwan.bridge.runtime.UltraPolicy
import com.leikwan.bridge.security.DeviceSecurityResult
import kotlinx.coroutines.runBlocking
import okhttp3.OkHttpClient
import okhttp3.mockwebserver.MockResponse
import okhttp3.mockwebserver.MockWebServer
import org.junit.After
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNull
import org.junit.Before
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.RobolectricTestRunner
import org.robolectric.annotation.Config
import java.time.Instant

/** The client advertises its offline validity on the config pull and the Ultra lease call only. */
@RunWith(RobolectricTestRunner::class)
@Config(sdk = [34])
class UltraOfflineValidityHeaderTest {
    private lateinit var server: MockWebServer
    private lateinit var repository: PanelRepository
    private val now = Instant.parse("2030-01-01T00:00:00Z")
    private val target = "origin.example:443"
    private val generation = "a".repeat(64)

    @Before
    fun setUp() {
        server = MockWebServer().apply { start() }
        val context = ApplicationProvider.getApplicationContext<Context>()
        val prefs = Prefs(
            context.getSharedPreferences("ultra-offline-header-test", Context.MODE_PRIVATE).also {
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
        repository = PanelRepository(
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
        )
    }

    @After
    fun tearDown() {
        server.shutdown()
    }

    @Test
    fun `pull and lease advertise 1800 s offline validity and accept the long grant`() = runBlocking {
        val expiresAt = now.plusSeconds(1_800)
        server.enqueue(MockResponse().setResponseCode(200).setBody("""{"ok":true}"""))
        server.enqueue(MockResponse().setResponseCode(200).setBody(pullBody(expiresAt)))
        server.enqueue(MockResponse().setResponseCode(200).setBody(leaseBody(expiresAt)))

        repository.pullCatalog(90L)
        val contract = repository.getCachedEffectiveForward(90L)!!.runtimeContract
        val policy = UltraPolicy.from(contract, now)!!
        val leases = repository.resolveUltraLeases(policy, maxCandidates = 1)

        val heartbeat = server.takeRequest()
        val pull = server.takeRequest()
        val lease = server.takeRequest()
        assertEquals("/api/client/heartbeat", heartbeat.path)
        assertNull(heartbeat.getHeader(ULTRA_OFFLINE_VALIDITY_HEADER_NAME))
        assertEquals("/api/client/configs/pull", pull.path)
        assertEquals(ULTRA_OFFLINE_VALIDITY_SECONDS.toString(), pull.getHeader(ULTRA_OFFLINE_VALIDITY_HEADER_NAME))
        assertEquals("/api/client/ultra/lease", lease.path)
        assertEquals(ULTRA_OFFLINE_VALIDITY_SECONDS.toString(), lease.getHeader(ULTRA_OFFLINE_VALIDITY_HEADER_NAME))
        assertEquals(expiresAt, policy.expiresAt)
        assertEquals(expiresAt, leases.single().expiresAt)
        leases.forEach { it.wipe() }
    }

    @Test
    fun `header literal matches the advertised constants`() {
        assertEquals(
            "$ULTRA_OFFLINE_VALIDITY_HEADER_NAME: $ULTRA_OFFLINE_VALIDITY_SECONDS",
            ULTRA_OFFLINE_VALIDITY_HEADER,
        )
    }

    private fun pullBody(expiresAt: Instant): String = """
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

    private fun leaseBody(expiresAt: Instant): String = """
        {
          "ok":true,
          "leases":[{
            "protocol":"leikwan-ultra-policy-v2",
            "forward_id":90,
            "session_ticket":"ticket-90",
            "policy_generation":"$generation",
            "lease_id":"ul2_test_lease_1234567890",
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
          }]
        }
    """.trimIndent()
}
