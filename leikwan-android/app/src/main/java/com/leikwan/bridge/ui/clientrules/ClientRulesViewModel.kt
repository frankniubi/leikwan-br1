package com.leikwan.bridge.ui.clientrules

import android.content.Context
import androidx.annotation.StringRes
import androidx.lifecycle.ViewModel
import androidx.lifecycle.ViewModelProvider
import androidx.lifecycle.viewModelScope
import com.leikwan.bridge.R
import com.leikwan.bridge.api.PanelRepository
import com.leikwan.bridge.clientrules.ClientRouteRule
import com.leikwan.bridge.clientrules.ClientRouteRuleSnapshot
import com.leikwan.bridge.clientrules.ClientRuleValidationResult
import com.leikwan.bridge.clientrules.ClientRuleWriteDraft
import com.leikwan.bridge.clientrules.RouteDirection
import com.leikwan.bridge.clientrules.RouteDirectionStatus
import com.leikwan.bridge.clientrules.RouteRuleEntitlement
import com.leikwan.bridge.data.clientrules.ClientRouteRuleStore
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.launch

interface ClientRulesStore {
    val snapshot: StateFlow<ClientRouteRuleSnapshot>
    fun current(): ClientRouteRuleSnapshot
    fun validateDraft(draft: ClientRuleWriteDraft): ClientRuleValidationResult
}

interface ClientRulesRepository {
    suspend fun createClientRule(draft: ClientRuleWriteDraft): ClientRouteRule
    suspend fun updateClientRule(ruleId: String, draft: ClientRuleWriteDraft): ClientRouteRule
    suspend fun deleteClientRule(ruleId: String): ClientRouteRule
}

data class ClientRulesUiState(
    val enabled: Boolean = false,
    val serviceScopes: List<ClientRuleServiceScopeOption> = emptyList(),
    val selectedServiceScopeId: String? = null,
    val serviceScopeSelectionEnabled: Boolean = false,
    val quota: ClientRuleQuotaLabel? = null,
    val directions: List<RouteDirection> = emptyList(),
    val rules: List<ClientRouteRule> = emptyList(),
    val draftDestinationIp: String = "",
    val draftDestinationPort: String = "",
    val draftDirectionId: String? = null,
    val draftEnabled: Boolean = true,
    val errorCode: String? = null,
    val unavailableReason: ClientRulesText? = null,
    val saving: Boolean = false,
)

data class ClientRulesText(
    @StringRes val resId: Int,
    val args: List<Any> = emptyList(),
)

data class ClientRuleQuotaLabel(
    val remainingRules: Int,
    val maxRules: Int,
)

data class ClientRuleServiceScopeOption(
    val serviceScopeId: String,
    val label: ClientRulesText,
)

