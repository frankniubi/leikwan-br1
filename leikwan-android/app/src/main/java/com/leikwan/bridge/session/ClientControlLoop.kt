package com.leikwan.bridge.session

import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.CoroutineStart
import kotlinx.coroutines.Job
import kotlinx.coroutines.channels.Channel
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch
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
) {
    private val lock = Any()
    private var job: Job? = null
    private val wake = Channel<Unit>(Channel.CONFLATED)

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
                        // is never shortened, so a dead panel is not hammered.
                        withTimeoutOrNull(waitMs) { wake.receive() }
                    } else {
                        delay(waitMs)
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
