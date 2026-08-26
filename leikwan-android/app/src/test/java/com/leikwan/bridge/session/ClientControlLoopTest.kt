package com.leikwan.bridge.session

import kotlinx.coroutines.CompletableDeferred
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.SupervisorJob
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
}
