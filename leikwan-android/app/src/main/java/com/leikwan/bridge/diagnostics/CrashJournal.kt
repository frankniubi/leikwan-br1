package com.leikwan.bridge.diagnostics

import android.content.Context
import com.leikwan.bridge.BuildConfig
import java.io.File
import java.io.FileOutputStream
import java.io.PrintWriter
import java.io.Writer
import java.nio.file.Files
import java.nio.file.StandardCopyOption
import java.time.Instant

/** Best-effort evidence only. The platform's crash handler still owns termination/reporting. */
object CrashJournal {
    internal const val MAX_BYTES = 64 * 1024
    internal const val FILE_NAME = "uncaught-exceptions.log"

    @Synchronized
    fun install(context: Context) {
        val previous = Thread.getDefaultUncaughtExceptionHandler()
        if (previous is JournalHandler) return
        Thread.setDefaultUncaughtExceptionHandler(JournalHandler(context.filesDir, previous))
    }

    internal class JournalHandler(
        private val directory: File,
        private val previous: Thread.UncaughtExceptionHandler?,
    ) : Thread.UncaughtExceptionHandler {
        private val handling = ThreadLocal<Boolean>()

        override fun uncaughtException(thread: Thread, error: Throwable) {
            // Some installed handlers call the current default handler again. Break that cycle
            // without duplicating the journal or recursively delegating to the same handler.
            if (handling.get() == true) return
            handling.set(true)
            try {
                try {
                    recordTo(directory, thread, error)
                } catch (_: Throwable) {
                    // OOM, full disk and journal bugs must not swallow the original crash.
                }
                if (previous != null) previous.uncaughtException(thread, error) else {
                    android.os.Process.killProcess(android.os.Process.myPid())
                    kotlin.system.exitProcess(10)
                }
            } finally {
                handling.remove()
            }
        }
    }

    @Synchronized
    internal fun recordTo(directory: File, thread: Thread, error: Throwable) {
        val text = StringBuilder()
        val writer = object : Writer() {
            override fun write(chars: CharArray, offset: Int, count: Int) {
                val remaining = MAX_BYTES - text.length
                if (remaining > 0) text.append(chars, offset, minOf(count, remaining))
            }
            override fun flush() = Unit
            override fun close() = Unit
        }
        PrintWriter(writer).use { out ->
            out.println("=== uncaught exception utc=${Instant.now()} version=${BuildConfig.VERSION_NAME} " +
                "build=${BuildConfig.VERSION_CODE} pid=${android.os.Process.myPid()} thread=${thread.name} ===")
            error.printStackTrace(out)
        }
        val bytes = DebugLogUploader.redact(text.toString()).toByteArray(Charsets.UTF_8)
            .let { it.copyOf(minOf(it.size, MAX_BYTES)) }
        val file = File(directory, FILE_NAME)
        val old = if (file.isFile) file.inputStream().use { it.readBounded(MAX_BYTES) } else ByteArray(0)
        val payload = if (old.size + bytes.size <= MAX_BYTES) old + bytes else bytes
        if (old.isNotEmpty() && old.size + bytes.size > MAX_BYTES) {
            atomicWrite(File(directory, "$FILE_NAME.1"), old)
        }
        atomicWrite(file, payload)
    }

    @Synchronized
    fun read(context: Context): String = runCatching {
        listOf("$FILE_NAME.1", FILE_NAME).map { File(context.filesDir, it) }
            .filter(File::isFile).joinToString("\n") { file ->
                file.inputStream().use { String(it.readBounded(MAX_BYTES), Charsets.UTF_8) }
            }
    }.getOrDefault("")

    private fun atomicWrite(file: File, bytes: ByteArray) {
        val temp = File(file.parentFile, "${file.name}.tmp")
        try {
            FileOutputStream(temp).use { it.write(bytes); it.fd.sync() }
            Files.move(temp.toPath(), file.toPath(), StandardCopyOption.ATOMIC_MOVE, StandardCopyOption.REPLACE_EXISTING)
        } finally {
            temp.delete()
        }
    }
}
