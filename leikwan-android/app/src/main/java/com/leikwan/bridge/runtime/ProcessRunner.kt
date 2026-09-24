package com.leikwan.bridge.runtime

import android.util.Log
import java.io.IOException
import java.io.File
import java.net.InetSocketAddress
import java.net.Socket
import java.util.concurrent.ConcurrentHashMap
import java.util.concurrent.atomic.AtomicBoolean
import java.util.concurrent.atomic.AtomicReference

private const val TAG = "ProcessRunner"

open class ProcessRunner {
    private val pools = ConcurrentHashMap<Long, GuardedProcessPool>()
    private val privateRuntimePaths = ConcurrentHashMap<Long, List<String>>()

    /**
     * Receives every runtime output line (stdout and the redirected stderr) with its process key,
     * on the pool's log reader thread. Must be quick and must not block; exceptions are contained.
     */
    @Volatile
    var runtimeLineObserver: ((Long, String) -> Unit)? = null

    /** Delivers one runtime output line to [runtimeLineObserver]. */
    fun publishRuntimeLine(forwardId: Long, line: String) {
        val observer = runtimeLineObserver ?: return
        try {
            observer(forwardId, line)
        } catch (error: Exception) {
            Log.w(TAG, "$forwardId runtime line observer failed", error)
        }
    }

    open fun start(
        forwardId: Long,
        runtimeContext: RuntimeContext,
        cleanupStale: Boolean = true,
    ) {
        stopForward(forwardId)
        if (cleanupStale) {
            AndroidRuntimeJanitor.cleanupStaleRuntimeProcesses()
        }
        val fatalError = AtomicReference<IOException?>(null)
        val starting = AtomicBoolean(true)
        val pool = GuardedProcessPool(
            onFatal = { error ->
                fatalError.compareAndSet(null, error)
                if (!starting.get()) Log.w(TAG, "$forwardId runtime process gave up", error)
            },
            logLine = { line ->
                Log.d(TAG, "$forwardId $line")
                publishRuntimeLine(forwardId, line)
            },
            // A crash during startup still fails the start (as before). Once the forward is up,
            // fast exits only trigger the pool's bounded restart backoff.
            onFastExit = { error -> if (starting.get()) fatalError.compareAndSet(null, error) },
        )
        try {
            runtimeContext.processPlans.forEach { plan ->
                pool.start(plan.command, permanentExitCodes = plan.permanentExitCodes)
                plan.readyPort?.let { awaitLocalPort(it) }
                fatalError.get()?.let { throw it }
            }
            starting.set(false)
            pools[forwardId] = pool
            if (runtimeContext.privateRuntimePaths.isNotEmpty()) {
                privateRuntimePaths[forwardId] = runtimeContext.privateRuntimePaths
            }
        } catch (error: Exception) {
            pool.close()
            cleanupPrivateRuntimePaths(runtimeContext.privateRuntimePaths)
            throw error
        }
    }

    open fun stopForward(forwardId: Long) {
        pools.remove(forwardId)?.close()
        cleanupPrivateRuntimePaths(privateRuntimePaths.remove(forwardId).orEmpty())
    }

    open fun stopAll() {
        pools.keys.toList().forEach { stopForward(it) }
    }

    open fun awaitLocalPort(port: Int, timeoutMs: Long = 15_000) {
        val deadline = System.currentTimeMillis() + timeoutMs
        while (System.currentTimeMillis() < deadline) {
            runCatching {
                Socket().use { socket ->
                    socket.connect(InetSocketAddress("127.0.0.1", port), 250)
                }
            }.onSuccess {
                return
            }
            Thread.sleep(200)
        }
        throw IllegalStateException("Timed out waiting for local runtime port $port")
    }

    private fun cleanupPrivateRuntimePaths(paths: List<String>) {
        paths.sortedByDescending { it.length }.forEach { path ->
            runCatching { File(path).delete() }
        }
    }
}
