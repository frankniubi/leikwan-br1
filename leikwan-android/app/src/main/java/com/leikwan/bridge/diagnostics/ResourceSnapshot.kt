package com.leikwan.bridge.diagnostics

import android.system.Os
import java.io.File

/**
 * Process resource facts that decide whether a native abort was resource exhaustion.
 *
 * Android reports a failed fd fixup on the binder ioctl as -12 (ENOMEM), and Conscrypt's
 * SSL_new fails with "Unable to create application data" when it cannot allocate, so neither
 * message on its own names the resource that ran out. The fd table, its ceiling and the
 * largest fd families separate an fd leak from memory pressure.
 */
object ResourceSnapshot {
    data class Value(
        val fdCount: Int,
        val fdLimit: Int,
        val rssKb: Long,
        val families: List<Pair<String, Int>>,
    ) {
        /** One bounded line for the lifecycle journal. */
        fun summary(): String =
            "fds=${if (fdCount >= 0) fdCount.toString() else "?"} " +
                "limit=${if (fdLimit > 0) fdLimit.toString() else "?"} " +
                "rss_kb=${if (rssKb >= 0) rssKb.toString() else "?"}"
    }

    fun capture(familyLimit: Int = 12): Value = Value(
        fdCount = fdCount(),
        fdLimit = fdLimit(),
        rssKb = rssKb(),
        families = fdFamilies(familyLimit),
    )

    fun fdCount(): Int = runCatching { File("/proc/self/fd").list()?.size ?: -1 }.getOrDefault(-1)

    /** Soft "Max open files" limit from /proc/self/limits; 0 when unreadable. */
    fun fdLimit(): Int = runCatching {
        File("/proc/self/limits").useLines { lines ->
            lines.firstOrNull { it.startsWith("Max open files") }
                ?.trim()?.split(WHITESPACE)?.getOrNull(3)?.toIntOrNull()
        } ?: 0
    }.getOrDefault(0)

    /** Resident set size from /proc/self/status; -1 when unreadable. */
    fun rssKb(): Long = runCatching {
        File("/proc/self/status").useLines { lines ->
            lines.firstOrNull { it.startsWith("VmRSS:") }
                ?.trim()?.split(WHITESPACE)?.getOrNull(1)?.toLongOrNull()
        } ?: -1L
    }.getOrDefault(-1L)

    /** Top fd families by count, e.g. `socket:[N]`, `/dev/tun`, `anon_inode:[eventpoll]`. */
    fun fdFamilies(limit: Int = 12): List<Pair<String, Int>> = runCatching {
        val counts = HashMap<String, Int>()
        File("/proc/self/fd").list()?.forEach { name ->
            val target = runCatching { Os.readlink("/proc/self/fd/$name") }.getOrNull() ?: return@forEach
            counts.merge(family(target), 1, Int::plus)
        }
        counts.entries
            .sortedWith(compareByDescending<Map.Entry<String, Int>> { it.value }.thenBy { it.key })
            .take(limit)
            .map { it.key to it.value }
    }.getOrDefault(emptyList())

    /** Collapses per-open descriptors ("socket:[12345]") into one family. */
    internal fun family(target: String): String {
        val bracket = target.substringAfter('[', "").substringBefore(']', "")
        val base = target.substringBefore('[').replace(NUMBERS, "N").trim()
        val name = bracket.replace(NUMBERS, "N")
        return when {
            name.isEmpty() -> if (base.isEmpty()) target else base
            base.isEmpty() -> "[$name]"
            else -> "$base[$name]"
        }
    }

    /** Multi-line section for the debug bundle. */
    fun render(): String {
        val value = capture()
        return buildString {
            appendLine(value.summary())
            value.families.forEach { (family, count) -> appendLine("$count $family") }
        }.trimEnd()
    }

    private val WHITESPACE = Regex("\\s+")
    private val NUMBERS = Regex("\\d+")
}
