package com.leikwan.bridge.session

import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.CoroutineStart
import kotlinx.coroutines.Job
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch

/** One bounded foreground control poller. Network failures wait for the next interval; terminal controls stop. */
class ClientControlLoop(
    private val scope: CoroutineScope,
    private val intervalMs: Long = 30_000L,
    private val poll: suspend () -> Unit,
    private val onTerminal: (PanelControlException) -> Unit,
    private val shouldContinue: () -> Boolean = { true },
) {
    private val lock = Any()
    private var job: Job? = null

    var startCount: Int = 0
        private set

    val isRunning: Boolean
        get() = synchronized(lock) { job?.isActive == true }

    fun start() {
        synchronized(lock) {
            if (job?.isActive == true) return
            val next = scope.launch(start = CoroutineStart.LAZY) {
                while (shouldContinue()) {
                    try {
                        poll()
                    } catch (cancelled: CancellationException) {
                        throw cancelled
                    } catch (terminal: PanelControlException) {
                        onTerminal(terminal)
                        return@launch
                    } catch (_: Exception) {
                        // A transient control-plane failure must not tear down a healthy data plane.
                    }
                    delay(intervalMs)
                }
            }
            job = next
            startCount += 1
            next.start()
        }
    }

    fun stop() {
        synchronized(lock) {
            job?.cancel()
            job = null
        }
    }
}
