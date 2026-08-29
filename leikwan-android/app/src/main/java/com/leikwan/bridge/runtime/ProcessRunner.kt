package com.leikwan.bridge.runtime

import android.util.Log
import java.io.IOException
import java.io.File
import java.net.InetSocketAddress
import java.net.Socket
import java.util.concurrent.ConcurrentHashMap
import java.util.concurrent.atomic.AtomicReference

private const val TAG = "ProcessRunner"

private object ProcessRunnerRegistry {
    val lock = Any()
    val pools = ConcurrentHashMap<Long, GuardedProcessPool>()
    val privateRuntimePaths = ConcurrentHashMap<Long, List<String>>()
}

open class ProcessRunner {
    open fun start(
        forwardId: Long,
        runtimeContext: RuntimeContext,
        cleanupStale: Boolean = true,
    ) = synchronized(ProcessRunnerRegistry.lock) {
        ProcessRunnerRegistry.pools.remove(forwardId)?.close()
        cleanupPrivateRuntimePaths(ProcessRunnerRegistry.privateRuntimePaths.remove(forwardId).orEmpty())
        if (cleanupStale) {
            AndroidRuntimeJanitor.cleanupStaleRuntimeProcesses()
        }
        val fatalError = AtomicReference<IOException?>(null)
        val pool = GuardedProcessPool(
            onFatal = { error -> fatalError.compareAndSet(null, error) },
            logLine = { line -> Log.d(TAG, "$forwardId $line") },
        )
        try {
            runtimeContext.processPlans.forEach { plan ->
                pool.start(plan.command)
                plan.readyPort?.let { awaitLocalPort(it) }
                fatalError.get()?.let { throw it }
            }
            ProcessRunnerRegistry.pools[forwardId] = pool
            if (runtimeContext.privateRuntimePaths.isNotEmpty()) {
                ProcessRunnerRegistry.privateRuntimePaths[forwardId] = runtimeContext.privateRuntimePaths
            }
        } catch (error: Exception) {
            pool.close()
            cleanupPrivateRuntimePaths(runtimeContext.privateRuntimePaths)
            throw error
        }
    }

    open fun stopForward(forwardId: Long) = synchronized(ProcessRunnerRegistry.lock) {
        ProcessRunnerRegistry.pools.remove(forwardId)?.close()
        cleanupPrivateRuntimePaths(ProcessRunnerRegistry.privateRuntimePaths.remove(forwardId).orEmpty())
    }

    open fun stopAll() = synchronized(ProcessRunnerRegistry.lock) {
        ProcessRunnerRegistry.pools.keys.toList().forEach { forwardId ->
            ProcessRunnerRegistry.pools.remove(forwardId)?.close()
            cleanupPrivateRuntimePaths(ProcessRunnerRegistry.privateRuntimePaths.remove(forwardId).orEmpty())
        }
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
