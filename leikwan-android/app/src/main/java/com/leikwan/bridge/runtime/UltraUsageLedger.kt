package com.leikwan.bridge.runtime

import com.google.gson.JsonArray
import com.google.gson.JsonObject
import com.google.gson.JsonParser
import com.google.gson.annotations.SerializedName
import java.io.File

/** One per-forward Ultra usage delta, as the panel heartbeat expects it (`ultra_usage[]`). */
data class UltraUsageRow(
    @SerializedName("forward_id") val forwardId: Long,
    @SerializedName("policy_generation") val policyGeneration: String,
    @SerializedName("sequence") val sequence: Long,
    @SerializedName("bytes_in") val bytesIn: Long,
    @SerializedName("bytes_out") val bytesOut: Long,
)

/**
 * Turns the Ultra runtime's cumulative byte counters (`bytes_in` / `bytes_out` in each
 * `<forward>.status.json`) into acknowledged per-forward usage deltas for the heartbeat,
 * mirroring the desktop HeartbeatService:
 *
 * * a row is a delta since the last reported counter of that runtime instance (status file);
 *   a counter that went backwards means the runtime restarted, so it counts from zero;
 * * every row gets the next `sequence` of its forward; sequences are persisted, so a restart
 *   never reuses one (the panel deduplicates on client, device, forward and sequence);
 * * at most [MAX_ROWS] rows per heartbeat; observations left out keep their cursor and are
 *   reported next time;
 * * a batch stays pending, and is resent unchanged, until the panel acknowledges it;
 * * the final counters of a runtime are captured before its status file is deleted.
 *
 * State lives in one app-private JSON file next to the runtime directory. It holds counters
 * only, never lease material.
 */
