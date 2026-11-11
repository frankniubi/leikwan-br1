package com.leikwan.bridge.diagnostics

import kotlinx.coroutines.runBlocking
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test
import java.nio.file.Files
import java.util.Base64

class DebugLogUploaderTest {
    @Test
    fun `redact removes tokens passwords and ip literals while keeping timestamps`() {
        val raw = """
            12:34:56.789 Authorization: Bearer supersecret123
            agent_token=tok_987 password="my pass" ?token=qtok&ok=1
            peer 203.0.113.42:443 v6 [2001:db8::abcd]:8443
        """.trimIndent()
        val out = DebugLogUploader.redact(raw)
        for (secret in listOf("supersecret123", "tok_987", "my pass", "qtok", "203.0.113.42", "2001:db8::abcd")) {
            assertFalse("leaked $secret in: $out", out.contains(secret))
        }
        assertTrue(out.contains("12:34:56"))
        assertTrue(out.contains("ok=1"))
    }

    @Test
    fun `uploadBytes splits chunks and verifies passive state persistence`() = runBlocking {
        val payload = ByteArray(DebugLogUploader.CHUNK_BYTES + 33) { 'a'.code.toByte() }
        val uuid = "123e4567-e89b-42d3-a456-426614174000"
        val received = mutableListOf<ByteArray>()
        val (chunks, total) = DebugLogUploader.uploadBytes(uuid, payload) { seq, sha, b64, _ ->
            assertEquals(received.size, seq)
            assertEquals(64, sha.length)
            received += Base64.getDecoder().decode(b64)
        }
        assertEquals(2, chunks)
        assertEquals(payload.size, total)
        assertEquals(payload.size, received.sumOf { it.size })

        val dir = Files.createTempDirectory("lw-android-debug").toFile()
        try {
            assertFalse(DebugLogUploader.hasUploadedPassiveCollection(dir, uuid))
            DebugLogUploader.markUploadedPassiveCollection(dir, uuid)
            assertTrue(DebugLogUploader.hasUploadedPassiveCollection(dir, uuid))
        } finally {
            dir.deleteRecursively()
        }
    }
}
