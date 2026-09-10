package com.leikwan.bridge.api

import android.content.Context
import androidx.test.core.app.ApplicationProvider
import com.leikwan.bridge.data.Prefs
import com.leikwan.bridge.data.clientrules.ClientRouteRuleStore
import com.leikwan.bridge.runtime.ForwardContractParser
import com.leikwan.bridge.runtime.NativeOutboundCapability
import com.leikwan.bridge.runtime.NativeOutboundCarrier
import com.leikwan.bridge.runtime.NativeOutboundLease
import com.leikwan.bridge.security.DeviceSecurityResult
import com.google.gson.JsonParser
import kotlinx.coroutines.runBlocking
import okhttp3.OkHttpClient
import okhttp3.mockwebserver.MockResponse
import okhttp3.mockwebserver.MockWebServer
import org.junit.After
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNotNull
import org.junit.Assert.assertThrows
import org.junit.Assert.assertTrue
import org.junit.Before
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.RobolectricTestRunner
import org.robolectric.annotation.Config
import java.time.Instant

@RunWith(RobolectricTestRunner::class)
@Config(sdk = [34])
class NativeOutboundRepositoryTest {
    private lateinit var server: MockWebServer
    private lateinit var prefs: Prefs
    private lateinit var repository: PanelRepository
    private val now = Instant.parse("2030-01-01T00:00:00Z")
    private val forwardId = 159L
    private val direction = "Hong Kong -> China Mainland"
    private val target = "93.184.216.34:443"

