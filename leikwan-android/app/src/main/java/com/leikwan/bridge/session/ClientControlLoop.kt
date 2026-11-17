package com.leikwan.bridge.session

import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.CoroutineStart
import kotlinx.coroutines.Job
import kotlinx.coroutines.channels.Channel
import kotlinx.coroutines.launch
import kotlinx.coroutines.selects.select
import kotlinx.coroutines.withTimeoutOrNull

enum class ControlPlaneState {
    READY,
    RECONNECTING,
    STOPPED,
}

/**
 * One bounded foreground control poller. A successful poll waits [intervalMs]; a transient
 * failure retries after [retryBackoff] (exponential, full jitter, 5 s -> 60 s by default, reset on
 * the next success) and never tears down the data plane. Terminal controls stop the loop.
 *
 * A full online device limit ([DeviceLimitReachedException]) is neither: the device stays
 * enrolled, [onDeviceLimitChanged] tells the UI, and the poll repeats every [deviceLimitRetryMs]
 * so the device recovers on its own once a slot frees (the panel frees one after ~180 s of
 * silence). The fast backoff would only hammer a panel whose answer cannot change that quickly.
 */
class ClientControlLoop(
    private val scope: CoroutineScope,
    private val intervalMs: Long = 30_000L,
    private val poll: suspend () -> Unit,
    private val onTerminal: (PanelControlException) -> Unit,
    private val shouldContinue: () -> Boolean = { true },
    private val onStateChanged: (ControlPlaneState) -> Unit = {},
    private val retryBackoff: ControlPlaneBackoff = ControlPlaneBackoff(),
    private val onRetryScheduled: (Long) -> Unit = {},
    private val deviceLimitRetryMs: Long = 60_000L,
    /** Called with the refusal when the limit is hit and with null when a poll succeeds again. */
    private val onDeviceLimitChanged: (DeviceLimitReachedException?) -> Unit = {},
    /** Minimum spacing of [requestPollAfterNetworkRestored] wakes, so a flapping network cannot hammer the panel. */
    private val networkWakeSpacingMs: Long = 5_000L,
    private val nowMs: () -> Long = { System.nanoTime() / 1_000_000L },
) {
    private val lock = Any()
    private var job: Job? = null
    private val wake = Channel<Unit>(Channel.CONFLATED)
    private val networkWake = Channel<Unit>(Channel.CONFLATED)
    private var lastNetworkWakeAtMs: Long? = null

    var startCount: Int = 0
        private set

    @Volatile
    var state: ControlPlaneState = ControlPlaneState.STOPPED
        private set

    val isRunning: Boolean
        get() = synchronized(lock) { job?.isActive == true }

    fun start() {
        synchronized(lock) {
            if (job?.isActive == true) return
            val next = scope.launch(start = CoroutineStart.LAZY) {
                retryBackoff.reset()
                var limited = false
                while (shouldContinue()) {
                    // This poll serves every wake requested before it starts.
                    wake.tryReceive()
                    networkWake.tryReceive()
                    var healthy = false
                    val waitMs = try {
                        poll()
                        retryBackoff.reset()
                        if (limited) {
                            limited = false
                            onDeviceLimitChanged(null)
                        }
                        updateState(ControlPlaneState.READY)
                        healthy = true
                        intervalMs
                    } catch (cancelled: CancellationException) {
                        throw cancelled
                    } catch (terminal: PanelControlException) {
                        onTerminal(terminal)
                        updateState(ControlPlaneState.STOPPED)
                        return@launch
                    } catch (refused: DeviceLimitReachedException) {
                        // Recoverable but user-visible: keep enrollment and the data plane, and
                        // retry at a steady cadence rather than the fast transient backoff.
                        limited = true
                        onDeviceLimitChanged(refused)
                        retryBackoff.reset()
                        updateState(ControlPlaneState.RECONNECTING)
                        deviceLimitRetryMs.also(onRetryScheduled)
                    } catch (_: Exception) {
                        // A transient control-plane failure must not tear down a healthy data plane.
                        updateState(ControlPlaneState.RECONNECTING)
                        retryBackoff.nextDelayMs().also(onRetryScheduled)
                    }
                    if (healthy) {
                        // requestPollSoon() may cut a healthy interval short; a retry backoff
                        // is never shortened by it, so a dead panel is not hammered.
                        withTimeoutOrNull(waitMs) {
                            select<Unit> {
                                wake.onReceive {}
                                networkWake.onReceive {}
                            }
                        }
                    } else {
                        // Only a restored network ends a retry backoff early: it is new evidence
                        // that the panel may answer now (see requestPollAfterNetworkRestored).
                        withTimeoutOrNull(waitMs) { networkWake.receive() }
                    }
                }
                updateState(ControlPlaneState.STOPPED)
            }
            job = next
            startCount += 1
            updateState(ControlPlaneState.RECONNECTING)
            next.start()
        }
    }

    /**
     * Asks for the next poll now instead of at the end of the interval (a failure-driven lease
     * renewal). Honoured only while the control plane is [ControlPlaneState.READY]: during a
     * retry backoff the request waits for the scheduled retry, which does the same work. Repeated
     * requests coalesce into one poll. Returns true when the wake was accepted.
     */
    fun requestPollSoon(): Boolean {
        if (!isRunning || state != ControlPlaneState.READY) return false
        return wake.trySend(Unit).isSuccess
    }

    /**
     * The device's network came back (a voice call ended and cellular data resumed, Wi-Fi
     * rejoined): poll now, even inside a retry backoff. The backoff only exists so an unreachable
     * panel is not hammered; heartbeats that failed during the outage had grown it to up to a
     * minute of jittered wait, which kept a lease that lapsed during the call unrenewed after the
     * user was already back online. At most one wake per [networkWakeSpacingMs]. Returns true
     * when the wake was accepted.
     */
    fun requestPollAfterNetworkRestored(): Boolean {
        if (!isRunning) return false
        val now = nowMs()
        synchronized(lock) {
            val last = lastNetworkWakeAtMs
            if (last != null && now - last < networkWakeSpacingMs) return false
            lastNetworkWakeAtMs = now
        }
        return networkWake.trySend(Unit).isSuccess
    }

    fun stop() {
        synchronized(lock) {
            job?.cancel()
            job = null
            updateState(ControlPlaneState.STOPPED)
        }
    }

    private fun updateState(next: ControlPlaneState) {
        if (state == next) return
        state = next
        onStateChanged(next)
    }
}
