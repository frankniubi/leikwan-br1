package com.leikwan.bridge.diagnostics

import com.google.gson.Gson
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.currentCoroutineContext
import kotlinx.coroutines.ensureActive
import kotlinx.coroutines.sync.Mutex
import kotlinx.coroutines.sync.withLock
import java.io.File
import java.io.FileOutputStream
import java.io.IOException
import java.nio.file.Files
import java.nio.file.StandardCopyOption
import java.security.MessageDigest
import java.util.Base64
import java.util.UUID
import java.util.concurrent.ConcurrentHashMap

internal enum class UploadFailureAction { RETRY, PAUSE, TERMINAL }

internal class DebugUploadException(val status: Int, val code: String) : IOException(code) {
    val action: UploadFailureAction get() = when {
        status == 401 || status == 403 -> UploadFailureAction.PAUSE
        status == 408 || status == 429 || status in 500..599 -> UploadFailureAction.RETRY
        status == 410 || status == 413 || status == 422 -> UploadFailureAction.TERMINAL
        status == 409 && code == "debug_chunk_replay" -> UploadFailureAction.TERMINAL
        status == 404 && code in setOf("collection_not_found", "debug_collection_not_found") -> UploadFailureAction.TERMINAL
        else -> UploadFailureAction.PAUSE // Unknown errors must never discard evidence.
    }
}

internal data class ManualDebugCollection(val uuid: String, val referenceCode: String, val expiresAtMs: Long,
    val accountBinding: String = "")
internal data class ManualDebugUpload(val collection: ManualDebugCollection, val chunks: Int, val bytes: Int)

/** One immutable, redacted snapshot per collection. All repository instances share the directory lock. */
internal class DebugUploadStore(private val directory: File) {
    /** Hold the same lock BEFORE /debug/start through the final acknowledgement. */
    suspend fun uploadManual(
        nowMs: Long,
        start: suspend () -> ManualDebugCollection,
        collect: () -> ByteArray,
        accountBinding: String = "",
        send: suspend (uuid: String, uploadId: String, sequence: Int, sha256: String, base64: String) -> Unit,
    ): ManualDebugUpload = lockFor(directory).withLock {
        val collection = selectManualCollection(nowMs, accountBinding, start)
        val (chunks, bytes) = uploadLocked(collection.uuid, true, collect, collection.expiresAtMs, nowMs, accountBinding) { id, seq, sha, b64 ->
            send(collection.uuid, id, seq, sha, b64)
        }
        ManualDebugUpload(collection, chunks, bytes)
    }

    /** A manual retry must not start a fresh collection and strand the existing snapshot. Lock held. */
    private suspend fun selectManualCollection(nowMs: Long, accountBinding: String,
        start: suspend () -> ManualDebugCollection): ManualDebugCollection {
        val pointer = File(directory, "debug-manual-collection.json")
        if (pointer.exists()) {
            val cached = readManual(pointer)
            val stateFile = File(directory, "debug-uploads/${cached.uuid}.json")
            val state = if (stateFile.exists()) read(stateFile, cached.uuid) else null
            if (cached.accountBinding == accountBinding && cached.expiresAtMs > nowMs &&
                (state == null || state.status == "pending")) return cached
        }
        val created = start().copy(accountBinding = accountBinding)
        require(DebugLogUploader.isValidCollectionUuid(created.uuid) && created.expiresAtMs > nowMs) {
            "debug_collection_invalid"
        }
        persist(pointer, created)
        return created
    }

    private fun readManual(file: File): ManualDebugCollection = try {
        require(file.length() in 1..4096)
        val value = Gson().fromJson(file.readText(), ManualDebugCollection::class.java)
        require(DebugLogUploader.isValidCollectionUuid(value.uuid))
        require(value.referenceCode.length <= 256 && value.expiresAtMs > 0)
        value
    } catch (error: Exception) {
        throw IOException("debug_upload_state_invalid", error)
    }

    private data class State(
        val version: Int = 1,
        val uuid: String,
        val uploadId: String,
        val payload: String,
        val sha256: String,
        val bytes: Int,
        val nextSequence: Int = 0,
        val status: String = "pending",
        val error: String = "",
        val expiresAtMs: Long = 0,
        val accountBinding: String = "",
    )

    suspend fun upload(
        uuid: String,
        supportsUploadId: Boolean,
        collect: () -> ByteArray,
        expiresAtMs: Long = 0,
        nowMs: Long = System.currentTimeMillis(),
        accountBinding: String = "",
        send: suspend (uploadId: String, sequence: Int, sha256: String, base64: String) -> Unit,
    ): Pair<Int, Int> = lockFor(directory).withLock {
        uploadLocked(uuid, supportsUploadId, collect, expiresAtMs, nowMs, accountBinding, send)
    }

