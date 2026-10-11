package com.leikwan.bridge.runtime

import com.google.gson.JsonObject
import com.google.gson.JsonParser
import java.io.File
import java.time.Instant

/**
 * Policy state the Go runtime reports in its status file (`policy_state`).
 *
 * AWAITING, LAPSED and REVOKED are idle on purpose: the runtime keeps its listeners bound and
 * fails each connection closed until a host injects a (new) lease. None of them is a reason to
 * restart the process.
 */
enum class UltraPolicyState(val wireValue: String) {
    ACTIVE("active"),
    AWAITING("awaiting"),
    LAPSED("lapsed"),
    REVOKED("revoked"),
    UNKNOWN("");

    companion object {
        fun fromWire(value: String?): UltraPolicyState {
            val normalized = value?.trim()?.lowercase().orEmpty()
            return entries.firstOrNull { it != UNKNOWN && it.wireValue == normalized } ?: UNKNOWN
        }

        /** Older runtimes only report `error_code` / `last_error`; map their idle codes. */
        fun fromErrorCode(value: String?): UltraPolicyState = when (value?.trim()?.lowercase()) {
            "awaiting_policy" -> AWAITING
            "policy_lapsed" -> LAPSED
            "policy_revoked" -> REVOKED
            else -> UNKNOWN
        }
    }
}

/** The subset of the Ultra runtime status file a host needs for health decisions. */
data class UltraRuntimeStatus(
    val policyState: UltraPolicyState,
    val errorCode: String,
    val policyGeneration: String,
    val updatedAt: Instant?,
) {
    /** The runtime is alive and waiting for policy on purpose. Never treat this as broken. */
    val isIdleByDesign: Boolean
        get() = policyState == UltraPolicyState.AWAITING
            || policyState == UltraPolicyState.LAPSED
            || policyState == UltraPolicyState.REVOKED

    /**
     * The runtime holds no usable policy and a host lease injection would fix it (for example a
     * process the guard restarted, which starts again in `awaiting`). REVOKED is deliberately
     * excluded: a revoked runtime only resumes through a fresh start by its owner.
     */
    val needsLease: Boolean
        get() = policyState == UltraPolicyState.AWAITING || policyState == UltraPolicyState.LAPSED

    companion object {
        /** Reads the status file; null when it is absent, unreadable or not a status object. */
        fun read(path: String?): UltraRuntimeStatus? {
            if (path.isNullOrBlank()) return null
            val text = runCatching { File(path).takeIf(File::isFile)?.readText(Charsets.UTF_8) }
                .getOrNull() ?: return null
            return parse(text)
        }

        fun parse(text: String): UltraRuntimeStatus? {
            val root = runCatching { JsonParser.parseString(text) }.getOrNull()
                ?.takeIf { it.isJsonObject }?.asJsonObject ?: return null
            val errorCode = root.stringValue("error_code") ?: root.stringValue("last_error") ?: ""
            val reported = UltraPolicyState.fromWire(root.stringValue("policy_state"))
            val policyState = if (reported != UltraPolicyState.UNKNOWN) {
                reported
            } else {
                UltraPolicyState.fromErrorCode(errorCode)
            }
            return UltraRuntimeStatus(
                policyState = policyState,
                errorCode = errorCode,
                policyGeneration = root.stringValue("policy_generation") ?: "",
                updatedAt = root.stringValue("updated_at")
                    ?.let { value -> runCatching { Instant.parse(value) }.getOrNull() },
            )
        }

        private fun JsonObject.stringValue(name: String): String? {
            val value = get(name) ?: return null
            if (value.isJsonNull || !value.isJsonPrimitive) return null
            return runCatching { value.asString.trim() }.getOrNull()?.takeIf(String::isNotEmpty)
        }
    }
}
