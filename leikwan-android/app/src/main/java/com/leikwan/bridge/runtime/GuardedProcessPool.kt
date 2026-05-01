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

class GuardedProcessPool(
    private val launcher: ProcessLauncher = DefaultProcessLauncher,
    private val minRestartWindowMs: Long = 1_000,
    private val onFatal: (IOException) -> Unit = {},
    private val logLine: (String) -> Unit = {},
    private val nowMs: () -> Long = { System.currentTimeMillis() },
) {
    private val guards = mutableListOf<Guard>()
    @Volatile private var closed = false

    fun start(
        command: List<String>,
        environment: Map<String, String> = emptyMap(),
    ) {
        if (closed) {
            throw IllegalStateException("GuardedProcessPool is already closed")
        }
        val guard = Guard(command, environment)
        synchronized(guards) {
            guards += guard
        }
        guard.start()
    }

    fun close() {
        closed = true
        val snapshot = synchronized(guards) {
            guards.toList().also { guards.clear() }
        }
        snapshot.forEach { it.close() }
    }

    private inner class Guard(
        private val command: List<String>,
        private val environment: Map<String, String>,
    ) {
        private val commandName = File(command.first()).nameWithoutExtension.ifBlank { command.first() }
        @Volatile private var process: ManagedProcess? = null

        fun start() {
            try {
                spawn()
            } catch (e: IOException) {
                onFatal(e)
            }
        }

        private fun spawn() {
            if (closed) return
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
            val exitCode = try {
                managed.waitFor()
            } catch (_: Exception) {
                return
            }
            if (closed || process !== managed) {
                return
            }

            val uptimeMs = nowMs() - startedAt
            if (uptimeMs < minRestartWindowMs) {
                onFatal(IOException("$commandName exits too fast (exit code: $exitCode)"))
                return
            }

            logLine("restart process: ${command.joinToString(" ")} (last exit code: $exitCode)")
            try {
                spawn()
            } catch (e: IOException) {
                onFatal(e)
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
            val current = process ?: return
            process = null
            runCatching { current.destroy() }
            if (current.isAlive()) {
                runCatching { current.destroyForcibly() }
            }
        }
    }
}
