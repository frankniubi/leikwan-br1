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
import com.leikwan.bridge.net.PanelNetwork
import com.leikwan.bridge.runtime.ForwardCatalog
import com.leikwan.bridge.runtime.ForwardListItem
import com.leikwan.bridge.runtime.NativeOutboundCapability
import com.leikwan.bridge.runtime.NATIVE_ROUTE_POLICY_RESTRICTED
import com.leikwan.bridge.runtime.NATIVE_ROUTE_POLICY_UNRESTRICTED
import com.leikwan.bridge.runtime.isNativeRoutePolicyValue
import com.leikwan.bridge.runtime.normalizeNativeRoutePolicy
import com.leikwan.bridge.service.ConnectProgress
import com.leikwan.bridge.service.LeikwanVpnService
import com.leikwan.bridge.service.ProxyConnectionMode
import com.leikwan.bridge.service.TunnelOrchestrator
import com.leikwan.bridge.session.DeviceLimitGate
import com.leikwan.bridge.session.DeviceLimitNotice
import com.leikwan.bridge.session.DeviceLimitReachedException
import com.leikwan.bridge.session.PanelControlException
import com.leikwan.bridge.ui.shared.thirdPartyVpnHintOrNull
import com.leikwan.bridge.ui.shared.withThirdPartyVpnHint
import kotlinx.coroutines.Job
import kotlinx.coroutines.delay
import kotlinx.coroutines.flow.Flow
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch

