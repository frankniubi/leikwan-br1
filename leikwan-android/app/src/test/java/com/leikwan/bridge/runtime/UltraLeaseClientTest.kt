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
