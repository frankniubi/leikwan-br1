package com.leikwan.bridge.runtime

import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Before
import org.junit.Rule
import org.junit.Test
import org.junit.rules.TemporaryFolder
import java.io.File

class UltraUsageLedgerTest {
    @get:Rule val temp = TemporaryFolder()

    private lateinit var filesDir: File
    private lateinit var statusDir: File
    private val genA = "a".repeat(64)
    private val genB = "b".repeat(64)

    @Before
    fun setUp() {
        filesDir = temp.newFolder("files")
        statusDir = File(filesDir, "ultra").apply { mkdirs() }
    }

    private fun ledger() = UltraUsageLedger.forFilesDir(filesDir)

    private fun status(name: String, forwardId: Long, generation: String, bytesIn: Long, bytesOut: Long): File =
        File(statusDir, "$name.status.json").apply {
            writeText(
                """{"forward_id":$forwardId,"state":"connected","policy_generation":"$generation",""" +
                    """"bytes_in":$bytesIn,"bytes_out":$bytesOut,"updated_at":"2026-10-03T00:00:00Z"}""",
            )
        }

    private fun row(forwardId: Long, generation: String, sequence: Long, bytesIn: Long, bytesOut: Long) =
        UltraUsageRow(forwardId, generation, sequence, bytesIn, bytesOut)

    @Test
    fun `reports deltas since the last acknowledged counters with a rising sequence`() {
        val file = status("forward-170-aaaaaaaaaaaa", 170, genA, 1_000, 200)
        val first = ledger().collect()
        assertEquals(listOf(row(170, genA, 1, 1_000, 200)), first)
        ledger().acknowledge(first, acknowledged = true)

        assertEquals(emptyList<UltraUsageRow>(), ledger().collect())

        status("forward-170-aaaaaaaaaaaa", 170, genA, 1_500, 260)
        val second = ledger().collect()
        assertEquals(listOf(row(170, genA, 2, 500, 60)), second)
        assertTrue(file.exists())
    }

    @Test
    fun `an unacknowledged batch is resent unchanged and the sequence survives a restart`() {
        status("forward-170-aaaaaaaaaaaa", 170, genA, 1_000, 0)
        val first = ledger().collect()
        ledger().acknowledge(first, acknowledged = false)

        status("forward-170-aaaaaaaaaaaa", 170, genA, 4_000, 0)
        // A new ledger instance is what an app restart sees: same pending rows, same sequence.
        assertEquals(first, ledger().collect())
        // A heartbeat that threw never acknowledges; still the same batch.
        assertEquals(first, ledger().collect())

        ledger().acknowledge(first, acknowledged = true)
        assertEquals(listOf(row(170, genA, 2, 3_000, 0)), ledger().collect())
    }

    @Test
    fun `acknowledging a batch that is not the pending one changes nothing`() {
        status("forward-170-aaaaaaaaaaaa", 170, genA, 10, 10)
        val pending = ledger().collect()
        ledger().acknowledge(listOf(row(170, genA, 99, 10, 10)), acknowledged = true)
        assertEquals(pending, ledger().collect())
    }

    @Test
    fun `a restarted runtime counts again from zero`() {
        status("forward-170-aaaaaaaaaaaa", 170, genA, 5_000, 5_000)
        ledger().acknowledge(ledger().collect(), acknowledged = true)

        status("forward-170-aaaaaaaaaaaa", 170, genA, 300, 40)
        assertEquals(listOf(row(170, genA, 2, 300, 40)), ledger().collect())
    }

    @Test
    fun `bytes moved before a status file is deleted are still reported`() {
        val file = status("forward-170-aaaaaaaaaaaa", 170, genA, 1_000, 100)
        ledger().acknowledge(ledger().collect(), acknowledged = true)

        status("forward-170-aaaaaaaaaaaa", 170, genA, 1_700, 150)
        UltraUsageLedger.captureBeforeDelete(file)
        file.delete()

        assertEquals(listOf(row(170, genA, 2, 700, 50)), ledger().collect())
    }

    @Test
    fun `final captures and live runtimes of one forward and generation share a row`() {
        val old = status("forward-170-aaaaaaaaaaaa", 170, genA, 400, 0)
        UltraUsageLedger.captureBeforeDelete(old)
        old.delete()
        status("forward-170-aaaaaaaaaaab", 170, genA, 100, 0)
        status("forward-171-bbbbbbbbbbbb", 171, genB, 0, 9)

        assertEquals(
            listOf(row(170, genA, 1, 500, 0), row(171, genB, 1, 0, 9)),
            ledger().collect(),
        )
    }

    @Test
    fun `at most 32 rows per heartbeat and the rest follow`() {
        (1L..40L).forEach { forwardId -> status("forward-$forwardId-aaaaaaaaaaaa", forwardId, genA, forwardId, 0) }

        val first = ledger().collect()
        assertEquals(32, first.size)
        assertEquals((1L..32L).toList(), first.map { it.forwardId })
        ledger().acknowledge(first, acknowledged = true)

        val second = ledger().collect()
        assertEquals((33L..40L).toList(), second.map { it.forwardId })
        assertTrue(second.all { it.sequence == 1L && it.bytesIn == it.forwardId })
    }

    @Test
    fun `a row never exceeds the panel per-row ceiling`() {
        val huge = UltraUsageLedger.MAX_ROW_BYTES + 5
        status("forward-170-aaaaaaaaaaaa", 170, genA, huge, 0)

        val first = ledger().collect()
        assertEquals(listOf(row(170, genA, 1, UltraUsageLedger.MAX_ROW_BYTES, 0)), first)
        ledger().acknowledge(first, acknowledged = true)
        assertEquals(listOf(row(170, genA, 2, 5, 0)), ledger().collect())
    }

    @Test
    fun `malformed or partial status files are skipped without losing their cursor`() {
        val file = status("forward-170-aaaaaaaaaaaa", 170, genA, 1_000, 0)
        ledger().acknowledge(ledger().collect(), acknowledged = true)

        file.writeText("""{"forward_id":170,"policy_gen""")
        status("forward-9-bad", 9, "not-a-generation", 50, 50)
        status("forward-0-zero", 0, genA, 50, 50)
        assertEquals(emptyList<UltraUsageRow>(), ledger().collect())

        status("forward-170-aaaaaaaaaaaa", 170, genA, 1_200, 0)
        assertEquals(listOf(row(170, genA, 2, 200, 0)), ledger().collect())
    }

    @Test
    fun `only ultra runtime status files are captured`() {
        val other = File(temp.newFolder("elsewhere"), "forward-1.status.json").apply {
            writeText("""{"forward_id":1,"policy_generation":"$genA","bytes_in":5,"bytes_out":5}""")
        }
        UltraUsageLedger.captureBeforeDelete(other)
        assertTrue(!File(filesDir, "ultra-usage-state.json").exists() || ledger().collect().isEmpty())
        assertTrue(!File(other.parentFile.parentFile, "ultra-usage-state.json").exists())
    }
}
