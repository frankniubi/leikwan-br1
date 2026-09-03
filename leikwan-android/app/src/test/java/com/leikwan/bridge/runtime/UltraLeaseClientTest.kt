package com.leikwan.bridge.runtime

import com.google.gson.JsonObject
import com.google.gson.JsonParser
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Test
import java.nio.ByteBuffer
import java.nio.ByteOrder
import java.security.MessageDigest
import java.time.Instant

class UltraLeaseClientTest {
    @Test
    fun `native batch retries are bounded when the local adapter is unavailable`() {
        val now = Instant.now()
        val direction = "China Mainland -> International"
        val lease = NativeOutboundLease(
            forwardId = 42,
            leaseId = "native-lease-unavailable",
            policyGeneration = "a".repeat(64),
            expiresAt = now.plusSeconds(120),
            transport = "tcp+udp",
            direction = direction,
            routeScope = "intl_only",
            scopeDigest = NativeOutboundLease.scopeDigest(42, direction, "tcp+udp", "intl_only"),
            endpointIp = "203.0.113.20",
            endpointPort = 5438,
            user = "line-user".toByteArray(),
            password = "line-password".toByteArray(),
            rateLimitMbps = 20,
            quotaRemainingBytes = 1_000_000,
            selectionRef = "selection_ref_unavailable",
            selectionTier = "paid",
        )
        var calls = 0
        val socket = UltraControlSocket { _, _ ->
            calls++
            error("adapter unavailable")
        }

        val accepted = UltraLeaseClient(socket, sleep = {}).sendNative("/private/native.sock", listOf(lease))

        assertTrue(!accepted)
        assertEquals(20, calls)
        assertTrue(lease.user.all { it == 0.toByte() })
        assertTrue(lease.password.all { it == 0.toByte() })
    }

    @Test
    fun `native scope digest canonicalizes mainland to international aliases`() {
        assertEquals(
            NativeOutboundLease.scopeDigest(42, "Mainland China -> International", "tcp+udp", "intl_only"),
            NativeOutboundLease.scopeDigest(42, "Mainland China -> International", "tcp+udp", "international_only"),
        )
    }

    @Test
    fun `client sends bounded big endian frame and wipes lease credentials`() {
        val now = Instant.parse("2026-08-27T00:00:00Z")
        val forward = sampleForward()
        val policy = UltraPolicy.from(forward, now)!!
        val lease = UltraLease.from(leaseJson(), policy, now)!!
        var sentPayload = ""
        val socket = UltraControlSocket { path, frame ->
            assertEquals("/data/user/0/com.leikwan.bridge/files/ultra/forward-42.sock", path)
            val size = ByteBuffer.wrap(frame, 0, 4).order(ByteOrder.BIG_ENDIAN).int
            assertEquals(frame.size - 4, size)
            sentPayload = frame.copyOfRange(4, frame.size).toString(Charsets.UTF_8)
            """{"ok":true,"forward_id":42,"policy_generation":"${policy.policyGeneration}"}"""
                .toByteArray(Charsets.UTF_8)
        }

        val accepted = UltraLeaseClient(socket, sleep = {}).send(
            controlSocketPath = "/data/user/0/com.leikwan.bridge/files/ultra/forward-42.sock",
            policy = policy,
            lease = lease,
        )

        assertTrue(accepted)
        assertTrue(sentPayload.contains("\"endpoint\""))
        assertTrue(sentPayload.contains("\"credential\""))
        assertTrue(lease.user.all { it == 0.toByte() })
        assertTrue(lease.password.all { it == 0.toByte() })
    }

    @Test
    fun `client rejects an oversized control reply and still wipes credentials`() {
        val now = Instant.parse("2026-08-27T00:00:00Z")
        val policy = UltraPolicy.from(sampleForward(), now)!!
        val lease = UltraLease.from(leaseJson(), policy, now)!!
        val socket = UltraControlSocket { _, _ -> ByteArray(64 * 1024 + 1) }

        val accepted = UltraLeaseClient(socket, sleep = {}).send("/private/ultra.sock", policy, lease)

        assertTrue(!accepted)
        assertTrue(lease.user.all { it == 0.toByte() })
        assertTrue(lease.password.all { it == 0.toByte() })
    }

    @Test
    fun `native client sends adapter lease without exposing a direct sing-box credential config`() {
        val now = Instant.now()
        val direction = "Hong Kong -> China Mainland"
        val scope = "global"
        val lease = NativeOutboundLease(
            forwardId = 42,
            leaseId = "native-lease-1234567890",
            policyGeneration = "a".repeat(64),
            expiresAt = now.plusSeconds(120),
            transport = "tcp+udp",
            direction = direction,
            routeScope = scope,
            scopeDigest = NativeOutboundLease.scopeDigest(42, direction, "tcp+udp", scope),
            endpointIp = "203.0.113.20",
            endpointPort = 5438,
            user = "line-user".toByteArray(),
            password = "line-password".toByteArray(),
            rateLimitMbps = 20,
            quotaRemainingBytes = 1_000_000,
            selectionRef = "selection_ref_1234567890",
            selectionTier = "paid",
        )
        var sentPayload = ""
        val socket = UltraControlSocket { _, frame ->
            val size = ByteBuffer.wrap(frame, 0, 4).order(ByteOrder.BIG_ENDIAN).int
            sentPayload = frame.copyOfRange(4, 4 + size).toString(Charsets.UTF_8)
            """{"ok":true,"forward_id":42,"policy_generation":"${"a".repeat(64)}"}"""
                .toByteArray(Charsets.UTF_8)
        }
        val accepted = UltraLeaseClient(socket, sleep = {}).sendNative("/private/native.sock", lease)
        assertTrue(accepted)
        assertTrue(sentPayload.contains("\"purpose\":\"native_outbound\""))
        assertTrue(sentPayload.contains("\"route_scope\":\"global\""))
        assertTrue(lease.user.all { it == 0.toByte() })
        assertTrue(lease.password.all { it == 0.toByte() })
    }