    @Before
    fun setUp() {
        server = MockWebServer().apply { start() }
        val context = ApplicationProvider.getApplicationContext<Context>()
        prefs = Prefs(
            context.getSharedPreferences("native-outbound-repo-test", Context.MODE_PRIVATE).also {
                it.edit().clear().commit()
            },
        ).apply {
            panelUrl = server.url("/").toString()
            clientId = 17L
            agentToken = "agent-token"
            enrolledDeviceId = "android-client-17-test"
            deviceSessionToken = "device-session"
            deviceSessionExpiresAtMs = now.plusSeconds(900).toEpochMilli()
        }
        repository = PanelRepository(
            context = context,
            prefsOverride = prefs,
            routeRuleStore = ClientRouteRuleStore.from(context),
            // Release Prefs intentionally ignores panelUrl overrides. Rewrite every
            // test request to MockWebServer so fixtures never contact the live Panel.
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
    fun `native pull capability and lease request stay redacted and scoped`() = runBlocking {
        val digest = NativeOutboundLease.scopeDigest(forwardId, direction, "tcp+udp", "cn_only")
        server.enqueue(json(200, """{"ok":true}"""))
        server.enqueue(
            json(
                200,
                """
                {
                  "ok": true,
                  "client": {"id":17},
                  "forwards": [
                    {
                      "forward_id": $forwardId,
                      "service_name": "Native carrier",
                      "target": "$target",
                      "forward_combination_direction": "$direction",
                      "listen_port": 30443,
                      "mode": "ultra",
                      "backend_mode": "ultra",
                      "transport": "tcp+udp",
                      "service_status": "active",
                      "endpoint_mode": "manual",
                      "ultra_access": {
                        "protocol": "leikwan-ultra-policy-v2",
                        "forward_id": $forwardId,
                        "session_ticket": "ticket-redacted",
                        "policy_generation": "${"a".repeat(64)}",
                        "issued_at": "2030-01-01T00:00:00Z",
                        "renew_after": "2030-01-01T00:01:00Z",
                        "expires_at": "2030-01-01T00:04:00Z",
                        "service_state": "active",
                        "transport": "tcp+udp",
                        "rate_limit_mbps": 100,
                        "quota_remaining_bytes": 1000000,
                        "target_digest": "${com.leikwan.bridge.runtime.UltraPolicy.targetDigest(target)}"
                      }
                    }
                  ],
                  "native_outbound": {
                    "enabled": true,
                    "protocol": "socks5",
                    "lease_protocol": "leikwan-ultra-native-socks5-v1",
                    "transport": "tcp+udp",
                    "forward_ids": [$forwardId],
                    "default_forward_id": $forwardId,
                    "route_scopes": ["cn_only", "global"],
                    "default_route_scope": "global",
                    "forwards": [{
                      "forward_id": $forwardId,
                      "direction": "$direction",
                      "scope_digest": "${NativeOutboundLease.scopeDigest(forwardId, direction, "tcp+udp", "global")}",
                      "transport": "tcp+udp",
                      "route_scopes": ["cn_only", "global"],
                      "default_route_scope": "global"
                    }]
                  }
                }
                """.trimIndent(),
            ),
        )
        server.enqueue(
            json(
                200,
                """
                {
                  "ok": true,
                  "leases": [{
                    "protocol": "leikwan-ultra-native-socks5-v1",
                    "purpose": "native_outbound",
                    "forward_id": $forwardId,
                    "lease_id": "ul2_native_lease",
                    "policy_generation": "${"a".repeat(64)}",
                    "expires_at": "2030-01-01T00:02:00Z",
                    "transport": "tcp+udp",
                    "direction": "$direction",
                    "route_scope": "cn_only",
                    "scope_digest": "$digest",
                    "endpoint": {"ip":"198.51.100.20","port":8443},
                    "credential": {"user":"short-user","password":"short-password"}
                  }, {
                    "protocol": "leikwan-ultra-native-socks5-v1",
                    "purpose": "native_outbound",
                    "forward_id": $forwardId,
                    "lease_id": "ul2_native_lease_second",
                    "policy_generation": "${"a".repeat(64)}",
                    "expires_at": "2030-01-01T00:02:00Z",
                    "transport": "tcp+udp",
                    "direction": "$direction",
                    "route_scope": "cn_only",
                    "scope_digest": "$digest",
                    "selection_ref": "native-selection-ref-second",
                    "selection_tier": "mixed",
                    "endpoint": {"ip":"198.51.100.21","port":8444},
                    "credential": {"user":"short-user-2","password":"short-password-2"}
                  }]
                }
                """.trimIndent(),
            ),
        )

        val catalog = repository.pullCatalog()
        val forward = repository.getCachedContract(forwardId)
        assertNotNull(catalog.nativeOutbound)
        assertNotNull(forward)
        val leases = repository.resolveNativeOutboundLeases(
            requireNotNull(forward),
            requireNotNull(catalog.nativeOutbound),
            "cn_only",
        )
        assertEquals(2, leases.size)
        val lease = leases.first()

        server.takeRequest() // heartbeat
        server.takeRequest() // pull
        val request = server.takeRequest()
        assertEquals("/api/client/ultra/lease", request.path)
        val body = request.body.readUtf8()
        val item = JsonParser.parseString(body).asJsonObject
            .getAsJsonArray("forwards")[0].asJsonObject
        assertEquals("native_outbound", item.get("purpose").asString)
        assertEquals("cn_only", item.get("route_scope").asString)
        assertEquals("restricted", item.get("route_policy").asString)
        assertEquals("auto", item.get("selection_mode").asString)
        assertEquals(4, item.get("max_candidates").asInt)
        assertEquals(digest, item.get("target_digest").asString)
        assertEquals("tcp+udp", item.get("transport").asString)
        assertFalse(body.contains("Leigod", ignoreCase = true))
        assertFalse(body.contains("雷神"))
        assertEquals("198.51.100.20", lease.endpointIp)
        assertEquals(8443, lease.endpointPort)
        lease.wipe()
        assertTrue(lease.user.all { it.toInt() == 0 })
        assertTrue(lease.password.all { it.toInt() == 0 })
        leases.drop(1).forEach { it.wipe() }
    }

    @Test
    fun `live cn to international lease binds forward 180 and uses restricted v1 global contract`() = runBlocking {
        val liveForwardId = 180L
        val liveDirection = "CAN -> HKG"
        val liveTarget = "native-can-hkg.example:443"
        val digest = NativeOutboundLease.scopeDigest(liveForwardId, liveDirection, "tcp+udp", "global")
        server.enqueue(json(200, """{"ok":true}"""))
        server.enqueue(
            json(
                200,
                """
                {
                  "ok": true,
                  "client": {"id":17},
                  "forwards": [{
                    "forward_id": $liveForwardId,
                    "service_name": "Native carrier",
                    "target": "$liveTarget",
                    "forward_combination_direction": "$liveDirection",
                    "listen_port": 30443,
                    "mode": "ultra",
                    "backend_mode": "ultra",
                    "transport": "tcp+udp",
                    "service_status": "active",
                    "endpoint_mode": "manual",
                    "ultra_access": {
                      "protocol": "leikwan-ultra-policy-v2",
                      "forward_id": $liveForwardId,
                      "session_ticket": "ticket-redacted",
                      "policy_generation": "${"a".repeat(64)}",
                      "issued_at": "2030-01-01T00:00:00Z",
                      "renew_after": "2030-01-01T00:01:00Z",
                      "expires_at": "2030-01-01T00:04:00Z",
                      "service_state": "active",
                      "transport": "tcp+udp",
                      "rate_limit_mbps": 100,
                      "quota_remaining_bytes": 1000000,
                      "target_digest": "${com.leikwan.bridge.runtime.UltraPolicy.targetDigest(liveTarget)}"
                    }
                  }],
                  "native_outbound": {
                    "enabled": true,
                    "protocol": "socks5",
                    "lease_protocol": "leikwan-ultra-native-socks5-v1",
                    "transport": "tcp+udp",
                    "forward_ids": [$liveForwardId],
                    "default_forward_id": $liveForwardId,
                    "route_scopes": ["global"],
                    "default_route_scope": "global",
                    "forwards": [{
                      "forward_id": $liveForwardId,
                      "direction": "$liveDirection",
                      "scope_digest": "$digest",
                      "transport": "tcp+udp",
                      "route_scopes": ["global"],
                      "default_route_scope": "global"
                    }]
                  }
                }
                """.trimIndent(),
            ),
        )
        server.enqueue(
            json(
                200,
                """
                {
                  "ok": true,
                  "leases": [{
                    "protocol": "leikwan-ultra-native-socks5-v1",
                    "purpose": "native_outbound",
                    "forward_id": $liveForwardId,
                    "lease_id": "ul2_native_live_180",
                    "policy_generation": "${"a".repeat(64)}",
                    "expires_at": "2030-01-01T00:02:00Z",
                    "transport": "tcp+udp",
                    "direction": "$liveDirection",
                    "route_scope": "global",
                    "route_policy": "restricted",
                    "scope_digest": "$digest",
                    "selection_ref": "native-selection-live-180",
                    "selection_tier": "paid",
                    "endpoint": {"ip":"198.51.100.180","port":8443},
                    "credential": {"user":"short-user","password":"short-password"}
                  }]
                }
                """.trimIndent(),
            ),
        )

        val catalog = repository.pullCatalog()
        val forward = requireNotNull(repository.getCachedContract(liveForwardId))
        val capability = requireNotNull(catalog.nativeOutbound)
        assertEquals(liveDirection, forward.direction)
        assertEquals(liveDirection, capability.carrier(liveForwardId)?.direction)
        val leases = repository.resolveNativeOutboundLeases(
            forward = forward,
            capability = capability,
            routeScope = "global",
            maxCandidates = 1,
        )
        assertEquals(1, leases.size)
        val lease = leases.single()

        server.takeRequest() // heartbeat
        server.takeRequest() // pull
        val request = server.takeRequest()
        assertEquals("/api/client/ultra/lease", request.path)
        val body = request.body.readUtf8()
        val item = JsonParser.parseString(body).asJsonObject
            .getAsJsonArray("forwards")[0].asJsonObject
        assertEquals("native_outbound", item.get("purpose").asString)
        assertEquals(liveForwardId, item.get("forward_id").asLong)
        assertEquals("global", item.get("route_scope").asString)
        assertEquals("restricted", item.get("route_policy").asString)
        assertEquals("single", item.get("selection_mode").asString)
        assertEquals(1, item.get("max_candidates").asInt)
        assertEquals(digest, item.get("target_digest").asString)
        assertEquals("tcp+udp", item.get("transport").asString)
        assertFalse(body.contains("Leigod", ignoreCase = true))
        assertFalse(body.contains("雷神"))
        assertEquals(liveForwardId, lease.forwardId)
        assertEquals(liveDirection, lease.direction)
        assertEquals("global", lease.routeScope)
        assertEquals("restricted", lease.routePolicy)
        assertEquals("198.51.100.180", lease.endpointIp)
        assertEquals(8443, lease.endpointPort)
        lease.wipe()
        assertTrue(lease.user.all { it.toInt() == 0 })
        assertTrue(lease.password.all { it.toInt() == 0 })
    }

    @Test
    fun `unrestricted native lease uses v2 digest and requires carrier entitlement`() = runBlocking {
        val unrestrictedDirection = "Mainland China -> International"
        val unrestrictedDigest = NativeOutboundLease.scopeDigestV2(
            forwardId,
            unrestrictedDirection,
            "tcp+udp",
            "global",
            "unrestricted",
        )
        server.enqueue(json(200, "{\"ok\":true}"))
        server.enqueue(
            json(
                200,
                """
                {
                  "ok": true,
                  "client": {"id":17},
                  "forwards": [{
                    "forward_id": $forwardId,
                    "service_name": "Native carrier",
                    "target": "$target",
                    "forward_combination_direction": "$unrestrictedDirection",
                    "listen_port": 30443,
                    "mode": "ultra",
                    "backend_mode": "ultra",
                    "transport": "tcp+udp",
                    "service_status": "active",
                    "endpoint_mode": "manual",
                    "ultra_access": {
                      "protocol": "leikwan-ultra-policy-v2",
                      "forward_id": $forwardId,
                      "session_ticket": "ticket-redacted",
                      "policy_generation": "${"a".repeat(64)}",
                      "issued_at": "2030-01-01T00:00:00Z",
                      "renew_after": "2030-01-01T00:01:00Z",
                      "expires_at": "2030-01-01T00:04:00Z",
                      "service_state": "active",
                      "transport": "tcp+udp",
                      "rate_limit_mbps": 100,
                      "quota_remaining_bytes": 1000000,
                      "target_digest": "${com.leikwan.bridge.runtime.UltraPolicy.targetDigest(target)}"
                    }
                  }],
                  "native_outbound": {
                    "enabled": true,
                    "protocol": "socks5",
                    "lease_protocol": "leikwan-ultra-native-socks5-v1",
                    "transport": "tcp+udp",
                    "forward_ids": [$forwardId],
                    "default_forward_id": $forwardId,
                    "route_scopes": ["intl_only", "global"],
                    "default_route_scope": "global",
                    "forwards": [{
                      "forward_id": $forwardId,
                      "direction": "$unrestrictedDirection",
                      "scope_digest": "${NativeOutboundLease.scopeDigest(forwardId, unrestrictedDirection, "tcp+udp", "global")}",
                      "transport": "tcp+udp",
                      "route_scopes": ["intl_only", "global"],
                      "default_route_scope": "global",
                      "unrestricted_allowed": true
                    }]
                  }
                }
                """.trimIndent(),
            ),
        )
        server.enqueue(
            json(
                200,
                """
                {
                  "ok": true,
                  "leases": [{
                    "protocol": "leikwan-ultra-native-socks5-v1",
                    "purpose": "native_outbound",
                    "forward_id": $forwardId,
                    "lease_id": "ul2_native_unrestricted",
                    "policy_generation": "${"a".repeat(64)}",
                    "expires_at": "2030-01-01T00:02:00Z",
                    "transport": "tcp+udp",
                    "direction": "$unrestrictedDirection",
                    "route_scope": "global",
                    "route_policy": "unrestricted",
                    "scope_digest": "$unrestrictedDigest",
                    "endpoint": {"ip":"198.51.100.30","port":8443},
                    "credential": {"user":"short-user","password":"short-password"}
                  }]
                }
                """.trimIndent(),
            ),
        )

        val catalog = repository.pullCatalog()
        val forward = requireNotNull(repository.getCachedContract(forwardId))
        val capability = requireNotNull(catalog.nativeOutbound)
        val leases = repository.resolveNativeOutboundLeases(
            forward = forward,
            capability = capability,
            routeScope = "global",
            maxCandidates = 1,
            routePolicy = "unrestricted",
        )
        val lease = leases.single()

        server.takeRequest()
        server.takeRequest()
        val request = server.takeRequest()
        val item = JsonParser.parseString(request.body.readUtf8()).asJsonObject
            .getAsJsonArray("forwards")[0].asJsonObject
        assertEquals("unrestricted", item.get("route_policy").asString)
        assertEquals(unrestrictedDigest, item.get("target_digest").asString)
        assertEquals("unrestricted", lease.routePolicy)
        lease.wipe()
    }

    @Test
    fun `native lease rejects a capability direction that is not bound to the selected forward`() {
        val forwardDirection = "Hong Kong -> China Mainland"
        val carrierDirection = "CAN -> HKG"
        val forward = requireNotNull(
            ForwardContractParser.parse(
                JsonParser.parseString(
                    """{"forward_id":180,"forward_combination_direction":"$forwardDirection"}""",
                ),
            ),
        )
        val capability = NativeOutboundCapability(
            enabled = true,
            protocol = "socks5",
            leaseProtocol = "leikwan-ultra-native-socks5-v1",
            transport = "tcp+udp",
            forwardIds = listOf(180L),
            defaultForwardId = 180L,
            routeScopes = listOf("global"),
            defaultRouteScope = "global",
            forwards = listOf(
                NativeOutboundCarrier(
                    forwardId = 180L,
                    direction = carrierDirection,
                    scopeDigest = NativeOutboundLease.scopeDigest(180L, carrierDirection, "tcp+udp", "global"),
                    transport = "tcp+udp",
                    routeScopes = listOf("global"),
                    defaultRouteScope = "global",
                ),
            ),
        )

        val error = assertThrows(IllegalStateException::class.java) {
            runBlocking {
                repository.resolveNativeOutboundLeases(
                    forward = forward,
                    capability = capability,
                    routeScope = "global",
                    maxCandidates = 1,
                )
            }
        }
        assertEquals("native_forward_direction_mismatch", error.message)
    }

    private fun json(status: Int, body: String): MockResponse = MockResponse()
        .setResponseCode(status)
        .setHeader("Content-Type", "application/json")
        .setBody(body)
}
