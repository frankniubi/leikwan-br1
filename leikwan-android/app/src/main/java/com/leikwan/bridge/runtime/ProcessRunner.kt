package com.leikwan.bridge.runtime

import android.util.Log
import java.io.IOException
import java.net.InetSocketAddress
import java.net.Socket
import java.util.concurrent.ConcurrentHashMap
import java.util.concurrent.atomic.AtomicReference

private const val TAG = "ProcessRunner"

open class ProcessRunner {
    private val pools = ConcurrentHashMap<Long, GuardedProcessPool>()

    open fun start(forwardId: Long, runtimeContext: RuntimeContext) {
        stopForward(forwardId)
        AndroidRuntimeJanitor.cleanupStaleRuntimeProcesses()
        val fatalError = AtomicReference<IOException?>(null)
        val pool = GuardedProcessPool(
            onFatal = { error -> fatalError.compareAndSet(null, error) },
            logLine = { line -> Log.d(TAG, "$forwardId $line") },
        )
        try {
            runtimeContext.processPlans.forEach { plan ->
                pool.start(plan.command)
                plan.readyPort?.let { waitForLocalPort(it) }
                fatalError.get()?.let { throw it }
            }
            pools[forwardId] = pool
        } catch (error: Exception) {
            pool.close()
            throw error
        }
    }

    open fun stopForward(forwardId: Long) {
        pools.remove(forwardId)?.close()
    }

    open fun stopAll() {
        pools.keys.toList().forEach { stopForward(it) }
    }

    private fun waitForLocalPort(port: Int, timeoutMs: Long = 15_000) {
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
}
