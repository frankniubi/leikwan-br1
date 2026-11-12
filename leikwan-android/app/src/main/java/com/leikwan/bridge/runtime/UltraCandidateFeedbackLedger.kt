package com.leikwan.bridge.runtime

import com.google.gson.JsonArray
import com.google.gson.JsonElement
import com.google.gson.JsonObject
import com.google.gson.JsonParser
import com.google.gson.annotations.SerializedName
import java.io.File
import java.time.Instant
import java.time.OffsetDateTime

/**
 * One data-plane outcome about a leased candidate, as the panel heartbeat expects it
 * (`ultra_candidate_feedback[]`, `UltraClientStatusService::ingestFeedback`). The candidate and
 * lease are opaque hashes the panel issued; no endpoint or line id is ever reported.
 */
data class UltraCandidateFeedbackReport(
    @SerializedName("forward_id") val forwardId: Long,
    @SerializedName("candidate_key_hash") val candidateKeyHash: String,
    @SerializedName("lease_hash") val leaseHash: String,
    @SerializedName("protocol") val protocol: String,
    @SerializedName("outcome") val outcome: String,
    @SerializedName("reason") val reason: String,
    @SerializedName("evidence_id") val evidenceId: String,
    @SerializedName("observed_at") val observedAt: String,
)

/**
 * Forwards the runtime's rolling `candidate_feedback` list (in each `<forward>.status.json`) to
 * the panel, like the desktop HeartbeatService and the OpenWrt router:
 *
 * * only fresh observations ([MAX_AGE_SECONDS] old at most, [FUTURE_SKEW_SECONDS] ahead) in the
 *   shape the panel accepts are sent, at most [MAX_ENTRIES] per heartbeat;
 * * an entry is identified by forward and evidence id; once a heartbeat carrying it succeeds its
 *   key joins a persisted acknowledged set (newest [MAX_ACKNOWLEDGED] kept), so the runtime's
 *   rolling list never reports the same evidence twice;
 * * feedback is read even when no bytes move, so setup failures (credential_rejected,
 *   relay_connect_timeout, ...) reach the panel promptly.
 *
 * State is one app-private JSON file holding dedupe keys only, never lease material.
 */
