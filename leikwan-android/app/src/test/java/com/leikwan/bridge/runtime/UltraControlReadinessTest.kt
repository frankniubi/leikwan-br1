package com.leikwan.bridge.runtime

import com.google.gson.JsonObject
import com.google.gson.JsonParser
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test
import java.security.MessageDigest
import java.time.Instant

/**
 * The runtime binds its control socket shortly after the process starts. A delivery that finds
 * nobody listening yet must wait (bounded) instead of reporting the lease as rejected.
 */
class UltraControlReadinessTest {
    private val now = Instant.parse("2026-08-27T00:00:00Z")
    private val policy = UltraPolicy.from(sampleForward(), now)!!

    /** Fake clock that only moves when the client sleeps. */
    private class FakeClock {
        var nowMs = 1_000L
        val sleeps = mutableListOf<Long>()
        fun sleep(ms: Long) {
            sleeps += ms
            nowMs += ms
        }
    }

    private fun accepted(): ByteArray =
        """{"ok":true,"forward_id":42,"policy_generation":"${policy.policyGeneration}"}"""
            .toByteArray(Charsets.UTF_8)

    private fun client(socket: UltraControlSocket, clock: FakeClock, waited: MutableList<Long> = mutableListOf()) =
        UltraLeaseClient(
            controlSocket = socket,
            sleep = clock::sleep,
            nowMs = { clock.nowMs },
            onReadyAfterWait = { waited += it },
        )

    @Test
    fun `a listening runtime is reached on the first try without any wait`() {
        val clock = FakeClock()
        val waited = mutableListOf<Long>()
        var calls = 0
        val socket = UltraControlSocket { _, _ -> calls++; accepted() }

        assertTrue(client(socket, clock, waited).send("/private/ultra.sock", policy, leases(4)))

        assertEquals(1, calls)
        assertTrue(clock.sleeps.isEmpty())
        assertTrue(waited.isEmpty())
    }

    @Test
    fun `a batch waits for the control socket instead of failing after three tries`() {
        val clock = FakeClock()
        val waited = mutableListOf<Long>()
        val frames = mutableListOf<ByteArray>()
        val socket = UltraControlSocket { _, frame ->
            frames += frame.copyOf()
            // Not listening for the first 1.2 s after the process started.
            if (clock.nowMs < 2_200L) throw UltraControlNotReadyException()
            // The runtime answers a batch only after its ~30 s selection window.
            clock.nowMs += 30_000L
            accepted()
        }
        val batch = leases(4)

        assertTrue(client(socket, clock, waited).send("/private/ultra.sock", policy, batch))

        // Far more than the 3 post-write attempts a batch gets: not-ready tries do not count.
        assertTrue(frames.size > 3)
        assertTrue(frames.all { it.contentEquals(frames.first()) })
        assertEquals(listOf(25L, 50L, 100L, 200L, 400L, 400L), clock.sleeps.take(6))
        assertTrue(clock.sleeps.all { it <= 400L })
        assertEquals(1, waited.size)
        // Only the readiness wait is reported, not the selection window.
        assertTrue(waited.single() in 1_200L..1_600L)
        batch.forEach { lease -> assertTrue(lease.password.all { it == 0.toByte() }) }
    }

    @Test
    fun `the readiness wait is bounded when the runtime never listens`() {
        val clock = FakeClock()
        var calls = 0
        val socket = UltraControlSocket { _, _ -> calls++; throw UltraControlNotReadyException() }

        assertFalse(client(socket, clock).send("/private/ultra.sock", policy, leases(2)))

        assertEquals(ULTRA_CONTROL_READY_BUDGET_MS, clock.sleeps.sum())
        assertTrue(calls in 10..30)
    }

    @Test
    fun `a failure after connecting keeps the bounded batch attempts`() {
        val clock = FakeClock()
        var calls = 0
        // Connected and wrote the frame, then the reply timed out: do not hammer the runtime.
        val socket = UltraControlSocket { _, _ -> calls++; throw java.io.IOException("read timed out") }

        assertFalse(client(socket, clock).send("/private/ultra.sock", policy, leases(4)))

        assertEquals(3, calls)
        assertEquals(listOf(50L, 50L), clock.sleeps)
    }

    @Test
    fun `not-ready waits and later post-write failures are counted separately`() {
        val clock = FakeClock()
        var calls = 0
        val socket = UltraControlSocket { _, _ ->
            calls++
            if (calls <= 2) throw UltraControlNotReadyException()
            throw java.io.IOException("connection reset")
        }

        assertFalse(client(socket, clock).send("/private/ultra.sock", policy, leases(2)))

        assertEquals(2 + 3, calls)
        assertEquals(listOf(25L, 50L, 50L, 50L), clock.sleeps)
    }

    @Test
    fun `a single lease also waits for a cold runtime`() {
        val clock = FakeClock()
        val socket = UltraControlSocket { _, _ ->
            if (clock.nowMs < 1_300L) throw UltraControlNotReadyException()
            accepted()
        }
        val lease = leases(1).single()

        assertTrue(client(socket, clock).send("/private/ultra.sock", policy, lease))
        assertTrue(lease.password.all { it == 0.toByte() })
    }

    @Test
    fun `a runtime refusal after the wait is still a refusal`() {
        val clock = FakeClock()
        val socket = UltraControlSocket { _, _ ->
            if (clock.nowMs < 1_100L) throw UltraControlNotReadyException()
            """{"ok":false,"error":"lease_invalid"}""".toByteArray(Charsets.UTF_8)
        }

        assertFalse(client(socket, clock).send("/private/ultra.sock", policy, leases(2)))
    }

    private fun leases(count: Int): List<UltraLease> = (0 until count).map { index ->
        val raw = leaseJson().deepCopy().apply {
            addProperty("lease_id", "ul2_ready_lease_$index")
            addProperty("nonce_hash", ('b'.code + index).toChar().toString().repeat(64))
            addProperty("selection_ref", "selection_ready_ref_$index")
            add("endpoint", JsonObject().apply {
                addProperty("ip", "203.0.113.${20 + index}")
                addProperty("port", 5438 + index)
            })
        }
        UltraLease.from(raw, policy, now)!!
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
