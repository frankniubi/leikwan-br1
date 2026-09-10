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
            ProcessBuilder("sh", "-c", "ps -A").redirectErrorStream(true).start()
                .inputStream.bufferedReader().readText()
        }.getOrNull() ?: return

        parseRuntimePids(psOutput, currentPid).forEach { pid ->
            runCatching {
                ProcessBuilder("sh", "-c", "kill -9 $pid")
                    .redirectErrorStream(true)
                    .start()
                    .waitFor()
            }
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
