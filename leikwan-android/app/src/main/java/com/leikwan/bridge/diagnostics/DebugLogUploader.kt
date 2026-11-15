package com.leikwan.bridge.diagnostics

import android.app.ActivityManager
import android.app.ApplicationExitInfo
import android.content.Context
import android.os.Build
import com.google.gson.Gson
import com.leikwan.bridge.BuildConfig
import com.leikwan.bridge.service.LeikwanVpnService
import java.io.File
import java.io.FileOutputStream
import java.io.InputStream
import java.io.ByteArrayOutputStream
import java.io.IOException
import java.nio.file.Files
import java.nio.file.StandardCopyOption
import java.util.concurrent.Executors
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
        """(?i)(["']?\b(?:password|passwd|secret|token|(?:[a-z]+[_-])*(?:token|secret|credential)|credential|session[_-]?ticket|private[_-]?key|api[_-]?key)\b["']?\s*[:=]\s*)(?:"[^"]*"|'[^']*'|[^\s,;&}]+)""",
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
                    sb.appendLine(file.inputStream().use { String(it.readBounded(64 * 1024), Charsets.UTF_8) })
                }
        }
        // Logcat below covers only this process and minutes of output; these two say how earlier
        // tunnels and processes ended.
        val exits = runCatching { recentProcessExits(context) }.getOrDefault("")
        if (exits.isNotBlank()) {
            sb.appendLine("=== recent process exits ===")
            sb.appendLine(exits)
        }
        val crashes = CrashJournal.read(context)
        if (crashes.isNotBlank()) {
            sb.appendLine("=== persistent uncaught exceptions ===")
            sb.appendLine(crashes)
        }
        val historicalCrashes = runCatching { captureHistoricalCrashLogcat() }.getOrDefault("")
        if (historicalCrashes.isNotBlank()) {
            sb.appendLine("=== app crash buffer (historical pids) ===")
            sb.appendLine(historicalCrashes)
        }
        val journal = VpnEventJournal.read(context)
        if (journal.isNotBlank()) {
            sb.appendLine("=== vpn events ===")
            sb.appendLine(journal)
        }
        val logcat = runCatching { logcatSupplier() }.getOrDefault("")
        if (logcat.isNotBlank()) {
            sb.appendLine("=== logcat (app process) ===")
            sb.appendLine(logcat)
        }
        val sanitized = redact(sb.toString()).toByteArray(Charsets.UTF_8)
        return if (sanitized.size > MAX_BUNDLE_BYTES) sanitized.copyOf(MAX_BUNDLE_BYTES) else sanitized
    }

    /** Why this app's last processes ended (crash, low memory, OEM kill, user stop), API 30+. */
    private fun recentProcessExits(context: Context): String {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.R) return ""
        val activityManager = context.getSystemService(ActivityManager::class.java) ?: return ""
        return activityManager.getHistoricalProcessExitReasons(context.packageName, 0, 8)
            .joinToString("\n") { exit ->
                val trace = if (exit.reason in setOf(ApplicationExitInfo.REASON_CRASH,
                        ApplicationExitInfo.REASON_CRASH_NATIVE, ApplicationExitInfo.REASON_ANR)) {
                    runCatching {
                        exit.traceInputStream?.use { String(it.readBounded(64 * 1024), Charsets.UTF_8) }
                    }.getOrNull().orEmpty()
                } else ""
                "${Instant.ofEpochMilli(exit.timestamp)} pid=${exit.pid} process=${exit.processName} " +
                    "reason=${exitReasonName(exit.reason)} status=${exit.status} importance=${exit.importance} " +
                    "description=${exit.description.orEmpty().take(200)}" +
                    if (trace.isBlank()) "" else "\n--- exit trace (bounded) ---\n$trace"
            }
    }

    private fun exitReasonName(reason: Int): String = when (reason) {
        ApplicationExitInfo.REASON_EXIT_SELF -> "exit_self"
        ApplicationExitInfo.REASON_SIGNALED -> "signaled"
        ApplicationExitInfo.REASON_LOW_MEMORY -> "low_memory"
        ApplicationExitInfo.REASON_CRASH -> "crash"
        ApplicationExitInfo.REASON_CRASH_NATIVE -> "crash_native"
        ApplicationExitInfo.REASON_ANR -> "anr"
        ApplicationExitInfo.REASON_EXCESSIVE_RESOURCE_USAGE -> "excessive_resource_usage"
        ApplicationExitInfo.REASON_USER_REQUESTED -> "user_requested"
        ApplicationExitInfo.REASON_USER_STOPPED -> "user_stopped"
        ApplicationExitInfo.REASON_DEPENDENCY_DIED -> "dependency_died"
        ApplicationExitInfo.REASON_OTHER -> "other"
        else -> "code_$reason"
    }

    private val logcatReader = Executors.newSingleThreadExecutor { task ->
        Thread(task, "debug-logcat-reader").apply { isDaemon = true }
    }

    private fun captureProcessLogcat(): String = captureLogcat(
        listOf("--pid=${android.os.Process.myPid()}", "-t", "2500"),
    )

    // UID selection includes previous app processes, without collecting other apps' crashes.
    private fun captureHistoricalCrashLogcat(): String = captureLogcat(
        listOf("-b", "crash", "--uid=${android.os.Process.myUid()}", "-t", "400"),
    )

    private fun captureLogcat(arguments: List<String>): String {
        val proc = ProcessBuilder(listOf("logcat", "-d", "-v", "threadtime") + arguments)
            .redirectErrorStream(true).start()
        val reader = logcatReader.submit<ByteArray> { proc.inputStream.use { it.readBounded(1024 * 1024) } }
        return try {
            String(reader.get(3, TimeUnit.SECONDS), Charsets.UTF_8)
        } finally {
            proc.destroy()
            reader.cancel(true)
        }
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
        if (!stateFile.exists()) return false
        if (!stateFile.isFile) throw IOException("debug_upload_state_invalid")
        if (stateFile.length() > 4096) throw IOException("debug_upload_state_invalid")
        val state = try {
            Gson().fromJson(stateFile.readText(), PassiveState::class.java)
                ?: throw IOException("debug_upload_state_invalid")
        } catch (error: Exception) {
            throw IOException("debug_upload_state_invalid", error)
        }
        return state.completedUuid.equals(uuid.trim(), ignoreCase = true)
    }

    @Synchronized
    fun markUploadedPassiveCollection(stateDir: File, uuid: String) {
        require(isValidCollectionUuid(uuid))
        if (!stateDir.isDirectory && !stateDir.mkdirs()) throw IOException("debug_upload_state_unavailable")
        val target = File(stateDir, STATE_FILE_NAME)
        val temp = File(stateDir, "$STATE_FILE_NAME.tmp")
        try {
            FileOutputStream(temp).use {
                it.write(Gson().toJson(PassiveState(uuid.trim().lowercase())).toByteArray(Charsets.UTF_8))
                it.fd.sync()
            }
            Files.move(temp.toPath(), target.toPath(), StandardCopyOption.ATOMIC_MOVE, StandardCopyOption.REPLACE_EXISTING)
        } finally {
            temp.delete()
        }
    }

    private fun sha256Hex(bytes: ByteArray): String =
        MessageDigest.getInstance("SHA-256").digest(bytes).joinToString("") { "%02x".format(it) }

    private data class PassiveState(val completedUuid: String = "")
    private const val STATE_FILE_NAME = "debug-upload-state.json"
}

internal fun InputStream.readBounded(limit: Int): ByteArray {
    val output = ByteArrayOutputStream(minOf(limit, 8192))
    val buffer = ByteArray(8192)
    while (output.size() < limit) {
        val count = read(buffer, 0, minOf(buffer.size, limit - output.size()))
        if (count < 0) break
        if (count == 0) continue
        output.write(buffer, 0, count)
    }
    return output.toByteArray()
}
