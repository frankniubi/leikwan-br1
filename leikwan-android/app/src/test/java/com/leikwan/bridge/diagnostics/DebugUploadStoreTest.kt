package com.leikwan.bridge.diagnostics

import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.CompletableDeferred
import kotlinx.coroutines.async
import kotlinx.coroutines.runBlocking
import org.junit.Assert.*
import org.junit.Test
import java.io.File
import java.io.IOException
import java.nio.file.Files
import java.util.Base64

class DebugUploadStoreTest {
    private val uuid = "123e4567-e89b-42d3-a456-426614174000"
    private fun directory() = Files.createTempDirectory("debug-upload-test").toFile()

    @Test
    fun `failure in second chunk resumes immutable redacted bytes and upload ID in a new instance`() = runBlocking {
        val dir = directory()
        try {
            val attempts = mutableListOf<Triple<String, Int, String>>()
            val data = "password=top-secret\n203.0.113.42\n" + "a".repeat(DebugLogUploader.CHUNK_BYTES + 123)
            try {
                DebugUploadStore(dir).upload(uuid, true, { data.toByteArray() }) { id, seq, _, b64 ->
                    attempts += Triple(id, seq, b64)
                    if (seq == 1) throw IOException("connection reset")
                }
                fail("must interrupt")
            } catch (_: IOException) { }
            assertEquals(listOf(0, 1), attempts.map { it.second })
            val persisted = File(dir, "debug-uploads/$uuid.json").readText()
            assertFalse(persisted.contains("top-secret"))
            val result = DebugUploadStore(dir).upload(uuid, true, { error("must not recollect") }) { id, seq, _, b64 ->
                attempts += Triple(id, seq, b64)
            }
            assertEquals(listOf(0, 1, 1), attempts.map { it.second })
            assertEquals(1, attempts.map { it.first }.distinct().size)
            assertTrue(DebugLogUploader.isValidCollectionUuid(attempts.first().first))
            assertEquals(attempts[1], attempts[2])
            val uploaded = Base64.getDecoder().decode(attempts[0].third) + Base64.getDecoder().decode(attempts[2].third)
            assertEquals(DebugLogUploader.redact(data), String(uploaded))
            assertEquals(uploaded.size, result.second)
            assertEquals(result, DebugUploadStore(dir).upload(uuid, true, { error("complete") }) { _, _, _, _ -> error("complete") })
        } finally { dir.deleteRecursively() }
    }

    @Test
    fun `lost acknowledgement and cancellation replay identical first chunk without completing`() = runBlocking<Unit> {
        val dir = directory()
        try {
            var initial = ""
            try {
                DebugUploadStore(dir).upload(uuid, true, { "one snapshot".toByteArray() }) { id, _, sha, b64 ->
                    initial = "$id $sha $b64"
                    throw CancellationException("cancel")
                }
                fail("must cancel")
            } catch (_: CancellationException) { }
            DebugUploadStore(dir).upload(uuid, true, { error("must not recapture") }) { id, seq, sha, b64 ->
                assertEquals(0, seq)
                assertEquals(initial, "$id $sha $b64")
            }
        } finally { dir.deleteRecursively() }
    }

    @Test
    fun `concurrent repository instances serialize and share completion`() = runBlocking {
        val dir = directory()
        try {
            val inSend = CompletableDeferred<Unit>()
            val release = CompletableDeferred<Unit>()
            var captures = 0
            var sends = 0
            val first = async {
                DebugUploadStore(dir).upload(uuid, true, { captures++; "snapshot".toByteArray() }) { _, _, _, _ ->
                    sends++
                    inSend.complete(Unit)
                    release.await()
                }
            }
            inSend.await()
            val second = async {
                DebugUploadStore(dir).upload(uuid, true, { captures++; "changed".toByteArray() }) { _, _, _, _ -> sends++ }
            }
            release.complete(Unit)
            assertEquals(first.await(), second.await())
            assertEquals(1, captures)
            assertEquals(1, sends)
        } finally { dir.deleteRecursively() }
    }

