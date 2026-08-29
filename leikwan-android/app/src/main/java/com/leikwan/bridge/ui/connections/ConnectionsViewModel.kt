package com.leikwan.bridge.ui.connections

import android.app.Application
import android.util.Log
import androidx.annotation.StringRes
import androidx.lifecycle.AndroidViewModel
import androidx.lifecycle.viewModelScope
import com.leikwan.bridge.R
import com.leikwan.bridge.api.PanelRepository
import com.leikwan.bridge.data.AppDatabase
import com.leikwan.bridge.data.Prefs
import com.leikwan.bridge.data.ProxyNode
import com.leikwan.bridge.runtime.ForwardCatalog
import com.leikwan.bridge.runtime.ForwardListItem
import com.leikwan.bridge.service.LeikwanVpnService
import com.leikwan.bridge.service.ProxyConnectionMode
import com.leikwan.bridge.service.TunnelOrchestrator
import kotlinx.coroutines.flow.Flow
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch

private const val TAG = "ConnectionsViewModel"

enum class VpnStatus { DISCONNECTED, CONNECTING, CONNECTED }

/**
 * UI state for the "连接" (Connect) home screen.
 *
 * The client only forwards packets. Service forwards come from the panel (each exposing a local
 * listen port); the user's own ss/vless/wg nodes come from the device database. Each forward pairs
 * with ONE of the user's nodes — the user picks that node per forward ([nodeByForward]). On connect
 * the runtime rewrites the node's address to 127.0.0.1:<forward listen port> and dials it.
 *
 * Network/DB collaborators are injected so tests avoid EncryptedSharedPreferences (AndroidKeyStore)
 * and the real tunnel runtime.
 */
data class ConnectionsUiState(
    val vpnStatus: VpnStatus = VpnStatus.DISCONNECTED,
    val forwards: List<ForwardListItem> = emptyList(),
    /** The user's own nodes, enabled only. */
    val nodes: List<ProxyNode> = emptyList(),
    val selectedForwardId: Long = 0L,
    /** forwardId -> nodeId; the user chooses which node each forward rides on. */
    val nodeByForward: Map<Long, Long> = emptyMap(),
    val isLoading: Boolean = false,
    val error: String? = null,
) {
    val hasNode: Boolean get() = nodes.isNotEmpty()

    val selectedForwardServiceName: String
        get() = forwards.firstOrNull { it.forwardId == selectedForwardId }?.serviceName.orEmpty()

    val bridgedListenPort: Int
        get() = forwards.firstOrNull { it.forwardId == selectedForwardId }?.listenPort ?: 0

    /** The node paired to [forwardId], falling back to the first enabled node. */
    fun nodeFor(forwardId: Long): ProxyNode? {
        if (nodes.isEmpty()) return null
        return nodes.firstOrNull { it.id == nodeByForward[forwardId] } ?: nodes.first()
    }
}