class UltraCandidateFeedbackLedger(
    private val statusDir: File,
    private val stateFile: File,
) {
    fun collect(now: Instant): List<UltraCandidateFeedbackReport> = synchronized(lock) {
        val seen = load().toMutableSet()
        val result = mutableListOf<UltraCandidateFeedbackReport>()
        val files = statusDir
            .listFiles { file -> file.isFile && file.name.endsWith(STATUS_SUFFIX) }
            .orEmpty()
            .sortedBy { it.name }
        for (file in files) {
            if (result.size >= MAX_ENTRIES) break
            val root = readDocument(file) ?: continue
            for (item in feedbackOf(root, now)) {
                if (result.size >= MAX_ENTRIES) break
                if (!seen.add(dedupeKey(item))) continue
                result += item
            }
        }
        result
    }

    /** Records [sent] as delivered after a heartbeat carrying it succeeded. */
    fun acknowledge(sent: List<UltraCandidateFeedbackReport>) = synchronized(lock) {
        if (sent.isEmpty()) return@synchronized
        val keys = LinkedHashSet(load())
        sent.forEach { keys += dedupeKey(it) }
        val ordered = keys.toList()
        save(ordered.drop(maxOf(0, ordered.size - MAX_ACKNOWLEDGED)))
    }

    private fun readDocument(file: File): JsonObject? = runCatching {
        if (file.length() !in 1..MAX_STATUS_FILE_BYTES) return null
        JsonParser.parseString(file.readText(Charsets.UTF_8)).takeIf { it.isJsonObject }?.asJsonObject
    }.getOrNull()

    private fun load(): List<String> = runCatching {
        if (!stateFile.isFile) return emptyList()
        JsonParser.parseString(stateFile.readText(Charsets.UTF_8)).asJsonObject
            .getAsJsonArray("acknowledged")
            ?.mapNotNull { element -> element.takeIf { it.isJsonPrimitive }?.asString?.takeIf { it.isNotEmpty() } }
            .orEmpty()
    }.getOrDefault(emptyList())

    private fun save(keys: List<String>) {
        val root = JsonObject().apply {
            addProperty("v", 1)
            add("acknowledged", JsonArray().also { array -> keys.forEach(array::add) })
        }
        runCatching {
            stateFile.parentFile?.mkdirs()
            val temp = File(stateFile.parentFile, stateFile.name + ".tmp")
            temp.writeText(root.toString(), Charsets.UTF_8)
            if (!temp.renameTo(stateFile)) {
                stateFile.delete()
                temp.renameTo(stateFile)
            }
        }
    }

    companion object {
        /** UltraCandidateReputationService::MAX_FEEDBACK. */
        const val MAX_ENTRIES = 32
        const val MAX_ACKNOWLEDGED = 512
        /** UltraMode::FEEDBACK_MAX_AGE_SECONDS / FEEDBACK_FUTURE_SKEW_SECONDS. */
        const val MAX_AGE_SECONDS = 300L
        const val FUTURE_SKEW_SECONDS = 60L
        private const val MAX_STATUS_FILE_BYTES = 64L * 1024L
        private const val STATUS_SUFFIX = ".status.json"
        private const val RUNTIME_DIR = "ultra"
        private const val STATE_FILE = "ultra-feedback-state.json"
        private val HASH = Regex("^[a-f0-9]{64}$")
        private val EVIDENCE_ID = Regex("^[A-Za-z0-9._:-]{1,128}$")
        private val OBSERVED_AT = Regex("^\\d{4}-\\d{2}-\\d{2}T\\d{2}:\\d{2}:\\d{2}(\\.\\d{1,9})?Z$")
        private val PROTOCOLS = setOf("tcp", "udp", "tcp+udp", "native_socks5")
        private val OUTCOMES = setOf("failure", "success")
        private val lock = Any()

        /** The ledger for an app's files directory (runtime status files live in `ultra/`). */
        fun forFilesDir(filesDir: File): UltraCandidateFeedbackLedger =
            UltraCandidateFeedbackLedger(File(filesDir, RUNTIME_DIR), File(filesDir, STATE_FILE))

        /** The fresh, panel-shaped entries of one status document. */
        fun feedbackOf(root: JsonObject, now: Instant): List<UltraCandidateFeedbackReport> {
            val forwardId = runCatching {
                root.get("forward_id")?.takeIf { it.isJsonPrimitive && it.asJsonPrimitive.isNumber }?.asLong
            }.getOrNull() ?: return emptyList()
            if (forwardId <= 0L) return emptyList()
            val array = root.get("candidate_feedback")?.takeIf { it.isJsonArray }?.asJsonArray ?: return emptyList()
            return array.mapNotNull { element ->
                val item = element.takeIf { it.isJsonObject }?.asJsonObject ?: return@mapNotNull null
                val candidateHash = string(item.get("candidate_key_hash")).lowercase()
                val leaseHash = string(item.get("lease_hash")).lowercase()
                val protocol = string(item.get("protocol")).lowercase()
                val outcome = string(item.get("outcome")).lowercase()
                val reason = UltraStatusCollector.text(string(item.get("reason")), 64).lowercase()
                val evidenceId = string(item.get("evidence_id"))
                val observedAt = string(item.get("observed_at"))
                if (!HASH.matches(candidateHash) || !HASH.matches(leaseHash) ||
                    protocol !in PROTOCOLS || outcome !in OUTCOMES ||
                    reason.isEmpty() || reason == "process_stderr" ||
                    (evidenceId.isNotEmpty() && !EVIDENCE_ID.matches(evidenceId)) ||
                    !isFresh(observedAt, now)
                ) {
                    return@mapNotNull null
                }
                UltraCandidateFeedbackReport(forwardId, candidateHash, leaseHash, protocol, outcome, reason, evidenceId, observedAt)
            }
        }

        /** Forward and evidence id; entries without one fall back to what identifies the observation. */
        fun dedupeKey(item: UltraCandidateFeedbackReport): String =
            if (item.evidenceId.isNotEmpty()) {
                "${item.forwardId}:${item.evidenceId}"
            } else {
                "${item.forwardId}:${item.candidateKeyHash}:${item.reason}:${item.observedAt}"
            }

        /** An absent time is accepted (the panel does too); a malformed or stale one is not. */
        private fun isFresh(observedAt: String, now: Instant): Boolean {
            if (observedAt.isEmpty()) return true
            if (!OBSERVED_AT.matches(observedAt)) return false
            val parsed = runCatching { OffsetDateTime.parse(observedAt).toInstant() }.getOrNull() ?: return false
            return !parsed.isBefore(now.minusSeconds(MAX_AGE_SECONDS)) && !parsed.isAfter(now.plusSeconds(FUTURE_SKEW_SECONDS))
        }

        private fun string(element: JsonElement?): String =
            element?.takeIf { it.isJsonPrimitive && it.asJsonPrimitive.isString }?.asString?.trim().orEmpty()
    }
}