    @Test
    fun `authentication pauses and transient failures preserve pending state`() = runBlocking {
        for ((status, code) in listOf(401 to "unauthorized", 403 to "device_untrusted", 408 to "timeout", 429 to "quota", 503 to "busy", 404 to "route_missing", 409 to "unknown_conflict")) {
            val dir = directory()
            try {
                try {
                    DebugUploadStore(dir).upload(uuid, true, { "snapshot".toByteArray() }) { _, _, _, _ ->
                        throw DebugUploadException(status, code)
                    }
                    fail("must surface $status")
                } catch (_: DebugUploadException) { }
                var sends = 0
                DebugUploadStore(dir).upload(uuid, true, { error("must preserve for $status") }) { _, _, _, _ -> sends++ }
                assertEquals("resume $status", 1, sends)
            } finally { dir.deleteRecursively() }
        }
    }

    @Test
    fun `terminal responses stop retry without becoming successful completion`() = runBlocking {
        for ((status, code) in listOf(410 to "expired", 404 to "collection_not_found", 404 to "debug_collection_not_found", 409 to "debug_chunk_replay", 413 to "quota", 422 to "invalid")) {
            val dir = directory()
            try {
                repeat(2) { attempt ->
                    try {
                        DebugUploadStore(dir).upload(uuid, true, { check(attempt == 0); "snapshot".toByteArray() }) { _, _, _, _ ->
                            check(attempt == 0) { "terminal retried" }
                            throw DebugUploadException(status, code)
                        }
                        fail("terminal response must not report success")
                    } catch (error: IOException) { assertEquals(code, error.message) }
                }
            } finally { dir.deleteRecursively() }
        }
    }

    @Test
    fun `capability absent sends nothing and creates no state`() = runBlocking {
        val dir = directory()
        try {
            try {
                DebugUploadStore(dir).upload(uuid, false, { error("unsupported collection") }) { _, _, _, _ -> error("unsupported send") }
                fail("old server must be explicit")
            } catch (error: IOException) { assertEquals("unsupported_upload_protocol", error.message) }
            assertTrue(dir.listFiles()!!.isEmpty())
        } finally { dir.deleteRecursively() }
    }

    @Test
    fun `invalid UUID state corruption and write failures fail closed`() = runBlocking {
        val dir = directory()
        try {
            try {
                DebugUploadStore(dir).upload(uuid.replace("42d3", "12d3"), true, { error("invalid") }) { _, _, _, _ -> error("invalid") }
                fail("UUIDv1 rejected")
            } catch (_: IllegalArgumentException) { }
            File(dir, "debug-uploads").mkdirs()
            val file = File(dir, "debug-uploads/$uuid.json")
            file.writeText("{broken")
            try {
                DebugUploadStore(dir).upload(uuid, true, { error("corrupt") }) { _, _, _, _ -> error("corrupt") }
                fail("corrupt state must fail")
            } catch (error: IOException) { assertEquals("debug_upload_state_invalid", error.message) }
            file.delete()
            File(dir, "debug-uploads/$uuid.json.tmp").mkdir()
            try {
                DebugUploadStore(dir).upload(uuid, true, { "snapshot".toByteArray() }) { _, _, _, _ -> error("not persisted") }
                fail("cannot send without durable state")
            } catch (error: IOException) { assertEquals("debug_upload_state_unavailable", error.message) }
        } finally { dir.deleteRecursively() }
    }

    @Test
    fun `legacy manual or passive completion still suppresses upload`() = runBlocking<Unit> {
        val dir = directory()
        try {
            DebugLogUploader.markUploadedPassiveCollection(dir, uuid)
            DebugUploadStore(dir).upload(uuid, true, { error("already completed") }) { _, _, _, _ -> error("already completed") }
        } finally { dir.deleteRecursively() }
    }

