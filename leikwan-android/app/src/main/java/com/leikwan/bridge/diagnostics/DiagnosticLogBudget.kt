package com.leikwan.bridge.diagnostics

/** Shared by all detailed streams; resetting a callback/batch never resets the budget. */
internal class DiagnosticLogBudget(
    private val limit: Int = 30,
    private val windowMs: Long = 10_000,
    private val nowMs: () -> Long = { System.nanoTime() / 1_000_000 },
) {
    private var windowStart = nowMs()
    private var emitted = 0
    private var suppressed = 0L

    @Synchronized
    fun admit(): Boolean {
        if (emitted < limit) { emitted++; return true }
        suppressed++
        return false
    }

    /** Called before each batch/status update; reports the previous window once, including zero traffic. */
    @Synchronized
    fun advance(): Long {
        val now = nowMs()
        if (now >= windowStart && now - windowStart < windowMs) return 0
        val count = suppressed
        windowStart = now
        emitted = 0
        suppressed = 0
        return count
    }
}
