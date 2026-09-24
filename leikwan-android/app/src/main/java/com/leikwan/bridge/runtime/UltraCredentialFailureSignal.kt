package com.leikwan.bridge.runtime

import java.time.Duration
import java.time.Instant

/**
 * Recognises the Ultra runtime output lines that suggest the relay stopped accepting the
 * forward's provider credential. The source host invalidates a line's credential when it
 * re-acquires the line (about every 20 min) and uploads the new one within seconds; until the
 * host renews, the relay resets or aborts every stream on that line.
 *
 * Mirrors `UltraRuntimeFailureLog.IsCredentialSuspect` on Desktop. Reason tokens are
 * `haErrorClass()` in tools/ultra-client/ha_tcp.go.
 */
object UltraCredentialFailureSignal {
    private val alwaysSuspect = listOf(
        "tcp stream setup failed " to "tcp_stream_setup_failed",
        "tcp stream remote write stalled " to "tcp_remote_write_stall",
        "external UoT carrier reconnect " to "external_uot_carrier_reconnect",
    )
    private const val STREAM_ENDED = "tcp stream ended "

    /** Relay-side closes. eof/timeout are ordinary target behaviour, not credential evidence. */
    private val suspectStreamReasons = setOf("reset", "aborted", "broken_pipe", "stall")
    private val relayStages = setOf("remote_read", "remote_write")

    // GuardedProcessPool prefixes "[command] "; Go's log adds "yyyy/MM/dd HH:mm:ss[.micro] ".
    private val leadingNoise = Regex("""^(?:\[[^\]]*] )?(?:\d{4}/\d{2}/\d{2} \d{2}:\d{2}:\d{2}(?:\.\d+)? )?""")
    private val stagePattern = Regex(""" stage=([A-Za-z0-9_]+)""")
    private val reasonPattern = Regex(""" reason=([A-Za-z0-9_]+)""")

    /** The failure kind when [line] is credential-suspect, otherwise null. */
    fun credentialSuspectKind(line: String): String? {
        val message = line.replaceFirst(leadingNoise, "")
        alwaysSuspect.firstOrNull { (prefix, _) -> message.startsWith(prefix) }?.let { return it.second }
        if (!message.startsWith(STREAM_ENDED)) return null
        val stage = stagePattern.find(message)?.groupValues?.get(1) ?: return null
        val reason = reasonPattern.find(message)?.groupValues?.get(1) ?: return null
        return if (stage in relayStages && reason in suspectStreamReasons) "tcp_stream_ended" else null
    }
}

/**
 * Paces failure-driven Ultra lease renewals per forward (same policy as Desktop's
 * `UltraFailureRenewalLimiter`): the first signal of a burst is admitted, the next no sooner than
 * [minInterval] later; while signals keep coming the interval doubles up to [maxInterval]; after
 * [quietReset] without a signal the forward starts again at [minInterval].
 */
class UltraFailureRenewalGate(
    private val minInterval: Duration = Duration.ofSeconds(20),
    private val maxInterval: Duration = Duration.ofSeconds(160),
    private val quietReset: Duration = Duration.ofMinutes(2),
) {
    init {
        require(!minInterval.isNegative && !minInterval.isZero) { "renewal_min_interval_invalid" }
        require(maxInterval >= minInterval) { "renewal_max_interval_invalid" }
        require(quietReset > minInterval) { "renewal_quiet_reset_invalid" }
    }

    private class State {
        var lastSignal: Instant? = null
        var nextAllowed: Instant = Instant.MIN
        var interval: Duration = Duration.ZERO
    }

    private val states = HashMap<Long, State>()

    @Synchronized
    fun tryAdmit(forwardId: Long, now: Instant): Boolean {
        val state = states.getOrPut(forwardId, ::State)
        val last = state.lastSignal
        if (last == null || Duration.between(last, now) >= quietReset) {
            state.interval = Duration.ZERO
            state.nextAllowed = Instant.MIN
        }
        state.lastSignal = now
        if (now.isBefore(state.nextAllowed)) return false
        state.interval = if (state.interval.isZero) minInterval else minOf(state.interval.multipliedBy(2), maxInterval)
        state.nextAllowed = now.plus(state.interval)
        return true
    }
}
