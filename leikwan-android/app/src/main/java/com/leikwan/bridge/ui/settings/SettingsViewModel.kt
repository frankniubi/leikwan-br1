package com.leikwan.bridge.ui.settings

import android.app.Application
import androidx.annotation.StringRes
import androidx.lifecycle.AndroidViewModel
import androidx.lifecycle.viewModelScope
import com.leikwan.bridge.R
import com.leikwan.bridge.api.ClientInfo
import com.leikwan.bridge.api.PanelRepository
import com.leikwan.bridge.data.AppDatabase
import com.leikwan.bridge.data.Prefs
import com.leikwan.bridge.data.routing.RulePackFetchResult
import com.leikwan.bridge.data.routing.RulePackFetcher
import com.leikwan.bridge.data.routing.RulePackManager
import com.leikwan.bridge.data.routing.RulePackSnapshot
import com.leikwan.bridge.routing.RoutingDiagnosticsSource
import com.leikwan.bridge.service.LeikwanVpnService
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
    val rulePackLabel: String = "none",
    val rulePackStale: Boolean = false,
    val isUpdatingRules: Boolean = false,
    val rulePackMessage: String? = null,
)

class SettingsViewModel @JvmOverloads constructor(
    app: Application,
    prefsOverride: Prefs? = null,
) : AndroidViewModel(app) {
    private val appContext = app.applicationContext
    private val prefs = prefsOverride ?: Prefs.get(app)
    private val db = AppDatabase.get(app)
    private val repo = PanelRepository(app, prefsOverride = prefs)
    private val gson = Gson()
    private val rulePackManager = RulePackManager(db, prefs)
    private val rulePackFetcher = RulePackFetcher(manager = rulePackManager)

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
            rulePackLabel = rulePackLabelFromPrefs(),
            rulePackStale = prefs.activeRulePackStale,
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

    fun updateRules() {
        viewModelScope.launch {
            _state.value = _state.value.copy(isUpdatingRules = true, rulePackMessage = null)
            val result = if (LeikwanVpnService.isRunning) {
                rulePackFetcher.fetchAndActivate()
            } else {
                val seed = rulePackFetcher.ensureSeed()
                RulePackFetchResult.Success(seed)
            }
            _state.value = when (result) {
                is RulePackFetchResult.Success -> _state.value.copy(
                    isUpdatingRules = false,
                    rulePackLabel = rulePackLabel(result.snapshot),
                    rulePackStale = result.snapshot.stale,
                    rulePackMessage = s(R.string.settings_rules_ready_format, result.snapshot.categoryPackVersion),
                )
                is RulePackFetchResult.Error -> _state.value.copy(
                    isUpdatingRules = false,
                    rulePackMessage = result.message,
                )
            }
        }
    }

    fun rollbackRules() {
        viewModelScope.launch {
            val previous = rulePackManager.previous()
            if (previous == null) {
                _state.value = _state.value.copy(rulePackMessage = s(R.string.settings_no_previous_rule_pack))
            } else {
                val activated = rulePackManager.activate(previous.copy(rollbackAvailable = false))
                _state.value = _state.value.copy(
                    rulePackLabel = rulePackLabel(activated),
                    rulePackStale = activated.stale,
                    rulePackMessage = s(R.string.settings_rolled_back_format, activated.categoryPackVersion),
                )
            }
        }
    }

    fun logout() {
        repo.logout()
    }

    private fun rulePackLabelFromPrefs(): String {
        val source = prefs.activeRulePackSourceLabel ?: return s(R.string.settings_rule_pack_none)
        val country = prefs.activeRulePackCountryVersion ?: ""
        val category = prefs.activeRulePackCategoryVersion ?: ""
        return "$source geoip=$country geosite=$category"
    }

    private fun rulePackLabel(snapshot: RulePackSnapshot): String =
        "${snapshot.sourceLabel} geoip=${snapshot.countryPackVersion} geosite=${snapshot.categoryPackVersion}"

    private fun s(@StringRes id: Int, vararg args: Any): String =
        appContext.getString(id, *args)
}
