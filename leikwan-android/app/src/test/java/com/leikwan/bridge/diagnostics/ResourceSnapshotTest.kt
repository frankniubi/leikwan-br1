package com.leikwan.bridge.diagnostics

import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Test

class ResourceSnapshotTest {
    @Test
    fun `fd families collapse per-open descriptors`() {
        assertEquals("socket:[N]", ResourceSnapshot.family("socket:[1234567]"))
        assertEquals("anon_inode:[eventpoll]", ResourceSnapshot.family("anon_inode:[eventpoll]"))
        assertEquals("/dev/tun", ResourceSnapshot.family("/dev/tun"))
        // Numeric path segments collapse too, so one file opened twice stays one family.
        assertEquals(
            "/data/user/N/com.leikwan.bridge/files/vpn-events.log",
            ResourceSnapshot.family("/data/user/0/com.leikwan.bridge/files/vpn-events.log"),
        )
    }

    @Test
    fun `summary omits unknown values instead of printing negatives`() {
        val summary = ResourceSnapshot.Value(
            fdCount = 812,
            fdLimit = 32_768,
            rssKb = 91_234,
            families = emptyList(),
        ).summary()
        assertEquals("fds=812 limit=32768 rss_kb=91234", summary)

        val unknown = ResourceSnapshot.Value(-1, 0, -1, emptyList()).summary()
        assertTrue(unknown, unknown.contains("fds=?"))
        assertTrue(unknown, unknown.contains("limit=?"))
        assertTrue(unknown, unknown.contains("rss_kb=?"))
    }

    @Test
    fun `render lists the largest families after the summary line`() {
        val text = ResourceSnapshot.render()
        assertTrue(text, text.startsWith("fds="))
        assertTrue(text, text.contains("rss_kb="))
    }
}
