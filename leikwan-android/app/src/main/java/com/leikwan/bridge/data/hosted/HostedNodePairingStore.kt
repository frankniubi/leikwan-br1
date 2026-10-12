package com.leikwan.bridge.data.hosted

import android.content.Context
import android.content.SharedPreferences
import com.google.gson.Gson
import com.google.gson.reflect.TypeToken
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow

/**
 * Last applied hosted-node state for this device: which client and panel revision it came from, and
 * the forward -> local node id pairings the panel delivered (already resolved to Room ids).
 *
 * Also holds this device's suppression list: cloud nodes the user removed from this device only,
 * keyed by server node id (with the name, for the restore list). Sync never brings them back
 * until they are restored here or deleted everywhere.
 *
 * Holds no credentials: the nodes themselves are Room rows written by [HostedNodeSync].
 */
class HostedNodePairingStore(
    private val prefs: SharedPreferences,
    private val gson: Gson = Gson(),
) {
    private val _pairings = MutableStateFlow(loadPairings())
    private val _suppressed = MutableStateFlow(loadSuppressed())

    /** Server node id -> name of cloud nodes hidden on this device only. */
    val suppressed: StateFlow<Map<Long, String>> = _suppressed.asStateFlow()

    /** forwardId -> local ProxyNode id, as paired on the panel. Emits only on change. */
    val pairings: StateFlow<Map<Long, Long>> = _pairings.asStateFlow()

    val revision: String get() = prefs.getString(KEY_REVISION, "").orEmpty()

    val clientId: Long get() = prefs.getLong(KEY_CLIENT_ID, 0L)

    fun save(clientId: Long, revision: String, pairings: Map<Long, Long>) {
        prefs.edit()
            .putLong(KEY_CLIENT_ID, clientId)
            .putString(KEY_REVISION, revision)
            .putString(KEY_PAIRINGS, gson.toJson(pairings.mapKeys { it.key.toString() }))
            .apply()
        _pairings.value = pairings
    }

    /** Drops panel pairings that resolve to [localNodeId] (the row is gone from this device). */
    fun dropPairingsTo(localNodeId: Long) {
        val kept = _pairings.value.filterValues { it != localNodeId }
        if (kept.size == _pairings.value.size) return
        prefs.edit().putString(KEY_PAIRINGS, gson.toJson(kept.mapKeys { it.key.toString() })).apply()
        _pairings.value = kept
    }

    fun suppress(hostedId: Long, name: String) = saveSuppressed(_suppressed.value + (hostedId to name))

    fun unsuppress(hostedIds: Collection<Long>) {
        if (hostedIds.none { it in _suppressed.value }) return
        saveSuppressed(_suppressed.value - hostedIds.toSet())
    }

    /** Replaces the whole suppression list (sync prunes ids the panel no longer has). */
    fun replaceSuppressed(entries: Map<Long, String>) {
        if (entries != _suppressed.value) saveSuppressed(entries)
    }

    /** Forces the next pull to fetch the listing even if the panel revision did not move. */
    fun invalidateRevision() {
        prefs.edit().putString(KEY_REVISION, REVISION_INVALID).apply()
    }

    fun clear() {
        prefs.edit().clear().apply()
        _pairings.value = emptyMap()
        _suppressed.value = emptyMap()
    }

    private fun saveSuppressed(entries: Map<Long, String>) {
        prefs.edit().putString(KEY_SUPPRESSED, gson.toJson(entries.mapKeys { it.key.toString() })).apply()
        _suppressed.value = entries
    }

    private fun loadSuppressed(): Map<Long, String> = runCatching {
        val raw = prefs.getString(KEY_SUPPRESSED, null) ?: return emptyMap()
        val type = object : TypeToken<Map<String, String>>() {}.type
        gson.fromJson<Map<String, String>>(raw, type)
            .mapNotNull { (id, name) -> id.toLongOrNull()?.let { it to name } }
            .toMap()
    }.getOrDefault(emptyMap())

    private fun loadPairings(): Map<Long, Long> = runCatching {
        val raw = prefs.getString(KEY_PAIRINGS, null) ?: return emptyMap()
        val type = object : TypeToken<Map<String, Long>>() {}.type
        gson.fromJson<Map<String, Long>>(raw, type)
            .mapNotNull { (forward, node) -> forward.toLongOrNull()?.let { it to node } }
            .toMap()
    }.getOrDefault(emptyMap())

    companion object {
        private const val PREFS_NAME = "hosted-node-pairings"
        private const val KEY_CLIENT_ID = "client_id"
        private const val KEY_REVISION = "revision"
        private const val KEY_PAIRINGS = "pairings"
        private const val KEY_SUPPRESSED = "suppressed"

        /** Never equal to a panel revision (a hex digest or empty). */
        private const val REVISION_INVALID = "invalidated"

        @Volatile private var instance: HostedNodePairingStore? = null

        /** One instance per process so every collector sees the same [pairings] flow. */
        fun from(context: Context): HostedNodePairingStore =
            instance ?: synchronized(this) {
                instance ?: HostedNodePairingStore(
                    context.applicationContext.getSharedPreferences(PREFS_NAME, Context.MODE_PRIVATE),
                ).also { instance = it }
            }
    }
}
