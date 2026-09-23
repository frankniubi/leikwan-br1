package com.leikwan.bridge.runtime

import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Test
import java.io.ByteArrayInputStream
import java.io.InputStream
import java.io.IOException
import java.util.concurrent.CopyOnWriteArrayList
import java.util.concurrent.CountDownLatch
import java.util.concurrent.TimeUnit

class GuardedProcessPoolTest {

    @Test
    fun `pool restarts process after a healthy-lived exit`() {
        val processes = CopyOnWriteArrayList<FakeManagedProcess>()
        val launcher = ProcessLauncher { _, _ ->
            FakeManagedProcess().also { processes += it }
        }
        val pool = GuardedProcessPool(
            launcher = launcher,
            minRestartWindowMs = 25,
        )

        pool.start(listOf("fake", "command"))
        assertEquals(1, processes.size)

        Thread.sleep(40)
        processes[0].finish(0)

        assertEventually("expected process to restart") {
            processes.size == 2
        }

        pool.close()
    }

    @Test
    fun `fast exit burst restarts with bounded backoff instead of giving up`() {
        val processes = CopyOnWriteArrayList<FakeManagedProcess>()
        val launcher = ProcessLauncher { _, _ ->
            FakeManagedProcess().also { processes += it }
        }
        val fatalErrors = CopyOnWriteArrayList<IOException>()
        val fastExits = CopyOnWriteArrayList<IOException>()
        val logs = CopyOnWriteArrayList<String>()
        val pool = GuardedProcessPool(
            launcher = launcher,
            minRestartWindowMs = 250,
            onFatal = { fatalErrors += it },
            logLine = { logs += it },
            restartBackoffBaseMs = 10,
            restartBackoffCapMs = 40,
            onFastExit = { fastExits += it },
        )

        pool.start(listOf("fake", "command"))
        // A burst of five crashes, each right after its (re)start.
        for (index in 0 until 5) {
            assertEventually("expected spawn #${index + 1}") { processes.size == index + 1 }
            processes[index].finish(9)
        }

        assertEventually("expected the guard to keep restarting after the burst") { processes.size == 6 }
        assertTrue("no permanent give-up on a transient burst", fatalErrors.isEmpty())
        assertEquals(5, fastExits.size)
        assertTrue(fastExits.first().message!!.contains("exits too fast"))
        assertTrue(logs.any { it.contains("restart process in 40ms") })
        assertTrue(processes.last().isAlive())

        pool.close()
    }

    @Test
    fun `restart backoff doubles and is capped`() {
        val pool = GuardedProcessPool(
            launcher = { _, _ -> FakeManagedProcess() },
            restartBackoffBaseMs = 1_000,
            restartBackoffCapMs = 30_000,
        )

        assertEquals(
            listOf(1_000L, 2_000L, 4_000L, 8_000L, 16_000L, 30_000L, 30_000L),
            (1..7).map(pool::restartDelayMs),
        )
        assertEquals(30_000L, pool.restartDelayMs(10_000))
        pool.close()
    }

    @Test
    fun `repeated configuration error exit is reported as fatal`() {
        val processes = CopyOnWriteArrayList<FakeManagedProcess>()
        val launcher = ProcessLauncher { _, _ ->
            FakeManagedProcess().also { processes += it }
        }
        val fatalLatch = CountDownLatch(1)
        val fatalErrors = CopyOnWriteArrayList<IOException>()
        val pool = GuardedProcessPool(
            launcher = launcher,
            minRestartWindowMs = 250,
            onFatal = {
                fatalErrors += it
                fatalLatch.countDown()
            },
            restartBackoffBaseMs = 5,
            restartBackoffCapMs = 5,
            permanentExitThreshold = 3,
        )

        pool.start(listOf("fake", "command"), permanentExitCodes = setOf(1))
        for (index in 0 until 3) {
            assertEventually("expected spawn #${index + 1}") { processes.size == index + 1 }
            processes[index].finish(1)
        }

        assertTrue("expected fatal callback", fatalLatch.await(2, TimeUnit.SECONDS))
        Thread.sleep(50)
        assertEquals("no restart after the configuration error", 3, processes.size)
        assertTrue(fatalErrors.single().message!!.contains("exit code: 1"))

        pool.close()
    }

