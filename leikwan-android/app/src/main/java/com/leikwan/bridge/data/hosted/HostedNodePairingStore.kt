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
 * Holds no credentials: the nodes themselves are Room rows written by [HostedNodeSync].
 */
class HostedNodePairingStore(
    private val prefs: SharedPreferences,
    private val gson: Gson = Gson(),
) {
    private val _pairings = MutableStateFlow(loadPairings())

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

    fun clear() {
        prefs.edit().clear().apply()
        _pairings.value = emptyMap()
    }

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
