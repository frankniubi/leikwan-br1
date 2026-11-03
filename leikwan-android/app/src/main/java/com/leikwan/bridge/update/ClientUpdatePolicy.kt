package com.leikwan.bridge.update

import com.google.gson.JsonElement
import com.leikwan.bridge.BuildConfig
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow

/** Dotted-numeric versionName comparison ("0.2.45"); a non-numeric suffix ("-beta") is ignored. */
object ClientVersion {
    private val NUMERIC_PREFIX = Regex("^(\\d+(?:\\.\\d+)*)")
    private const val MAX_COMPONENT_DIGITS = 9

    /** The numeric components of [version], or null when it does not start with one. */
    fun parse(version: String?): List<Int>? {
        val numeric = NUMERIC_PREFIX.find(version?.trim().orEmpty())?.groupValues?.get(1) ?: return null
        return numeric.split('.').map { part ->
            if (part.length > MAX_COMPONENT_DIGITS) return null
            part.toIntOrNull() ?: return null
        }
    }

    /** Component-wise comparison; missing components count as 0. Null when either is unparseable. */
    fun compare(left: String?, right: String?): Int? {
        val a = parse(left) ?: return null
        val b = parse(right) ?: return null
        for (index in 0 until maxOf(a.size, b.size)) {
            val difference = a.getOrElse(index) { 0 }.compareTo(b.getOrElse(index) { 0 })
            if (difference != 0) return difference
        }
        return 0
    }

    /** True only when both parse and [current] is below [minimum]; anything unparseable fails open. */
    fun isUpdateRequired(current: String?, minimum: String?): Boolean {
        val order = compare(current, minimum) ?: return false
        return order < 0
    }
}

/**
 * The panel's minimum-version policy, delivered as the top-level `client_update` object of
 * `configs/pull` (only to `X-Device-Platform: android-host`, and only when a minimum is set).
 */
object ClientUpdateDirective {
    /** `client_update.minimum_version` when it is a parseable version, else null. */
    fun minimumVersion(clientUpdate: JsonElement?): String? {
        val obj = clientUpdate?.takeIf { it.isJsonObject }?.asJsonObject ?: return null
        val value = obj.get("minimum_version")?.takeIf { it.isJsonPrimitive }?.asString?.trim()
        return value?.takeIf { it.isNotEmpty() && ClientVersion.parse(it) != null }
    }
}

/**
 * Process-wide minimum-version gate. Applied on every `configs/pull` parse, so it also takes
 * effect mid-session (nothing else re-checks while the VPN runs). The last parseable minimum
 * is remembered for the life of the process: a later pull that omits `client_update` (or sends
 * an unparseable one) does not lift the gate; only a newer parseable minimum replaces it.
 */
object ClientUpdatePolicy {
    private val lock = Any()
    private var lastMinimum: String? = null
    private val mutableRequiredMinimum = MutableStateFlow<String?>(null)

    /** The minimum version this build must update to, or null when no update is required. */
    val requiredMinimumVersion: StateFlow<String?> = mutableRequiredMinimum.asStateFlow()

    /** Applies one pull's `client_update`; returns true when an update is required afterwards. */
    fun applyFromPull(clientUpdate: JsonElement?, currentVersion: String = BuildConfig.VERSION_NAME): Boolean {
        val minimum = ClientUpdateDirective.minimumVersion(clientUpdate)
        synchronized(lock) {
            if (minimum != null) lastMinimum = minimum
            val required = lastMinimum?.takeIf { ClientVersion.isUpdateRequired(currentVersion, it) }
            mutableRequiredMinimum.value = required
            return required != null
        }
    }

    /** Test hook: forget the remembered policy. */
    internal fun resetForTest() {
        synchronized(lock) {
            lastMinimum = null
            mutableRequiredMinimum.value = null
        }
    }
}
