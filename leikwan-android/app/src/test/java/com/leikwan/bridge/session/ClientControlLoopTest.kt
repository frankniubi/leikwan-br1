package com.leikwan.bridge.session

import kotlinx.coroutines.CompletableDeferred
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.SupervisorJob
import java.io.IOException
import java.util.concurrent.atomic.AtomicInteger
import org.junit.Assert.assertFalse
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Test

class ClientControlLoopTest {
    @Test
    fun `control loop owns at most one foreground polling job`() {
        val scope = CoroutineScope(SupervisorJob() + Dispatchers.Unconfined)
        val hold = CompletableDeferred<Unit>()
        val loop = ClientControlLoop(
            scope = scope,
            intervalMs = 30_000,
            poll = { hold.await() },
            onTerminal = {},
        )

        loop.start()
        loop.start()

        assertEquals(1, loop.startCount)
        assertTrue(loop.isRunning)
        loop.stop()
    }

    @Test
    fun `terminal panel control ends polling instead of retrying`() {
        val scope = CoroutineScope(SupervisorJob() + Dispatchers.Unconfined)
        var terminal: PanelControlException? = null
        val loop = ClientControlLoop(
            scope = scope,
            intervalMs = 30_000,
            poll = { throw PanelControlException.banned(version = 5) },
            onTerminal = { terminal = it },
        )

        loop.start()

        assertEquals("device_banned", terminal?.code)
        assertTrue(!loop.isRunning)
    }

    @Test
    fun `transient control failure reports reconnecting without stopping the loop`() {
        val scope = CoroutineScope(SupervisorJob() + Dispatchers.Unconfined)
        val states = mutableListOf<ControlPlaneState>()
        val loop = ClientControlLoop(
            scope = scope,
            intervalMs = 30_000,
            poll = { throw IOException("panel timeout") },
            onTerminal = {},
            onStateChanged = states::add,
        )

        loop.start()

        assertEquals(ControlPlaneState.RECONNECTING, loop.state)
        assertTrue(ControlPlaneState.RECONNECTING in states)
        assertTrue(loop.isRunning)
        loop.stop()
        assertEquals(ControlPlaneState.STOPPED, loop.state)
    }
    @Test
    fun `requestPollSoon cuts a healthy interval short and coalesces`() {
        val scope = CoroutineScope(SupervisorJob() + Dispatchers.IO)
        val polls = AtomicInteger(0)
        val loop = ClientControlLoop(
            scope = scope,
            intervalMs = 60_000,
            poll = { polls.incrementAndGet() },
            onTerminal = {},
        )
        loop.start()
        waitUntil { polls.get() == 1 && loop.state == ControlPlaneState.READY }

        repeat(10) { loop.requestPollSoon() }
        waitUntil { polls.get() >= 2 }
        Thread.sleep(300)
        // Requests coalesce: one poll, plus at most one more for a request that
        // raced the start of that poll. Never one poll per request.
        val afterBurst = polls.get()
        assertTrue("polls after burst: $afterBurst", afterBurst in 2..3)
        Thread.sleep(300)
        assertEquals(afterBurst, polls.get())
        loop.stop()
        assertFalse(loop.requestPollSoon())
    }

    @Test
    fun `requestPollSoon never shortens a retry backoff`() {
        val scope = CoroutineScope(SupervisorJob() + Dispatchers.IO)
        val polls = AtomicInteger(0)
        val loop = ClientControlLoop(
            scope = scope,
            intervalMs = 60_000,
            poll = {
                polls.incrementAndGet()
                throw IOException("panel down")
            },
            onTerminal = {},
            retryBackoff = ControlPlaneBackoff(baseMs = 60_000, capMs = 60_000, randomUpTo = { it }),
        )
        loop.start()
        waitUntil { polls.get() == 1 && loop.state == ControlPlaneState.RECONNECTING }

        assertFalse(loop.requestPollSoon())
        Thread.sleep(300)
        assertEquals(1, polls.get())
        loop.stop()
    }

    @Test
    fun `device limit keeps polling at the steady cadence without revoking`() {
        val scope = CoroutineScope(SupervisorJob() + Dispatchers.Unconfined)
        var terminal: PanelControlException? = null
        val retries = mutableListOf<Long>()
        val limits = mutableListOf<DeviceLimitReachedException?>()
        val loop = ClientControlLoop(
            scope = scope,
            intervalMs = 30_000,
            poll = { throw DeviceLimitReachedException(limit = 2, active = 2) },
            onTerminal = { terminal = it },
            onRetryScheduled = retries::add,
            retryBackoff = ControlPlaneBackoff(randomUpTo = { 0L }),
            deviceLimitRetryMs = 60_000,
            onDeviceLimitChanged = limits::add,
        )

        loop.start()

        assertEquals(null, terminal)
        assertTrue(loop.isRunning)
        assertEquals(ControlPlaneState.RECONNECTING, loop.state)
        // The fast backoff would schedule 0 ms here (randomUpTo = 0); the limit uses its own cadence.
        assertEquals(listOf(60_000L), retries)
        assertEquals(2, limits.single()?.limit)
        loop.stop()
    }

    @Test
    fun `device limit clears on the next successful poll`() {
        val scope = CoroutineScope(SupervisorJob() + Dispatchers.IO)
        val polls = AtomicInteger(0)
        val limits = java.util.Collections.synchronizedList(mutableListOf<DeviceLimitReachedException?>())
        val loop = ClientControlLoop(
            scope = scope,
            intervalMs = 60_000,
            poll = {
                if (polls.incrementAndGet() == 1) throw DeviceLimitReachedException(limit = 1, active = 1)
            },
            onTerminal = {},
            deviceLimitRetryMs = 10,
            onDeviceLimitChanged = limits::add,
        )

        loop.start()
        waitUntil { polls.get() >= 2 && loop.state == ControlPlaneState.READY }

        assertEquals(2, limits.size)
        assertEquals(1, limits[0]?.limit)
        assertEquals(null, limits[1])
        loop.stop()
    }

    private fun waitUntil(timeoutMs: Long = 5_000, condition: () -> Boolean) {
        val deadline = System.currentTimeMillis() + timeoutMs
        while (!condition()) {
            if (System.currentTimeMillis() > deadline) throw AssertionError("condition not met in ${timeoutMs}ms")
            Thread.sleep(10)
        }
    }
}
