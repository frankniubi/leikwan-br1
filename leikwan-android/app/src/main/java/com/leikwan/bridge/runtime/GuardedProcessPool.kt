package com.leikwan.bridge.runtime

import java.io.File
import java.io.IOException
import java.io.InputStream
import kotlin.concurrent.thread

fun interface ProcessLauncher {
    fun start(command: List<String>, environment: Map<String, String>): ManagedProcess
}

interface ManagedProcess {
    val inputStream: InputStream
    val errorStream: InputStream

    fun waitFor(): Int
    fun destroy()
    fun destroyForcibly()
    fun isAlive(): Boolean
}

private object DefaultProcessLauncher : ProcessLauncher {
    override fun start(command: List<String>, environment: Map<String, String>): ManagedProcess {
        val process = ProcessBuilder(command)
            .redirectErrorStream(true)
            .apply {
                environment().putAll(environment)
            }
            .start()
        return JavaManagedProcess(process)
    }
}

private class JavaManagedProcess(private val delegate: Process) : ManagedProcess {
    override val inputStream: InputStream
        get() = delegate.inputStream

    override val errorStream: InputStream
        get() = delegate.errorStream

    override fun waitFor(): Int = delegate.waitFor()

    override fun destroy() {
        delegate.destroy()
    }

    override fun destroyForcibly() {
        delegate.destroyForcibly()
    }

    override fun isAlive(): Boolean = delegate.isAlive
}

/**
 * Keeps each started command alive.
 *
 * A process that exits after a healthy run is restarted at once. A process that exits within
 * [minRestartWindowMs] of its start is restarted after a bounded exponential backoff
 * ([restartBackoffBaseMs] doubling up to [restartBackoffCapMs]; reset by the next healthy run),
 * and [onFastExit] is told about it. The guard gives up ([onFatal]) only when the command cannot
 * be spawned on first start, or when it keeps exiting fast with one of its
 * `permanentExitCodes` (a configuration/validation error) [permanentExitThreshold] times in a
 * row. A transient crash burst therefore never leaves a runtime permanently dead.
 *
 * The guard only watches process exits. A process that stays alive is never treated as broken,
 * whatever it reports: an Ultra runtime idle on purpose (`awaiting`/`lapsed` policy) keeps
 * running until its owner injects a lease or stops it.
 */