private const val TAG = "ConnectionsViewModel"
private const val DEVICE_LIMIT_RETRY_MS = 60_000L

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
    val nativeOutbound: NativeOutboundCapability? = null,
    val nativeSelected: Boolean = false,
    val nativeForwardId: Long = 0L,
    val nativeRouteScope: String = "global",
    val nativeBypassChina: Boolean = false,
    val nativeRoutePolicy: String = NATIVE_ROUTE_POLICY_RESTRICTED,
    val isLoading: Boolean = false,
    val error: String? = null,
    /** Set while the panel refuses this device because the online device limit is full. */
    val deviceLimit: DeviceLimitNotice? = null,
    /** Candidate routes the runtime is probing while connecting, or null outside route selection. */
    val selectingRouteCandidates: Int? = null,
) {
    val hasNode: Boolean get() = nodes.isNotEmpty()
    val nativeVisible: Boolean get() = nativeOutbound?.isUsable() == true
    val nativeCarrier get() = nativeOutbound?.carrier(nativeForwardId)
    val nativeUnrestrictedAllowed: Boolean get() = nativeCarrier?.unrestrictedAllowed == true
    val nativeScopes: List<String>
        get() = nativeCarrier?.routeScopes ?: nativeOutbound?.routeScopes.orEmpty()
    val hasUsablePath: Boolean get() = hasNode || (nativeSelected && nativeVisible && nativeCarrier != null)

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
    private val startNativeConnect: suspend (Long, String, Boolean, String) -> Unit = { id, scope, bypass, policy ->
        TunnelOrchestrator.forApplication(app).startNativeForForward(id, scope, bypass, policy)
    },
    private val deviceLimitState: StateFlow<DeviceLimitNotice?> = DeviceLimitGate.state,
    private val deviceLimitRetryMs: Long = DEVICE_LIMIT_RETRY_MS,
    private val thirdPartyVpnBlocking: () -> Boolean = PanelNetwork::shouldHintThirdPartyVpn,
    private val routeSelectionState: StateFlow<Int?> = ConnectProgress.selectingRouteCandidates,
    vpnRunning: Boolean = LeikwanVpnService.isRunning,
) : AndroidViewModel(app) {

    private val prefs = prefsOverride ?: Prefs.get(app)

    /** Pending retry after a device-limit refusal; the running VPN's control loop covers itself. */
    private var deviceLimitRetry: Job? = null

    private val _state = MutableStateFlow(
        ConnectionsUiState(
            selectedForwardId = prefs.selectedForwardId,
            vpnStatus = if (vpnRunning) VpnStatus.CONNECTED else VpnStatus.DISCONNECTED,
            nativeSelected = prefs.nativeOutboundSelected,
            nativeForwardId = prefs.nativeOutboundForwardId,
            nativeRouteScope = prefs.nativeOutboundRouteScope,
            nativeBypassChina = prefs.nativeOutboundBypassChina,
            nativeRoutePolicy = normalizeNativeRoutePolicy(prefs.nativeOutboundRoutePolicy),
        )
    )
    val state = _state.asStateFlow()

    init {
        viewModelScope.launch {
            deviceLimitState.collect { notice -> _state.update { it.copy(deviceLimit = notice) } }
        }
        viewModelScope.launch {
            routeSelectionState.collect { count -> _state.update { it.copy(selectingRouteCandidates = count) } }
        }
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
            val currentState = _state.value
            val wasNativeActive = currentState.nativeSelected
                && currentState.vpnStatus != VpnStatus.DISCONNECTED
            val activeNativeForwardId = currentState.nativeForwardId
            val activeNativeDirection = currentState.nativeCarrier?.direction.orEmpty()
            val activeNativeRouteScope = currentState.nativeRouteScope
            val activeNativeRoutePolicy = currentState.nativeRoutePolicy
            _state.update { it.copy(isLoading = true, error = null) }
            try {
                val catalog = catalogFetcher(_state.value.selectedForwardId)
                val forwards = catalog.visibleForwards
                val desired = catalog.selectedForwardId
                    ?: _state.value.selectedForwardId.takeIf { fid -> forwards.any { it.forwardId == fid } }
                val selected = desired ?: forwards.firstOrNull()?.forwardId ?: 0L
                prefs.selectedForwardId = selected
                val capability = catalog.nativeOutbound?.takeIf { it.isUsable() }
                val nativeId = capability?.forwards
                    ?.firstOrNull { it.forwardId == prefs.nativeOutboundForwardId }
                    ?.forwardId
                    ?: capability?.defaultForwardId?.takeIf { id -> capability.carrier(id) != null }
                    ?: capability?.forwards?.firstOrNull()?.forwardId
                    ?: 0L
                val nativeCarrier = capability?.carrier(nativeId)
                val nativeDirection = nativeCarrier?.direction.orEmpty()
                val preferredNativeScope = NativeOutboundCapability.normalizeScope(
                    prefs.nativeOutboundRouteScope,
                )
                val nativeScope = nativeCarrier?.routeScopes
                    ?.firstOrNull { it == preferredNativeScope }
                    ?: nativeCarrier?.defaultRouteScope
                    ?: capability?.defaultRouteScope
                    ?: "global"
                val preferredNativePolicy = normalizeNativeRoutePolicy(prefs.nativeOutboundRoutePolicy)
                val nativePolicy = if (
                    preferredNativePolicy == NATIVE_ROUTE_POLICY_UNRESTRICTED
                        && nativeCarrier?.unrestrictedAllowed == true
                ) {
                    NATIVE_ROUTE_POLICY_UNRESTRICTED
                } else {
                    NATIVE_ROUTE_POLICY_RESTRICTED
                }
                val nativeSelected = prefs.nativeOutboundSelected && capability != null && nativeCarrier != null
                val displayedSelected = if (nativeSelected && forwards.any { it.forwardId == nativeId }) {
                    nativeId
                } else {
                    selected
                }
                if (nativeSelected) prefs.selectedForwardId = displayedSelected
                prefs.nativeOutboundForwardId = nativeId
                prefs.nativeOutboundRouteScope = nativeScope
                prefs.nativeOutboundBypassChina = if (nativeScope == "global") prefs.nativeOutboundBypassChina else false
                prefs.nativeOutboundRoutePolicy = nativePolicy
                prefs.nativeOutboundSelected = nativeSelected
                _state.update { current ->
                    val next = current.copy(
                        forwards = forwards,
                        selectedForwardId = displayedSelected,
                        nodeByForward = seedNodeByForward(forwards, current.nodes, current.nodeByForward),
                        nativeOutbound = capability,
                        nativeSelected = nativeSelected,
                        nativeForwardId = nativeId,
                        nativeRouteScope = nativeScope,
                        nativeBypassChina = prefs.nativeOutboundBypassChina,
                        nativeRoutePolicy = nativePolicy,
                        isLoading = false,
                    )
                    next.selectedForwardId.takeIf { it > 0 }?.let { fid ->
                        next.nodeFor(fid)?.let { prefs.selectedProxyId = it.id }
                    }
                    next
                }
                val nativeAuthorizationChanged = wasNativeActive && (
                    !nativeSelected
                        || nativeId != activeNativeForwardId
                        // A view model created over a running tunnel has not seen its carrier yet;
                        // an unknown direction is not a changed one.
                        || (activeNativeDirection.isNotEmpty() && nativeDirection != activeNativeDirection)
                        || nativeScope != activeNativeRouteScope
                        || nativePolicy != activeNativeRoutePolicy
                )
                if (nativeAuthorizationChanged) {
                    runCatching { stopConnect() }
                        .onFailure { Log.w(TAG, "Error stopping revoked native VPN", it) }
                    prefs.nativeOutboundSelected = false
                    _state.update {
                        it.copy(
                            vpnStatus = VpnStatus.DISCONNECTED,
                            nativeSelected = false,
                        )
                    }
                }
            } catch (e: Exception) {
                _state.update { it.copy(error = userError(e), isLoading = false) }
                if (e is DeviceLimitReachedException) retryAfterDeviceLimit(::refreshForwards)
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

    fun setNativeSelected(selected: Boolean) {
        val current = _state.value
        if (selected && (!current.nativeVisible || current.nativeCarrier == null)) return
        if (!selected && current.nativeSelected && current.vpnStatus != VpnStatus.DISCONNECTED) {
            runCatching { stopConnect() }
                .onFailure { Log.w(TAG, "Error stopping disabled native VPN", it) }
            _state.update { it.copy(vpnStatus = VpnStatus.DISCONNECTED) }
        }
        prefs.nativeOutboundSelected = selected
        _state.update { it.copy(nativeSelected = selected) }
    }

    fun setNativeForward(forwardId: Long) {
        val current = _state.value
        val carrier = current.nativeOutbound?.carrier(forwardId) ?: return
        val scope = carrier.routeScopes.firstOrNull {
            it.equals(current.nativeRouteScope, ignoreCase = true)
        } ?: carrier.defaultRouteScope
        val policy = if (
            current.nativeRoutePolicy == NATIVE_ROUTE_POLICY_UNRESTRICTED && carrier.unrestrictedAllowed
        ) NATIVE_ROUTE_POLICY_UNRESTRICTED else NATIVE_ROUTE_POLICY_RESTRICTED
        val bypass = current.nativeBypassChina && scope == "global"
        prefs.nativeOutboundForwardId = forwardId
        prefs.nativeOutboundRouteScope = scope
        prefs.nativeOutboundBypassChina = bypass
        prefs.nativeOutboundRoutePolicy = policy
        prefs.selectedForwardId = forwardId
        _state.update {
            it.copy(
                selectedForwardId = forwardId,
                nativeForwardId = forwardId,
                nativeRouteScope = scope,
                nativeBypassChina = bypass,
                nativeRoutePolicy = policy,
            )
        }
    }

    fun setNativeRouteScope(scope: String) {
        val current = _state.value
        val normalized = NativeOutboundCapability.normalizeScope(scope) ?: return
        if (normalized !in current.nativeScopes) return
        val bypass = current.nativeBypassChina && normalized == "global"
        prefs.nativeOutboundRouteScope = normalized
        prefs.nativeOutboundBypassChina = bypass
        _state.update { it.copy(nativeRouteScope = normalized, nativeBypassChina = bypass) }
    }

    fun setNativeBypassChina(enabled: Boolean) {
        if (_state.value.nativeRouteScope != "global") return
        prefs.nativeOutboundBypassChina = enabled
        _state.update { it.copy(nativeBypassChina = enabled) }
    }

    fun setNativeRoutePolicy(policy: String) {
        val current = _state.value
        if (current.nativeSelected && current.vpnStatus != VpnStatus.DISCONNECTED) return
        if (!isNativeRoutePolicyValue(policy)) return
        val normalized = normalizeNativeRoutePolicy(policy)
        if (normalized == NATIVE_ROUTE_POLICY_UNRESTRICTED && !current.nativeUnrestrictedAllowed) return
        prefs.nativeOutboundRoutePolicy = normalized
        _state.update { it.copy(nativeRoutePolicy = normalized) }
    }

    fun selectNodeForForward(forwardId: Long, nodeId: Long) {
        val state = _state.value
        prefs.selectedProxyId = nodeId
        _state.update {
            it.copy(nodeByForward = it.nodeByForward + (forwardId to nodeId))
        }
    }

    fun toggleConnect() {
        deviceLimitRetry?.cancel()
        viewModelScope.launch {
            val s = _state.value
            if (s.vpnStatus != VpnStatus.DISCONNECTED) {
                _state.update { it.copy(vpnStatus = VpnStatus.DISCONNECTED) }
                runCatching { stopConnect() }
                    .onFailure { Log.w(TAG, "Error stopping VPN", it) }
                return@launch
            }
            val forwardId = s.selectedForwardId
            if (!s.nativeSelected && (forwardId == 0L || s.forwards.none { it.forwardId == forwardId })) {
                _state.update { it.copy(error = s(R.string.connections_error_select_service)) }
                return@launch
            }
            if (s.nativeSelected) {
                val nativeId = s.nativeForwardId.takeIf { id -> s.nativeOutbound?.carrier(id) != null }
                    ?: s.nativeOutbound?.defaultForwardId
                    ?: 0L
                val carrier = s.nativeOutbound?.carrier(nativeId)
                if (!s.nativeVisible || carrier == null) {
                    _state.update { it.copy(error = s(R.string.connections_error_native_unavailable)) }
                    return@launch
                }
                val scope = carrier.routeScopes.firstOrNull {
                    it.equals(s.nativeRouteScope, ignoreCase = true)
                } ?: carrier.defaultRouteScope
                val routePolicy = if (
                    s.nativeRoutePolicy == NATIVE_ROUTE_POLICY_UNRESTRICTED && carrier.unrestrictedAllowed
                ) NATIVE_ROUTE_POLICY_UNRESTRICTED else NATIVE_ROUTE_POLICY_RESTRICTED
                val bypass = s.nativeBypassChina && scope == "global"
                prefs.proxyConnectionMode = ProxyConnectionMode.SERVICES
                prefs.selectedForwardId = nativeId
                prefs.nativeOutboundForwardId = nativeId
                prefs.nativeOutboundRouteScope = scope
                prefs.nativeOutboundBypassChina = bypass
                prefs.nativeOutboundRoutePolicy = routePolicy
                _state.update {
                    it.copy(
                        selectedForwardId = nativeId,
                        nativeForwardId = nativeId,
                        nativeRouteScope = scope,
                        nativeBypassChina = bypass,
                        nativeRoutePolicy = routePolicy,
                        vpnStatus = VpnStatus.CONNECTING,
                        error = null,
                    )
                }
                try {
                    startNativeConnect(nativeId, scope, bypass, routePolicy)
                    _state.update { it.copy(vpnStatus = VpnStatus.CONNECTED) }
                } catch (e: Exception) {
                    _state.update { it.copy(vpnStatus = VpnStatus.DISCONNECTED, error = userError(e)) }
                    if (e is DeviceLimitReachedException) retryConnectAfterDeviceLimit()
                }
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
                _state.update { it.copy(vpnStatus = VpnStatus.DISCONNECTED, error = userError(e)) }
                if (e is DeviceLimitReachedException) retryConnectAfterDeviceLimit()
            }
        }
    }

    /**
     * A device-limit refusal is shown by the persistent device-limit card, not as a raw error
     * line. Reported here too in case the refusal came from a call that does not publish it.
     */
    private fun userError(e: Exception): String? {
        if (e is DeviceLimitReachedException) {
            DeviceLimitGate.report(e)
            return null
        }
        // A terminal control is the panel answering; only other failures may be another VPN's doing.
        if (e is PanelControlException) return e.message
        val hint = getApplication<Application>().thirdPartyVpnHintOrNull(thirdPartyVpnBlocking())
        return withThirdPartyVpnHint(e.message, hint)
    }

    /** The user asked to connect; keep trying at the control loop's steady cadence until a slot frees. */
    private fun retryConnectAfterDeviceLimit() = retryAfterDeviceLimit {
        if (_state.value.vpnStatus == VpnStatus.DISCONNECTED) toggleConnect()
    }

    private fun retryAfterDeviceLimit(action: () -> Unit) {
        deviceLimitRetry?.cancel()
        deviceLimitRetry = viewModelScope.launch {
            delay(deviceLimitRetryMs)
            action()
        }
    }

    private fun s(@StringRes id: Int, vararg args: Any): String =
        getApplication<Application>().getString(id, *args)
}
