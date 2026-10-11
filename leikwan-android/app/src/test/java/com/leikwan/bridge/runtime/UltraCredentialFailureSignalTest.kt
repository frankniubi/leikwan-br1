package com.leikwan.bridge.runtime

import java.time.Duration
import java.time.Instant
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test

class UltraCredentialFailureSignalTest {
    @Test
    fun `relay-side stream failures and carrier drops are credential-suspect`() {
        val cases = mapOf(
            "[ultra-client] 2026/09/24 17:02:22 tcp stream ended forward_id=243 direction=upstream stage=remote_write reason=aborted generation=g" to "tcp_stream_ended",
            "2026/09/24 17:02:22.123456 tcp stream ended forward_id=243 direction=downstream stage=remote_read reason=reset generation=g" to "tcp_stream_ended",
            "tcp stream ended forward_id=243 direction=upstream stage=remote_write reason=broken_pipe" to "tcp_stream_ended",
            "tcp stream ended forward_id=243 direction=upstream stage=remote_write reason=stall" to "tcp_stream_ended",
            "[ultra-client] tcp stream setup failed forward_id=243: provider handshake failed" to "tcp_stream_setup_failed",
            "[ultra-client] 2026/09/24 17:02:23 external UoT carrier reconnect forward_id=242 elapsed=1s reconnects=1 drops=16 reason=read tcp4: forcibly closed" to "external_uot_carrier_reconnect",
            "tcp stream remote write stalled forward_id=243 stage=remote_write reason=stall selection_ref=r" to "tcp_remote_write_stall",
        )
        cases.forEach { (line, kind) -> assertEquals(line, kind, UltraCredentialFailureSignal.credentialSuspectKind(line)) }
    }

    @Test
    fun `local closes, ordinary ends and telemetry are not credential-suspect`() {
        listOf(
            "[ultra-client] 2026/09/24 17:02:22 tcp stream ended forward_id=243 direction=upstream stage=local_read reason=reset",
            "tcp stream ended forward_id=243 direction=downstream stage=local_write reason=aborted",
            "tcp stream ended forward_id=243 direction=downstream stage=remote_read reason=eof",
            "tcp stream ended forward_id=243 direction=downstream stage=remote_read reason=timeout",
            "tcp stream ended forward_id=243 direction=downstream stage=remote_read reason=complete",
            "tcp stream had no downstream bytes forward_id=243 remote_stopped_first=true",
            "udp candidate failed forward_id=243 reason=timeout",
            "active candidate forwarding test succeeded protocol=tcp forward_id=243",
            "",
        ).forEach { line -> assertNull(line, UltraCredentialFailureSignal.credentialSuspectKind(line)) }
    }

    @Test
    fun `a burst is one renewal and the next comes no sooner than twenty seconds`() {
        val gate = UltraFailureRenewalGate()
        val start = Instant.parse("2026-09-24T09:02:22Z")
        assertTrue(gate.tryAdmit(243L, start))
        repeat(200) { i -> assertFalse(gate.tryAdmit(243L, start.plusMillis(5L * i))) }
        assertFalse(gate.tryAdmit(243L, start.plusMillis(19_900)))
        assertTrue("another forward has its own budget", gate.tryAdmit(242L, start.plusSeconds(1)))
        assertTrue(gate.tryAdmit(243L, start.plusSeconds(20)))
    }

    @Test
    fun `persistent failures back off to the cap and quiet resets`() {
        val gate = UltraFailureRenewalGate()
        val start = Instant.parse("2026-09-24T09:02:22Z")
        val admitted = (0 until 720).filter { second -> gate.tryAdmit(56L, start.plusSeconds(second.toLong())) }
        assertEquals(listOf(0, 20, 60, 140, 300, 460, 620), admitted)
        assertTrue(gate.tryAdmit(56L, start.plusSeconds(719L + 120L)))
        assertFalse(gate.tryAdmit(56L, start.plusSeconds(719L + 121L)))
    }

    @Test(expected = IllegalArgumentException::class)
    fun `quiet reset must exceed the minimum interval`() {
        UltraFailureRenewalGate(quietReset = Duration.ofSeconds(20))
    }
}