class GuardedProcessPool(
    private val launcher: ProcessLauncher = DefaultProcessLauncher,
    private val minRestartWindowMs: Long = 1_000,
    private val onFatal: (IOException) -> Unit = {},
    private val logLine: (String) -> Unit = {},
    private val nowMs: () -> Long = { System.currentTimeMillis() },
    private val restartBackoffBaseMs: Long = 1_000,
    private val restartBackoffCapMs: Long = 30_000,
    private val permanentExitThreshold: Int = 3,
    private val onFastExit: (IOException) -> Unit = {},
) {
    private val guards = mutableListOf<Guard>()
    private val closeSignal = Object()
    @Volatile private var closed = false

    fun start(
        command: List<String>,
        environment: Map<String, String> = emptyMap(),
        permanentExitCodes: Set<Int> = emptySet(),
    ) {
        if (closed) {
            throw IllegalStateException("GuardedProcessPool is already closed")
        }
        val guard = Guard(command, environment, permanentExitCodes)
        synchronized(guards) {
            guards += guard
        }
        guard.start()
    }

    fun close() {
        closed = true
        synchronized(closeSignal) { closeSignal.notifyAll() }
        val snapshot = synchronized(guards) {
            guards.toList().also { guards.clear() }
        }
        snapshot.forEach { it.close() }
    }

    /** Delay before the restart that follows the given number of consecutive fast exits (>= 1). */
    internal fun restartDelayMs(consecutiveFastExits: Int): Long {
        var delay = restartBackoffBaseMs.coerceAtLeast(0L)
        repeat((consecutiveFastExits - 1).coerceAtLeast(0)) {
            if (delay >= restartBackoffCapMs) return restartBackoffCapMs
            delay = if (delay > restartBackoffCapMs / 2) restartBackoffCapMs else delay * 2
        }
        return delay.coerceAtMost(restartBackoffCapMs)
    }

    /** Waits up to [delayMs]; false when the pool closed meanwhile. */
    private fun awaitRestartDelay(delayMs: Long): Boolean {
        if (delayMs <= 0L) return !closed
        val deadline = System.nanoTime() + delayMs * 1_000_000L
        synchronized(closeSignal) {
            while (!closed) {
                val remainingMs = (deadline - System.nanoTime()) / 1_000_000L
                if (remainingMs <= 0L) break
                closeSignal.wait(remainingMs)
            }
        }
        return !closed
    }

    private inner class Guard(
        private val command: List<String>,
        private val environment: Map<String, String>,
        private val permanentExitCodes: Set<Int>,
    ) {
        private val commandName = File(command.first()).nameWithoutExtension.ifBlank { command.first() }
        @Volatile private var process: ManagedProcess? = null
        @Volatile private var stopped = false
        private var consecutiveFastExits = 0
        private var consecutivePermanentExits = 0

        fun start() {
            try {
                spawn()
            } catch (e: IOException) {
                onFatal(e)
            }
        }

        private fun spawn() {
            if (closed || stopped) return
            val startedAt = nowMs()
            val managed = launcher.start(command, environment)
            process = managed
            pipeLogs(managed.inputStream)
            pipeLogs(managed.errorStream)
            thread(
                isDaemon = true,
                name = "guard-$commandName",
            ) {
                monitor(managed, startedAt)
            }
        }

        private fun monitor(managed: ManagedProcess, startedAt: Long) {
            var current = managed
            var currentStartedAt = startedAt
            while (true) {
                val exitCode = try {
                    current.waitFor()
                } catch (_: Exception) {
                    return
                }
                if (closed || stopped || process !== current) {
                    return
                }

                val uptimeMs = nowMs() - currentStartedAt
                if (uptimeMs < minRestartWindowMs) {
                    consecutiveFastExits += 1
                    val error = IOException("$commandName exits too fast (exit code: $exitCode)")
                    if (exitCode in permanentExitCodes) {
                        consecutivePermanentExits += 1
                        if (consecutivePermanentExits >= permanentExitThreshold) {
                            logLine("giving up on ${command.joinToString(" ")}: configuration error (exit code: $exitCode)")
                            onFatal(error)
                            return
                        }
                    } else {
                        consecutivePermanentExits = 0
                    }
                    onFastExit(error)
                    val delayMs = restartDelayMs(consecutiveFastExits)
                    logLine("restart process in ${delayMs}ms: ${command.joinToString(" ")} (fast exit #$consecutiveFastExits, exit code: $exitCode)")
                    if (!awaitRestartDelay(delayMs) || stopped || process !== current) {
                        return
                    }
                } else {
                    consecutiveFastExits = 0
                    consecutivePermanentExits = 0
                    logLine("restart process: ${command.joinToString(" ")} (last exit code: $exitCode)")
                }

                if (closed || stopped) return
                currentStartedAt = nowMs()
                current = try {
                    launcher.start(command, environment)
                } catch (e: IOException) {
                    // The command ran before, so a spawn failure now is treated like a fast
                    // exit: back off and try again instead of giving up for good.
                    consecutiveFastExits += 1
                    onFastExit(e)
                    val delayMs = restartDelayMs(consecutiveFastExits)
                    logLine("restart of ${command.joinToString(" ")} failed; retrying in ${delayMs}ms: ${e.message}")
                    if (!awaitRestartDelay(delayMs)) return
                    continue
                }
                process = current
                if (closed || stopped) {
                    // close() raced the respawn; do not leak the new process.
                    process = null
                    runCatching { current.destroy() }
                    if (current.isAlive()) runCatching { current.destroyForcibly() }
                    return
                }
                pipeLogs(current.inputStream)
                pipeLogs(current.errorStream)
            }
        }

        private fun pipeLogs(stream: InputStream) {
            thread(
                isDaemon = true,
                name = "guard-log-$commandName",
            ) {
                runCatching {
                    stream.bufferedReader().useLines { lines ->
                        lines.forEach { line -> logLine("[$commandName] $line") }
                    }
                }
            }
        }

        fun close() {
            stopped = true
            val current = process ?: return
            process = null
            runCatching { current.destroy() }
            if (current.isAlive()) {
                runCatching { current.destroyForcibly() }
            }
        }
    }
}
