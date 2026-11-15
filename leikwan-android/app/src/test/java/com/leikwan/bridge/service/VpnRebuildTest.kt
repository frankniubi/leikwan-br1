package com.leikwan.bridge.service

import java.io.IOException
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.CompletableDeferred
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.Job
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.cancel
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch
import kotlinx.coroutines.runBlocking
import kotlinx.coroutines.withTimeout
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertSame
import org.junit.Assert.assertTrue
import org.junit.Assert.fail
import org.junit.Test

class VpnRebuildTest {

    private class Attempts(
        private val failures: Int,
        private val tunnelUpWhileFailing: Boolean = false,
    ) {
        var calls = 0
        var tunnelUp = tunnelUpWhileFailing
        val pauses = mutableListOf<Long>()
        val failedAttempts = mutableListOf<Int>()
        val lastError = IOException("panel unreachable")

        suspend fun run(
            recovering: () -> Boolean = { true },
            connectIntent: () -> Boolean = { true },
        ): RebuildOutcome = VpnRecovery.runRebuildAttempts(
            reconnect = {
                calls += 1
                if (calls <= failures) throw lastError
            },
            stillRecovering = recovering,
            connectIntent = connectIntent,
            tunnelStillUp = { tunnelUp },
            onAttemptFailed = { attempt, _ -> failedAttempts += attempt },
            pause = { pauses += it },
        )
    }

    @Test
    fun `a rebuild that works on the first attempt does not wait`() = runBlocking {
        val attempts = Attempts(failures = 0)

        assertSame(RebuildOutcome.Reconnected, attempts.run())
        assertEquals(1, attempts.calls)
        assertTrue(attempts.pauses.isEmpty())
    }

    @Test
    fun `a stopped tunnel is retried on the recovery schedule until it comes back`() = runBlocking {
        val attempts = Attempts(failures = 2)

        assertSame(RebuildOutcome.Reconnected, attempts.run())
        assertEquals(3, attempts.calls)
        assertEquals(listOf(1, 2), attempts.failedAttempts)
        assertEquals(VpnRecovery.RETRY_DELAYS_MS.take(2), attempts.pauses)
    }

    @Test
    fun `every attempt failing gives up with the last error`() = runBlocking {
        val attempts = Attempts(failures = Int.MAX_VALUE)

        val outcome = attempts.run()

        assertEquals(RebuildOutcome.GaveUp(attempts.lastError), outcome)
        assertEquals(VpnRecovery.ATTEMPTS, attempts.calls)
        assertEquals(VpnRecovery.RETRY_DELAYS_MS.toList(), attempts.pauses)
    }

    @Test
    fun `a failure before the running tunnel was stopped keeps that tunnel`() = runBlocking {
        // A post-switch rebuild that cannot reach the panel fails in its catalog pull, before it
        // stops anything; retrying would tear down a tunnel that may still be fine.
        val attempts = Attempts(failures = Int.MAX_VALUE, tunnelUpWhileFailing = true)

        assertEquals(RebuildOutcome.KeptCurrent(attempts.lastError), attempts.run())
        assertEquals(1, attempts.calls)
        assertTrue(attempts.pauses.isEmpty())
    }

    @Test
    fun `a failure after the tunnel was stopped keeps retrying`() = runBlocking {
        var calls = 0
        var tunnelUp = true
        val outcome = VpnRecovery.runRebuildAttempts(
            reconnect = {
                calls += 1
                // The orchestrator stops the old tunnel, then fails to start the new one.
                tunnelUp = false
                if (calls == 1) throw IOException("lease refused")
            },
            stillRecovering = { true },
            connectIntent = { true },
            tunnelStillUp = { tunnelUp },
            pause = {},
        )

        assertSame(RebuildOutcome.Reconnected, outcome)
        assertEquals(2, calls)
    }

    @Test
    fun `a recovery called off meanwhile ends without reporting a failure`() = runBlocking {
        val attempts = Attempts(failures = Int.MAX_VALUE)

        assertSame(RebuildOutcome.CalledOff, attempts.run(recovering = { false }))
        assertEquals(0, attempts.calls)
    }

    @Test
    fun `a disconnect in the app ends the rebuild as given up`() = runBlocking {
        val attempts = Attempts(failures = Int.MAX_VALUE)

        assertEquals(RebuildOutcome.GaveUp(null), attempts.run(connectIntent = { false }))
        assertEquals(0, attempts.calls)
    }

