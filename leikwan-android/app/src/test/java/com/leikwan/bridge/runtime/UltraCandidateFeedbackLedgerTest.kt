package com.leikwan.bridge.runtime

import com.google.gson.Gson
import com.google.gson.JsonParser
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Before
import org.junit.Rule
import org.junit.Test
import org.junit.rules.TemporaryFolder
import java.io.File
import java.time.Instant

/** Runtime candidate feedback reaches the heartbeat once, fresh and in the panel's shape. */
class UltraCandidateFeedbackLedgerTest {
    @get:Rule val temp = TemporaryFolder()

    private lateinit var filesDir: File
    private lateinit var statusDir: File
    private val now = Instant.parse("2030-01-01T00:10:00Z")
    private val candidate = "a".repeat(64)
    private val lease = "b".repeat(64)

    @Before
    fun setUp() {
        filesDir = temp.newFolder("files")
        statusDir = File(filesDir, "ultra").apply { mkdirs() }
    }

    private fun ledger() = UltraCandidateFeedbackLedger.forFilesDir(filesDir)

    private fun entry(
        evidence: String,
        reason: String = "relay_connect_timeout",
        observedAt: String = "2030-01-01T00:09:00Z",
        protocol: String = "tcp",
        outcome: String = "failure",
        candidateHash: String = candidate,
        leaseHash: String = lease,
    ) = """{"candidate_key_hash":"$candidateHash","lease_hash":"$leaseHash","protocol":"$protocol","outcome":"$outcome",
            "reason":"$reason","evidence_id":"$evidence","observed_at":"$observedAt","endpoint":"203.0.113.9:443"}"""

    private fun writeStatus(name: String, forwardId: Long, vararg entries: String) {
        File(statusDir, name).writeText(
            """{"forward_id":$forwardId,"state":"error","bytes_in":0,"candidate_feedback":[${entries.joinToString(",")}]}""",
        )
    }

    @Test
    fun `fresh valid entries are sent in the panel shape and invalid or stale ones are dropped`() {
        writeStatus(
            "forward-170-cccccccccccc.status.json",
            170,
            entry("ev-1"),
            entry("ev-2", reason = "Credential_Rejected", protocol = "UDP", candidateHash = candidate.uppercase()),
            entry("ev-stale", observedAt = "2030-01-01T00:04:59Z"),
            entry("ev-future", observedAt = "2030-01-01T00:11:01Z"),
            entry("ev-offset", observedAt = "2030-01-01T08:09:00+08:00"),
            entry("ev-stderr", reason = "process_stderr"),
            entry("ev-proto", protocol = "quic"),
            entry("ev-outcome", outcome = "maybe"),
            entry("ev-hash", leaseHash = "short"),
            entry("bad evidence!"),
            entry("", reason = "udp_arm_failed", observedAt = ""),
        )
        val feedback = ledger().collect(now)
        assertEquals(listOf("ev-1", "ev-2", ""), feedback.map { it.evidenceId })
        val second = feedback[1]
        assertEquals(170L, second.forwardId)
        assertEquals("credential_rejected", second.reason)
        assertEquals("udp", second.protocol)
        assertEquals(candidate, second.candidateKeyHash)
        assertEquals(lease, second.leaseHash)

        val wire = JsonParser.parseString(Gson().toJson(feedback)).asJsonArray[0].asJsonObject
        assertEquals(
            setOf("forward_id", "candidate_key_hash", "lease_hash", "protocol", "outcome", "reason", "evidence_id", "observed_at"),
            wire.keySet(),
        )
        assertFalse(Gson().toJson(feedback).contains("203.0.113.9"))
    }

    @Test
    fun `acknowledged evidence is never sent again and unacknowledged evidence is retried`() {
        writeStatus("forward-1-aaaaaaaaaaaa.status.json", 1, entry("ev-1"), entry("ev-2"))
        val first = ledger().collect(now)
        assertEquals(2, first.size)
        // Not acknowledged (failed heartbeat): the same entries come again.
        assertEquals(first, ledger().collect(now))

        ledger().acknowledge(first)
        assertEquals(emptyList<UltraCandidateFeedbackReport>(), ledger().collect(now))

        // The runtime's rolling list moves on; only the new evidence is sent.
        writeStatus("forward-1-aaaaaaaaaaaa.status.json", 1, entry("ev-2"), entry("ev-3"))
        assertEquals(listOf("ev-3"), ledger().collect(now).map { it.evidenceId })

        // The same evidence id on another forward is a different observation.
        writeStatus("forward-2-aaaaaaaaaaaa.status.json", 2, entry("ev-1"))
        assertEquals(listOf(2L to "ev-1", 1L to "ev-3"), ledger().collect(now).map { it.forwardId to it.evidenceId }.sortedByDescending { it.first })
    }

    @Test
    fun `at most 32 entries per heartbeat and 512 acknowledged keys are kept`() {
        writeStatus("forward-1-aaaaaaaaaaaa.status.json", 1, *(1..40).map { entry("ev-$it") }.toTypedArray())
        val first = ledger().collect(now)
        assertEquals(UltraCandidateFeedbackLedger.MAX_ENTRIES, first.size)
        ledger().acknowledge(first)
        assertEquals((33..40).map { "ev-$it" }, ledger().collect(now).map { it.evidenceId })

        val many = (1..600).map {
            UltraCandidateFeedbackReport(9, candidate, lease, "tcp", "failure", "relay_connect_timeout", "bulk-$it", "")
        }
        ledger().acknowledge(many)
        val state = JsonParser.parseString(File(filesDir, "ultra-feedback-state.json").readText()).asJsonObject
        val keys = state.getAsJsonArray("acknowledged").map { it.asString }
        assertEquals(UltraCandidateFeedbackLedger.MAX_ACKNOWLEDGED, keys.size)
        assertEquals("9:bulk-600", keys.last())
        assertTrue(keys.none { it.startsWith("1:ev-") })
    }
}
