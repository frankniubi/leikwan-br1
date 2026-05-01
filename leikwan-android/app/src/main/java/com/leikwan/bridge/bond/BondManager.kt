package com.leikwan.bridge.bond

import android.content.Context
import android.util.Log
import com.google.gson.JsonElement

private const val TAG = "BondManager"

data class BondParams(
    val chunkSize: Long = 131072,
    val maxWindowBytes: Long = 16777216,
    val retransmitMs: Long = 300,
    val connectTimeoutMs: Long = 3000,
    val activeLegLimit: Long = 0,
    val interactiveChunkBytes: Long = 16384,
    val latencyGuardBytes: Long = 262144,
    val interactiveRttSlackMs: Long = 30,
)

class BondManager(private val context: Context) {
    private val processes = mutableMapOf<Long, Process>()

    private fun bondBinaryPath(): String {
        return "${context.applicationInfo.nativeLibraryDir}/libleikwan_bond.so"
    }

    fun startBond(
        forwardId: Long,
        protocol: String,
        listen: String,
        legs: List<String>,
        sessionToken: String,
        params: BondParams = BondParams(),
    ) {
        if (processes.containsKey(forwardId)) {
            Log.w(TAG, "Bond already running for forward $forwardId")
            return
        }

        val cmd = mutableListOf(
            bondBinaryPath(), "client",
            "--protocol", protocol,
            "--listen", listen,
            "--legs", legs.joinToString(","),
            "--session-token", sessionToken,
            "--chunk-size", params.chunkSize.toString(),
            "--max-window-bytes", params.maxWindowBytes.toString(),
            "--retransmit-ms", params.retransmitMs.toString(),
            "--connect-timeout-ms", params.connectTimeoutMs.toString(),
            "--interactive-chunk-bytes", params.interactiveChunkBytes.toString(),
            "--latency-guard-bytes", params.latencyGuardBytes.toString(),
            "--interactive-rtt-slack-ms", params.interactiveRttSlackMs.toString(),
        )
        if (params.activeLegLimit > 0) {
            cmd += listOf("--active-leg-limit", params.activeLegLimit.toString())
        }

        val pb = ProcessBuilder(cmd).redirectErrorStream(true)
        val process = pb.start()
        processes[forwardId] = process
        Log.i(TAG, "Bond started for forward $forwardId, listen=$listen, legs=${legs.size}")

        Thread {
            process.inputStream.bufferedReader().use { reader ->
                reader.forEachLine { line -> Log.d(TAG, "bond[$forwardId]: $line") }
            }
        }.start()
    }

    fun stopBond(forwardId: Long) {
        processes.remove(forwardId)?.let { proc ->
            proc.destroy()
            proc.waitFor()
            Log.i(TAG, "Bond stopped for forward $forwardId")
        }
    }

    fun stopAll() {
        processes.forEach { (id, proc) ->
            proc.destroy()
            proc.waitFor()
            Log.i(TAG, "Bond stopped for forward $id")
        }
        processes.clear()
    }

    fun isRunning(forwardId: Long): Boolean = processes.containsKey(forwardId)

    companion object {
        fun parseBondParams(bondingConfig: JsonElement?): BondParams {
            val obj = bondingConfig?.takeIf { it.isJsonObject }?.asJsonObject ?: return BondParams()
            return BondParams(
                chunkSize = obj.get("chunk_size")?.asLong ?: 131072,
                maxWindowBytes = obj.get("max_window_bytes")?.asLong ?: 16777216,
                retransmitMs = obj.get("retransmit_ms")?.asLong ?: 300,
                connectTimeoutMs = obj.get("connect_timeout_ms")?.asLong ?: 3000,
                activeLegLimit = obj.get("active_leg_limit")?.asLong ?: 0,
                interactiveChunkBytes = obj.get("interactive_chunk_bytes")?.asLong ?: 16384,
                latencyGuardBytes = obj.get("latency_guard_bytes")?.asLong ?: 262144,
                interactiveRttSlackMs = obj.get("interactive_rtt_slack_ms")?.asLong ?: 30,
            )
        }
    }
}
