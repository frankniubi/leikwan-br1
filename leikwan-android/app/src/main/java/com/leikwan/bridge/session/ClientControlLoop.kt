package com.leikwan.bridge.session

import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.CoroutineStart
import kotlinx.coroutines.Job
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch

enum class ControlPlaneState {
    READY,
    RECONNECTING,
    STOPPED,
}

/**
 * One bounded foreground control poller. A successful poll waits [intervalMs]; a transient
 * failure retries after [retryBackoff] (exponential, full jitter, 5 s -> 60 s by default, reset on
 * the next success) and never tears down the data plane. Terminal controls stop the loop.
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
) {
    private val lock = Any()
    private var job: Job? = null

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
                while (shouldContinue()) {
                    val waitMs = try {
                        poll()
                        retryBackoff.reset()
                        updateState(ControlPlaneState.READY)
                        intervalMs
                    } catch (cancelled: CancellationException) {
                        throw cancelled
                    } catch (terminal: PanelControlException) {
                        onTerminal(terminal)
                        updateState(ControlPlaneState.STOPPED)
                        return@launch
                    } catch (_: Exception) {
                        // A transient control-plane failure must not tear down a healthy data plane.
                        updateState(ControlPlaneState.RECONNECTING)
                        retryBackoff.nextDelayMs().also(onRetryScheduled)
                    }
                    delay(waitMs)
                }
                updateState(ControlPlaneState.STOPPED)
            }
            job = next
            startCount += 1
            updateState(ControlPlaneState.RECONNECTING)
            next.start()
        }
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