    @Test
    fun `native client sends a bounded candidate batch and wipes every credential`() {
        val now = Instant.now()
        val direction = "China Mainland -> Hong Kong"
        val leases = (0..1).map { index ->
            NativeOutboundLease(
                forwardId = 42,
                leaseId = "native-lease-batch-$index",
                policyGeneration = "a".repeat(64),
                expiresAt = now.plusSeconds(120),
                transport = "tcp+udp",
                direction = direction,
                routeScope = "intl_only",
                scopeDigest = NativeOutboundLease.scopeDigest(42, direction, "tcp+udp", "intl_only"),
                endpointIp = "203.0.113.${20 + index}",
                endpointPort = 5438 + index,
                user = "line-user-$index".toByteArray(),
                password = "line-password-$index".toByteArray(),
                rateLimitMbps = 20,
                quotaRemainingBytes = 1_000_000,
                selectionRef = "selection_batch_ref_$index",
                selectionTier = "paid",
            )
        }
        var sentPayload = ""
        val socket = UltraControlSocket { _, frame ->
            val size = ByteBuffer.wrap(frame, 0, 4).order(ByteOrder.BIG_ENDIAN).int
            sentPayload = frame.copyOfRange(4, 4 + size).toString(Charsets.UTF_8)
            """{"ok":true,"forward_id":42,"policy_generation":"${"a".repeat(64)}"}"""
                .toByteArray(Charsets.UTF_8)
        }

        val accepted = UltraLeaseClient(socket, sleep = {}).sendNative("/private/native.sock", leases)

        assertTrue(accepted)
        val root = JsonParser.parseString(sentPayload).asJsonObject
        assertEquals(2, root.getAsJsonArray("leases").size())
        assertTrue(sentPayload.contains("native-lease-batch-0"))
        assertTrue(sentPayload.contains("native-lease-batch-1"))
        leases.forEach { lease ->
            assertTrue(lease.user.all { it == 0.toByte() })
            assertTrue(lease.password.all { it == 0.toByte() })
        }
    }

    private fun sampleForward(): ForwardContract = ForwardContract(
        forwardId = 42L,
        serviceName = "Ultra test",
        target = "origin.example:443",
        listenPort = 11042,
        mode = ForwardMode.ULTRA,
        rawMode = "ultra",
        backendMode = "ultra",
        clientMode = "ultra",
        transport = "tcp",
        serviceStatus = "active",
        endpointMode = "managed",
        sni = "",
        pathPrefix = "",
        speedLimitMbps = 20,
        endpoints = emptyList(),
        bonding = null,
        mptcp = null,
        ultraAccess = JsonParser.parseString(
            """
            {
              "protocol":"leikwan-ultra-policy-v2",
              "forward_id":42,
              "session_ticket":"ticket-42",
              "policy_generation":"${"a".repeat(64)}",
              "issued_at":"2026-08-27T00:00:00Z",
              "renew_after":"2026-08-27T00:02:00Z",
              "expires_at":"2026-08-27T00:04:00Z",
              "service_state":"active",
              "transport":"tcp",
              "rate_limit_mbps":20,
              "quota_remaining_bytes":1048576,
              "target_digest":"${digest("origin.example:443")}"
            }
            """.trimIndent(),
        ).asJsonObject,
    )

    private fun leaseJson(): JsonObject = JsonParser.parseString(
        """
        {
          "protocol":"leikwan-ultra-policy-v2",
          "forward_id":42,
          "session_ticket":"ticket-42",
          "policy_generation":"${"a".repeat(64)}",
          "lease_id":"ul2_test_lease_1234567890",
          "expires_at":"2026-08-27T00:04:00Z",
          "transport":"tcp",
          "rate_limit_mbps":20,
          "quota_remaining_bytes":1048576,
          "selection_ref":"selection_ref_1234567890",
          "target_digest":"${digest("origin.example:443")}",
          "nonce_hash":"${"b".repeat(64)}",
          "endpoint":{"ip":"203.0.113.20","port":5438},
          "credential":{"user":"line-user","password":"line-password"},
          "direct_ip_cidrs":["203.0.113.20/32"]
        }
        """.trimIndent(),
    ).asJsonObject

    private fun digest(value: String): String = MessageDigest.getInstance("SHA-256")
        .digest(value.toByteArray(Charsets.UTF_8))
        .joinToString("") { byte -> "%02x".format(byte.toInt() and 0xff) }
}
