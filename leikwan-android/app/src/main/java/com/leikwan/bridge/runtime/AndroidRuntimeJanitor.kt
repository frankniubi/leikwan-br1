package com.leikwan.bridge.runtime

import android.os.Process

object AndroidRuntimeJanitor {
    private val runtimeNames = listOf(
        "libleikwan_ingress.so",
        "libleikwan_bond.so",
        "libwstunnel.so",
        "libultra_client.so",
        "leikwan-ingress",
        "leikwan-bond",
        "wstunnel",
        "ultra-client",
    )

    fun cleanupStaleRuntimeProcesses(currentPid: Int = Process.myPid()) {
        val psOutput = runCatching {
            // One child per forward start; leaving it unreaped (and its three pipe fds open)
            // accumulates across the frequent rebuilds a backgrounded tunnel performs.
            runChild("ps -A") { process ->
                process.inputStream.bufferedReader().use { it.readText() }
            }
        }.getOrNull() ?: return

        parseRuntimePids(psOutput, currentPid).forEach { pid ->
            runCatching { runChild("kill -9 $pid") { _ -> } }
        }
    }

    /**
     * Runs a shell command to completion and always closes its streams and reaps the child.
     * Returns null when the command could not be run; [read]'s result otherwise.
     */
    private fun <T> runChild(script: String, read: (java.lang.Process) -> T): T? {
        val process = ProcessBuilder("sh", "-c", script)
            .redirectErrorStream(true)
            .start()
        return try {
            read(process)
        } finally {
            runCatching { process.inputStream.close() }
            runCatching { process.errorStream.close() }
            runCatching { process.outputStream.close() }
            runCatching { process.waitFor() }.onFailure { runCatching { process.destroy() } }
        }
    }

    fun parseRuntimePids(
        psOutput: String,
        currentPid: Int,
    ): List<Int> {
        return psOutput.lineSequence()
            .mapNotNull { line ->
                if (runtimeNames.none { line.contains(it) }) {
                    return@mapNotNull null
                }
                val parts = line.trim().split(Regex("\\s+"))
                val pid = parts.getOrNull(1)?.toIntOrNull() ?: return@mapNotNull null
                pid.takeIf { it != currentPid }
            }
            .distinct()
            .toList()
    }
}
