package com.leikwan.bridge.runtime

import com.google.gson.Gson
import com.google.gson.JsonElement
import com.google.gson.JsonObject
import com.google.gson.JsonParser
import com.google.gson.annotations.SerializedName
import java.io.File
import java.time.Instant
import java.time.OffsetDateTime

/**
 * One forward's runtime health as the panel heartbeat expects it (`ultra_status[]`). Carries
 * exactly the fields `UltraClientStatusService::normalizeStatus` keeps: credentials, endpoints,
 * selection tiers, selection lease hashes and the runtime attestation are never included.
 * Null fields are omitted on the wire (Gson default).
 */
data class UltraStatusReport(
    @SerializedName("forward_id") val forwardId: Long,
    @SerializedName("state") val state: String,
    @SerializedName("udp_transport") val udpTransport: String? = null,
    @SerializedName("detected_ingress_zone") val detectedIngressZone: String,
    @SerializedName("latest_tcp") val latestTcp: UltraSelectionReport? = null,
    @SerializedName("active_udp") val activeUdp: UltraSelectionReport? = null,
    @SerializedName("ha_failures") val haFailures: Long = 0L,
    @SerializedName("udp_queue_drops") val udpQueueDrops: Long = 0L,
    @SerializedName("udp_setup_failures") val udpSetupFailures: Long = 0L,
    @SerializedName("bytes_in") val bytesIn: Long = 0L,
    @SerializedName("bytes_out") val bytesOut: Long = 0L,
    @SerializedName("ha_events") val haEvents: List<UltraHAEventReport> = emptyList(),
    @SerializedName("updated_at") val updatedAt: String = "",
    @SerializedName("error_code") val errorCode: String = "",
)

data class UltraSelectionReport(
    @SerializedName("selection_ref") val selectionRef: String,
    @SerializedName("rtt_ms") val rttMs: Long,
    @SerializedName("selected_at") val selectedAt: String,
    @SerializedName("candidate_key_hash") val candidateKeyHash: String? = null,
    @SerializedName("p95_rtt_ms") val p95RttMs: Long? = null,
    @SerializedName("jitter_ms") val jitterMs: Long? = null,
    @SerializedName("loss_percent") val lossPercent: Long? = null,
    @SerializedName("probe_sent") val probeSent: Long? = null,
    @SerializedName("probe_received") val probeReceived: Long? = null,
)

data class UltraHAEventReport(
    @SerializedName("protocol") val protocol: String,
    @SerializedName("reason") val reason: String,
    @SerializedName("generation") val generation: String,
    @SerializedName("at") val at: String,
    @SerializedName("candidate_key_hash") val candidateKeyHash: String? = null,
    @SerializedName("lease_hash") val leaseHash: String? = null,
    @SerializedName("upstream_bytes") val upstreamBytes: Long? = null,
    @SerializedName("remote_read_bytes") val remoteReadBytes: Long? = null,
    @SerializedName("remote_read_reason") val remoteReadReason: String? = null,
    @SerializedName("remote_stopped_first") val remoteStoppedFirst: Boolean? = null,
    @SerializedName("local_path_avoided") val localPathAvoided: Boolean? = null,
)

/**
 * Builds the heartbeat's `ultra_status` list from the Ultra runtime status documents
 * (`<forward>.status.json` in the runtime directory), shaped, bounded and redacted like the
 * OpenWrt router (`tools/leikwand/ultra_status.go`):
 *
 * * the newest document per forward wins; stopped runtimes and documents not rewritten for
 *   [MAX_AGE_SECONDS] (a runtime that is gone or wedged) are left out;
 * * at most [MAX_ENTRIES] entries and [MAX_EVENTS] HA events each, newest first;
 * * the encoded list stays under [MAX_BYTES], well inside the panel's 64 KiB, which drops an
 *   oversized list as a whole; event history is the first thing an entry gives up;
 * * every string is trimmed to printable ASCII and bounded, hashes must be 64 lowercase hex.
 *
 * Usage rows are delta-based ([UltraUsageLedger]); status is a liveness signal and is sent on
 * every heartbeat, as an empty list when no runtime runs.
 */
class UltraStatusCollector(private val statusDir: File) {
    fun collect(now: Instant): List<UltraStatusReport> {
        val documents = statusDir
            .listFiles { file -> file.isFile && file.name.endsWith(STATUS_SUFFIX) }
            .orEmpty()
            .sortedBy { it.name }
            .mapNotNull { file -> readDocument(file) }
        return buildReports(documents, now)
    }

