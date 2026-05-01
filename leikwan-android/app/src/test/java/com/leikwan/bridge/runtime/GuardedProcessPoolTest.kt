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
    fun `pool reports fatal when process exits too fast`() {
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
        )

        pool.start(listOf("fake", "command"))
        assertEquals(1, processes.size)

        processes[0].finish(9)

        assertTrue("expected fatal callback", fatalLatch.await(2, TimeUnit.SECONDS))
        assertEquals(1, processes.size)
        assertTrue(fatalErrors.single().message!!.contains("exits too fast"))

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

private class FakeManagedProcess : ManagedProcess {
    private val finished = CountDownLatch(1)
    @Volatile private var exitCode: Int = 0
    @Volatile private var alive = true

    override val inputStream: InputStream = ByteArrayInputStream(ByteArray(0))
    override val errorStream: InputStream = ByteArrayInputStream(ByteArray(0))

    override fun waitFor(): Int {
        finished.await(2, TimeUnit.SECONDS)
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