class ClientRulesViewModel(
    private val store: ClientRulesStore,
    private val repository: ClientRulesRepository,
) : ViewModel() {

    private val _state = MutableStateFlow(reduce(store.current(), selectedScopeId = null, previous = ClientRulesUiState()))
    val state: StateFlow<ClientRulesUiState> = _state.asStateFlow()

    init {
        viewModelScope.launch {
            store.snapshot.collect { snapshot ->
                _state.value = reduce(
                    snapshot = snapshot,
                    selectedScopeId = _state.value.selectedServiceScopeId,
                    previous = _state.value,
                )
            }
        }
    }

    fun selectServiceScope(serviceScopeId: String) {
        _state.value = reduce(
            snapshot = store.current(),
            selectedScopeId = serviceScopeId,
            previous = _state.value.copy(errorCode = null),
        )
    }

    fun updateDraftDestinationIp(value: String) {
        _state.value = _state.value.copy(draftDestinationIp = value, errorCode = null)
    }

    fun updateDraftDestinationPort(value: String) {
        _state.value = _state.value.copy(draftDestinationPort = value.filter { it.isDigit() }, errorCode = null)
    }

    fun selectDraftDirection(directionId: String) {
        if (_state.value.directions.any { it.id == directionId }) {
            _state.value = _state.value.copy(draftDirectionId = directionId, errorCode = null)
        }
    }

    fun setDraftEnabled(enabled: Boolean) {
        _state.value = _state.value.copy(draftEnabled = enabled)
    }

    fun submitDraft() {
        val draft = buildCreateDraft()
        if (selectedEntitlement()?.remainingRules == 0) {
            _state.value = _state.value.copy(errorCode = "over_quota")
            return
        }
        store.validateDraft(draft).error?.let { error ->
            _state.value = _state.value.copy(errorCode = error)
            return
        }
        viewModelScope.launch {
            _state.value = _state.value.copy(saving = true, errorCode = null)
            runCatching { repository.createClientRule(draft) }
                .onFailure { error -> _state.value = _state.value.copy(errorCode = error.message ?: "client_rule_write_failed") }
            _state.value = _state.value.copy(saving = false)
        }
    }

    fun toggleRuleEnabled(ruleId: String) {
        val rule = _state.value.rules.firstOrNull { it.id == ruleId } ?: return
        val draft = ClientRuleWriteDraft(
            serviceScopeId = rule.serviceScopeId,
            destinationIp = rule.destinationIp,
            destinationPort = rule.destinationPort,
            directionId = rule.directionId,
            enabled = !rule.enabled,
            syncVersion = rule.syncVersion,
        )
        store.validateDraft(draft).error?.let { error ->
            _state.value = _state.value.copy(errorCode = error)
            return
        }
        viewModelScope.launch {
            _state.value = _state.value.copy(saving = true, errorCode = null)
            runCatching { repository.updateClientRule(ruleId, draft) }
                .onFailure { error -> _state.value = _state.value.copy(errorCode = error.message ?: "client_rule_write_failed") }
            _state.value = _state.value.copy(saving = false)
        }
    }

    fun deleteRule(ruleId: String) {
        viewModelScope.launch {
            _state.value = _state.value.copy(saving = true, errorCode = null)
            runCatching { repository.deleteClientRule(ruleId) }
                .onFailure { error -> _state.value = _state.value.copy(errorCode = error.message ?: "client_rule_write_failed") }
            _state.value = _state.value.copy(saving = false)
        }
    }

    private fun buildCreateDraft(): ClientRuleWriteDraft = ClientRuleWriteDraft(
        serviceScopeId = _state.value.selectedServiceScopeId,
        destinationIp = _state.value.draftDestinationIp,
        destinationPort = _state.value.draftDestinationPort.toIntOrNull(),
        directionId = _state.value.draftDirectionId,
        enabled = _state.value.draftEnabled,
    )

    private fun selectedEntitlement(): RouteRuleEntitlement? = store.current()
        .entitlementFor(_state.value.selectedServiceScopeId.orEmpty())

    private fun reduce(
        snapshot: ClientRouteRuleSnapshot,
        selectedScopeId: String?,
        previous: ClientRulesUiState,
    ): ClientRulesUiState {
        if (!snapshot.capabilities.routeDirectionRules || !snapshot.capabilities.exactDestinationRules || snapshot.entitlements.isEmpty()) {
            return previous.copy(
                enabled = false,
                serviceScopes = emptyList(),
                selectedServiceScopeId = null,
                serviceScopeSelectionEnabled = false,
                quota = null,
                directions = emptyList(),
                rules = emptyList(),
                draftDirectionId = null,
                errorCode = "client_rules_unavailable",
                unavailableReason = ClientRulesText(R.string.client_rules_unavailable_client),
            )
        }

        val selectedEntitlement = snapshot.entitlements.firstOrNull { it.serviceScopeId == selectedScopeId }
            ?: snapshot.entitlements.first()
        val allowedDirections = snapshot.allowedDirections(selectedEntitlement)
        val selectedDraftDirection = when {
            previous.draftDirectionId != null && allowedDirections.any { it.id == previous.draftDirectionId } -> previous.draftDirectionId
            else -> allowedDirections.firstOrNull()?.id
        }
        val scopeRules = snapshot.rules
            .filter { it.serviceScopeId == selectedEntitlement.serviceScopeId && it.deletedAtMs == null }
            .sortedWith(compareBy<ClientRouteRule> { it.destinationIp }.thenBy { it.destinationPort })

        return previous.copy(
            enabled = allowedDirections.isNotEmpty(),
            serviceScopes = snapshot.entitlements.map { it.toOption() },
            selectedServiceScopeId = selectedEntitlement.serviceScopeId,
            serviceScopeSelectionEnabled = snapshot.entitlements.size > 1,
            quota = ClientRuleQuotaLabel(
                remainingRules = selectedEntitlement.remainingRules,
                maxRules = selectedEntitlement.maxRules,
            ),
            directions = allowedDirections,
            rules = scopeRules,
            draftDirectionId = selectedDraftDirection,
            errorCode = previous.errorCode?.takeUnless { it == "client_rules_unavailable" },
            unavailableReason = if (allowedDirections.isEmpty()) {
                ClientRulesText(R.string.client_rules_unavailable_no_directions)
            } else {
                null
            },
        )
    }

    private fun ClientRouteRuleSnapshot.allowedDirections(entitlement: RouteRuleEntitlement): List<RouteDirection> = directions
        .filter { direction -> direction.id in entitlement.allowedDirectionIds && direction.state == RouteDirectionStatus.AVAILABLE }
        .sortedBy { it.sortOrder }

    private fun RouteRuleEntitlement.toOption(): ClientRuleServiceScopeOption = ClientRuleServiceScopeOption(
        serviceScopeId = serviceScopeId,
        label = if (subscriptionId != null) {
            ClientRulesText(
                resId = R.string.client_rules_service_plan_label,
                args = listOf(serviceId, subscriptionId),
            )
        } else {
            ClientRulesText(
                resId = R.string.client_rules_service_label,
                args = listOf(serviceId),
            )
        },
    )

    companion object {
        fun factory(context: Context): ViewModelProvider.Factory {
            val appContext = context.applicationContext
            return object : ViewModelProvider.Factory {
                @Suppress("UNCHECKED_CAST")
                override fun <T : ViewModel> create(modelClass: Class<T>): T {
                    val routeRuleStore = ClientRouteRuleStore.from(appContext)
                    return ClientRulesViewModel(
                        store = ClientRouteRuleStoreAdapter(routeRuleStore),
                        repository = PanelClientRulesRepository(PanelRepository(appContext)),
                    ) as T
                }
            }
        }
    }
}

private class ClientRouteRuleStoreAdapter(
    private val delegate: ClientRouteRuleStore,
) : ClientRulesStore {
    override val snapshot: StateFlow<ClientRouteRuleSnapshot> = delegate.snapshot
    override fun current(): ClientRouteRuleSnapshot = delegate.current()
    override fun validateDraft(draft: ClientRuleWriteDraft): ClientRuleValidationResult = ClientRouteRuleStore.validateDraft(draft)
}

private class PanelClientRulesRepository(
    private val delegate: PanelRepository,
) : ClientRulesRepository {
    override suspend fun createClientRule(draft: ClientRuleWriteDraft): ClientRouteRule = delegate.createClientRule(draft)
    override suspend fun updateClientRule(ruleId: String, draft: ClientRuleWriteDraft): ClientRouteRule = delegate.updateClientRule(ruleId, draft)
    override suspend fun deleteClientRule(ruleId: String): ClientRouteRule = delegate.deleteClientRule(ruleId)
}
