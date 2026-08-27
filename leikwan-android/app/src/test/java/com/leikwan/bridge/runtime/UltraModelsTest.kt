package com.leikwan.bridge.runtime

import com.google.gson.JsonObject
import com.google.gson.JsonParser
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNotNull
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test
import java.security.MessageDigest
import java.time.Instant

class UltraModelsTest {

    private val now = Instant.parse("2026-08-27T00:00:00Z")

    @Test
    fun `policy v2 accepts only a scoped short lived active grant`() {
        val forward = sampleForward(policyJson())

        val policy = UltraPolicy.from(forward, now)

        assertNotNull(policy)
        assertEquals(forward.forwardId, policy!!.forwardId)
        assertEquals("tcp+udp", policy.transport)
        assertEquals(targetDigest(forward.target), policy.targetDigest)
        assertEquals("2026-08-27T00:04:00Z", policy.expiresAt.toString())
    }

    @Test
    fun `policy v2 accepts panel tickets up to four kilobytes`() {
        val forward = sampleForward(policyJson(sessionTicket = "t".repeat(2131)))

        assertNotNull(UltraPolicy.from(forward, now))
    }

    @Test
    fun `policy v2 drops bad target digest expired and malformed generation`() {
        val good = sampleForward(policyJson())
        val badDigest = good.copy(ultraAccess = policyJson(targetDigest = "b".repeat(64)))
        val expired = good.copy(ultraAccess = policyJson(expiresAt = "2026-08-26T23:59:59Z"))
        val malformedGeneration = good.copy(ultraAccess = policyJson(policyGeneration = "not-a-generation"))

        assertNull(UltraPolicy.from(badDigest, now))
        assertNull(UltraPolicy.from(expired, now))
        assertNull(UltraPolicy.from(malformedGeneration, now))
    }

    @Test
    fun `lease retains only narrow direct CIDRs and always protects provider endpoint`() {
        val policy = UltraPolicy.from(sampleForward(policyJson()), now)!!
        val lease = UltraLease.from(leaseJson(), policy, now)

        assertNotNull(lease)
        assertTrue(lease!!.directConnectivity.ipCidrs.contains("203.0.113.20/32"))
        assertTrue(lease.directConnectivity.ipCidrs.contains("198.51.100.0/24"))
        lease.wipe()
    }

    @Test
    fun `lease rejects a broad direct CIDR instead of expanding bypass scope`() {
        val policy = UltraPolicy.from(sampleForward(policyJson()), now)!!
        val invalid = leaseJson().apply {
            add("direct_ip_cidrs", JsonParser.parseString("[\"0.0.0.0/0\"]").asJsonArray)
        }

        assertNull(UltraLease.from(invalid, policy, now))
    }

    private fun sampleForward(access: JsonObject): ForwardContract = ForwardContract(
        forwardId = 42L,
        serviceName = "Ultra test",
        target = "origin.example:443",
        listenPort = 11042,
        mode = ForwardMode.ULTRA,
        rawMode = "ultra",
        backendMode = "ultra",
        clientMode = "ultra",
        transport = "tcp+udp",
        serviceStatus = "active",
        endpointMode = "managed",
        sni = "",
        pathPrefix = "",
        speedLimitMbps = 20,
        endpoints = emptyList(),
        bonding = null,
        mptcp = null,
        ultraAccess = access,
    )

    private fun policyJson(
        sessionTicket: String = "ticket-42",
        targetDigest: String = targetDigest("origin.example:443"),
        expiresAt: String = "2026-08-27T00:04:00Z",
        policyGeneration: String = "a".repeat(64),
    ): JsonObject = JsonParser.parseString(
        """
        {
          "protocol":"leikwan-ultra-policy-v2",
          "forward_id":42,
          "session_ticket":"$sessionTicket",
          "policy_generation":"$policyGeneration",
          "issued_at":"2026-08-27T00:00:00Z",
          "renew_after":"2026-08-27T00:02:00Z",
          "expires_at":"$expiresAt",
          "service_state":"active",
          "transport":"tcp+udp",
          "rate_limit_mbps":20,
          "quota_remaining_bytes":1048576,
          "target_digest":"$targetDigest"
        }
        """.trimIndent(),
    ).asJsonObject

    private fun leaseJson(): JsonObject = JsonParser.parseString(
        """
        {
          "protocol":"leikwan-ultra-policy-v2",
          "forward_id":42,
          "session_ticket":"ticket-42",
          "policy_generation":"${"a".repeat(64)}",
          "lease_id":"ul2_test_lease_1234567890",
          "expires_at":"2026-08-27T00:04:00Z",
          "transport":"tcp+udp",
          "rate_limit_mbps":20,
          "quota_remaining_bytes":1048576,
          "selection_ref":"selection_ref_1234567890",
          "target_digest":"${targetDigest("origin.example:443")}",
          "nonce_hash":"${"b".repeat(64)}",
          "endpoint":{"ip":"203.0.113.20","port":5438},
          "credential":{"user":"line-user","password":"line-password"},
          "direct_ip_cidrs":["203.0.113.20/32","198.51.100.0/24"]
        }
        """.trimIndent(),
    ).asJsonObject

    private fun targetDigest(value: String): String = MessageDigest.getInstance("SHA-256")
        .digest(value.toByteArray(Charsets.UTF_8))
        .joinToString("") { byte -> "%02x".format(byte.toInt() and 0xff) }
}