    @Test
    fun `cancellation is not counted as a failed attempt`() = runBlocking {
        try {
            VpnRecovery.runRebuildAttempts(
                reconnect = { throw CancellationException("service destroyed") },
                stillRecovering = { true },
                connectIntent = { true },
                tunnelStillUp = { false },
                onAttemptFailed = { _, _ -> fail("cancellation must propagate") },
                pause = {},
            )
            fail("expected cancellation")
        } catch (_: CancellationException) {
        }
    }

    /**
     * The 2026-11-14 field failure: the post-switch rebuild ran inside the switch job, and the
     * rebuild's own stop of the old tunnel (stopVpn) cancels that job. The rebuild then died at
     * its next suspension, after the catalog pull and before the lease request, leaving no VPN.
     */
    @Test
    fun `a rebuild inside the job its own stop cancels never finishes`() = runBlocking {
        val scope = CoroutineScope(SupervisorJob() + Dispatchers.Default)
        try {
            var finished = false
            lateinit var switchJob: Job
            switchJob = scope.launch {
                VpnRecovery.runRebuildAttempts(
                    reconnect = {
                        switchJob.cancel() // stopVpn() -> switchRecoveryJob?.cancel()
                        delay(10) // the lease request
                        finished = true
                    },
                    stillRecovering = { true },
                    connectIntent = { true },
                    tunnelStillUp = { false },
                )
            }
            switchJob.join()
            assertFalse(finished)
        } finally {
            scope.cancel()
        }
    }

    @Test
    fun `a rebuild handed off to its own job survives the stop cancelling the switch job`() = runBlocking {
        val scope = CoroutineScope(SupervisorJob() + Dispatchers.Default)
        try {
            val outcome = CompletableDeferred<RebuildOutcome>()
            lateinit var switchJob: Job
            switchJob = scope.launch {
                // What onDefaultInterfaceChanged now does: hand the rebuild to recoveryScope.
                scope.launch {
                    outcome.complete(
                        VpnRecovery.runRebuildAttempts(
                            reconnect = {
                                switchJob.cancel()
                                delay(10)
                            },
                            stillRecovering = { true },
                            connectIntent = { true },
                            tunnelStillUp = { false },
                        ),
                    )
                }
            }
            assertSame(RebuildOutcome.Reconnected, withTimeout(5_000) { outcome.await() })
        } finally {
            scope.cancel()
        }
    }

    @Test
    fun `the switch probe accepts any resolver of the new network`() = runBlocking {
        val asked = mutableListOf<String>()
        val pauses = mutableListOf<Long>()

        val healthy = VpnRecovery.anyResolverAnswers(
            listOf("2409:8057::1", "211.136.17.107"),
            pause = { pauses += it },
        ) { server ->
            asked += server
            server == "211.136.17.107"
        }

        assertTrue(healthy)
        assertEquals(listOf("2409:8057::1", "211.136.17.107"), asked)
        assertTrue(pauses.isEmpty())
    }

    @Test
    fun `the switch probe retries while the new network validates`() = runBlocking {
        var round = 0
        val pauses = mutableListOf<Long>()

        val healthy = VpnRecovery.anyResolverAnswers(listOf("192.168.1.1"), pause = { pauses += it }) {
            round += 1
            round == 2
        }

        assertTrue(healthy)
        assertEquals(listOf(VpnRecovery.SWITCH_PROBE_RETRY_DELAY_MS), pauses)
    }

    @Test
    fun `the switch probe fails only after every round of every resolver`() = runBlocking {
        val asked = mutableListOf<String>()
        val pauses = mutableListOf<Long>()
        val resolvers = listOf("a", "b", "a", "c", "d")

        val healthy = VpnRecovery.anyResolverAnswers(resolvers, pause = { pauses += it }) {
            asked += it
            false
        }

        assertFalse(healthy)
        val expectedRound = listOf("a", "b", "c").take(VpnRecovery.SWITCH_PROBE_MAX_RESOLVERS)
        assertEquals(List(VpnRecovery.SWITCH_PROBE_ROUNDS) { expectedRound }.flatten(), asked)
        assertEquals(VpnRecovery.SWITCH_PROBE_ROUNDS - 1, pauses.size)
    }

    @Test
    fun `a network without resolvers is not judged broken`() = runBlocking {
        assertTrue(VpnRecovery.anyResolverAnswers(emptyList(), pause = {}) { fail("no query expected"); false })
    }
}
