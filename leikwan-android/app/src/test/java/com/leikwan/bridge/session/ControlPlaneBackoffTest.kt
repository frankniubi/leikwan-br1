package com.leikwan.bridge.session

import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.ExperimentalCoroutinesApi
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.test.StandardTestDispatcher
import kotlinx.coroutines.test.TestScope
import kotlinx.coroutines.test.advanceTimeBy
import kotlinx.coroutines.test.runCurrent
import java.io.IOException
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Test

class ControlPlaneBackoffTest {

    @Test
    fun `ceiling doubles from 5 s and caps at 60 s`() {
        val backoff = ControlPlaneBackoff()

        assertEquals(listOf(5_000L, 10_000L, 20_000L, 40_000L, 60_000L, 60_000L),
            (0..5).map(backoff::ceilingMs))
        assertEquals(60_000L, backoff.ceilingMs(10_000))
    }

    @Test
    fun `full jitter draws from zero to the current ceiling`() {
        val bounds = mutableListOf<Long>()
        val backoff = ControlPlaneBackoff(randomUpTo = { bound -> bounds += bound; bound })

        repeat(6) { backoff.nextDelayMs() }

        assertEquals(listOf(5_000L, 10_000L, 20_000L, 40_000L, 60_000L, 60_000L), bounds)
        assertEquals(6, backoff.consecutiveFailures)
    }

    @Test
    fun `real jitter stays within bounds`() {
        val backoff = ControlPlaneBackoff()
        repeat(50) { attempt ->
            val delay = backoff.nextDelayMs()
            assertTrue("attempt $attempt delay $delay", delay in 0L..backoff.ceilingMs(attempt))
        }
    }

    @Test
    fun `reset returns to the 5 s ceiling`() {
        val bounds = mutableListOf<Long>()
        val backoff = ControlPlaneBackoff(randomUpTo = { bound -> bounds += bound; 0L })

        repeat(4) { backoff.nextDelayMs() }
        backoff.reset()
        backoff.nextDelayMs()

        assertEquals(5_000L, bounds.last())
        assertEquals(1, backoff.consecutiveFailures)
    }

    @OptIn(ExperimentalCoroutinesApi::class)
    @Test
    fun `control loop retries failures with backoff and resets after a success`() {
        val dispatcher = StandardTestDispatcher()
        val testScope = TestScope(dispatcher)
        val scope = CoroutineScope(SupervisorJob() + dispatcher)
        val scheduled = mutableListOf<Long>()
        // Always wait the full ceiling so the timeline is deterministic.
        val backoff = ControlPlaneBackoff(randomUpTo = { it })
        // fail, fail, fail, ok, fail
        val outcomes = ArrayDeque(listOf(false, false, false, true, false))
        var polls = 0
        val loop = ClientControlLoop(
            scope = scope,
            intervalMs = 30_000,
            poll = {
                polls += 1
                if (!(outcomes.removeFirstOrNull() ?: true)) throw IOException("panel unreachable")
            },
            onTerminal = {},
            retryBackoff = backoff,
            onRetryScheduled = scheduled::add,
        )

        loop.start()
        testScope.runCurrent()
        assertEquals(1, polls)
        assertEquals(ControlPlaneState.RECONNECTING, loop.state)
        testScope.advanceTimeBy(5_001)
        assertEquals(2, polls)
        testScope.advanceTimeBy(10_001)
        assertEquals(3, polls)
        testScope.advanceTimeBy(20_001)
        assertEquals(4, polls)
        assertEquals(ControlPlaneState.READY, loop.state)
        // A success waits the regular interval, then the next failure starts from 5 s again.
        testScope.advanceTimeBy(30_001)
        assertEquals(5, polls)
        assertEquals(listOf(5_000L, 10_000L, 20_000L, 5_000L), scheduled)
        assertTrue(loop.isRunning)
        loop.stop()
    }
}
