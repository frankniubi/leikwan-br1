package com.leikwan.bridge.diagnostics

import android.content.Context
import java.io.File
import java.time.Instant

/**
 * A small on-disk record of tunnel lifecycle events (start, stop and why, interface switches,
 * rebuilds). The debug bundle's logcat covers only the current process and minutes of sing-box
 * output, so a VPN that went down earlier left no trace in an uploaded log; this file outlives
 * both. One line per event, capped at [MAX_BYTES] with a single rotated predecessor.
 */
object VpnEventJournal {
    private const val FILE_NAME = "vpn-events.log"
    internal const val MAX_BYTES = 64 * 1024L

    fun record(context: Context, event: String) = recordTo(context.filesDir, event)

    fun read(context: Context): String = readFrom(context.filesDir)

    @Synchronized
    internal fun recordTo(directory: File, event: String, now: Instant = Instant.now()) {
        runCatching {
            val file = File(directory, FILE_NAME)
            if (file.length() >= MAX_BYTES) {
                val previous = File(directory, "$FILE_NAME.1")
                previous.delete()
                file.renameTo(previous)
            }
            val line = event.replace('\r', ' ').replace('\n', ' ').take(400)
            file.appendText("$now $line\n")
        }
    }

    @Synchronized
    internal fun readFrom(directory: File): String = runCatching {
        listOf(File(directory, "$FILE_NAME.1"), File(directory, FILE_NAME))
            .filter(File::isFile)
            .joinToString("") { it.readText() }
    }.getOrDefault("")
}