    @Test
    fun `manual retry reuses persisted collection and entire start upload shares passive lock`() = runBlocking<Unit> {
        val dir = directory()
        try {
            var starts = 0
            val ids = mutableListOf<String>()
            val collection = ManualDebugCollection(uuid, "ref", 1000)
            try {
                DebugUploadStore(dir).uploadManual(100, { starts++; collection }, { "snapshot".toByteArray() }) { _, id, _, _, _ ->
                    ids += id
                    throw IOException("lost response")
                }
                fail("must interrupt")
            } catch (_: IOException) { }
            DebugUploadStore(dir).uploadManual(101, { error("must reuse collection") }, { error("must reuse bytes") }) { u, id, _, _, _ ->
                assertEquals(uuid, u)
                ids += id
            }
            assertEquals(1, starts)
            assertEquals(1, ids.distinct().size)
            // A pending passive upload holds the lock before a new manual start can be issued.
            val other = "123e4567-e89b-42d3-a456-426614174001"
            val entered = CompletableDeferred<Unit>()
            val release = CompletableDeferred<Unit>()
            val passive = async {
                DebugUploadStore(dir).upload(other, true, { "passive".toByteArray() }, nowMs = 101) { _, _, _, _ ->
                    entered.complete(Unit)
                    release.await()
                }
            }
            entered.await()
            val manual = async {
                DebugUploadStore(dir).uploadManual(102, { starts++; collection }, { "new snapshot".toByteArray() }) { _, _, _, _, _ -> }
            }
            kotlinx.coroutines.yield()
            assertEquals(1, starts)
            release.complete(Unit)
            passive.await()
            manual.await()
            assertEquals(2, starts)
        } finally { dir.deleteRecursively() }
    }

    @Test
    fun `known expired records release pending and terminal slots without evicting live evidence`() = runBlocking {
        val dir = directory()
        try {
            repeat(4) { index ->
                val id = "123e4567-e89b-42d3-a456-${index.toString().padStart(12, '0')}"
                try {
                    DebugUploadStore(dir).upload(id, true, { "pending".toByteArray() }, expiresAtMs = 200, nowMs = 100) { _, _, _, _ ->
                        throw IOException("offline")
                    }
                } catch (_: IOException) { }
            }
            try {
                DebugUploadStore(dir).upload(uuid, true, { error("pending cap") }, nowMs = 101) { _, _, _, _ -> error("pending cap") }
                fail("must cap pending snapshots")
            } catch (error: IOException) { assertEquals("debug_upload_storage_full", error.message) }
            DebugUploadStore(dir).upload(uuid, true, { "new snapshot".toByteArray() }, expiresAtMs = 400, nowMs = 201) { _, _, _, _ -> }
            assertEquals(1, File(dir, "debug-uploads").listFiles()!!.size)
            repeat(65) { index ->
                val id = "123e4567-e89b-42d3-a456-${(index + 100).toString().padStart(12, '0')}"
                try {
                    DebugUploadStore(dir).upload(id, true, { "terminal".toByteArray() }, expiresAtMs = 300L + index, nowMs = 299L + index) { _, _, _, _ ->
                        throw DebugUploadException(410, "expired")
                    }
                } catch (_: DebugUploadException) { }
            }
            assertTrue(File(dir, "debug-uploads").listFiles()!!.size <= 2)
        } finally { dir.deleteRecursively() }
    }

    @Test
    fun `persisted payload binding rejects a different account without modifying evidence`() = runBlocking<Unit> {
        val dir = directory()
        try {
            try {
                DebugUploadStore(dir).upload(uuid, true, { "old account snapshot".toByteArray() }, accountBinding = "account-A") { _, _, _, _ ->
                    throw IOException("offline")
                }
            } catch (_: IOException) { }
            val file = File(dir, "debug-uploads/$uuid.json")
            val original = file.readText()
            try {
                DebugUploadStore(dir).upload(uuid, true, { error("must not recapture") }, accountBinding = "account-B") { _, _, _, _ -> error("must not send") }
                fail("cross-account resume rejected")
            } catch (error: IOException) {
                assertEquals("debug_upload_context_changed", error.message)
            }
            assertEquals(original, file.readText())
            DebugUploadStore(dir).upload(uuid, true, { error("must reuse") }, accountBinding = "account-A") { _, _, _, _ -> }
        } finally { dir.deleteRecursively() }
    }

    @Test
    fun `bundles are bounded before persistence and sending`() = runBlocking {
        val dir = directory()
        try {
            var bytes = 0
            DebugUploadStore(dir).upload(uuid, true, { ByteArray(DebugLogUploader.MAX_BUNDLE_BYTES + 100) { 65 } }) { _, _, _, b64 ->
                bytes += Base64.getDecoder().decode(b64).size
            }
            assertEquals(DebugLogUploader.MAX_BUNDLE_BYTES, bytes)
        } finally { dir.deleteRecursively() }
    }
}