    @Test
    fun `a crash code outside the permanent set never gives up`() {
        val processes = CopyOnWriteArrayList<FakeManagedProcess>()
        val launcher = ProcessLauncher { _, _ ->
            FakeManagedProcess().also { processes += it }
        }
        val fatalErrors = CopyOnWriteArrayList<IOException>()
        val pool = GuardedProcessPool(
            launcher = launcher,
            minRestartWindowMs = 250,
            onFatal = { fatalErrors += it },
            restartBackoffBaseMs = 5,
            restartBackoffCapMs = 5,
            permanentExitThreshold = 3,
        )

        // Go panics exit with status 2: a crash, not a configuration error.
        pool.start(listOf("fake", "command"), permanentExitCodes = setOf(1))
        for (index in 0 until 4) {
            assertEventually("expected spawn #${index + 1}") { processes.size == index + 1 }
            processes[index].finish(2)
        }

        assertEventually("expected another restart") { processes.size == 5 }
        assertTrue(fatalErrors.isEmpty())
        pool.close()
    }

    @Test
    fun `close during restart backoff stops the guard`() {
        val processes = CopyOnWriteArrayList<FakeManagedProcess>()
        val launcher = ProcessLauncher { _, _ ->
            FakeManagedProcess().also { processes += it }
        }
        val fastExit = CountDownLatch(1)
        val pool = GuardedProcessPool(
            launcher = launcher,
            minRestartWindowMs = 250,
            restartBackoffBaseMs = 60_000,
            restartBackoffCapMs = 60_000,
            onFastExit = { fastExit.countDown() },
        )

        pool.start(listOf("fake", "command"))
        processes[0].finish(9)
        assertTrue(fastExit.await(2, TimeUnit.SECONDS))
        pool.close()
        Thread.sleep(100)

        assertEquals(1, processes.size)
    }

    @Test
    fun `an alive process is never restarted whatever it reports`() {
        val processes = CopyOnWriteArrayList<FakeManagedProcess>()
        val launcher = ProcessLauncher { _, _ ->
            FakeManagedProcess(holdMs = 10_000).also { processes += it }
        }
        val fatalErrors = CopyOnWriteArrayList<IOException>()
        val pool = GuardedProcessPool(
            launcher = launcher,
            minRestartWindowMs = 25,
            onFatal = { fatalErrors += it },
        )

        pool.start(listOf("ultra-client", "--config", "forward.json"))
        // An Ultra runtime waiting for policy stays alive with its listeners bound; the guard
        // only reacts to exits, so it must not touch it.
        Thread.sleep(150)

        assertEquals(1, processes.size)
        assertTrue(processes.single().isAlive())
        assertTrue(fatalErrors.isEmpty())
        pool.close()
    }

    private fun assertEventually(
        message: String,
        timeoutMs: Long = 2_000,
        condition: () -> Boolean,
    ) {
        val deadline = System.currentTimeMillis() + timeoutMs
        while (System.currentTimeMillis() < deadline) {
            if (condition()) {
                return
            }
            Thread.sleep(10)
        }
        throw AssertionError(message)
    }
}

private class FakeManagedProcess(private val holdMs: Long = 2_000) : ManagedProcess {
    private val finished = CountDownLatch(1)
    @Volatile private var exitCode: Int = 0
    @Volatile private var alive = true

    override val inputStream: InputStream = ByteArrayInputStream(ByteArray(0))
    override val errorStream: InputStream = ByteArrayInputStream(ByteArray(0))

    override fun waitFor(): Int {
        finished.await(holdMs, TimeUnit.MILLISECONDS)
        return exitCode
    }

    override fun destroy() {
        alive = false
        finished.countDown()
    }

    override fun destroyForcibly() {
        destroy()
    }

    override fun isAlive(): Boolean = alive

    fun finish(code: Int) {
        exitCode = code
        alive = false
        finished.countDown()
    }
}