    private fun readDocument(file: File): Document? = runCatching {
        if (file.length() !in 1..MAX_STATUS_FILE_BYTES) return null
        val root = JsonParser.parseString(file.readText(Charsets.UTF_8))
        if (!root.isJsonObject) return null
        Document(root.asJsonObject, Instant.ofEpochMilli(file.lastModified()))
    }.getOrNull()

    /** A parsed status document and when its file was last written. */
    data class Document(val root: JsonObject, val modifiedAt: Instant?)

    companion object {
        /** UltraClientStatusService::MAX_STATUSES. */
        const val MAX_ENTRIES = 32
        /** normalizeHAEvents keeps the last 8. */
        const val MAX_EVENTS = 8
        /** Keeps the encoded list well inside the panel's 64 KiB MAX_STATUS_BYTES. */
        const val MAX_BYTES = 48 * 1024
        /** Same as the desktop and the router: older documents describe a dead runtime. */
        const val MAX_AGE_SECONDS = 5L * 60L
        private const val MAX_STATUS_FILE_BYTES = 64L * 1024L
        private const val STATUS_SUFFIX = ".status.json"
        private const val RUNTIME_DIR = "ultra"
        private val ZONE = Regex("^[a-z0-9-]{1,32}$")
        private val SELECTION_REF = Regex("^[A-Za-z0-9_-]{16,128}$")
        private val CODE = Regex("^[a-z0-9_]{1,64}$")
        private val HASH = Regex("^[a-f0-9]{64}$")
        private val UDP_TRANSPORTS = setOf("disabled", "native_udp", "external_uot")
        private val gson = Gson()

        /** The collector for an app's files directory (runtime status files live in `ultra/`). */
        fun forFilesDir(filesDir: File): UltraStatusCollector = UltraStatusCollector(File(filesDir, RUNTIME_DIR))

        fun buildReports(documents: List<Document>, now: Instant): List<UltraStatusReport> {
            val oldest = now.minusSeconds(MAX_AGE_SECONDS)
            val latest = linkedMapOf<Long, Pair<UltraStatusReport, Instant?>>()
            documents.forEach { document ->
                val root = document.root
                val forwardId = long(root.get("forward_id"))
                if (forwardId <= 0L) return@forEach
                val rawState = string(root.get("state")).trim().lowercase()
                if (rawState == "stopped" || string(root.get("error_code")).trim() == "runtime_stopped") return@forEach
                val observedAt = parseInstant(string(root.get("updated_at"))) ?: document.modifiedAt
                if (observedAt != null && observedAt.isBefore(oldest)) return@forEach
                val previous = latest[forwardId]
                if (previous != null && !isAfter(observedAt, previous.second)) return@forEach
                latest[forwardId] = report(forwardId, root) to observedAt
            }

            val ordered = latest.values.sortedWith { left, right ->
                val byTime = compareInstants(right.second, left.second)
                if (byTime != 0) byTime else left.first.forwardId.compareTo(right.first.forwardId)
            }
            val reports = mutableListOf<UltraStatusReport>()
            var budget = MAX_BYTES
            for ((candidate, _) in ordered) {
                if (reports.size >= MAX_ENTRIES) break
                var report = candidate
                var size = encodedSize(report)
                if (size > budget) {
                    // Keep the health verdict and counters; the event history is the bulk.
                    report = report.copy(haEvents = emptyList())
                    size = encodedSize(report)
                }
                if (size > budget) break
                budget -= size
                reports += report
            }
            return reports
        }

        private fun report(forwardId: Long, root: JsonObject): UltraStatusReport {
            val state = when (val value = string(root.get("state")).trim().lowercase()) {
                "" -> "ready"
                "ready", "degraded", "error", "stopped" -> value
                else -> "error"
            }
            val zone = string(root.get("detected_ingress_zone")).trim().lowercase().takeIf { ZONE.matches(it) }
                ?: "unknown"
            return UltraStatusReport(
                forwardId = forwardId,
                state = state,
                udpTransport = string(root.get("udp_transport")).takeIf { it in UDP_TRANSPORTS },
                detectedIngressZone = zone,
                latestTcp = selection(root.get("latest_tcp")),
                activeUdp = selection(root.get("active_udp")),
                haFailures = long(root.get("ha_failures")),
                udpQueueDrops = long(root.get("udp_queue_drops")),
                udpSetupFailures = long(root.get("udp_setup_failures")),
                bytesIn = long(root.get("bytes_in")),
                bytesOut = long(root.get("bytes_out")),
                haEvents = events(root.get("ha_events")),
                updatedAt = text(string(root.get("updated_at")), 40),
                errorCode = text(string(root.get("error_code")), 64),
            )
        }

        private fun selection(element: JsonElement?): UltraSelectionReport? {
            val selection = element?.takeIf { it.isJsonObject }?.asJsonObject ?: return null
            val reference = string(selection.get("selection_ref")).trim()
            if (!SELECTION_REF.matches(reference)) return null
            return UltraSelectionReport(
                selectionRef = reference,
                rttMs = long(selection.get("rtt_ms")),
                selectedAt = text(string(selection.get("selected_at")), 40),
                candidateKeyHash = hash(selection.get("candidate_key_hash")),
                p95RttMs = long(selection.get("p95_rtt_ms")).takeIf { it > 0L },
                jitterMs = long(selection.get("jitter_ms")).takeIf { it > 0L },
                lossPercent = long(selection.get("loss_percent")).takeIf { it > 0L },
                probeSent = long(selection.get("probe_sent")).takeIf { it > 0L },
                probeReceived = long(selection.get("probe_received")).takeIf { it > 0L },
            )
        }

        /** The newest events the panel would keep, restricted to the fields it accepts. */
        private fun events(element: JsonElement?): List<UltraHAEventReport> {
            val array = element?.takeIf { it.isJsonArray }?.asJsonArray ?: return emptyList()
            val all = array.toList()
            return all.drop(maxOf(0, all.size - MAX_EVENTS)).mapNotNull { item ->
                val event = item.takeIf { it.isJsonObject }?.asJsonObject ?: return@mapNotNull null
                val protocol = string(event.get("protocol")).trim().lowercase()
                val reason = text(string(event.get("reason")), 64).lowercase()
                if ((protocol != "tcp" && protocol != "udp") || reason.isEmpty()) return@mapNotNull null
                UltraHAEventReport(
                    protocol = protocol,
                    reason = reason,
                    generation = text(string(event.get("generation")), 64),
                    at = text(string(event.get("at")), 40),
                    candidateKeyHash = hash(event.get("candidate_key_hash")),
                    leaseHash = hash(event.get("lease_hash")),
                    upstreamBytes = optionalLong(event.get("upstream_bytes")),
                    remoteReadBytes = optionalLong(event.get("remote_read_bytes")),
                    remoteReadReason = string(event.get("remote_read_reason")).takeIf { CODE.matches(it) },
                    remoteStoppedFirst = bool(event.get("remote_stopped_first")),
                    localPathAvoided = bool(event.get("local_path_avoided")),
                )
            }
        }

        /** Trims and bounds a runtime string to printable ASCII, so nothing can smuggle control bytes. */
        internal fun text(value: String, limit: Int): String {
            val builder = StringBuilder()
            for (char in value.trim()) {
                if (builder.length >= limit) break
                if (char.code in 0x21..0x7e) builder.append(char)
            }
            return builder.toString()
        }

        private fun hash(element: JsonElement?): String? =
            string(element).trim().lowercase().takeIf { HASH.matches(it) }

        private fun string(element: JsonElement?): String =
            element?.takeIf { it.isJsonPrimitive && it.asJsonPrimitive.isString }?.asString.orEmpty()

        private fun optionalLong(element: JsonElement?): Long? =
            runCatching {
                element?.takeIf { it.isJsonPrimitive && it.asJsonPrimitive.isNumber }?.asLong?.coerceAtLeast(0L)
            }.getOrNull()

        private fun long(element: JsonElement?): Long = optionalLong(element) ?: 0L

        private fun bool(element: JsonElement?): Boolean? =
            element?.takeIf { it.isJsonPrimitive && it.asJsonPrimitive.isBoolean }?.asBoolean

        private fun parseInstant(value: String): Instant? =
            value.trim().takeIf { it.isNotEmpty() }?.let { runCatching { OffsetDateTime.parse(it).toInstant() }.getOrNull() }

        private fun isAfter(left: Instant?, right: Instant?): Boolean = compareInstants(left, right) > 0

        /** Null (unknown time) sorts as the oldest. */
        private fun compareInstants(left: Instant?, right: Instant?): Int = when {
            left == null && right == null -> 0
            left == null -> -1
            right == null -> 1
            else -> left.compareTo(right)
        }

        private fun encodedSize(report: UltraStatusReport): Int =
            gson.toJson(report).toByteArray(Charsets.UTF_8).size + 1 // the separating comma
    }
}
