package com.leikwan.bridge.diagnostics

import java.io.File
import java.nio.file.Files
import java.time.Instant
import org.junit.After
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test

class VpnEventJournalTest {
    private val dir: File = Files.createTempDirectory("lw-vpn-events").toFile()

    @After
    fun cleanUp() {
        dir.deleteRecursively()
    }

    @Test
    fun `events are kept one per line with their time`() {
        VpnEventJournal.recordTo(dir, "vpn started", Instant.parse("2026-11-14T08:12:30Z"))
        VpnEventJournal.recordTo(dir, "vpn stop: orchestrator\n(running=true)", Instant.parse("2026-11-14T08:15:48Z"))

        assertEquals(
            "2026-11-14T08:12:30Z vpn started\n" +
                "2026-11-14T08:15:48Z vpn stop: orchestrator (running=true)\n",
            VpnEventJournal.readFrom(dir),
        )
    }

    @Test
    fun `the journal rotates once instead of growing without bound`() {
        val event = "x".repeat(300)
        repeat(((VpnEventJournal.MAX_BYTES * 3) / event.length).toInt()) {
            VpnEventJournal.recordTo(dir, event)
        }
        VpnEventJournal.recordTo(dir, "latest event")

        val files = dir.listFiles().orEmpty().map { it.name }.sorted()
        assertEquals(listOf("vpn-events.log", "vpn-events.log.1"), files)
        assertTrue(dir.listFiles().orEmpty().all { it.length() <= VpnEventJournal.MAX_BYTES + 512 })
        assertTrue(VpnEventJournal.readFrom(dir).trimEnd().endsWith("latest event"))
    }

    @Test
    fun `an empty journal reads as nothing`() {
        assertEquals("", VpnEventJournal.readFrom(dir))
        assertFalse(File(dir, "vpn-events.log").exists())
    }
}
