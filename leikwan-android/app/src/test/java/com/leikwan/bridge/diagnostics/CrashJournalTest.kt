package com.leikwan.bridge.diagnostics

import android.content.Context
import androidx.test.core.app.ApplicationProvider
import com.leikwan.bridge.BuildConfig
import org.junit.Assert.*
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.RobolectricTestRunner
import org.robolectric.annotation.Config
import java.io.File
import java.nio.file.Files

@RunWith(RobolectricTestRunner::class)
@Config(sdk = [34])
class CrashJournalTest {
    @Test
    fun `uncaught stack is redacted persisted and delegates original throwable`() {
        val dir = Files.createTempDirectory("crash-journal").toFile()
        try {
            val cause = IllegalArgumentException("password=secret token=hidden 203.0.113.4")
            val error = IllegalStateException("test crash", cause)
            var delegated: Throwable? = null
            val thread = Thread.currentThread()
            val handler = CrashJournal.JournalHandler(dir) { actualThread, actualError ->
                assertSame(thread, actualThread)
                delegated = actualError
            }
            handler.uncaughtException(thread, error)
            assertSame(error, delegated)
            val text = File(dir, CrashJournal.FILE_NAME).readText()
            assertTrue(text.contains("java.lang.IllegalStateException: test crash"))
            assertTrue(text.contains("Caused by: java.lang.IllegalArgumentException"))
            assertTrue(text.contains("CrashJournalTest"))
            for (secret in listOf("secret", "hidden", "203.0.113.4")) assertFalse(text.contains(secret))
        } finally { dir.deleteRecursively() }
    }

    @Test
    fun `journal failure never prevents delegation and each rotated file is bounded`() {
        val invalidDirectory = Files.createTempFile("journal-not-dir", ".tmp").toFile()
        var called = false
        CrashJournal.JournalHandler(invalidDirectory) { _, _ -> called = true }
            .uncaughtException(Thread.currentThread(), RuntimeException("failure"))
        assertTrue(called)
        invalidDirectory.delete()
        val dir = Files.createTempDirectory("journal-bounded").toFile()
        try {
            repeat(4) {
                CrashJournal.recordTo(dir, Thread.currentThread(), RuntimeException("x".repeat(200_000)))
            }
            val files = dir.listFiles()!!
            assertEquals(2, files.size)
            files.forEach { assertTrue(it.length() <= CrashJournal.MAX_BYTES) }
        } finally { dir.deleteRecursively() }
    }

    @Test
    fun `previous handler reentry cannot recurse or duplicate the crash`() {
        val dir = Files.createTempDirectory("journal-reentry").toFile()
        try {
            lateinit var installed: CrashJournal.JournalHandler
            var calls = 0
            installed = CrashJournal.JournalHandler(dir) { thread, error ->
                calls++
                installed.uncaughtException(thread, error)
            }
            installed.uncaughtException(Thread.currentThread(), RuntimeException("reentry-marker"))
            assertEquals(1, calls)
            val text = File(dir, CrashJournal.FILE_NAME).readText()
            assertEquals(1, Regex("=== uncaught exception").findAll(text).count())
            assertTrue("Expected version=${BuildConfig.VERSION_NAME} in crash journal:\n$text",
                text.contains("version=${BuildConfig.VERSION_NAME}"))
            assertTrue("Expected build=${BuildConfig.VERSION_CODE} and pid in crash journal:\n$text",
                text.contains("build=${BuildConfig.VERSION_CODE} pid="))
        } finally { dir.deleteRecursively() }
    }

    @Test
    fun `prior crash appears in diagnostic bundle`() {
        val context: Context = ApplicationProvider.getApplicationContext()
        try {
            CrashJournal.recordTo(context.filesDir, Thread.currentThread(), RuntimeException("prior-process-crash"))
            val bundle = String(DebugLogUploader.collectAppBundle(context) { "current process" })
            assertTrue(bundle.contains("=== persistent uncaught exceptions ==="))
            assertTrue(bundle.contains("prior-process-crash"))
            assertTrue(bundle.indexOf("prior-process-crash") < bundle.indexOf("=== logcat (app process) ==="))
        } finally {
            context.filesDir.listFiles { f -> f.name.startsWith(CrashJournal.FILE_NAME) }?.forEach { it.delete() }
        }
    }
}
