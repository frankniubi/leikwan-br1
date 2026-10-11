package com.leikwan.bridge.session

import java.util.concurrent.ThreadLocalRandom

/**
 * Exponential backoff with full jitter for control-plane retries (config pull, lease renewal).
 *
 * The n-th consecutive failure (n from 0) waits a uniformly random time in
 * `[0, min(capMs, baseMs * 2^n)]`: 5 s, 10 s, 20 s, 40 s, then 60 s ceilings by default. The
 * randomness keeps a fleet from stampeding the panel when it comes back. [reset] after a success.
 */
class ControlPlaneBackoff(
    private val baseMs: Long = 5_000L,
    private val capMs: Long = 60_000L,
    /** Returns a uniformly random value in `[0, bound]` (inclusive). */
    private val randomUpTo: (Long) -> Long = { bound -> ThreadLocalRandom.current().nextLong(bound + 1) },
) {
    init {
        require(baseMs > 0L) { "backoff_base_invalid" }
        require(capMs >= baseMs) { "backoff_cap_invalid" }
    }

    private val lock = Any()
    private var failures = 0

    val consecutiveFailures: Int
        get() = synchronized(lock) { failures }

    /** Ceiling for the given zero-based attempt, without jitter. */
    fun ceilingMs(attempt: Int): Long {
        var ceiling = baseMs
        repeat(attempt.coerceAtLeast(0)) {
            if (ceiling >= capMs) return capMs
            ceiling = if (ceiling > capMs / 2) capMs else ceiling * 2
        }
        return ceiling.coerceAtMost(capMs)
    }

    /** Records one failure and returns how long to wait before the next attempt. */
    fun nextDelayMs(): Long {
        val attempt = synchronized(lock) { failures.also { if (failures < Int.MAX_VALUE) failures += 1 } }
        val ceiling = ceilingMs(attempt)
        return randomUpTo(ceiling).coerceIn(0L, ceiling)
    }

    fun reset() {
        synchronized(lock) { failures = 0 }
    }
}
