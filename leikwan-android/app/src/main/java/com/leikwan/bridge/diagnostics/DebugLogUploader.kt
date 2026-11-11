package com.leikwan.bridge.diagnostics

import android.content.Context
import android.os.Build
import com.google.gson.Gson
import com.leikwan.bridge.BuildConfig
import com.leikwan.bridge.service.LeikwanVpnService
import java.io.File
import java.net.InetAddress
import java.security.MessageDigest
import java.time.Instant
import java.util.Base64
import java.util.concurrent.TimeUnit

data class DebugUploadSummary(
    val referenceCode: String,
    val uuid: String,
    val chunks: Int,
    val bytes: Int,
)

/**
 * Redacts and uploads Android client diagnostic logs in 256 KiB chunks to `/api/client/debug/log`.
 * Supports both panel-requested collections (exposed via `client.debug_collection` on pull) and
 * user-initiated uploads (`/api/client/debug/start`).
 */
object DebugLogUploader {
    const val CHUNK_BYTES = 256 * 1024
    const val MAX_BUNDLE_BYTES = 4 * 1024 * 1024

    private val bearerRegex = Regex("""(?i)(authorization\s*:\s*bearer\s+)[^\s,;]+""")
    private val secretRegex = Regex(
        """(?i)(\b(?:password|passwd|secret|token|agent_token|credential|session[_-]?ticket|private[_-]?key|api[_-]?key)\b\s*[:=]\s*)(?:"[^"]*"|'[^']*'|[^\s,;&]+)""",
    )
    private val querySecretRegex = Regex("""(?i)([?&](?:token|password|secret|credential|key)=)[^&\s]+""")
    private val ipv4Regex = Regex(
        """(?<![A-Za-z0-9])(?:25[0-5]|2[0-4][0-9]|1[0-9]{2}|[1-9]?[0-9])(?:\.(?:25[0-5]|2[0-4][0-9]|1[0-9]{2}|[1-9]?[0-9])){3}(?![A-Za-z0-9])""",
    )
    private val ipv6CandidateRegex = Regex(
        """(?<![A-Za-z0-9])(?:[0-9A-Fa-f]{0,4}:){2,}[0-9A-Fa-f:.]*(?:%[A-Za-z0-9_.-]+)?(?![A-Za-z0-9])""",
    )
    private val uuidRegex = Regex("""^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$""")

    fun isValidCollectionUuid(uuid: String): Boolean = uuidRegex.matches(uuid.trim().lowercase())

    fun redact(value: String): String {
        var text = value.replace("\u0000", "")
        text = bearerRegex.replace(text, "$1[REDACTED]")
        text = secretRegex.replace(text, "$1[REDACTED]")
        text = querySecretRegex.replace(text, "$1[REDACTED]")
        text = ipv4Regex.replace(text, "[REDACTED]")
        text = ipv6CandidateRegex.replace(text) { match ->
            if (looksLikeIpv6Literal(match.value)) "[REDACTED]" else match.value
        }
        return text
    }

    private fun looksLikeIpv6Literal(candidate: String): Boolean {
        val clean = candidate.trim('[', ']').substringBefore('%')
        if (!clean.contains(':')) return false
        // Reject HH:MM:SS(.mmm) timestamps where every group is digits and count == 3 without "::".
        if (!clean.contains("::")) {
            val parts = clean.split(':')
            if (parts.size < 4) return false
        }
        return runCatching {
            val bytes = InetAddress.getByName(clean).address
            bytes.size == 16
        }.getOrDefault(false)
    }

    fun collectAppBundle(
        context: Context,
        logcatSupplier: () -> String = ::captureProcessLogcat,
    ): ByteArray {
        val sb = StringBuilder()
        sb.appendLine(
            "=== leikwan-android version=${BuildConfig.VERSION_NAME} (${BuildConfig.VERSION_CODE}) " +
                "sdk=${Build.VERSION.SDK_INT} vpnRunning=${LeikwanVpnService.isRunning} utc=${Instant.now()} ===",
        )
        runCatching {
            context.filesDir.listFiles { f -> f.isFile && f.name.endsWith(".status.json") }
                ?.sortedBy { it.name }
                ?.take(12)
                ?.forEach { file ->
                    sb.appendLine("=== file: ${file.name} ===")
                    sb.appendLine(file.readText().take(64 * 1024))
                }
        }
        val logcat = runCatching { logcatSupplier() }.getOrDefault("")
        if (logcat.isNotBlank()) {
            sb.appendLine("=== logcat (app process) ===")
            sb.appendLine(logcat)
        }
        val sanitized = redact(sb.toString()).toByteArray(Charsets.UTF_8)
        return if (sanitized.size > MAX_BUNDLE_BYTES) sanitized.copyOf(MAX_BUNDLE_BYTES) else sanitized
    }

    private fun captureProcessLogcat(): String {
        val pid = android.os.Process.myPid().toString()
        val proc = ProcessBuilder("logcat", "-d", "-v", "threadtime", "--pid=$pid", "-t", "2500")
            .redirectErrorStream(true)
            .start()
        val output = proc.inputStream.bufferedReader(Charsets.UTF_8).use { reader ->
            reader.readText()
        }
        proc.waitFor(3, TimeUnit.SECONDS)
        runCatching { proc.destroy() }
        return output
    }

    suspend fun uploadBytes(
        uuid: String,
        sanitizedBytes: ByteArray,
        source: String = "android-client.log",
        sendChunk: suspend (sequence: Int, sha256: String, chunkBase64: String, source: String) -> Unit,
    ): Pair<Int, Int> {
        val normalizedUuid = uuid.trim().lowercase()
        require(isValidCollectionUuid(normalizedUuid)) { "debug_collection_invalid" }
        val payload = if (sanitizedBytes.isNotEmpty()) sanitizedBytes else "=== empty log ===\n".toByteArray(Charsets.UTF_8)
        var sequence = 0
        var offset = 0
        while (offset < payload.size) {
            val end = minOf(offset + CHUNK_BYTES, payload.size)
            val slice = payload.copyOfRange(offset, end)
            val sha = sha256Hex(slice)
            val b64 = Base64.getEncoder().encodeToString(slice)
            sendChunk(sequence, sha, b64, source)
            sequence++
            offset = end
        }
        return sequence to payload.size
    }

    fun hasUploadedPassiveCollection(stateDir: File, uuid: String): Boolean {
        val stateFile = File(stateDir, STATE_FILE_NAME)
        if (!stateFile.isFile) return false
        return runCatching {
            val state = Gson().fromJson(stateFile.readText(), PassiveState::class.java)
            state?.completedUuid?.equals(uuid.trim(), ignoreCase = true) == true
        }.getOrDefault(false)
    }

    fun markUploadedPassiveCollection(stateDir: File, uuid: String) {
        runCatching {
            stateDir.mkdirs()
            File(stateDir, STATE_FILE_NAME).writeText(Gson().toJson(PassiveState(uuid.trim().lowercase())))
        }
    }

    private fun sha256Hex(bytes: ByteArray): String =
        MessageDigest.getInstance("SHA-256").digest(bytes).joinToString("") { "%02x".format(it) }

    private data class PassiveState(val completedUuid: String = "")
    private const val STATE_FILE_NAME = "debug-upload-state.json"
}
