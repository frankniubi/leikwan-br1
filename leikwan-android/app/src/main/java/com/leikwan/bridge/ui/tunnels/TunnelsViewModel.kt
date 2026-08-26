package com.leikwan.bridge.ui.tunnels

import android.app.Application
import android.util.Log
import androidx.annotation.StringRes
import androidx.lifecycle.AndroidViewModel
import androidx.lifecycle.viewModelScope
import com.leikwan.bridge.R
import com.leikwan.bridge.api.PanelRepository
import com.leikwan.bridge.data.Prefs
import com.leikwan.bridge.data.ForwardStrategyStore
import com.leikwan.bridge.routing.RoutingDiagnosticsSource
import com.leikwan.bridge.runtime.ForwardListItem
import com.leikwan.bridge.runtime.ForwardStrategy
import com.leikwan.bridge.service.ProxyConnectionMode
import com.leikwan.bridge.service.LeikwanVpnService
import com.leikwan.bridge.service.TunnelOrchestrator
import com.leikwan.bridge.ui.shared.RoutingDiagnosticsUiModel
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.launch

enum class VpnStatus { DISCONNECTED, CONNECTING, CONNECTED }

data class TunnelsUiState(
    val vpnStatus: VpnStatus = VpnStatus.DISCONNECTED,
    val forwards: List<ForwardListItem> = emptyList(),
    val selectedForwardId: Long = 0L,
    val selectedStrategy: ForwardStrategy = ForwardStrategy.LATENCY_BEST,
    val connectionMode: ProxyConnectionMode = ProxyConnectionMode.SERVICES,
    val isLoading: Boolean = false,
    val error: String? = null,
    val routingDiagnostics: RoutingDiagnosticsUiModel? = null,
)

class TunnelsViewModel @JvmOverloads internal constructor(
    app: Application,
    prefsOverride: Prefs? = null,
    strategyStoreOverride: ForwardStrategyStore? = null,
) : AndroidViewModel(app) {
    private val appContext = app.applicationContext
    private val repo by lazy { PanelRepository(app) }
    private val prefs = prefsOverride ?: Prefs.get(app)
    private val strategyStore = strategyStoreOverride
        ?: ForwardStrategyStore.from(app, prefs.panelUrl, prefs.clientId)
    private val orchestrator by lazy { TunnelOrchestrator(app) }

    private val _state = MutableStateFlow(
        TunnelsUiState(
            selectedForwardId = prefs.selectedForwardId,
            selectedStrategy = prefs.selectedForwardId.takeIf { it > 0 }
                ?.let(strategyStore::get) ?: ForwardStrategy.LATENCY_BEST,
            connectionMode = ProxyConnectionMode.SERVICES,
            vpnStatus = if (LeikwanVpnService.isRunning) VpnStatus.CONNECTED else VpnStatus.DISCONNECTED,
            routingDiagnostics = runCatching {
                RoutingDiagnosticsSource.from(app).latest()
            }.getOrNull(),
        )
    )
    val state = _state.asStateFlow()

    init {
        refreshForwards()
    }

    fun refreshForwards() {
        viewModelScope.launch {
            _state.value = _state.value.copy(isLoading = true, error = null)
            try {
                val catalog = repo.pullCatalog(_state.value.selectedForwardId)
                val selectedId = catalog.selectedForwardId ?: 0L
                prefs.selectedForwardId = selectedId
                _state.value = _state.value.copy(
                    forwards = catalog.visibleForwards,
                    isLoading = false,
                    selectedForwardId = selectedId,
                    selectedStrategy = selectedId.takeIf { it > 0 }
                        ?.let(strategyStore::get) ?: ForwardStrategy.LATENCY_BEST,
                    connectionMode = ProxyConnectionMode.SERVICES,
                )
            } catch (e: Exception) {
                _state.value = _state.value.copy(error = e.message, isLoading = false)
            }
        }
    }

    fun selectForward(forwardId: Long) {
        val current = _state.value.selectedForwardId
        val newId = if (current == forwardId) 0L else forwardId
        prefs.selectedForwardId = newId
        _state.value = _state.value.copy(
            selectedForwardId = newId,
            selectedStrategy = newId.takeIf { it > 0 }
                ?.let(strategyStore::get) ?: ForwardStrategy.LATENCY_BEST,
        )
    }

    fun selectStrategy(strategy: ForwardStrategy) {
        val forwardId = _state.value.selectedForwardId
        if (forwardId <= 0) return
        strategyStore.set(forwardId, strategy)
        _state.value = _state.value.copy(selectedStrategy = strategy)
    }

    fun toggleVpn() {
        viewModelScope.launch {
            if (prefs.proxyConnectionMode != ProxyConnectionMode.SERVICES) {
                prefs.proxyConnectionMode = ProxyConnectionMode.SERVICES
            }
            val wantDisconnect = _state.value.vpnStatus != VpnStatus.DISCONNECTED
            if (wantDisconnect) {
                _state.value = _state.value.copy(vpnStatus = VpnStatus.DISCONNECTED)
                try {
                    orchestrator.stop()
                } catch (e: Exception) {
                    Log.w("TunnelsViewModel", "Error stopping VPN", e)
                }
            } else {
                val selectedId = _state.value.selectedForwardId
                if (selectedId == 0L) {
                    _state.value = _state.value.copy(error = s(R.string.tunnels_error_select_service))
                    return@launch
                }
                if (_state.value.forwards.none { it.forwardId == selectedId }) {
                    prefs.selectedForwardId = 0L
                    _state.value = _state.value.copy(
                        selectedForwardId = 0L,
                        error = s(R.string.tunnels_error_select_service),
                    )
                    return@launch
                }
                _state.value = _state.value.copy(vpnStatus = VpnStatus.CONNECTING, error = null)
                try {
                    orchestrator.startForForward(selectedId)
                    _state.value = _state.value.copy(vpnStatus = VpnStatus.CONNECTED)
                } catch (e: Exception) {
                    _state.value = _state.value.copy(vpnStatus = VpnStatus.DISCONNECTED, error = e.message)
                }
            }
        }
    }

    private fun s(@StringRes id: Int, vararg args: Any): String =
        appContext.getString(id, *args)
}
