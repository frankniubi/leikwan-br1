package com.leikwan.bridge.ui.routing

import android.app.Application
import androidx.lifecycle.AndroidViewModel
import androidx.lifecycle.viewModelScope
import com.leikwan.bridge.data.Prefs
import com.leikwan.bridge.data.routing.UserRoutingRuleStore
import com.leikwan.bridge.routing.RoutingCategoryCatalog
import com.leikwan.bridge.routing.RoutingRule
import com.leikwan.bridge.routing.RoutingRuleKind
import com.leikwan.bridge.routing.UserTrafficOutcome
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.launch

/**
 * UI state for the user-authored routing surface (the 用户自定义分流 screen).
 */
data class RoutingUiState(
    val globalDefault: UserTrafficOutcome = UserTrafficOutcome.TUNNEL,
    val countryRules: List<RoutingRule> = emptyList(),
    val categoryRules: List<RoutingRule> = emptyList(),
    val presetCategories: List<String> = emptyList(),
)

/**
 * Drives the Routing screen: a user-settable global default plus add/toggle/delete of country and
 * preset-category rules, all persisted via [UserRoutingRuleStore] / [Prefs] so the next VPN
 * start/reload compiles them into the sing-box route.
 *
 * Collaborators are constructor-injected (defaulting to the real implementations) so the view-model
 * is unit-testable without EncryptedSharedPreferences/AndroidKeyStore: a test can pass an in-memory
 * store and a plain-SharedPreferences-backed [Prefs].
 */
class RoutingViewModel(
    app: Application,
    private val store: UserRoutingRuleStore = UserRoutingRuleStore.from(app),
    private val prefs: Prefs = Prefs.get(app),
    private val categoryCatalog: RoutingCategoryCatalog = RoutingCategoryCatalog.default(),
) : AndroidViewModel(app) {

    private val _state = MutableStateFlow(
        RoutingUiState(
            globalDefault = prefs.routingDefaultOutcome,
            presetCategories = categoryCatalog.presetKeys(),
        ),
    )
    val state = _state.asStateFlow()

    init {
        refresh()
    }

    fun refresh() {
        viewModelScope.launch {
            _state.value = _state.value.copy(
                globalDefault = prefs.routingDefaultOutcome,
                countryRules = store.countryRules(),
                categoryRules = store.categoryRules(),
                presetCategories = categoryCatalog.presetKeys(),
            )
        }
    }

    fun setGlobalDefault(outcome: UserTrafficOutcome) {
        prefs.routingDefaultOutcome = outcome
        _state.value = _state.value.copy(globalDefault = outcome)
    }

    fun addCountryRule(code: String, outcome: UserTrafficOutcome) {
        val normalized = code.trim().uppercase()
        if (normalized.isEmpty()) return
        val rule = RoutingRule(
            id = "country-${normalized.lowercase()}",
            kind = RoutingRuleKind.COUNTRY,
            matchValue = normalized,
            outcome = outcome,
            enabled = true,
        )
        viewModelScope.launch {
            store.upsert(rule)
            refreshNow()
        }
    }

    fun addCategoryRule(category: String, outcome: UserTrafficOutcome) {
        val normalized = category.trim().lowercase()
        if (normalized !in categoryCatalog.presetKeys()) return
        val rule = RoutingRule(
            id = "category-$normalized",
            kind = RoutingRuleKind.WEBSITE_CATEGORY,
            matchValue = normalized,
            outcome = outcome,
            enabled = true,
        )
        viewModelScope.launch {
            store.upsert(rule)
            refreshNow()
        }
    }

    fun toggleRule(id: String) {
        viewModelScope.launch {
            val current = store.all().firstOrNull { it.id == id } ?: return@launch
            store.setEnabled(id, !current.enabled)
            refreshNow()
        }
    }

    fun deleteRule(id: String) {
        viewModelScope.launch {
            store.delete(id)
            refreshNow()
        }
    }

    private suspend fun refreshNow() {
        _state.value = _state.value.copy(
            globalDefault = prefs.routingDefaultOutcome,
            countryRules = store.countryRules(),
            categoryRules = store.categoryRules(),
            presetCategories = categoryCatalog.presetKeys(),
        )
    }
}