class UltraUsageLedger(
    private val statusDir: File,
    private val stateFile: File,
) {
    /** Rows to send with the next heartbeat; the pending batch when one is unacknowledged. */
    fun collect(): List<UltraUsageRow> = synchronized(lock) {
        val state = load()
        if (state.pending.isNotEmpty()) return@synchronized state.pending.toList()

        val observations = mutableListOf<Observation>()
        val files = statusFiles()
        files.forEach { file ->
            // A file caught mid-write is skipped this round; its cursor is kept below.
            val snapshot = readSnapshot(file) ?: return@forEach
            val cursor = state.cursors[file.name]
            var previousIn = cursor?.first ?: 0L
            var previousOut = cursor?.second ?: 0L
            // Counters restart at zero with a new runtime process.
            if (snapshot.bytesIn < previousIn) previousIn = 0L
            if (snapshot.bytesOut < previousOut) previousOut = 0L
            observations += Observation(
                key = file.name,
                forwardId = snapshot.forwardId,
                generation = snapshot.generation,
                currentIn = snapshot.bytesIn,
                currentOut = snapshot.bytesOut,
                previousIn = previousIn,
                previousOut = previousOut,
            )
        }
        // Cursors of runtimes that vanished without a final capture can never be used again.
        state.cursors.keys.retainAll(files.map { it.name }.toSet())
        state.carry.forEach { carry ->
            observations += Observation(
                key = null,
                forwardId = carry.forwardId,
                generation = carry.generation,
                currentIn = carry.bytesIn,
                currentOut = carry.bytesOut,
                previousIn = 0L,
                previousOut = 0L,
            )
        }

        observations.filter { it.deltaIn == 0L && it.deltaOut == 0L && it.key != null }.forEach {
            state.cursors[it.key!!] = it.currentIn to it.currentOut
        }
        val groups = observations
            .filter { it.deltaIn > 0L || it.deltaOut > 0L }
            .groupBy { it.forwardId to it.generation }
            .entries
            .sortedWith(compareBy({ it.key.first }, { it.key.second }))
            .take(MAX_ROWS)

        val rows = mutableListOf<UltraUsageRow>()
        val remainingCarry = state.carry.associateBy { it.forwardId to it.generation }.toMutableMap()
        groups.forEach { (key, members) ->
            var bytesIn = 0L
            var bytesOut = 0L
            members.forEach { observation ->
                val consumedIn = minOf(observation.deltaIn, MAX_ROW_BYTES - bytesIn)
                val consumedOut = minOf(observation.deltaOut, MAX_ROW_BYTES - bytesOut)
                bytesIn += consumedIn
                bytesOut += consumedOut
                if (observation.key != null) {
                    state.cursors[observation.key] =
                        (observation.previousIn + consumedIn) to (observation.previousOut + consumedOut)
                } else {
                    val left = Carry(
                        key.first,
                        key.second,
                        observation.currentIn - consumedIn,
                        observation.currentOut - consumedOut,
                    )
                    if (left.bytesIn > 0L || left.bytesOut > 0L) remainingCarry[key] = left else remainingCarry.remove(key)
                }
            }
            val sequence = (state.sequences[key.first] ?: 0L) + 1L
            state.sequences[key.first] = sequence
            rows += UltraUsageRow(key.first, key.second, sequence, bytesIn, bytesOut)
        }
        state.carry.clear()
        state.carry += remainingCarry.values
        state.pending.clear()
        state.pending += rows
        // Persist before sending: a crash after the send resends the same sequences.
        save(state)
        rows
    }

    /** Settles [rows] after a heartbeat answered; an unacknowledged batch stays pending. */
    fun acknowledge(rows: List<UltraUsageRow>, acknowledged: Boolean) = synchronized(lock) {
        if (!acknowledged || rows.isEmpty()) return@synchronized
        val state = load()
        if (state.pending == rows) {
            state.pending.clear()
            save(state)
        }
    }

    /**
     * Records the last counters of a runtime instance whose status file is about to be
     * deleted, so the bytes it moved since the last heartbeat are still reported.
     */
    fun captureFinal(statusFile: File) = synchronized(lock) {
        val state = load()
        val snapshot = readSnapshot(statusFile)
        val cursor = state.cursors.remove(statusFile.name)
        if (snapshot != null) {
            var previousIn = cursor?.first ?: 0L
            var previousOut = cursor?.second ?: 0L
            if (snapshot.bytesIn < previousIn) previousIn = 0L
            if (snapshot.bytesOut < previousOut) previousOut = 0L
            val deltaIn = snapshot.bytesIn - previousIn
            val deltaOut = snapshot.bytesOut - previousOut
            if (deltaIn > 0L || deltaOut > 0L) {
                val index = state.carry.indexOfFirst {
                    it.forwardId == snapshot.forwardId && it.generation == snapshot.generation
                }
                if (index >= 0) {
                    val existing = state.carry[index]
                    state.carry[index] = existing.copy(
                        bytesIn = existing.bytesIn + deltaIn,
                        bytesOut = existing.bytesOut + deltaOut,
                    )
                } else {
                    state.carry += Carry(snapshot.forwardId, snapshot.generation, deltaIn, deltaOut)
                }
            }
        }
        if (snapshot != null || cursor != null) save(state)
    }

    private fun statusFiles(): List<File> = statusDir
        .listFiles { file -> file.isFile && file.name.endsWith(STATUS_SUFFIX) }
        .orEmpty()
        .sortedBy { it.name }

    private fun readSnapshot(file: File): Snapshot? = runCatching {
        if (!file.isFile || file.length() !in 1..MAX_STATUS_BYTES) return null
        val root = JsonParser.parseString(file.readText(Charsets.UTF_8)).asJsonObject
        val forwardId = root.get("forward_id")?.asLong ?: return null
        val generation = root.get("policy_generation")?.asString?.trim()?.lowercase() ?: return null
        if (forwardId <= 0L || !generation.matches(GENERATION)) return null
        Snapshot(
            forwardId = forwardId,
            generation = generation,
            bytesIn = (root.get("bytes_in")?.takeIf { it.isJsonPrimitive }?.asLong ?: 0L).coerceAtLeast(0L),
            bytesOut = (root.get("bytes_out")?.takeIf { it.isJsonPrimitive }?.asLong ?: 0L).coerceAtLeast(0L),
        )
    }.getOrNull()

    private fun load(): State {
        val state = State()
        val root = runCatching {
            if (stateFile.isFile) JsonParser.parseString(stateFile.readText(Charsets.UTF_8)).asJsonObject else null
        }.getOrNull() ?: return state
        runCatching {
            root.getAsJsonObject("cursors")?.entrySet()?.forEach { (name, value) ->
                val cursor = value.asJsonObject
                state.cursors[name] = cursor.get("in").asLong to cursor.get("out").asLong
            }
            root.getAsJsonObject("sequences")?.entrySet()?.forEach { (forward, value) ->
                state.sequences[forward.toLong()] = value.asLong
            }
            root.getAsJsonArray("carry")?.forEach { item ->
                val carry = item.asJsonObject
                state.carry += Carry(
                    carry.get("forward_id").asLong,
                    carry.get("policy_generation").asString,
                    carry.get("bytes_in").asLong,
                    carry.get("bytes_out").asLong,
                )
            }
            root.getAsJsonArray("pending")?.forEach { item ->
                val row = item.asJsonObject
                state.pending += UltraUsageRow(
                    row.get("forward_id").asLong,
                    row.get("policy_generation").asString,
                    row.get("sequence").asLong,
                    row.get("bytes_in").asLong,
                    row.get("bytes_out").asLong,
                )
            }
        }
        return state
    }

    private fun save(state: State) {
        val root = JsonObject().apply {
            addProperty("v", 1)
            add("cursors", JsonObject().also { cursors ->
                state.cursors.forEach { (name, value) ->
                    cursors.add(name, JsonObject().apply {
                        addProperty("in", value.first)
                        addProperty("out", value.second)
                    })
                }
            })
            add("sequences", JsonObject().also { sequences ->
                state.sequences.forEach { (forward, value) -> sequences.addProperty(forward.toString(), value) }
            })
            add("carry", JsonArray().also { array ->
                state.carry.forEach { carry ->
                    array.add(JsonObject().apply {
                        addProperty("forward_id", carry.forwardId)
                        addProperty("policy_generation", carry.generation)
                        addProperty("bytes_in", carry.bytesIn)
                        addProperty("bytes_out", carry.bytesOut)
                    })
                }
            })
            add("pending", JsonArray().also { array ->
                state.pending.forEach { row ->
                    array.add(JsonObject().apply {
                        addProperty("forward_id", row.forwardId)
                        addProperty("policy_generation", row.policyGeneration)
                        addProperty("sequence", row.sequence)
                        addProperty("bytes_in", row.bytesIn)
                        addProperty("bytes_out", row.bytesOut)
                    })
                }
            })
        }
        stateFile.parentFile?.mkdirs()
        val temp = File(stateFile.parentFile, stateFile.name + ".tmp")
        temp.writeText(root.toString(), Charsets.UTF_8)
        if (!temp.renameTo(stateFile)) {
            stateFile.delete()
            if (!temp.renameTo(stateFile)) error("ultra_usage_state_write_failed")
        }
    }

    private class State {
        val cursors = mutableMapOf<String, Pair<Long, Long>>()
        val sequences = mutableMapOf<Long, Long>()
        val carry = mutableListOf<Carry>()
        val pending = mutableListOf<UltraUsageRow>()
    }

    private data class Carry(val forwardId: Long, val generation: String, val bytesIn: Long, val bytesOut: Long)

    private data class Snapshot(val forwardId: Long, val generation: String, val bytesIn: Long, val bytesOut: Long)

    private class Observation(
        val key: String?,
        val forwardId: Long,
        val generation: String,
        val currentIn: Long,
        val currentOut: Long,
        val previousIn: Long,
        val previousOut: Long,
    ) {
        val deltaIn: Long get() = currentIn - previousIn
        val deltaOut: Long get() = currentOut - previousOut
    }

    companion object {
        /** The panel reads at most this many rows per heartbeat. */
        const val MAX_ROWS = 32
        /** UltraMode::MAX_QUOTA_LEASE_BYTES: a larger row would be silently dropped. */
        const val MAX_ROW_BYTES = 107_374_182_400L
        private const val MAX_STATUS_BYTES = 64L * 1024L
        private const val STATUS_SUFFIX = ".status.json"
        private const val STATE_FILE = "ultra-usage-state.json"
        private const val RUNTIME_DIR = "ultra"
        private val GENERATION = Regex("^[a-f0-9]{64}$")
        private val lock = Any()

        /** The ledger for an app's files directory (runtime status files live in `ultra/`). */
        fun forFilesDir(filesDir: File): UltraUsageLedger =
            UltraUsageLedger(File(filesDir, RUNTIME_DIR), File(filesDir, STATE_FILE))

        /** Captures [file]'s final counters when it is an Ultra runtime status file. */
        fun captureBeforeDelete(file: File) {
            if (!file.name.endsWith(STATUS_SUFFIX) || file.parentFile?.name != RUNTIME_DIR) return
            val filesDir = file.parentFile?.parentFile ?: return
            runCatching { forFilesDir(filesDir).captureFinal(file) }
        }
    }
}