class ConnectionsViewModel @JvmOverloads internal constructor(
    app: Application,
    prefsOverride: Prefs? = null,
    private val catalogFetcher: suspend (Long) -> ForwardCatalog = { id ->
        PanelRepository(app).pullCatalog(id)
    },
    private val nodeFlows: () -> Flow<List<ProxyNode>> = {
        AppDatabase.get(app).proxyNodeDao().getAll()
    },
    private val startConnect: suspend (Long) -> Unit = { id ->
        TunnelOrchestrator.forApplication(app).startForForward(id)
    },
    private val stopConnect: () -> Unit = {
        TunnelOrchestrator.forApplication(app).stop()
    },
) : AndroidViewModel(app) {

    private val prefs = prefsOverride ?: Prefs.get(app)

    private val _state = MutableStateFlow(
        ConnectionsUiState(
            selectedForwardId = prefs.selectedForwardId,
            vpnStatus = if (LeikwanVpnService.isRunning) VpnStatus.CONNECTED else VpnStatus.DISCONNECTED,
        )
    )
    val state = _state.asStateFlow()

    init {
        viewModelScope.launch {
            nodeFlows().collect { all ->
                val enabled = all.filter { it.enabled }
                _state.update { current ->
                    current.copy(
                        nodes = enabled,
                        nodeByForward = seedNodeByForward(current.forwards, enabled, current.nodeByForward),
                    )
                }
            }
        }
        refreshForwards()
    }

    /** Re-assert a node for every forward, keeping valid existing picks and defaulting to the first node. */
    private fun seedNodeByForward(
        forwards: List<ForwardListItem>,
        enabled: List<ProxyNode>,
        prior: Map<Long, Long>,
    ): Map<Long, Long> {
        if (enabled.isEmpty()) return emptyMap()
        return forwards.associate { forward ->
            val nodeId = prior[forward.forwardId]
                ?.takeIf { id -> enabled.any { it.id == id } }
                ?: enabled.first().id
            forward.forwardId to nodeId
        }
    }

    fun refreshForwards() {
        viewModelScope.launch {
            _state.update { it.copy(isLoading = true, error = null) }
            try {
                val catalog = catalogFetcher(_state.value.selectedForwardId)
                val forwards = catalog.visibleForwards
                val desired = catalog.selectedForwardId
                    ?: _state.value.selectedForwardId.takeIf { fid -> forwards.any { it.forwardId == fid } }
                val selected = desired ?: forwards.firstOrNull()?.forwardId ?: 0L
                prefs.selectedForwardId = selected
                _state.update { current ->
                    val next = current.copy(
                        forwards = forwards,
                        selectedForwardId = selected,
                        nodeByForward = seedNodeByForward(forwards, current.nodes, current.nodeByForward),
                        isLoading = false,
                    )
                    next.selectedForwardId.takeIf { it > 0 }?.let { fid ->
                        next.nodeFor(fid)?.let { prefs.selectedProxyId = it.id }
                    }
                    next
                }
            } catch (e: Exception) {
                _state.update { it.copy(error = e.message, isLoading = false) }
            }
        }
    }

    fun selectForward(forwardId: Long) {
        val nodeId = _state.value.nodeByForward[forwardId]
            ?: _state.value.nodes.firstOrNull()?.id
            ?: 0L
        prefs.selectedForwardId = forwardId
        if (nodeId > 0L) prefs.selectedProxyId = nodeId
        _state.update { it.copy(selectedForwardId = forwardId) }
    }

    fun selectNodeForForward(forwardId: Long, nodeId: Long) {
        val state = _state.value
        prefs.selectedProxyId = nodeId
        _state.update {
            it.copy(nodeByForward = it.nodeByForward + (forwardId to nodeId))
        }
    }

    fun toggleConnect() {
        viewModelScope.launch {
            val s = _state.value
            if (s.vpnStatus != VpnStatus.DISCONNECTED) {
                _state.update { it.copy(vpnStatus = VpnStatus.DISCONNECTED) }
                runCatching { stopConnect() }
                    .onFailure { Log.w(TAG, "Error stopping VPN", it) }
                return@launch
            }
            val forwardId = s.selectedForwardId
            if (forwardId == 0L || s.forwards.none { it.forwardId == forwardId }) {
                _state.update { it.copy(error = s(R.string.connections_error_select_service)) }
                return@launch
            }
            val node = s.nodeFor(forwardId)
            if (node == null) {
                _state.update { it.copy(error = s(R.string.connections_error_select_node)) }
                return@launch
            }
            // A connection always rides the service forward (direct mode was removed from the UI).
            prefs.proxyConnectionMode = ProxyConnectionMode.SERVICES
            prefs.selectedForwardId = forwardId
            prefs.selectedProxyId = node.id
            _state.update { it.copy(vpnStatus = VpnStatus.CONNECTING, error = null) }
            try {
                startConnect(forwardId)
                _state.update { it.copy(vpnStatus = VpnStatus.CONNECTED) }
            } catch (e: Exception) {
                _state.update { it.copy(vpnStatus = VpnStatus.DISCONNECTED, error = e.message) }
            }
        }
    }

    private fun s(@StringRes id: Int, vararg args: Any): String =
        getApplication<Application>().getString(id, *args)
}
