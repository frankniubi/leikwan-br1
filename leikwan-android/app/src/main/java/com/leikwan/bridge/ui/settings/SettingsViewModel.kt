package com.leikwan.bridge.ui.settings

import android.app.Application
import androidx.lifecycle.AndroidViewModel
import androidx.lifecycle.viewModelScope
import com.leikwan.bridge.api.ClientInfo
import com.leikwan.bridge.api.PanelRepository
import com.leikwan.bridge.data.Prefs
import com.leikwan.bridge.routing.RoutingDiagnosticsSource
import com.leikwan.bridge.ui.shared.RoutingDiagnosticsUiModel
import com.google.gson.Gson
import com.google.gson.reflect.TypeToken
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.launch

data class SettingsUiState(
    val dnsServer: String = "1.1.1.1",
    val dnsFallback: String = "8.8.8.8",
    val dnsStrategy: String = "prefer_ipv4",
    val bypassLan: Boolean = true,
    val bypassChina: Boolean = false,
    val customBypassRules: String = "",
    val username: String = "",
    val clientId: Long = 0L,
    val clientName: String = "",
    val clients: List<ClientInfo> = emptyList(),
    val isSwitchingClient: Boolean = false,
    val switchError: String? = null,
    val routingDiagnostics: RoutingDiagnosticsUiModel? = null,
)

class SettingsViewModel(app: Application) : AndroidViewModel(app) {
    private val prefs = Prefs.get(app)
    private val repo = PanelRepository(app)
    private val gson = Gson()

    private val _state = MutableStateFlow(
        SettingsUiState(
            dnsServer = prefs.dnsServer,
            dnsFallback = prefs.dnsFallback,
            dnsStrategy = prefs.dnsStrategy,
            bypassLan = prefs.bypassLan,
            bypassChina = prefs.bypassChina,
            customBypassRules = prefs.customBypassRules,
            username = prefs.username ?: "",
            clientId = prefs.clientId,
            clientName = prefs.clientName ?: "",
            clients = parseClients(),
            routingDiagnostics = runCatching {
                RoutingDiagnosticsSource.from(app).latest()
            }.getOrNull(),
        ),
    )
    val state = _state.asStateFlow()

    private fun parseClients(): List<ClientInfo> {
        val json = prefs.clientsJson ?: return emptyList()
        return try {
            val type = object : TypeToken<List<ClientInfo>>() {}.type
            gson.fromJson(json, type)
        } catch (_: Exception) {
            emptyList()
        }
    }

    fun switchClient(clientId: Long) {
        if (clientId == prefs.clientId) return
        viewModelScope.launch {
            _state.value = _state.value.copy(isSwitchingClient = true, switchError = null)
            try {
                repo.switchClient(clientId)
                _state.value = _state.value.copy(
                    isSwitchingClient = false,
                    clientId = prefs.clientId,
                    clientName = prefs.clientName ?: "",
                )
            } catch (e: Exception) {
                _state.value = _state.value.copy(
                    isSwitchingClient = false,
                    switchError = e.message,
                )
            }
        }
    }

    fun updateDnsServer(value: String) {
        prefs.dnsServer = value
        _state.value = _state.value.copy(dnsServer = value)
    }

    fun updateDnsFallback(value: String) {
        prefs.dnsFallback = value
        _state.value = _state.value.copy(dnsFallback = value)
    }

    fun updateDnsStrategy(value: String) {
        prefs.dnsStrategy = value
        _state.value = _state.value.copy(dnsStrategy = value)
    }

    fun updateBypassLan(value: Boolean) {
        prefs.bypassLan = value
        _state.value = _state.value.copy(bypassLan = value)
    }

    fun updateBypassChina(value: Boolean) {
        prefs.bypassChina = value
        _state.value = _state.value.copy(bypassChina = value)
    }

    fun updateCustomBypass(value: String) {
        prefs.customBypassRules = value
        _state.value = _state.value.copy(customBypassRules = value)
    }

    fun logout() {
        prefs.logout()
    }
}