    /** Internal core never locks recursively: manual and passive share the outer directory lock. */
    private suspend fun uploadLocked(
        uuid: String,
        supportsUploadId: Boolean,
        collect: () -> ByteArray,
        expiresAtMs: Long,
        nowMs: Long,
        accountBinding: String,
        send: suspend (uploadId: String, sequence: Int, sha256: String, base64: String) -> Unit,
    ): Pair<Int, Int> {
        if (!supportsUploadId) throw IOException("unsupported_upload_protocol")
        require(DebugLogUploader.isValidCollectionUuid(uuid)) { "debug_collection_invalid" }
        val normalized = uuid.lowercase()
        if (DebugLogUploader.hasUploadedPassiveCollection(directory, normalized)) return 0 to 0
        if (expiresAtMs > 0 && expiresAtMs <= nowMs) throw DebugUploadException(410, "debug_collection_expired")
        val root = File(directory, "debug-uploads")
        if (!root.isDirectory && !root.mkdirs()) throw IOException("debug_upload_state_unavailable")
        val file = File(root, "$normalized.json")
        val records = pruneExpired(root, nowMs, normalized)
        var state = if (file.exists()) read(file, normalized) else {
            // No eviction of live snapshots/completion records. Expired collections are safe to prune.
            if (records.size >= MAX_RECORDS || records.count { it.status == "pending" } >= MAX_PENDING) {
                throw IOException("debug_upload_storage_full")
            }
            currentCoroutineContext().ensureActive()
            val redacted = DebugLogUploader.redact(String(collect(), Charsets.UTF_8))
                .toByteArray(Charsets.UTF_8).let { it.copyOf(minOf(it.size, DebugLogUploader.MAX_BUNDLE_BYTES)) }
                .let { if (it.isEmpty()) "=== empty log ===\n".toByteArray() else it }
            State(
                uuid = normalized,
                uploadId = UUID.randomUUID().toString(),
                payload = Base64.getEncoder().encodeToString(redacted),
                sha256 = hash(redacted),
                bytes = redacted.size,
                expiresAtMs = expiresAtMs, accountBinding = accountBinding,
            ).also { persist(file, it) } // No network send until durable state exists.
        }
        if (state.accountBinding != accountBinding) throw IOException("debug_upload_context_changed")
        if (state.expiresAtMs == 0L && expiresAtMs > 0) {
            state = state.copy(expiresAtMs = expiresAtMs)
            persist(file, state)
        }
        val chunks = (state.bytes + DebugLogUploader.CHUNK_BYTES - 1) / DebugLogUploader.CHUNK_BYTES
        if (state.status == "complete") return chunks to state.bytes
        if (state.status == "terminal") throw IOException(state.error)
        if (state.expiresAtMs > 0 && state.expiresAtMs <= nowMs) throw DebugUploadException(410, "debug_collection_expired")
        val bytes = Base64.getDecoder().decode(state.payload)
        try {
            while (state.nextSequence < chunks) {
                currentCoroutineContext().ensureActive()
                val start = state.nextSequence * DebugLogUploader.CHUNK_BYTES
                val slice = bytes.copyOfRange(start, minOf(start + DebugLogUploader.CHUNK_BYTES, bytes.size))
                send(state.uploadId, state.nextSequence, hash(slice), Base64.getEncoder().encodeToString(slice))
                currentCoroutineContext().ensureActive()
                state = state.copy(nextSequence = state.nextSequence + 1)
                persist(file, state) // Lost acknowledgements replay identical bytes and upload_id.
            }
            currentCoroutineContext().ensureActive()
            state = state.copy(status = "complete", payload = "")
            persist(file, state)
            return chunks to state.bytes
        } catch (cancelled: CancellationException) {
            throw cancelled
        } catch (error: DebugUploadException) {
            if (error.action == UploadFailureAction.TERMINAL) {
                persist(file, state.copy(status = "terminal", payload = "", error = DebugLogUploader.redact(error.code).take(200)))
            }
            throw error
        }
    }

    private fun pruneExpired(root: File, nowMs: Long, currentUuid: String): List<State> {
        val files = root.listFiles { f -> f.name.endsWith(".json") } ?: throw IOException("debug_upload_state_unavailable")
        val retained = mutableListOf<State>()
        for (file in files) {
            val state = read(file, file.name.removeSuffix(".json"))
            if (state.uuid != currentUuid && state.expiresAtMs > 0 && state.expiresAtMs <= nowMs) {
                if (!file.delete()) throw IOException("debug_upload_state_unavailable")
            } else retained += state
        }
        return retained
    }

    private fun read(file: File, uuid: String): State {
        try {
            if (file.length() > MAX_STATE_BYTES) throw IOException("debug_upload_state_invalid")
            val state = Gson().fromJson(file.readText(), State::class.java)
                ?: throw IOException("debug_upload_state_invalid")
            require(state.version == 1 && state.uuid == uuid && DebugLogUploader.isValidCollectionUuid(state.uploadId))
            require(state.bytes in 1..DebugLogUploader.MAX_BUNDLE_BYTES && state.expiresAtMs >= 0)
            require(state.status in setOf("pending", "complete", "terminal"))
            val chunks = (state.bytes + DebugLogUploader.CHUNK_BYTES - 1) / DebugLogUploader.CHUNK_BYTES
            require(state.nextSequence in 0..chunks)
            if (state.status == "pending") {
                val bytes = Base64.getDecoder().decode(state.payload)
                require(bytes.size == state.bytes && hash(bytes) == state.sha256)
            }
            return state
        } catch (error: Exception) {
            throw IOException("debug_upload_state_invalid", error) // Never silently recapture after corruption.
        }
    }

    private fun persist(file: File, state: Any) {
        val temp = File(file.parentFile, "${file.name}.tmp")
        try {
            FileOutputStream(temp).use { out ->
                out.write(Gson().toJson(state).toByteArray(Charsets.UTF_8))
                out.fd.sync()
            }
            Files.move(temp.toPath(), file.toPath(), StandardCopyOption.ATOMIC_MOVE, StandardCopyOption.REPLACE_EXISTING)
        } catch (error: Exception) {
            throw IOException("debug_upload_state_unavailable", error)
        } finally {
            temp.delete()
        }
    }

    companion object {
        private const val MAX_PENDING = 4
        private const val MAX_RECORDS = 64
        private const val MAX_STATE_BYTES = 6L * 1024 * 1024
        private val locks = ConcurrentHashMap<String, Mutex>()
        internal fun lockFor(directory: File): Mutex = locks.getOrPut(directory.canonicalPath) { Mutex() }
        private fun hash(bytes: ByteArray): String = MessageDigest.getInstance("SHA-256")
            .digest(bytes).joinToString("") { "%02x".format(it) }
    }
}
