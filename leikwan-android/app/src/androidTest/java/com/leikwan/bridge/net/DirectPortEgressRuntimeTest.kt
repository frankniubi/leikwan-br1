package com.leikwan.bridge.net

import android.net.LocalSocket
import android.net.LocalSocketAddress
import android.system.Os
import android.system.OsConstants
import androidx.test.ext.junit.runners.AndroidJUnit4
import androidx.test.platform.app.InstrumentationRegistry
import com.leikwan.bridge.runtime.RuntimeBinaryInstaller
import java.io.File
import java.io.IOException
import java.util.concurrent.TimeUnit
import java.util.concurrent.atomic.AtomicInteger
import org.junit.Assert.*
import org.junit.Test
import org.junit.runner.RunWith

/** Safe CI whitelist: real kernel descriptors and physical Network, no account, panel or provider. */
@RunWith(AndroidJUnit4::class)
class DirectPortEgressRuntimeTest {
    private val context get() = InstrumentationRegistry.getInstrumentation().targetContext

    private fun exchange(path: File, version: Int = 1, descriptor: java.io.FileDescriptor? = null): Int {
        LocalSocket().use { socket ->
            socket.connect(LocalSocketAddress(path.absolutePath, LocalSocketAddress.Namespace.FILESYSTEM))
            socket.soTimeout = 3_000
            if (descriptor != null) socket.setFileDescriptorsForSend(arrayOf(descriptor))
            socket.outputStream.write(version)
            socket.outputStream.flush()
            return socket.inputStream.read()
        }
    }

    @Test fun ancillaryDescriptorIsActuallyBoundAndCopiesDoNotLeak() {
        DirectPortNetwork.start(context, PortNetworkChoice.AUTO)
        val path = File(context.filesDir, "test-eg.sock")
        val calls = AtomicInteger()
        val broker = EgressSocketBroker(path) { fd ->
            DirectPortNetwork.bindDescriptor(fd)
            calls.incrementAndGet()
        }
        try {
            DirectPortNetwork.requireAvailable()
            broker.start()
            val fd = Os.socket(OsConstants.AF_INET, OsConstants.SOCK_STREAM, 0)
            try {
                val before = File("/proc/self/fd").list()?.size ?: error("fd inventory unavailable")
                repeat(32) { assertEquals(0, exchange(path, descriptor = fd)) }
                assertEquals(32, calls.get())
                // The receiver must close its duplicates, never the sender's descriptor.
                assertNotNull(Os.fstat(fd))
                val after = File("/proc/self/fd").list()?.size ?: error("fd inventory unavailable")
                assertTrue("received descriptor copies leaked: $before -> $after", after <= before + 4)
            } finally { Os.close(fd) }
        } finally { broker.close(); DirectPortNetwork.stop() }
        assertFalse(path.exists())
    }

    @Test fun invalidRequestsAndBindRefusalAreNotAcknowledged() {
        val path = File(context.filesDir, "test-eg.sock")
        val broker = EgressSocketBroker(path) { throw IOException("fixture_binding_refused") }
        val fd = Os.socket(OsConstants.AF_INET, OsConstants.SOCK_DGRAM, 0)
        try {
            broker.start()
            assertEquals(1, exchange(path)) // No ancillary descriptor.
            assertEquals(1, exchange(path, 9, fd))
            assertEquals(1, exchange(path, descriptor = fd))
            assertNotNull(Os.fstat(fd))
        } finally { Os.close(fd); broker.close() }
    }

    @Test fun staleNetworkGenerationCannotBindAReceivedDescriptor() {
        DirectPortNetwork.start(context, PortNetworkChoice.AUTO)
        val generation = DirectPortNetwork.generation
        DirectPortNetwork.stop()
        val path = File(context.filesDir, "test-eg.sock")
        val broker = EgressSocketBroker(path) { DirectPortNetwork.bindDescriptor(it, generation) }
        val fd = Os.socket(OsConstants.AF_INET, OsConstants.SOCK_DGRAM, 0)
        try { broker.start(); assertEquals(1, exchange(path, descriptor = fd)) }
        finally { Os.close(fd); broker.close() }
    }

    @Test fun packagedGoCoreCapabilityAndActualPreconnectFdBrokerRoundtrip() {
        val binary = requireNotNull(RuntimeBinaryInstaller(context).prepareBinaryPaths().ultraBinary)
        fun run(vararg args: String): Pair<Int, String> {
            val process = ProcessBuilder(listOf(binary) + args).redirectErrorStream(true).start()
            try {
                assertTrue("packaged Go diagnostic timed out", process.waitFor(5, TimeUnit.SECONDS))
                return process.exitValue() to process.inputStream.bufferedReader().readText().trim()
            } finally { process.destroy(); if (process.isAlive) process.destroyForcibly() }
        }
        assertEquals(0 to "direct-egress-v1", run("--check-direct-egress"))
        DirectPortNetwork.start(context, PortNetworkChoice.AUTO)
        val path = File(context.filesDir, "test-eg.sock")
        val bound = AtomicInteger()
        val broker = EgressSocketBroker(path) { fd -> DirectPortNetwork.bindDescriptor(fd); bound.incrementAndGet() }
        try {
            broker.start()
            assertEquals(0 to "direct-egress-socket-bound", run("--test-direct-egress", path.absolutePath))
            assertTrue("Go did not send an actual pre-connect socket", bound.get() > 0)
        } finally { broker.close(); DirectPortNetwork.stop() }
        val rejecting = EgressSocketBroker(path) { throw IOException("fixture_bind_denied") }
        try {
            rejecting.start()
            assertNotEquals(0, run("--test-direct-egress", path.absolutePath).first)
        } finally { rejecting.close() }
    }
}
